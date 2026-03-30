<?php
class PanelApp
{
    protected $config = array();
    protected $storage;
    protected $viewPath;
    protected $store;
    protected $requestMethod;
    protected $requestPath;
    protected $basePath;
    protected $apiContext = false;
    protected $apiCaptured = null;

    public function __construct($config)
    {
        $this->config = $config;
        $this->storage = PANEL_ROOT . '/storage';
        $this->viewPath = PANEL_ROOT . '/app/views';
        $this->ensureDirectories();
        $this->store = new JsonStore($this->storage);
        $this->applyRuntimeTimezone();
        $this->store->ensureCollections(array(
            'admins', 'resellers', 'nodes', 'templates', 'customers', 'customer_links',
            'tickets', 'ticket_messages', 'credit_ledger', 'activity', 'notices',
            'telegram_bindings', 'telegram_states'
        ));
        $this->startSession();
        $this->requestMethod = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');
        $this->ensureInstallLockIfInstalled();
        $this->maybeDecodeShieldPost();
        $this->sanitizeIncomingRequests();
        $this->basePath = $this->detectBasePath();
        $requestUriPath = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
        if (!$requestUriPath) {
            $requestUriPath = '/';
        }
        if ($this->basePath !== '' && strpos($requestUriPath, $this->basePath) === 0) {
            $requestUriPath = substr($requestUriPath, strlen($this->basePath));
            if ($requestUriPath === false || $requestUriPath === '') {
                $requestUriPath = '/';
            }
        }
        $this->requestPath = $requestUriPath;
        if (rtrim($this->requestPath, '/') === '') {
            $this->requestPath = '/';
        } else {
            $this->requestPath = rtrim($this->requestPath, '/');
        }
    }

    protected function applyRuntimeTimezone()
    {
        $cfg = $this->store ? $this->store->readConfig('app') : array();
        if (!empty($cfg['timezone'])) {
            @date_default_timezone_set((string) $cfg['timezone']);
        }
    }

    protected function detectBasePath()
    {
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
        $base = panel_normalize_base_path(dirname($scriptName));
        if ($base !== '' && substr($base, -7) === '/public') {
            $base = substr($base, 0, -7);
            $base = panel_normalize_base_path($base);
        }
        return $base;
    }

    public function basePath()
    {
        return $this->basePath;
    }

    public function projectRoot()
    {
        return PANEL_ROOT;
    }

    public function url($path)
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', (string) $path)) {
            return (string) $path;
        }
        return panel_url_join($this->basePath, $path);
    }

    public function asset($path)
    {
        $clean = ltrim((string) $path, '/');
        if ($clean !== '' && preg_match('/\.js$/i', $clean) && !empty($this->securitySettings()['js_hardening'])) {
            return $this->url('/__asset/' . rawurlencode($clean));
        }
        return $this->url('/assets/' . $clean);
    }

    protected function ensureDirectories()
    {
        $paths = array(
            $this->storage,
            $this->storage . '/config',
            $this->storage . '/data',
            $this->storage . '/logs',
            $this->storage . '/cache',
            $this->storage . '/cache/cookies',
            $this->storage . '/cache/rate_limits',
            $this->storage . '/cache/qrcodes',
            $this->storage . '/locks',
            $this->storage . '/backups',
        );
        foreach ($paths as $path) {
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
    }

    protected function startSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(isset($this->config['session_name']) ? $this->config['session_name'] : 'xui_reseller');
            ini_set('session.gc_maxlifetime', '7200');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_httponly', '1');
            if ($this->requestIsSecure()) {
                ini_set('session.cookie_secure', '1');
            }
            session_start();
        }
        if (!isset($_SESSION['_meta'])) {
            $_SESSION['_meta'] = array('created_at' => time(), 'regenerated_at' => time());
        }
        if (time() - (int) $_SESSION['_meta']['regenerated_at'] >= 300) {
            session_regenerate_id(true);
            $_SESSION['_meta']['regenerated_at'] = time();
        }
    }

    public function run()
    {
        $path = $this->requestPath;
        $method = $this->requestMethod;

        if (preg_match('#^/user/([a-zA-Z0-9_-]+)$#', $path, $m) && $method === 'GET') {
            return $this->publicSubscription($m[1]);
        }
        if (preg_match('#^/user/([a-zA-Z0-9_-]+)/export$#', $path, $m) && $method === 'GET') {
            return $this->publicSubscriptionExport($m[1]);
        }
        if ($path === '/get' && $method === 'GET') {
            $this->requireInstalled();
            return $this->publicGetAccess();
        }
        if ($path === '/get' && $method === 'POST') {
            $this->requireInstalled();
            $this->validateCsrf();
            return $this->publicGetAccess();
        }
        if (($path === '/__qr' || preg_match('#^/__qr/([a-f0-9]{40})$#', $path, $qrMatch)) && $method === 'GET') {
            $this->requireInstalled();
            $qrToken = isset($qrMatch[1]) ? $qrMatch[1] : trim((string) $this->input('k', ''));
            return $this->serveLocalQr($qrToken);
        }
        if (preg_match('#^/__asset/([^/]+)$#', $path, $m) && $method === 'GET') {
            return $this->serveInternalAsset(rawurldecode($m[1]));
        }
        if (preg_match('#^/telegram/webhook/([a-zA-Z0-9_-]+)$#', $path, $m)) {
            return $this->telegramWebhook($m[1]);
        }
        if (preg_match('#^/telegram/poll/([a-zA-Z0-9_-]+)$#', $path, $m)) {
            return $this->telegramPollEndpoint($m[1]);
        }

        if (preg_match('#^/sync/export/([a-zA-Z0-9_-]+)$#', $path, $m) && $method === 'GET') {
            $this->requireInstalled();
            return $this->panelSyncExportEndpoint($m[1]);
        }
        if (preg_match('#^/sync/run/([a-zA-Z0-9_-]+)$#', $path, $m) && ($method === 'GET' || $method === 'POST')) {
            $this->requireInstalled();
            return $this->panelSyncRunEndpoint($m[1]);
        }

        if ($path === '/') {
            if (!$this->isInstalled()) {
                $this->redirect('/install');
            }
            if ($this->authCheck()) {
                $this->redirect($this->authRole() === 'admin' ? '/admin/dashboard' : '/reseller/dashboard');
            }
            $this->redirect('/login');
        }

        if ($path === '/install' && $method === 'GET') {
            $this->installerOnly();
            return $this->renderAuth('install.php', array('errors' => array(), 'old' => array()));
        }
        if ($path === '/install' && $method === 'POST') {
            $this->installerOnly();
            $this->validateCsrf();
            return $this->handleInstall();
        }


$this->requireInstalled();

if (strpos($path, '/api/reseller') === 0) {
    return $this->handleResellerApi($path, $method);
}

if ($path === '/login' && $method === 'GET') {

            $this->guestOnly(true);
            return $this->renderAuth('login.php', array('errors' => array(), 'old' => array('username' => '')));
        }
        if ($path === '/login' && $method === 'POST') {
            $this->guestOnly(true);
            $this->validateCsrf();
            return $this->handleLogin();
        }
        if ($path === '/logout') {
            return $this->logout();
        }

        if ($path === '/admin/dashboard' && $method === 'GET') { $this->adminOnly(); return $this->adminDashboard(); }
        if ($path === '/admin/resellers' && $method === 'GET') { $this->adminOnly(); return $this->adminResellers(); }
        if ($path === '/admin/resellers/create' && $method === 'GET') { $this->adminOnly(); return $this->adminResellerForm('create'); }
        if ($path === '/admin/resellers/create' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveReseller(); }
        if (preg_match('#^/admin/resellers/([^/]+)/edit$#', $path, $m) && $method === 'GET') { $this->adminOnly(); return $this->adminResellerForm('edit', $m[1]); }
        if (preg_match('#^/admin/resellers/([^/]+)/edit$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveReseller($m[1]); }
        if (preg_match('#^/admin/resellers/([^/]+)/toggle$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->toggleEntity('resellers', $m[1], '/admin/resellers', 'Reseller status updated.'); }
        if (preg_match('#^/admin/resellers/([^/]+)/delete$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->deleteReseller($m[1]); }
        if (preg_match('#^/admin/resellers/([^/]+)/credit$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->adjustResellerCredit($m[1]); }

        if ($path === '/admin/nodes' && $method === 'GET') { $this->adminOnly(); return $this->adminNodes(); }
        if ($path === '/admin/nodes/create' && $method === 'GET') { $this->adminOnly(); return $this->adminNodeForm('create'); }
        if ($path === '/admin/nodes/create' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveNode(); }
        if (preg_match('#^/admin/nodes/([^/]+)/edit$#', $path, $m) && $method === 'GET') { $this->adminOnly(); return $this->adminNodeForm('edit', $m[1]); }
        if (preg_match('#^/admin/nodes/([^/]+)/edit$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveNode($m[1]); }
        if (preg_match('#^/admin/nodes/([^/]+)/toggle$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->toggleEntity('nodes', $m[1], '/admin/nodes', 'Server status updated.'); }
        if (preg_match('#^/admin/nodes/([^/]+)/delete$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->deleteNode($m[1]); }
        if (preg_match('#^/admin/nodes/([^/]+)/test$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->testNode($m[1]); }

        if ($path === '/admin/templates' && $method === 'GET') { $this->adminOnly(); return $this->adminTemplates(); }
        if ($path === '/admin/templates/create' && $method === 'GET') { $this->adminOnly(); return $this->adminTemplateForm('create'); }
        if ($path === '/admin/templates/create' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveTemplate(); }
        if (preg_match('#^/admin/templates/([^/]+)/edit$#', $path, $m) && $method === 'GET') { $this->adminOnly(); return $this->adminTemplateForm('edit', $m[1]); }
        if (preg_match('#^/admin/templates/([^/]+)/edit$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveTemplate($m[1]); }
        if (preg_match('#^/admin/templates/([^/]+)/toggle$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->toggleEntity('templates', $m[1], '/admin/templates', 'Template status updated.'); }
        if (preg_match('#^/admin/templates/([^/]+)/delete$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->deleteTemplate($m[1]); }
        if (preg_match('#^/admin/nodes/([^/]+)/import-inbounds$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->importNodeInbounds($m[1]); }

        if ($path === '/admin/customers' && $method === 'GET') { $this->adminOnly(); return $this->customersPage('admin'); }
        if ($path === '/admin/customers/sync-visible' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->syncCustomersList('admin'); }
        if (preg_match('#^/admin/customers/([^/]+)$#', $path, $m) && $method === 'GET') { $this->adminOnly(); return $this->customerDetailsPage('admin', $m[1]); }
        if (preg_match('#^/admin/customers/([^/]+)/toggle$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->toggleCustomer($m[1], false); }
        if (preg_match('#^/admin/customers/([^/]+)/delete$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->deleteCustomer($m[1], false); }
        if (preg_match('#^/admin/customers/([^/]+)/sync$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->syncCustomer($m[1], false); }

        if ($path === '/admin/tickets' && $method === 'GET') { $this->adminOnly(); return $this->ticketsPage('admin'); }
        if ($path === '/admin/tickets/create' && $method === 'GET') { $this->adminOnly(); return $this->ticketForm('admin'); }
        if ($path === '/admin/tickets/create' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveTicket('admin'); }
        if (preg_match('#^/admin/tickets/([^/]+)$#', $path, $m) && $method === 'GET') { $this->adminOnly(); return $this->ticketView('admin', $m[1]); }
        if (preg_match('#^/admin/tickets/([^/]+)/reply$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->replyTicket('admin', $m[1]); }
        if (preg_match('#^/admin/tickets/([^/]+)/status$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->ticketStatus('admin', $m[1]); }
        if (preg_match('#^/admin/tickets/([^/]+)/delete$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->deleteTicket('admin', $m[1]); }


if ($path === '/admin/notices' && $method === 'GET') { $this->adminOnly(); return $this->adminNotices(); }
if ($path === '/admin/notices/create' && $method === 'GET') { $this->adminOnly(); return $this->adminNoticeForm('create'); }
if ($path === '/admin/notices/create' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveNotice(); }
if (preg_match('#^/admin/notices/([^/]+)/edit$#', $path, $m) && $method === 'GET') { $this->adminOnly(); return $this->adminNoticeForm('edit', $m[1]); }
if (preg_match('#^/admin/notices/([^/]+)/edit$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveNotice($m[1]); }
if (preg_match('#^/admin/notices/([^/]+)/toggle$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->toggleEntity('notices', $m[1], '/admin/notices', 'Notice status updated.'); }
if (preg_match('#^/admin/notices/([^/]+)/delete$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->deleteNotice($m[1]); }
if ($path === '/admin/activity' && $method === 'GET') { $this->adminOnly(); return $this->adminActivity(); }
if ($path === '/admin/transactions' && $method === 'GET') { $this->adminOnly(); return $this->adminTransactions(); }
if ($path === '/admin/logs' && $method === 'GET') { $this->adminOnly(); return $this->adminSystemLogs(); }
if ($path === '/admin/settings' && $method === 'GET') { $this->adminOnly(); return $this->adminSettings(); }

        if ($path === '/admin/settings' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveAdminSettings(); }
        if ($path === '/admin/logs/clear' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->clearAdminLog(); }
        if ($path === '/admin/telegram/poll' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->adminTelegramPoll(); }
        if ($path === '/admin/telegram/webhook/set' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->adminTelegramSetWebhook(); }
        if ($path === '/admin/telegram/webhook/delete' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->adminTelegramDeleteWebhook(); }
        if ($path === '/admin/sync/run' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->adminRunPanelSync(); }
        if ($path === '/admin/backups/create' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->createAdminBackup(); }
        if ($path === '/admin/backups/download' && $method === 'GET') { $this->adminOnly(); return $this->downloadAdminBackup(); }
        if ($path === '/admin/backups/delete' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->deleteAdminBackup(); }

if ($path === '/reseller/dashboard' && $method === 'GET') { $this->resellerOnly(); return $this->resellerDashboard(); }
if ($path === '/reseller/profile' && $method === 'GET') { $this->resellerOnly(); return $this->resellerProfile(); }
if ($path === '/reseller/profile' && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->saveResellerPassword(); }
        if ($path === '/reseller/customers' && $method === 'GET') { $this->resellerOnly(); return $this->customersPage('reseller'); }
        if ($path === '/reseller/customers/sync-visible' && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->syncCustomersList('reseller'); }
        if ($path === '/reseller/customers/create' && $method === 'GET') { $this->resellerOnly(); return $this->customerForm(); }
        if ($path === '/reseller/customers/create' && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->saveCustomer(); }
        if (preg_match('#^/reseller/customers/([^/]+)$#', $path, $m) && $method === 'GET') { $this->resellerOnly(); return $this->customerDetailsPage('reseller', $m[1]); }
        if (preg_match('#^/reseller/customers/([^/]+)/edit$#', $path, $m) && $method === 'GET') { $this->resellerOnly(); return $this->customerForm($m[1]); }
        if (preg_match('#^/reseller/customers/([^/]+)/edit$#', $path, $m) && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->saveCustomer($m[1]); }
        if (preg_match('#^/reseller/customers/([^/]+)/toggle$#', $path, $m) && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->toggleCustomer($m[1], true); }
        if (preg_match('#^/reseller/customers/([^/]+)/delete$#', $path, $m) && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->deleteCustomer($m[1], true); }
        if (preg_match('#^/reseller/customers/([^/]+)/sync$#', $path, $m) && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->syncCustomer($m[1], true); }

        if ($path === '/reseller/tickets' && $method === 'GET') { $this->resellerOnly(); return $this->ticketsPage('reseller'); }
        if ($path === '/reseller/tickets/create' && $method === 'GET') { $this->resellerOnly(); return $this->ticketForm('reseller'); }
        if ($path === '/reseller/tickets/create' && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->saveTicket('reseller'); }
        if (preg_match('#^/reseller/tickets/([^/]+)$#', $path, $m) && $method === 'GET') { $this->resellerOnly(); return $this->ticketView('reseller', $m[1]); }
        if (preg_match('#^/reseller/tickets/([^/]+)/reply$#', $path, $m) && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->replyTicket('reseller', $m[1]); }
        if (preg_match('#^/reseller/tickets/([^/]+)/status$#', $path, $m) && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->ticketStatus('reseller', $m[1]); }

        $this->abort(404, 'Page not found.');
    }

    public function config($key, $default)
    {
        return array_key_exists($key, $this->config) ? $this->config[$key] : $default;
    }

    public function input($key, $default)
    {
        if (isset($_POST[$key])) { return $_POST[$key]; }
        if (isset($_GET[$key])) { return $_GET[$key]; }
        return $default;
    }

    protected function sanitizeIncomingRequests()
    {
        $_GET = $this->sanitizeRequestBag(isset($_GET) ? $_GET : array());
        $_POST = $this->sanitizeRequestBag(isset($_POST) ? $_POST : array());
        $_REQUEST = array_merge($_GET, $_POST);
    }

    protected function sanitizeRequestBag($bag, $depth = 0)
    {
        if (!is_array($bag) || $depth > 8) { return array(); }
        $clean = array();
        foreach ($bag as $key => $value) {
            $safeKey = $this->sanitizeRequestKey($key);
            if ($safeKey === '') { continue; }
            if (is_array($value)) {
                $clean[$safeKey] = $this->sanitizeRequestBag($value, $depth + 1);
            } else {
                $clean[$safeKey] = $this->sanitizeRequestScalar($value);
            }
        }
        return $clean;
    }

    protected function sanitizeRequestKey($key)
    {
        $key = (string) $key;
        $key = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $key);
        if (strlen($key) > 80) { $key = substr($key, 0, 80); }
        return $key;
    }

    protected function sanitizeRequestScalar($value)
    {
        if ($value === null) { return ''; }
        if (is_bool($value)) { return $value ? '1' : '0'; }
        if (is_int($value) || is_float($value)) { return (string) $value; }
        if (!is_string($value)) { return ''; }
        if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
            if (function_exists('mb_convert_encoding')) {
                $value = (string) @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }
        }
        $value = str_replace("", '', $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        if (strlen($value) > 200000) { $value = substr($value, 0, 200000); }
        return $value;
    }

    protected function sanitizeIdentifier($value, $maxLen)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9_\-]/', '', $value);
        if (strlen($value) > $maxLen) { $value = substr($value, 0, $maxLen); }
        return $value;
    }

    public function authCheck() { return !empty($_SESSION['auth']); }
    public function authUser() { return isset($_SESSION['auth']) ? $_SESSION['auth'] : null; }
    public function authRole() { $u = $this->authUser(); return is_array($u) && isset($u['role']) ? $u['role'] : null; }

    protected function requireAuth()
    {
        if (!$this->authCheck()) {
            $this->flash('error', 'Please sign in first.');
            $this->redirect('/login');
        }
    }
    protected function adminOnly() { $this->requireAuth(); if ($this->authRole() !== 'admin') { $this->abort(403, 'Forbidden'); } }
    protected function resellerOnly() { $this->requireAuth(); if ($this->authRole() !== 'reseller') { $this->abort(403, 'Forbidden'); } }

    public function flash($type, $message)
    {
        if ($type !== null && $message !== null) {
            $_SESSION['_flash'] = array('type' => $type, 'message' => $message);
            return null;
        }
        if (!isset($_SESSION['_flash'])) { return null; }
        $f = $_SESSION['_flash']; unset($_SESSION['_flash']); return $f;
    }

    public function csrfToken()
    {
        if (empty($_SESSION['_csrf'])) { $_SESSION['_csrf'] = panel_random_hex(64); }
        return $_SESSION['_csrf'];
    }

    protected function validateCsrf()
    {
        $token = (string) $this->input('_token', '');
        $known = isset($_SESSION['_csrf']) ? (string) $_SESSION['_csrf'] : '';
        if (!hash_equals($known, $token)) {
            $this->appendSecurityLog('firewall', 'error', 'CSRF token validation failed.', array('path' => $this->requestPath, 'ip' => $this->clientIp()));
            if ($this->isAjax()) { $this->json(array('ok' => false, 'message' => 'Invalid security token.'), 419); }
            $this->abort(419, 'Invalid security token.');
        }
        if (!$this->validateSameOriginPost()) {
            $this->appendSecurityLog('firewall', 'error', 'Origin check failed.', array('path' => $this->requestPath, 'ip' => $this->clientIp(), 'origin' => isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '', 'referer' => isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : ''));
            if ($this->isAjax()) { $this->json(array('ok' => false, 'message' => 'Origin check failed.'), 403); }
            $this->abort(403, 'Origin check failed.');
        }
    }

    public function isAjax()
    {
        return strtolower(isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : '') === 'xmlhttprequest';
    }

    public function isInstalled()
    {
        return is_file($this->storage . '/config/app.json') && count($this->store->all('admins')) > 0;
    }

    protected function installLockPath()
    {
        return $this->storage . '/config/install.lock.json';
    }

    protected function isInstallLocked()
    {
        return is_file($this->installLockPath());
    }

    protected function writeInstallLock()
    {
        $payload = array(
            'locked' => true,
            'locked_at' => panel_now(),
            'ip' => $this->clientIp(),
        );
        @file_put_contents($this->installLockPath() . '.tmp', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($this->installLockPath() . '.tmp', $this->installLockPath());
    }

    protected function ensureInstallLockIfInstalled()
    {
        if ($this->isInstalled() && !$this->isInstallLocked()) {
            $this->writeInstallLock();
        }
    }

    protected function installerOnly()
    {
        if ($this->authCheck()) {
            $this->redirect($this->authRole() === 'admin' ? '/admin/dashboard' : '/reseller/dashboard');
        }
        if ($this->isInstalled() || $this->isInstallLocked()) {
            $this->flash('error', 'Installer is locked because the panel is already installed.');
            $this->redirect('/login');
        }
    }

    protected function requireInstalled() { if (!$this->isInstalled()) { $this->redirect('/install'); } }
    protected function guestOnly($installedRequired)
    {
        if ($installedRequired && !$this->isInstalled()) { $this->redirect('/install'); }
        if ($this->authCheck()) {
            $this->redirect($this->authRole() === 'admin' ? '/admin/dashboard' : '/reseller/dashboard');
        }
    }

    protected function handleInstall()
    {
        $data = array(
            'app_name' => trim((string) $this->input('app_name', 'XUI Reseller Panel')),
            'app_url' => trim((string) $this->input('app_url', '')),
            'timezone' => trim((string) $this->input('timezone', 'Europe/Sofia')),
            'default_duration_days' => trim((string) $this->input('default_duration_days', '30')),
            'admin_username' => trim((string) $this->input('admin_username', 'admin')),
            'admin_password' => (string) $this->input('admin_password', ''),
        );
        $errors = array();
        if (strlen($data['app_name']) < 3) { $errors['app_name'][] = 'Application name must be at least 3 characters.'; }
        if ($data['app_url'] !== '' && filter_var($data['app_url'], FILTER_VALIDATE_URL) === false) { $errors['app_url'][] = 'Application URL is not valid.'; }
        if (!ctype_digit($data['default_duration_days']) || (int) $data['default_duration_days'] < 1) { $errors['default_duration_days'][] = 'Default duration must be a positive integer.'; }
        if (!preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $data['admin_username'])) { $errors['admin_username'][] = 'Admin username is invalid.'; }
        if (strlen($data['admin_password']) < 8) { $errors['admin_password'][] = 'Admin password must be at least 8 characters.'; }
        if ($errors) { return $this->renderAuth('install.php', array('errors' => $errors, 'old' => $data)); }

        $cfg = array(
            'app_name' => $data['app_name'],
            'app_url' => $data['app_url'],
            'timezone' => $data['timezone'],
            'app_key' => panel_random_hex(64),
            'default_duration_days' => (int) $data['default_duration_days'],
            'login_max_attempts' => 8,
            'login_window_seconds' => 900,
            'login_lockout_seconds' => 900,
            'subscription_max_requests' => 60,
            'subscription_window_seconds' => 60,
            'page_shield_mode' => 'off',
            'page_shield_key' => base64_encode(function_exists('random_bytes') ? random_bytes(32) : openssl_random_pseudo_bytes(32)),
            'page_shield_forms' => 1,
            'js_hardening' => 1,
            'api_enabled' => 0,
            'api_encryption' => 0,
            'panel_sync_enabled' => 0,
            'panel_sync_mode' => 'off',
            'panel_sync_master_url' => '',
            'panel_sync_shared_secret' => panel_random_hex(24),
            'panel_sync_interval_seconds' => 300,
            'panel_sync_prune_missing' => 0,
            'panel_sync_proxy_enabled' => 0,
            'panel_sync_proxy_type' => 'http',
            'panel_sync_proxy_host' => '',
            'panel_sync_proxy_port' => 0,
            'panel_sync_proxy_username' => '',
            'panel_sync_proxy_password' => '',
            'created_at' => panel_now(),
        );
        $this->store->writeConfig('app', $cfg);
        $this->store->insert('admins', array(
            'username' => $data['admin_username'],
            'display_name' => 'Administrator',
            'password_hash' => panel_password_hash($data['admin_password']),
            'status' => 'active',
        ), 'adm');
        $this->writeInstallLock();
        $this->log('install.completed', array('admin_username' => $data['admin_username']));
        $this->flash('success', 'Installation completed. Please sign in.');
        $this->redirect('/login');
    }

    protected function handleLogin()
    {
        $username = $this->sanitizeIdentifier($this->input('username', ''), 64);
        $password = $this->sanitizeRequestScalar($this->input('password', ''));

        if ($username === '' || strlen($password) < 1 || strlen($password) > 4096) {
            $this->noteLoginFailure('auto', $username === '' ? 'empty' : $username);
            $this->appendSecurityLog('login', 'error', 'Login rejected because username/password were empty or malformed.', array('username' => $username, 'ip' => $this->clientIp()));
            return $this->renderAuth('login.php', array('errors' => array('login' => array('Invalid credentials or disabled account.')), 'old' => array('username' => $username)));
        }

        $limit = $this->assertLoginRateAllowed('auto', $username);
        if (!$limit['ok']) {
            $this->appendSecurityLog('login', 'error', 'Login rate limit hit.', array('username' => $username, 'ip' => $this->clientIp(), 'message' => $limit['message']));
            return $this->renderAuth('login.php', array('errors' => array('login' => array($limit['message'])), 'old' => array('username' => $username)));
        }

        $record = $this->store->findBy('admins', 'username', $username);
        $role = 'admin';
        if (!$record) {
            $record = $this->store->findBy('resellers', 'username', $username);
            $role = 'reseller';
        }
        if (!$record || (isset($record['status']) && $record['status'] !== 'active') || !password_verify($password, isset($record['password_hash']) ? $record['password_hash'] : '')) {
            $this->noteLoginFailure('auto', $username);
            $this->appendSecurityLog('login', 'error', 'Login failed.', array('username' => $username, 'resolved_role' => $record ? $role : '', 'ip' => $this->clientIp()));
            return $this->renderAuth('login.php', array('errors' => array('login' => array('Invalid credentials or disabled account.')), 'old' => array('username' => $username)));
        }

        $this->clearLoginFailure('auto', $username);
        session_regenerate_id(true);
        $_SESSION['auth'] = array('id' => $record['id'], 'role' => $role, 'username' => $record['username'], 'display_name' => isset($record['display_name']) ? $record['display_name'] : $record['username']);
        $this->appendSecurityLog('login', 'access', 'Login succeeded.', array('username' => $username, 'role' => $role, 'ip' => $this->clientIp()));
        $this->flash('success', 'Welcome back.');
        $this->redirect($role === 'admin' ? '/admin/dashboard' : '/reseller/dashboard');
    }

    public function logout()
    {
        unset($_SESSION['auth']);
        session_regenerate_id(true);
        $this->flash('info', 'You have been signed out.');
        $this->redirect('/login');
    }

    protected function adminDashboard()
    {
        $resellers = $this->store->all('resellers');
        $nodes = $this->store->all('nodes');
        $templates = $this->store->all('templates');
        $customers = $this->store->all('customers');
        $openTickets = $this->store->filterBy('tickets', function ($r) { return isset($r['status']) && $r['status'] !== 'closed'; });
        $creditTotal = 0; foreach ($resellers as $item) { $creditTotal += (float) (isset($item['credit_gb']) ? $item['credit_gb'] : 0); }
        usort($resellers, array($this, 'sortNewest'));
        $this->renderPanel('admin_dashboard.php', array(
            'title' => 'Dashboard',
            'stats' => array('resellers' => count($resellers), 'nodes' => count($nodes), 'templates' => count($templates), 'customers' => count($customers), 'tickets' => count($openTickets), 'credit_gb' => $creditTotal),
            'recent_resellers' => array_slice($resellers, 0, 6),
        ));
    }

    protected function adminResellers()
    {
        $resellers = $this->store->all('resellers'); usort($resellers, array($this, 'sortNewest'));
        $templates = $this->sortedTemplates();
        $map = array(); foreach ($templates as $t) { $map[$t['id']] = $t; }
        $this->renderPanel('admin_resellers.php', array('title' => 'Resellers', 'resellers' => $resellers, 'template_map' => $map));
    }

    protected function adminResellerForm($mode, $id = null)
    {
        $record = array('username' => '', 'display_name' => '', 'prefix' => '', 'credit_gb' => '0', 'fixed_duration_days' => $this->defaultDurationDays(), 'max_expiration_days' => $this->defaultDurationDays(), 'max_ip_limit' => 0, 'min_customer_traffic_gb' => '0', 'max_customer_traffic_gb' => '0', 'status' => 'active', 'restrict' => 0, 'notes' => '', 'api_key' => '', 'regenerate_api_key' => 0, 'allowed_template_ids' => array());
        $errors = array();
        if ($mode === 'edit') {
            $found = $this->store->find('resellers', $id);
            if (!$found) { $this->flash('error', 'Reseller not found.'); $this->redirect('/admin/resellers'); }
            if (!isset($found['max_expiration_days'])) { $found['max_expiration_days'] = isset($found['fixed_duration_days']) ? (int) $found['fixed_duration_days'] : $this->defaultDurationDays(); }
            if (!isset($found['max_ip_limit'])) { $found['max_ip_limit'] = 0; }
            if (!isset($found['min_customer_traffic_gb'])) { $found['min_customer_traffic_gb'] = '0'; }
            if (!isset($found['max_customer_traffic_gb'])) { $found['max_customer_traffic_gb'] = '0'; }
            $record = array_merge($record, $found);
        }
        $this->renderPanel('admin_reseller_form.php', array('title' => $mode === 'edit' ? 'Edit reseller' : 'Create reseller', 'mode' => $mode, 'record' => $record, 'errors' => $errors, 'templates' => $this->sortedTemplates()));
    }

    protected function saveReseller($id = null)
    {
        $mode = $id ? 'edit' : 'create';
        $existing = $id ? $this->store->find('resellers', $id) : null;
        if ($id && !$existing) { $this->flash('error', 'Reseller not found.'); $this->redirect('/admin/resellers'); }
        $allowed = isset($_POST['allowed_template_ids']) ? (array) $_POST['allowed_template_ids'] : array();
        $data = array('username' => trim((string) $this->input('username', '')), 'display_name' => trim((string) $this->input('display_name', '')), 'password' => (string) $this->input('password', ''), 'prefix' => trim((string) $this->input('prefix', '')), 'credit_gb' => trim((string) $this->input('credit_gb', '0')), 'fixed_duration_days' => trim((string) $this->input('fixed_duration_days', (string) $this->defaultDurationDays())), 'max_expiration_days' => trim((string) $this->input('max_expiration_days', (string) $this->input('fixed_duration_days', (string) $this->defaultDurationDays()))), 'max_ip_limit' => trim((string) $this->input('max_ip_limit', '0')), 'min_customer_traffic_gb' => trim((string) $this->input('min_customer_traffic_gb', $existing && isset($existing['min_customer_traffic_gb']) ? (string) $existing['min_customer_traffic_gb'] : '0')), 'max_customer_traffic_gb' => trim((string) $this->input('max_customer_traffic_gb', $existing && isset($existing['max_customer_traffic_gb']) ? (string) $existing['max_customer_traffic_gb'] : '0')),  'telegram_user_id' => trim((string) $this->input('telegram_user_id', $existing && isset($existing['telegram_user_id']) ? $existing['telegram_user_id'] : '')), 'status' => trim((string) $this->input('status', 'active')), 'restrict' => isset($_POST['restrict']) ? 1 : 0, 'notes' => trim((string) $this->input('notes', '')), 'api_key' => trim((string) $this->input('api_key', $existing && isset($existing['api_key']) ? $existing['api_key'] : '')), 'regenerate_api_key' => isset($_POST['regenerate_api_key']) ? 1 : 0, 'allowed_template_ids' => array_values($allowed));
        $errors = array();
        if (!preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $data['username'])) { $errors['username'][] = 'Username may contain only letters, numbers, dash, and underscore.'; }
        if (strlen($data['display_name']) < 2) { $errors['display_name'][] = 'Display name must be at least 2 characters.'; }
        if ($mode === 'create' && strlen($data['password']) < 8) { $errors['password'][] = 'Password must be at least 8 characters.'; }
        if ($mode === 'edit' && $data['password'] !== '' && strlen($data['password']) < 8) { $errors['password'][] = 'Password must be at least 8 characters.'; }
        if (!preg_match('/^[a-zA-Z0-9_-]{2,20}$/', $data['prefix'])) { $errors['prefix'][] = 'Prefix is invalid.'; }
        if (!is_numeric($data['credit_gb']) || (float) $data['credit_gb'] < 0) { $errors['credit_gb'][] = 'Credit must be a non-negative number.'; }
        if (!ctype_digit($data['fixed_duration_days']) || (int) $data['fixed_duration_days'] < 1) { $errors['fixed_duration_days'][] = 'Duration must be a positive integer.'; }
        if (!ctype_digit($data['max_expiration_days']) || (int) $data['max_expiration_days'] < 0) { $errors['max_expiration_days'][] = 'Max expiration days must be zero or a positive integer.'; }
        if (!ctype_digit($data['max_ip_limit']) || (int) $data['max_ip_limit'] < 0) { $errors['max_ip_limit'][] = 'Max IP limit must be zero or a positive integer.'; }
        if (!is_numeric($data['min_customer_traffic_gb']) || (float) $data['min_customer_traffic_gb'] < 0) { $errors['min_customer_traffic_gb'][] = 'Minimum customer traffic must be zero or a positive number.'; }
        if (!is_numeric($data['max_customer_traffic_gb']) || (float) $data['max_customer_traffic_gb'] < 0) { $errors['max_customer_traffic_gb'][] = 'Maximum customer traffic must be zero or a positive number.'; }
        if (is_numeric($data['min_customer_traffic_gb']) && is_numeric($data['max_customer_traffic_gb']) && (float) $data['max_customer_traffic_gb'] > 0 && (float) $data['min_customer_traffic_gb'] > (float) $data['max_customer_traffic_gb']) { $errors['max_customer_traffic_gb'][] = 'Maximum customer traffic must be greater than or equal to the minimum.'; }
        if ($data['telegram_user_id'] !== '' && !ctype_digit($data['telegram_user_id'])) { $errors['telegram_user_id'][] = 'Telegram user ID must contain digits only.'; }
        if (!in_array($data['status'], array('active', 'disabled'), true)) { $errors['status'][] = 'Status is invalid.'; }
        $dup = $this->store->findBy('resellers', 'username', $data['username']);
        if ($dup && (!$id || $dup['id'] !== $id)) { $errors['username'][] = 'Username already exists.'; }
        if ($errors) { return $this->renderPanel('admin_reseller_form.php', array('title' => $mode === 'edit' ? 'Edit reseller' : 'Create reseller', 'mode' => $mode, 'record' => $data, 'errors' => $errors, 'templates' => $this->sortedTemplates())); }
        $payload = array('username' => $data['username'], 'display_name' => $data['display_name'], 'prefix' => $data['prefix'], 'credit_gb' => (float) $data['credit_gb'], 'fixed_duration_days' => (int) $data['fixed_duration_days'], 'max_expiration_days' => (int) $data['max_expiration_days'], 'max_ip_limit' => (int) $data['max_ip_limit'], 'min_customer_traffic_gb' => round((float) $data['min_customer_traffic_gb'], 2), 'max_customer_traffic_gb' => round((float) $data['max_customer_traffic_gb'], 2), 'telegram_user_id' => $data['telegram_user_id'], 'status' => $data['status'], 'restrict' => $data['restrict'] ? 1 : 0, 'notes' => $data['notes'], 'allowed_template_ids' => $data['allowed_template_ids']);
        if (!empty($data['api_key'])) { $payload['api_key'] = $data['api_key']; }
        if ($mode === 'create' || $data['regenerate_api_key']) { $payload['api_key'] = panel_random_hex(48); }
        if ($data['password'] !== '') { $payload['password_hash'] = panel_password_hash($data['password']); }
        if ($id) { $this->store->update('resellers', $id, $payload); $this->log('reseller.updated', array('reseller_id' => $id)); }
        else { $created = $this->store->insert('resellers', $payload, 'rsl'); $this->store->insert('credit_ledger', array('reseller_id' => $created['id'], 'amount_gb' => (float) $payload['credit_gb'], 'type' => 'initial', 'note' => 'Initial credit'), 'led'); $this->log('reseller.created', array('username' => $payload['username'])); }
        $this->flash('success', $id ? 'Reseller updated successfully.' : 'Reseller created successfully.');
        $this->redirect('/admin/resellers');
    }

    protected function adjustResellerCredit($id)
    {
        $reseller = $this->store->find('resellers', $id);
        if (!$reseller) { $this->flash('error', 'Reseller not found.'); $this->redirect('/admin/resellers'); }
        $amount = trim((string) $this->input('amount_gb', '0'));
        $note = trim((string) $this->input('note', 'Manual adjustment'));
        if (!is_numeric($amount)) { $this->flash('error', 'Credit amount is invalid.'); $this->redirect('/admin/resellers'); }
        $new = (float) $reseller['credit_gb'] + (float) $amount;
        if ($new < 0) { $this->flash('error', 'Credit cannot go below zero.'); $this->redirect('/admin/resellers'); }
        $this->store->update('resellers', $id, array('credit_gb' => $new));
        $this->store->insert('credit_ledger', array('reseller_id' => $id, 'amount_gb' => (float) $amount, 'type' => (float) $amount >= 0 ? 'admin_add' : 'admin_deduct', 'note' => $note), 'led');
        $this->flash('success', 'Reseller credit updated.');
        $this->redirect('/admin/resellers');
    }

    protected function toggleEntity($collection, $id, $redirect, $successMessage)
    {
        $record = $this->store->find($collection, $id);
        if (!$record) { $this->flash('error', 'Record not found.'); $this->redirect($redirect); }
        $current = isset($record['status']) ? (string) $record['status'] : 'active';
        $next = $current === 'active' ? 'disabled' : 'active';
        $this->store->update($collection, $id, array('status' => $next));
        $this->flash('success', $successMessage);
        $this->redirect($redirect);
    }

    protected function deleteReseller($id)
    {
        $customers = $this->store->filterBy('customers', function ($item) use ($id) { return isset($item['reseller_id']) && $item['reseller_id'] === $id; });
        if ($customers) { $this->flash('error', 'Delete reseller customers first.'); $this->redirect('/admin/resellers'); }
        $this->store->delete('resellers', $id);
        $this->flash('success', 'Reseller deleted.');
        $this->redirect('/admin/resellers');
    }

    protected function adminNodes()
    {
        $nodes = $this->sortedNodes();
        $this->renderPanel('admin_nodes.php', array('title' => 'Servers', 'nodes' => $nodes));
    }

    protected function adminNodeForm($mode, $id = null)
    {
        $record = array('title' => '', 'slug' => '', 'base_url' => '', 'panel_path' => '/panel', 'subscription_base' => '', 'panel_username' => '', 'panel_password' => '', 'request_timeout' => '20', 'connect_timeout' => '8', 'retry_attempts' => '2', 'allow_insecure_tls' => false, 'status' => 'active', 'notes' => '');
        if ($mode === 'edit') {
            $found = $this->store->find('nodes', $id);
            if (!$found) { $this->flash('error', 'Server not found.'); $this->redirect('/admin/nodes'); }
            $record = array_replace($record, $found);
        }
        $this->renderPanel('admin_node_form.php', array('title' => $mode === 'edit' ? 'Edit server' : 'Add server', 'mode' => $mode, 'record' => $record, 'errors' => array()));
    }

    protected function saveNode($id = null)
    {
        $mode = $id ? 'edit' : 'create';
        $existing = $id ? $this->store->find('nodes', $id) : null;
        if ($id && !$existing) { $this->flash('error', 'Server not found.'); $this->redirect('/admin/nodes'); }
        $data = array(
            'title' => trim((string) $this->input('title', '')),
            'slug' => trim((string) $this->input('slug', '')),
            'base_url' => rtrim(trim((string) $this->input('base_url', '')), '/'),
            'panel_path' => trim((string) $this->input('panel_path', '/panel')),
            'subscription_base' => trim((string) $this->input('subscription_base', '')),
            'panel_username' => trim((string) $this->input('panel_username', '')),
            'panel_password' => (string) $this->input('panel_password', ''),
            'request_timeout' => trim((string) $this->input('request_timeout', '20')),
            'connect_timeout' => trim((string) $this->input('connect_timeout', '8')),
            'retry_attempts' => trim((string) $this->input('retry_attempts', '2')),
            'allow_insecure_tls' => panel_parse_bool($this->input('allow_insecure_tls', ''), false),
            'status' => trim((string) $this->input('status', 'active')),
            'notes' => trim((string) $this->input('notes', '')),
        );
        $errors = array();
        if (strlen($data['title']) < 2) { $errors['title'][] = 'Title must be at least 2 characters.'; }
        if (!preg_match('/^[a-zA-Z0-9_-]{2,30}$/', $data['slug'])) { $errors['slug'][] = 'Slug is invalid.'; }
        if ($data['base_url'] === '' || filter_var($data['base_url'], FILTER_VALIDATE_URL) === false) { $errors['base_url'][] = 'Base URL is invalid.'; }
        if ($data['subscription_base'] !== '' && filter_var($data['subscription_base'], FILTER_VALIDATE_URL) === false) { $errors['subscription_base'][] = 'Subscription base URL is invalid.'; }
        if (strlen($data['panel_username']) < 2) { $errors['panel_username'][] = 'Panel username is required.'; }
        if ($mode === 'create' && strlen($data['panel_password']) < 1) { $errors['panel_password'][] = 'Panel password is required.'; }
        if (!ctype_digit($data['request_timeout']) || (int) $data['request_timeout'] < 5) { $errors['request_timeout'][] = 'Request timeout must be at least 5 seconds.'; }
        if (!ctype_digit($data['connect_timeout']) || (int) $data['connect_timeout'] < 3) { $errors['connect_timeout'][] = 'Connect timeout must be at least 3 seconds.'; }
        if (!ctype_digit($data['retry_attempts']) || (int) $data['retry_attempts'] < 1 || (int) $data['retry_attempts'] > 5) { $errors['retry_attempts'][] = 'Retry attempts must be between 1 and 5.'; }
        if (!in_array($data['status'], array('active', 'disabled'), true)) { $errors['status'][] = 'Status is invalid.'; }
        $dup = $this->store->findBy('nodes', 'slug', $data['slug']); if ($dup && (!$id || $dup['id'] !== $id)) { $errors['slug'][] = 'Slug already exists.'; }
        if ($errors) { return $this->renderPanel('admin_node_form.php', array('title' => $mode === 'edit' ? 'Edit server' : 'Add server', 'mode' => $mode, 'record' => $data, 'errors' => $errors)); }
        $payload = array(
            'title' => $data['title'],
            'slug' => $data['slug'],
            'base_url' => $data['base_url'],
            'panel_path' => $data['panel_path'],
            'subscription_base' => rtrim($data['subscription_base'], '/') . ($data['subscription_base'] !== '' ? '/' : ''),
            'panel_username' => $data['panel_username'],
            'request_timeout' => (int) $data['request_timeout'],
            'connect_timeout' => (int) $data['connect_timeout'],
            'retry_attempts' => (int) $data['retry_attempts'],
            'allow_insecure_tls' => $data['allow_insecure_tls'],
            'status' => $data['status'],
            'notes' => $data['notes']
        );
        if ($data['panel_password'] !== '') { $payload['panel_password'] = $this->encrypt($data['panel_password']); }
        if ($id) { $this->store->update('nodes', $id, $payload); } else { $this->store->insert('nodes', $payload, 'nod'); }
        $this->flash('success', $id ? 'Server updated successfully.' : 'Server saved successfully.');
        $this->redirect('/admin/nodes');
    }

    protected function deleteNode($id)
    {
        $templates = $this->store->filterBy('templates', function ($item) use ($id) { return isset($item['node_id']) && $item['node_id'] === $id; });
        if ($templates) { $this->flash('error', 'Delete related templates first.'); $this->redirect('/admin/nodes'); }
        $this->store->delete('nodes', $id);
        $this->flash('success', 'Server deleted.');
        $this->redirect('/admin/nodes');
    }

    protected function testNode($id)
    {
        $node = $this->store->find('nodes', $id);
        if (!$node) { $this->flash('error', 'Server not found.'); $this->redirect('/admin/nodes'); }
        $adapter = $this->nodeAdapter($node);
        $result = $adapter->ping();
        $message = $result['message'];
        if ($result['ok'] && isset($result['data']['inbounds']) && is_array($result['data']['inbounds'])) { $message .= ' Inbounds detected: ' . count($result['data']['inbounds']); }
        $this->flash($result['ok'] ? 'success' : 'error', $message);
        $this->redirect('/admin/nodes');
    }

    protected function importNodeInbounds($id)
    {
        $node = $this->store->find('nodes', $id);
        if (!$node) { $this->flash('error', 'Server not found.'); $this->redirect('/admin/nodes'); }
        $adapter = $this->nodeAdapter($node);
        $result = $adapter->listInbounds();
        if (!$result['ok'] || !is_array($result['data'])) {
            $this->flash('error', 'Could not load inbounds from node. ' . $result['message']);
            $this->redirect('/admin/nodes');
        }
        $imported = 0;
        $updated = 0;
        foreach ($result['data'] as $row) {
            if (!is_array($row) || !isset($row['id'])) { continue; }
            $title = isset($row['remark']) && trim((string) $row['remark']) !== '' ? trim((string) $row['remark']) : ('Inbound ' . $row['id']);
            $settings = panel_json_field($row, 'settings');
            $streamSettings = panel_json_field($row, 'streamSettings');
            $sniffing = panel_json_field($row, 'sniffing');
            $payload = array(
                'title' => $title,
                'public_label' => $title,
                'node_id' => $id,
                'inbound_id' => (string) $row['id'],
                'inbound_name' => $title,
                'protocol' => isset($row['protocol']) ? strtolower((string) $row['protocol']) : 'vless',
                'sort_order' => 10 + $imported + $updated,
                'status' => 'active',
                'listen' => isset($row['listen']) ? (string) $row['listen'] : '',
                'port' => isset($row['port']) ? (string) $row['port'] : '',
                'settings_json' => isset($row['settings']) ? (string) $row['settings'] : json_encode($settings),
                'stream_settings_json' => isset($row['streamSettings']) ? (string) $row['streamSettings'] : json_encode($streamSettings),
                'sniffing_json' => isset($row['sniffing']) ? (string) $row['sniffing'] : json_encode($sniffing),
                'network' => (string) panel_array_get($streamSettings, 'network', ''),
                'security' => (string) panel_array_get($streamSettings, 'security', ''),
                'notes' => 'Imported from node on ' . panel_now(),
                'raw_inbound_json' => json_encode($row, JSON_UNESCAPED_SLASHES),
            );
            $exists = null;
            $templates = $this->store->all('templates');
            foreach ($templates as $tpl) {
                if (isset($tpl['node_id'], $tpl['inbound_id']) && $tpl['node_id'] === $id && (string) $tpl['inbound_id'] === (string) $row['id']) { $exists = $tpl; break; }
            }
            if ($exists) {
                $this->store->update('templates', $exists['id'], $payload);
                $updated++;
            } else {
                $this->store->insert('templates', $payload, 'tpl');
                $imported++;
            }
        }
        $this->flash('success', 'Imported ' . $imported . ' inbound templates and updated ' . $updated . '.');
        $this->redirect('/admin/templates');
    }

    protected function adminTemplates()
    {
        $templates = $this->sortedTemplates();
        $nodeMap = array(); foreach ($this->store->all('nodes') as $node) { $nodeMap[$node['id']] = $node; }
        $this->renderPanel('admin_templates.php', array('title' => 'Inbound templates', 'templates' => $templates, 'node_map' => $nodeMap));
    }

    protected function adminTemplateForm($mode, $id = null)
    {
        $record = array('title' => '', 'public_label' => '', 'node_id' => '', 'inbound_id' => '', 'inbound_name' => '', 'protocol' => 'vless', 'sort_order' => '10', 'status' => 'active', 'listen' => '', 'port' => '', 'network' => '', 'security' => '', 'settings_json' => '', 'stream_settings_json' => '', 'sniffing_json' => '', 'notes' => '');
        if ($mode === 'edit') {
            $found = $this->store->find('templates', $id);
            if (!$found) { $this->flash('error', 'Template not found.'); $this->redirect('/admin/templates'); }
            $record = array_replace($record, $found);
        }
        $this->renderPanel('admin_template_form.php', array('title' => $mode === 'edit' ? 'Edit inbound template' : 'Add inbound template', 'mode' => $mode, 'record' => $record, 'errors' => array(), 'nodes' => $this->sortedNodes()));
    }

    protected function saveTemplate($id = null)
    {
        $mode = $id ? 'edit' : 'create';
        $existing = $id ? $this->store->find('templates', $id) : null;
        if ($id && !$existing) { $this->flash('error', 'Template not found.'); $this->redirect('/admin/templates'); }
        $data = array(
            'title' => trim((string) $this->input('title', '')),
            'public_label' => trim((string) $this->input('public_label', '')),
            'node_id' => trim((string) $this->input('node_id', '')),
            'inbound_id' => trim((string) $this->input('inbound_id', '')),
            'inbound_name' => trim((string) $this->input('inbound_name', '')),
            'protocol' => trim((string) $this->input('protocol', 'vless')),
            'sort_order' => trim((string) $this->input('sort_order', '10')),
            'status' => trim((string) $this->input('status', 'active')),
            'listen' => trim((string) $this->input('listen', isset($existing['listen']) ? $existing['listen'] : '')),
            'port' => trim((string) $this->input('port', isset($existing['port']) ? $existing['port'] : '')),
            'network' => trim((string) $this->input('network', isset($existing['network']) ? $existing['network'] : '')),
            'security' => trim((string) $this->input('security', isset($existing['security']) ? $existing['security'] : '')),
            'settings_json' => trim((string) $this->input('settings_json', isset($existing['settings_json']) ? $existing['settings_json'] : '')),
            'stream_settings_json' => trim((string) $this->input('stream_settings_json', isset($existing['stream_settings_json']) ? $existing['stream_settings_json'] : '')),
            'sniffing_json' => trim((string) $this->input('sniffing_json', isset($existing['sniffing_json']) ? $existing['sniffing_json'] : '')),
            'notes' => trim((string) $this->input('notes', '')),
        );
        $errors = array();
        if (strlen($data['title']) < 2) { $errors['title'][] = 'Title must be at least 2 characters.'; }
        if (strlen($data['public_label']) < 2) { $errors['public_label'][] = 'Public label must be at least 2 characters.'; }
        if ($data['node_id'] === '' || !$this->store->find('nodes', $data['node_id'])) { $errors['node_id'][] = 'Select a valid server.'; }
        if ($data['inbound_id'] === '') { $errors['inbound_id'][] = 'Inbound ID is required.'; }
        if (strlen($data['inbound_name']) < 2) { $errors['inbound_name'][] = 'Inbound name must be at least 2 characters.'; }
        if ($data['protocol'] === '') { $errors['protocol'][] = 'Protocol is required.'; }
        if (!preg_match('/^-?[0-9]+$/', $data['sort_order'])) { $errors['sort_order'][] = 'Sort order must be an integer.'; }
        if ($data['port'] !== '' && !ctype_digit($data['port'])) { $errors['port'][] = 'Port must be numeric.'; }
        if (!panel_is_valid_json_string($data['settings_json'])) { $errors['settings_json'][] = 'Settings JSON is invalid.'; }
        if (!panel_is_valid_json_string($data['stream_settings_json'])) { $errors['stream_settings_json'][] = 'Stream settings JSON is invalid.'; }
        if (!panel_is_valid_json_string($data['sniffing_json'])) { $errors['sniffing_json'][] = 'Sniffing JSON is invalid.'; }
        if (!in_array($data['status'], array('active', 'disabled'), true)) { $errors['status'][] = 'Status is invalid.'; }
        if ($errors) { return $this->renderPanel('admin_template_form.php', array('title' => $mode === 'edit' ? 'Edit inbound template' : 'Add inbound template', 'mode' => $mode, 'record' => $data, 'errors' => $errors, 'nodes' => $this->sortedNodes())); }
        $payload = array(
            'title' => $data['title'], 'public_label' => $data['public_label'], 'node_id' => $data['node_id'], 'inbound_id' => $data['inbound_id'], 'inbound_name' => $data['inbound_name'],
            'protocol' => strtolower($data['protocol']), 'sort_order' => (int) $data['sort_order'], 'status' => $data['status'], 'listen' => $data['listen'], 'port' => $data['port'],
            'network' => strtolower($data['network']), 'security' => strtolower($data['security']), 'settings_json' => $data['settings_json'], 'stream_settings_json' => $data['stream_settings_json'],
            'sniffing_json' => $data['sniffing_json'], 'notes' => $data['notes']
        );
        if ($id) { $this->store->update('templates', $id, $payload); } else { $this->store->insert('templates', $payload, 'tpl'); }
        $this->flash('success', $id ? 'Inbound template updated.' : 'Inbound template created.');
        $this->redirect('/admin/templates');
    }

    protected function deleteTemplate($id)
    {
        $customers = $this->store->filterBy('customers', function ($item) use ($id) { return isset($item['template_id']) && $item['template_id'] === $id; });
        if ($customers) { $this->flash('error', 'Delete related customers first.'); $this->redirect('/admin/templates'); }
        $this->store->delete('templates', $id);
        $this->flash('success', 'Template deleted.');
        $this->redirect('/admin/templates');
    }

    protected function resellerDashboard()
    {
        $reseller = $this->currentReseller();
        $templates = $this->resellerTemplates($reseller);
        $customers = $this->store->filterBy('customers', function ($item) use ($reseller) { return isset($item['reseller_id']) && $item['reseller_id'] === $reseller['id']; });
        usort($customers, array($this, 'sortNewest'));
        $sec = $this->securitySettings();
        $this->renderPanel('reseller_dashboard.php', array('title' => 'Dashboard', 'reseller' => $reseller, 'templates' => $templates, 'node_map' => $this->nodeMap(), 'template_map' => $this->templateMap(), 'customers' => array_slice($customers, 0, 6), 'api_enabled' => !empty($sec['api_enabled']), 'api_encryption' => !empty($sec['api_encryption'])));
    }

    protected function customersPage($scope)
    {
        $customers = $this->store->all('customers');
        $authReseller = null;
        if ($scope === 'reseller') {
            $authReseller = $this->currentReseller();
            $customers = $this->store->filterBy('customers', function ($item) use ($authReseller) { return isset($item['reseller_id']) && $item['reseller_id'] === $authReseller['id']; });
        }
        $allCustomers = $customers;
        $customerStateCounts = $this->customerStateCounts($allCustomers);
        $q = strtolower(trim((string) $this->input('q', '')));
        $sort = trim((string) $this->input('sort', 'new'));
        $bucket = strtolower(trim((string) $this->input('bucket', 'all')));
        if (!in_array($bucket, array('all', 'live', 'ended'), true)) {
            $bucket = 'all';
        }
        if ($q !== '') {
            $customers = array_values(array_filter($customers, function ($item) use ($q) {
                $blob = strtolower((isset($item['display_name']) ? $item['display_name'] : '') . ' ' . (isset($item['system_name']) ? $item['system_name'] : '') . ' ' . (isset($item['subscription_key']) ? $item['subscription_key'] : '') . ' ' . (isset($item['phone']) ? $item['phone'] : '') . ' ' . (isset($item['email']) ? $item['email'] : ''));
                return strpos($blob, $q) !== false;
            }));
        }
        if ($bucket !== 'all') {
            $customers = array_values(array_filter($customers, function ($item) use ($bucket) {
                return $this->customerMatchesBucket($item, $bucket);
            }));
        }
        if ($sort === 'name') { usort($customers, array($this, 'sortDisplayName')); }
        elseif ($sort === 'traffic') { usort($customers, array($this, 'sortByTrafficLeft')); }
        else { usort($customers, array($this, 'sortNewest')); }

        $syncVisibleIds = $this->pickCustomerSyncCandidates($customers);
        $autoSyncShouldRun = !empty($syncVisibleIds) && $this->customerAutoSyncAllowed($scope);

        $this->renderPanel('customers_index.php', array(
            'title' => 'Customers', 'scope' => $scope, 'customers' => $customers, 'node_map' => $this->nodeMap(), 'template_map' => $this->templateMap(), 'reseller_map' => $this->resellerMap(), 'query' => $q, 'sort' => $sort, 'bucket' => $bucket, 'customer_state_counts' => $customerStateCounts, 'reseller' => $scope === 'reseller' ? $authReseller : null,
            'sync_visible_ids' => $syncVisibleIds, 'auto_sync_should_run' => $autoSyncShouldRun, 'auto_sync_batch_limit' => $this->customerAutoSyncBatchLimit(), 'auto_sync_window_seconds' => $this->customerAutoSyncMinAgeSeconds(),
        ));
    }

    protected function customerAutoSyncBatchLimit()
    {
        return 8;
    }

    protected function customerAutoSyncMinAgeSeconds()
    {
        return 180;
    }

    protected function customerAutoSyncCooldownSeconds()
    {
        return 120;
    }

    protected function customerAutoSyncAllowed($scope)
    {
        if (!isset($_SESSION['_customer_auto_sync']) || !is_array($_SESSION['_customer_auto_sync'])) {
            $_SESSION['_customer_auto_sync'] = array();
        }
        $last = isset($_SESSION['_customer_auto_sync'][$scope]) ? (int) $_SESSION['_customer_auto_sync'][$scope] : 0;
        return ($last <= 0 || (time() - $last) >= $this->customerAutoSyncCooldownSeconds());
    }

    protected function markCustomerAutoSyncRun($scope)
    {
        if (!isset($_SESSION['_customer_auto_sync']) || !is_array($_SESSION['_customer_auto_sync'])) {
            $_SESSION['_customer_auto_sync'] = array();
        }
        $_SESSION['_customer_auto_sync'][$scope] = time();
    }

    protected function pickCustomerSyncCandidates($customers)
    {
        $out = array();
        $limit = $this->customerAutoSyncBatchLimit();
        $minAge = $this->customerAutoSyncMinAgeSeconds();
        foreach ((array) $customers as $item) {
            if (count($out) >= $limit) { break; }
            if (!is_array($item) || empty($item['id'])) { continue; }
            $lastSync = !empty($item['last_synced_at']) ? strtotime($item['last_synced_at']) : 0;
            if ($lastSync > 0 && (time() - $lastSync) < $minAge) { continue; }
            $out[] = $item['id'];
        }
        return $out;
    }

    protected function syncCustomersList($scope)
    {
        $ids = $this->input('ids', array());
        if (!is_array($ids)) {
            $ids = $ids === '' ? array() : explode(',', (string) $ids);
        }
        $cleanIds = array();
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id === '') { continue; }
            $cleanIds[$id] = $id;
            if (count($cleanIds) >= $this->customerAutoSyncBatchLimit()) { break; }
        }
        $cleanIds = array_values($cleanIds);
        $this->markCustomerAutoSyncRun($scope);

        if (empty($cleanIds)) {
            $this->flash('error', 'No customers selected for sync.');
            $this->redirect('/' . $scope . '/customers');
        }

        $ok = 0;
        $failed = 0;
        foreach ($cleanIds as $id) {
            try {
                $customer = $this->loadCustomerForScope($id, $scope === 'reseller');
            } catch (Exception $e) {
                $failed++;
                continue;
            }
            $sync = $this->refreshCustomerUsageFromNode($customer, true);
            if ($sync['ok']) {
                $ok++;
            } else {
                $failed++;
                if (!empty($customer['id'])) {
                    $this->store->update('customers', $customer['id'], array('last_error' => $sync['message']));
                }
            }
        }

        if ($ok > 0 && $failed === 0) {
            $this->flash('success', $ok . ' customer(s) synced.');
        } elseif ($ok > 0) {
            $this->flash('success', $ok . ' customer(s) synced, ' . $failed . ' failed.');
        } else {
            $this->flash('error', 'Customer sync failed for all selected entries.');
        }
        $this->redirect('/' . $scope . '/customers');
    }

    protected function customerDetailsPage($scope, $id)
    {
        $customer = $this->loadCustomerForScope($id, $scope === 'reseller');
        $template = $this->store->find('templates', $customer['template_id']);
        $node = $template ? $this->store->find('nodes', $template['node_id']) : null;
        $reseller = $this->store->find('resellers', $customer['reseller_id']);
        $link = $this->findCustomerLink($customer['id']);
        $subscriptionUrl = $this->appLink('/user/' . $customer['subscription_key']);
        $exportUrl = $this->appLink('/user/' . $customer['subscription_key'] . '/export');
        $proxySubscriptionUrl = $this->buildNodeSubscriptionUrl($node, isset($customer['remote_sub_id']) ? $customer['remote_sub_id'] : (isset($customer['subscription_key']) ? $customer['subscription_key'] : ''));
        $this->renderPanel('customer_show.php', array('title' => 'Customer details', 'scope' => $scope, 'customer' => $customer, 'template' => $template, 'node' => $node, 'reseller' => $reseller, 'link' => $link, 'subscription_url' => $subscriptionUrl, 'export_url' => $exportUrl, 'proxy_subscription_url' => $proxySubscriptionUrl));
    }

    protected function customerForm($id = null)
    {
        $reseller = $this->currentReseller();
        $mode = $id ? 'edit' : 'create';
        $maxIpLimit = $this->resellerMaxIpLimit($reseller);
        $maxExpirationDays = $this->resellerMaxExpirationDays($reseller);
        $record = array('display_name' => '', 'template_id' => '', 'traffic_gb' => '1', 'ip_limit' => $maxIpLimit > 0 ? '1' : '0', 'duration_days' => (string) $this->resellerDefaultCustomerDurationDays($reseller), 'duration_mode' => 'fixed', 'status' => 'active', 'phone' => '', 'email' => '', 'access_pin' => '', 'notes' => '');
        if ($mode === 'edit') {
            $record = array_merge($record, $this->loadCustomerForScope($id, true));
            $record['access_pin'] = '';
            if ($maxIpLimit > 0 && (!isset($record['ip_limit']) || (int) $record['ip_limit'] < 1)) { $record['ip_limit'] = 1; }
            if ($maxExpirationDays > 0 && (!isset($record['duration_days']) || (int) $record['duration_days'] < 1)) { $record['duration_days'] = $maxExpirationDays; }
        }
        $templates = $this->resellerTemplates($reseller);
        $this->renderCustomerForm($mode, $record, array(), $reseller, $templates);
    }

    protected function renderCustomerForm($mode, $record, $errors, $reseller, $templates = null)
    {
        if ($templates === null) { $templates = $this->resellerTemplates($reseller); }
        $this->renderPanel('customer_form.php', array('title' => $mode === 'edit' ? 'Edit customer' : 'Create customer', 'mode' => $mode, 'record' => $record, 'errors' => $errors, 'templates' => $templates, 'reseller' => $reseller, 'node_map' => $this->nodeMap(), 'max_ip_limit' => $this->resellerMaxIpLimit($reseller),
        'min_customer_traffic_gb' => isset($reseller['min_customer_traffic_gb']) ? (float) $reseller['min_customer_traffic_gb'] : 0.0,
        'max_customer_traffic_gb' => isset($reseller['max_customer_traffic_gb']) ? (float) $reseller['max_customer_traffic_gb'] : 0.0, 'max_expiration_days' => $this->resellerMaxExpirationDays($reseller)));
    }

    protected function saveCustomer($id = null)
    {
        $reseller = $this->currentReseller();
        $mode = $id ? 'edit' : 'create';
        $existing = $id ? $this->loadCustomerForScope($id, true) : null;
        $data = array('display_name' => trim((string) $this->input('display_name', '')), 'template_id' => trim((string) $this->input('template_id', '')), 'traffic_gb' => trim((string) $this->input('traffic_gb', '1')), 'ip_limit' => trim((string) $this->input('ip_limit', '0')), 'duration_days' => trim((string) $this->input('duration_days', (string) $this->resellerDefaultCustomerDurationDays($reseller))), 'duration_mode' => trim((string) $this->input('duration_mode', trim((string) $this->input('expiration_mode', 'fixed')))), 'status' => trim((string) $this->input('status', 'active')), 'phone' => $this->normalizeCustomerPhone($this->input('phone', '')), 'email' => $this->normalizeCustomerEmail($this->input('email', '')), 'access_pin' => trim((string) $this->input('access_pin', '')), 'notes' => trim((string) $this->input('notes', '')));
        $errors = array();
        if (strlen($data['display_name']) < 2) { $errors['display_name'][] = 'Name must be at least 2 characters.'; }
        if (!is_numeric($data['traffic_gb']) || (float) $data['traffic_gb'] <= 0) { $errors['traffic_gb'][] = 'Traffic must be greater than zero.'; }
        if (!ctype_digit($data['ip_limit']) || (int) $data['ip_limit'] < 0) { $errors['ip_limit'][] = 'IP limit must be zero or a positive integer.'; }
        if (!ctype_digit($data['duration_days']) || (int) $data['duration_days'] < 0) { $errors['duration_days'][] = 'Expiration days must be zero or a positive integer.'; }
        if (!in_array($data['duration_mode'], array('fixed', 'first_use'), true)) { $errors['duration_mode'][] = 'Expiration mode is invalid.'; }
        $hasPhone = $data['phone'] !== '';
        $hasEmail = $data['email'] !== '';
        $hasAccessIdentity = $hasPhone || $hasEmail;
        $hasPinInput = $data['access_pin'] !== '';
        $existingHasPin = $existing && !empty($existing['access_pin_hash']);
        if ($hasPhone && !$this->isValidCustomerPhone($data['phone'])) { $errors['phone'][] = 'Phone must contain only digits and be between 6 and 20 numbers.'; }
        if ($hasEmail && !$this->isValidCustomerEmail($data['email'])) { $errors['email'][] = 'Email address is invalid.'; }
        if ($hasPinInput && !$this->isValidCustomerPin($data['access_pin'])) { $errors['access_pin'][] = 'PIN must be 1 to 6 letters or numbers.'; }
        if ($mode === 'create') {
            if ($hasAccessIdentity xor $hasPinInput) {
                $errors['auth'][] = 'Phone or email plus PIN must both be filled to enable /get access, or all left blank to disable it.';
            }
        } else {
            if ($hasAccessIdentity && !$hasPinInput && !$existingHasPin) {
                $errors['auth'][] = 'Set a PIN too, or clear phone/email to disable /get access.';
            }
            if (!$hasAccessIdentity && $hasPinInput) {
                $errors['auth'][] = 'Phone or email is required when setting a PIN.';
            }
        }
        $maxIpLimit = $this->resellerMaxIpLimit($reseller);
        if ($maxIpLimit > 0 && ctype_digit($data['ip_limit'])) {
            if ((int) $data['ip_limit'] < 1 || (int) $data['ip_limit'] > $maxIpLimit) { $errors['ip_limit'][] = 'IP limit must be between 1 and ' . $maxIpLimit . ' for this reseller.'; }
        }
        $maxExpirationDays = $this->resellerMaxExpirationDays($reseller);
        if ($maxExpirationDays > 0 && ctype_digit($data['duration_days'])) {
            if ((int) $data['duration_days'] < 1 || (int) $data['duration_days'] > $maxExpirationDays) { $errors['duration_days'][] = 'Expiration days must be between 1 and ' . $maxExpirationDays . ' for this reseller.'; }
        }
        $template = $this->store->find('templates', $data['template_id']);
        if (!$template || !$this->resellerCanUseTemplate($reseller, $data['template_id']) || (isset($template['status']) && $template['status'] !== 'active')) { $errors['template_id'][] = 'Select a permitted inbound template.'; }
        if (!in_array($data['status'], array('active', 'disabled'), true)) { $errors['status'][] = 'Status is invalid.'; }
        if ($errors) { return $this->renderCustomerForm($mode, $data, $errors, $reseller); }

        $traffic = round((float) $data['traffic_gb'], 2);
        $minAllowedGb = isset($reseller['min_customer_traffic_gb']) ? round((float) $reseller['min_customer_traffic_gb'], 2) : 0;
        $maxAllowedGb = isset($reseller['max_customer_traffic_gb']) ? round((float) $reseller['max_customer_traffic_gb'], 2) : 0;
        if ($minAllowedGb > 0 && $traffic < $minAllowedGb) {
            $errors['traffic_gb'][] = 'Traffic must be at least ' . panel_format_gb($minAllowedGb) . ' GB for this reseller.';
        }
        if ($maxAllowedGb > 0 && $traffic > $maxAllowedGb) {
            $errors['traffic_gb'][] = 'Traffic must not be more than ' . panel_format_gb($maxAllowedGb) . ' GB for this reseller.';
        }
        $oldTraffic = $existing ? round((float) $existing['traffic_gb'], 2) : 0;
        $creditDelta = round($traffic - $oldTraffic, 2);
        $usedGb = $existing ? panel_to_gb_from_bytes(isset($existing['traffic_bytes_used']) ? $existing['traffic_bytes_used'] : 0) : 0;
        $liveUsage = null;
        $isRestrictedReseller = panel_parse_bool(isset($reseller['restrict']) ? $reseller['restrict'] : 0, false);
        if ($isRestrictedReseller && $data['status'] !== 'active') {
            $errors['status'][] = 'This reseller is restricted and cannot disable customers.';
        }
        if ($existing && $traffic < $oldTraffic) {
            if ($isRestrictedReseller) {
                $errors['traffic_gb'][] = 'This reseller is restricted and cannot lower customer traffic.';
            } else {
                $liveUsage = $this->refreshCustomerUsageFromNode($existing, true);
                if (!$liveUsage['ok']) {
                    $errors['traffic_gb'][] = 'Live usage sync is required before lowering traffic: ' . $liveUsage['message'];
                } else {
                    $usedGb = panel_to_gb_from_bytes($liveUsage['used_bytes']);
                    if ($traffic < $usedGb) {
                        $errors['traffic_gb'][] = 'Traffic cannot be lower than the customer\'s already used traffic (' . panel_format_gb($usedGb) . ' GB).';
                    }
                }
            }
        }
        if ($creditDelta > 0 && (float) $reseller['credit_gb'] < $creditDelta) {
            $errors['traffic_gb'][] = 'Not enough reseller credit left.';
        }
        if ($errors) {
            return $this->renderCustomerForm($mode, $data, $errors, $reseller);
        }

        $node = $this->store->find('nodes', $template['node_id']);
        if ($node && isset($node['status']) && $node['status'] !== 'active') {
            $errors['template_id'][] = 'The selected server is disabled.';
            return $this->renderCustomerForm($mode, $data, $errors, $reseller);
        }

        $durationDays = (int) $data['duration_days'];
        $durationMode = $data['duration_mode'] === 'first_use' ? 'first_use' : 'fixed';
        $expireAt = ($durationMode === 'fixed' && $durationDays > 0) ? (time() + ($durationDays * 86400)) : 0;
        $ipLimit = (int) $data['ip_limit'];
        $displaySlug = panel_slug($data['display_name'], true);
        if ($existing) {
            $systemName = $existing['system_name'];
        } else {
            $nameParts = array();
            $prefixPart = panel_slug(isset($reseller['prefix']) ? $reseller['prefix'] : '', true);
            if ($prefixPart !== '') { $nameParts[] = $prefixPart; }
            $nameParts[] = preg_replace('/[^0-9]/', '', (string) floor($traffic)) . 'gb';
            $nameParts[] = $displaySlug;
            $nameParts[] = panel_random_hex(6);
            $systemName = strtolower(implode('-', $nameParts));
        }
        $subKey = $existing ? $existing['subscription_key'] : panel_random_hex(16);
        $credential = $existing && isset($existing['uuid']) ? $existing['uuid'] : $this->generateClientCredential(isset($template['protocol']) ? $template['protocol'] : 'vless');
        $remoteEmail = $existing && isset($existing['remote_email']) ? $existing['remote_email'] : $systemName;
        $remoteSubId = $existing && isset($existing['remote_sub_id']) ? $existing['remote_sub_id'] : $subKey;
        $link = $existing ? $this->findCustomerLink($existing['id']) : null;
        $remoteClientId = $existing && isset($existing['remote_client_id']) ? $existing['remote_client_id'] : $this->remoteClientIdentifier(isset($template['protocol']) ? $template['protocol'] : 'vless', $credential, $remoteEmail);

        $payload = array(
            'reseller_id' => $reseller['id'],
            'display_name' => $data['display_name'],
            'system_name' => $systemName,
            'template_id' => $template['id'],
            'node_id' => $node ? $node['id'] : '',
            'traffic_gb' => $traffic,
            'traffic_bytes_total' => panel_to_bytes_from_gb($traffic),
            'traffic_bytes_used' => $existing ? panel_to_bytes_from_gb($usedGb) : 0,
            'traffic_bytes_left' => max(0, panel_to_bytes_from_gb($traffic) - ($existing ? panel_to_bytes_from_gb($usedGb) : 0)),
            'duration_days' => $durationDays,
            'duration_mode' => $durationMode,
            'ip_limit' => $ipLimit,
            'expires_at' => $expireAt > 0 ? gmdate('c', $expireAt) : '',
            'status' => $data['status'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'access_pin_hash' => ((!$hasAccessIdentity) ? '' : ($data['access_pin'] !== '' ? panel_password_hash($data['access_pin']) : ($existing && isset($existing['access_pin_hash']) ? $existing['access_pin_hash'] : ''))),
            'notes' => $data['notes'],
            'subscription_key' => $subKey,
            'uuid' => $credential,
            'remote_email' => $remoteEmail,
            'remote_client_id' => $remoteClientId,
            'remote_sub_id' => $remoteSubId,
            'last_error' => '',
        );

        $xuiMessage = '';
        if ($node) {
            $adapter = $this->nodeAdapter($node);
            $settings = $this->buildXuiClientSettings($customer = array_merge($existing ? $existing : array(), $payload), $template, $expireAt);
            if ($mode === 'edit') {
                $oldTemplate = $this->store->find('templates', $existing['template_id']);
                $oldNode = $oldTemplate ? $this->store->find('nodes', $oldTemplate['node_id']) : null;
                if ($oldTemplate && $oldNode && ($oldTemplate['id'] !== $template['id'] || $oldNode['id'] !== $node['id'])) {
                    $oldAdapter = $this->nodeAdapter($oldNode);
                    $oldAdapter->deleteClient($oldTemplate['inbound_id'], isset($existing['remote_client_id']) ? $existing['remote_client_id'] : '', isset($existing['remote_email']) ? $existing['remote_email'] : $existing['system_name']);
                    $remote = $adapter->ensureClientState($template['inbound_id'], $remoteClientId, $settings, $remoteEmail);
                } else {
                    $remote = $adapter->updateClient($remoteClientId, $template['inbound_id'], $settings);
                    if (!$remote['ok']) {
                        $check = $adapter->getClientTraffic($remoteEmail);
                        if ($check['ok']) {
                            $remote = $adapter->updateClientTraffic($remoteEmail, $payload['traffic_bytes_total'], $this->resolveXuiExpiryMillis($customer, $expireAt));
                        }
                    }
                }
            } else {
                $remote = $adapter->ensureClientState($template['inbound_id'], $remoteClientId, $settings, $remoteEmail);
            }
            if (!$remote['ok']) {
                $this->flash('error', 'Customer was not saved because node sync failed: ' . $remote['message']);
                $this->redirect('/reseller/customers');
            }
            $xuiMessage = $remote['message'];
        }

        if ($mode === 'edit') {
            $this->store->update('customers', $id, $payload);
            if ($creditDelta != 0) {
                $this->changeResellerCredit($reseller['id'], -$creditDelta, 'customer_edit', 'Traffic adjustment for customer ' . $payload['display_name']);
            }
            $customer = $this->store->find('customers', $id);
        } else {
            $customer = $this->store->insert('customers', $payload, 'cus');
            $this->changeResellerCredit($reseller['id'], -$traffic, 'customer_create', 'Traffic allocation for customer ' . $payload['display_name']);
        }
        $this->saveCustomerLink($customer, $template, $node, $remoteClientId, $remoteEmail, $remoteSubId);
        if ($mode === 'edit') { $this->logResellerActivity($reseller['id'], 'customer.edit', $customer, array('traffic_gb' => $traffic, 'ip_limit' => $ipLimit, 'duration_days' => $durationDays, 'duration_mode' => $durationMode)); } else { $this->logResellerActivity($reseller['id'], 'customer.create', $customer, array('traffic_gb' => $traffic, 'ip_limit' => $ipLimit, 'duration_days' => $durationDays, 'duration_mode' => $durationMode)); }
        $this->flash('success', ($mode === 'edit' ? 'Customer updated.' : 'Customer created successfully.') . ($xuiMessage !== '' ? ' ' . $xuiMessage : ''));
        $this->redirect('/reseller/customers');
    }

    protected function toggleCustomer($id, $scoped)
    {
        $customer = $this->loadCustomerForScope($id, $scoped);
        if ($scoped) {
            $reseller = $this->store->find('resellers', $customer['reseller_id']);
            if ($reseller && panel_parse_bool(isset($reseller['restrict']) ? $reseller['restrict'] : 0, false)) {
                $this->flash('error', 'This reseller is restricted and cannot disable or enable customers.');
                $this->redirect('/reseller/customers');
            }
        }
        $status = $customer['status'] === 'active' ? 'disabled' : 'active';
        $template = $this->store->find('templates', $customer['template_id']);
        $node = $template ? $this->store->find('nodes', $template['node_id']) : null;
        if ($template && $node && isset($node['status']) && $node['status'] === 'active') {
            $adapter = $this->nodeAdapter($node);
            $settings = $this->buildXuiClientSettings(array_merge($customer, array('status' => $status)), $template, strtotime($customer['expires_at']));
            $remote = $adapter->updateClient(isset($customer['remote_client_id']) ? $customer['remote_client_id'] : $this->remoteClientIdentifier($template['protocol'], $customer['uuid'], isset($customer['remote_email']) ? $customer['remote_email'] : $customer['system_name']), $template['inbound_id'], $settings);
            if (!$remote['ok']) {
                $this->flash('error', 'Remote status update failed: ' . $remote['message']);
                $this->redirect($scoped ? '/reseller/customers' : '/admin/customers');
            }
        }
        $this->store->update('customers', $customer['id'], array('status' => $status));
        if ($scoped) { $this->logResellerActivity($customer['reseller_id'], $status === 'active' ? 'customer.enable' : 'customer.disable', $customer, array('status' => $status)); }
        $this->flash('success', 'Customer status updated.');
        $this->redirect($scoped ? '/reseller/customers' : '/admin/customers');
    }

    protected function deleteCustomer($id, $scoped)
    {
        $customer = $this->loadCustomerForScope($id, $scoped);
        $reseller = $this->store->find('resellers', $customer['reseller_id']);
        if ($scoped && $reseller && panel_parse_bool(isset($reseller['restrict']) ? $reseller['restrict'] : 0, false)) {
            $this->flash('error', 'This reseller is restricted and cannot delete customers.');
            $this->redirect('/reseller/customers');
        }

        $usage = $this->refreshCustomerUsageFromNode($customer, true);
        if (!$usage['ok']) {
            $this->flash('error', 'Customer delete blocked because live usage could not be verified: ' . $usage['message']);
            $this->redirect($scoped ? '/reseller/customers' : '/admin/customers');
        }
        $customer = $usage['customer'];
        $template = $usage['template'];
        $node = $usage['node'];

        if ($template && $node && isset($node['status']) && $node['status'] === 'active') {
            $adapter = $this->nodeAdapter($node);
            $remote = $adapter->deleteClient($template['inbound_id'], isset($customer['remote_client_id']) ? $customer['remote_client_id'] : '', isset($customer['remote_email']) ? $customer['remote_email'] : $customer['system_name']);
            if (!$remote['ok']) {
                $traffic = $adapter->getClientTraffic(isset($customer['remote_email']) ? $customer['remote_email'] : $customer['system_name']);
                if ($traffic['ok']) {
                    $this->flash('error', 'Remote delete failed and the client still exists on the node: ' . $remote['message']);
                    $this->redirect($scoped ? '/reseller/customers' : '/admin/customers');
                }
            }
        }

        if ($reseller) {
            $refund = max(0, round((float) $customer['traffic_gb'] - panel_to_gb_from_bytes(isset($customer['traffic_bytes_used']) ? $customer['traffic_bytes_used'] : 0), 2));
            if ($refund > 0) { $this->changeResellerCredit($reseller['id'], $refund, 'customer_delete_refund', 'Refund for deleted customer ' . $customer['display_name']); }
        }
        $link = $this->findCustomerLink($customer['id']);
        if ($link) { $this->store->delete('customer_links', $link['id']); }
        $this->store->delete('customers', $customer['id']);
        if ($scoped) { $this->logResellerActivity($customer['reseller_id'], 'customer.delete', $customer, array('refund_gb' => isset($refund) ? $refund : 0)); }
        $this->flash('success', 'Customer deleted.');
        $this->redirect($scoped ? '/reseller/customers' : '/admin/customers');
    }

    protected function syncCustomer($id, $scoped)
    {
        $customer = $this->loadCustomerForScope($id, $scoped);
        $sync = $this->refreshCustomerUsageFromNode($customer, true);
        if (!$sync['ok']) {
            if (isset($customer['id'])) {
                $this->store->update('customers', $customer['id'], array('last_error' => $sync['message']));
            }
            $this->flash('error', 'Sync failed: ' . $sync['message']);
            $this->redirect($scoped ? '/reseller/customers' : '/admin/customers');
        }
        $this->flash('success', 'Customer usage synced.');
        $this->redirect($scoped ? '/reseller/customers/' . $customer['id'] : '/admin/customers/' . $customer['id']);
    }


    protected function refreshCustomerUsageFromNode($customer, $updateStore)
    {
        $template = $this->store->find('templates', isset($customer['template_id']) ? $customer['template_id'] : '');
        $node = $template ? $this->store->find('nodes', $template['node_id']) : null;
        if (!$template || !$node) {
            return array('ok' => false, 'message' => 'Template or node not found.', 'customer' => $customer, 'template' => $template, 'node' => $node, 'used_bytes' => isset($customer['traffic_bytes_used']) ? (float) $customer['traffic_bytes_used'] : 0);
        }
        if (isset($node['status']) && $node['status'] !== 'active') {
            return array('ok' => false, 'message' => 'The node is disabled.', 'customer' => $customer, 'template' => $template, 'node' => $node, 'used_bytes' => isset($customer['traffic_bytes_used']) ? (float) $customer['traffic_bytes_used'] : 0);
        }

        $adapter = $this->nodeAdapter($node);
        $traffic = $adapter->getClientTraffic(isset($customer['remote_email']) ? $customer['remote_email'] : $customer['system_name']);
        if (!$traffic['ok']) {
            return array('ok' => false, 'message' => $traffic['message'], 'customer' => $customer, 'template' => $template, 'node' => $node, 'used_bytes' => isset($customer['traffic_bytes_used']) ? (float) $customer['traffic_bytes_used'] : 0);
        }

        $usage = $this->extractTrafficUsage($traffic);
        $used = $usage['used_bytes'];
        $total = isset($customer['traffic_bytes_total']) ? (float) $customer['traffic_bytes_total'] : panel_to_bytes_from_gb(isset($customer['traffic_gb']) ? $customer['traffic_gb'] : 0);
        $left = max(0, $total - $used);
        $updates = array('traffic_bytes_used' => $used, 'traffic_bytes_left' => $left, 'last_synced_at' => panel_now(), 'last_online_at' => $usage['last_online_at'], 'last_error' => '');
        if ($updateStore && isset($customer['id'])) {
            $this->store->update('customers', $customer['id'], $updates);
            $customer = $this->store->find('customers', $customer['id']);
        } else {
            $customer = array_merge($customer, $updates);
        }

        return array('ok' => true, 'message' => 'Customer usage synced.', 'customer' => $customer, 'template' => $template, 'node' => $node, 'used_bytes' => $used, 'traffic' => $traffic);
    }

    protected function extractTrafficUsage($traffic)
    {
        $row = is_array(isset($traffic['data']) ? $traffic['data'] : null) ? $traffic['data'] : array();
        if (!isset($row['up']) && !isset($row['down']) && isset($traffic['raw']['obj'][0]) && is_array($traffic['raw']['obj'][0])) {
            $row = $traffic['raw']['obj'][0];
        }
        $used = (float) (isset($row['up']) ? $row['up'] : 0) + (float) (isset($row['down']) ? $row['down'] : 0);
        return array('row' => $row, 'used_bytes' => $used, 'last_online_at' => isset($row['lastOnline']) ? $row['lastOnline'] : '');
    }


    protected function normalizeCustomerPhone($value)
    {
        return preg_replace('/\D+/', '', (string) $value);
    }

    protected function normalizeCustomerEmail($value)
    {
        return strtolower(trim((string) $value));
    }

    protected function isValidCustomerEmail($value)
    {
        $value = $this->normalizeCustomerEmail($value);
        return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function normalizeGetIdentifier($value)
    {
        $value = trim((string) $value);
        if (strpos($value, '@') !== false) {
            return $this->normalizeCustomerEmail($value);
        }
        return $this->normalizeCustomerPhone($value);
    }

    protected function isValidCustomerPhone($value)
    {
        $value = $this->normalizeCustomerPhone($value);
        $len = strlen($value);
        return $len >= 6 && $len <= 20;
    }

    protected function isValidCustomerPin($value)
    {
        $value = trim((string) $value);
        return $value !== '' && (bool) preg_match('/^[A-Za-z0-9]{1,6}$/', $value);
    }

    protected function publicGetRateSettings()
    {
        $s = $this->securitySettings();
        return array(
            'max_attempts' => max(3, min(20, (int) $s['login_max_attempts'])),
            'window_seconds' => max(60, (int) $s['login_window_seconds']),
            'lockout_seconds' => max(60, (int) $s['login_lockout_seconds']),
        );
    }

    protected function assertPublicGetRateAllowed($identifier)
    {
        $s = $this->publicGetRateSettings();
        $ip = strtolower($this->clientIp());
        $byIp = $this->assertRateLimitAllowed('client_get_ip', $ip, $s['max_attempts'], $s['window_seconds'], $s['lockout_seconds'], 'Too many client access attempts from this IP.');
        if (!$byIp['ok']) { return $byIp; }
        $identity = strtolower($ip . '|' . $this->normalizeGetIdentifier($identifier));
        return $this->assertRateLimitAllowed('client_get_identity', $identity, $s['max_attempts'], $s['window_seconds'], $s['lockout_seconds'], 'Too many client access attempts for this phone.');
    }

    protected function notePublicGetFailure($identifier)
    {
        $s = $this->publicGetRateSettings();
        $ip = strtolower($this->clientIp());
        $identity = strtolower($ip . '|' . $this->normalizeGetIdentifier($identifier));
        $this->hitRateLimit('client_get_ip', $ip, $s['window_seconds'], $s['lockout_seconds']);
        $this->hitRateLimit('client_get_identity', $identity, $s['window_seconds'], $s['lockout_seconds']);
    }

    protected function clearPublicGetFailure($identifier)
    {
        $ip = strtolower($this->clientIp());
        $identity = strtolower($ip . '|' . $this->normalizeGetIdentifier($identifier));
        $this->clearRateLimit('client_get_ip', $ip);
        $this->clearRateLimit('client_get_identity', $identity);
    }

    protected function findCustomersByAccessAndPin($identifier, $pin)
    {
        $identifier = trim((string) $identifier);
        $pin = trim((string) $pin);
        if ($identifier === '' || $pin === '') { return array(); }
        $mode = (strpos($identifier, '@') !== false) ? 'email' : 'phone';
        $value = $mode === 'email' ? $this->normalizeCustomerEmail($identifier) : $this->normalizeCustomerPhone($identifier);
        if ($value === '') { return array(); }
        $items = $this->store->filterBy('customers', function ($row) use ($mode, $value) {
            $field = $mode === 'email' ? 'email' : 'phone';
            return isset($row[$field]) && (string) $row[$field] === (string) $value;
        });
        $matches = array();
        foreach ($items as $item) {
            $hash = isset($item['access_pin_hash']) ? (string) $item['access_pin_hash'] : '';
            if ($hash === '' || !function_exists('password_verify')) {
                continue;
            }
            if (password_verify($pin, $hash)) {
                $matches[] = $item;
            }
        }
        usort($matches, function ($a, $b) {
            $at = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
            $bt = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
            if ($at === $bt) { return strcmp(isset($a['display_name']) ? $a['display_name'] : '', isset($b['display_name']) ? $b['display_name'] : ''); }
            if ($bt === $at) { return 0; }
            return ($bt > $at) ? 1 : -1;
        });
        return $matches;
    }

    protected function publicGetAccess()
    {
        $identifier = trim((string) $this->input('access', $this->input('phone', '')));
        $pin = trim((string) $this->input('pin', ''));
        $errors = array();
        $entries = array();
        if ($this->requestMethod === 'POST') {
            $limit = $this->assertPublicGetRateAllowed($identifier);
            if (!$limit['ok']) {
                $errors['auth'][] = $limit['message'];
                $this->appendSecurityLog('get', 'error', 'Public /get rate limit hit.', array('identifier' => $this->normalizeGetIdentifier($identifier), 'ip' => $this->clientIp(), 'message' => $limit['message']));
            } else {
                if (strpos($identifier, '@') !== false) {
                    if (!$this->isValidCustomerEmail($identifier)) { $errors['access'][] = 'Enter a valid email address.'; }
                } else {
                    if (!$this->isValidCustomerPhone($identifier)) { $errors['access'][] = 'Phone must contain only digits and be between 6 and 20 numbers.'; }
                }
                if (!$this->isValidCustomerPin($pin)) {
                    $errors['pin'][] = 'PIN must be 1 to 6 letters or numbers.';
                }
                if (!$errors) {
                    $matches = $this->findCustomersByAccessAndPin($identifier, $pin);
                    if (empty($matches)) {
                        $this->notePublicGetFailure($identifier);
                        $this->appendSecurityLog('get', 'error', 'Public /get access failed.', array('identifier' => $this->normalizeGetIdentifier($identifier), 'ip' => $this->clientIp()));
                        $errors['auth'][] = 'Phone or PIN is invalid.';
                    } else {
                        $this->clearPublicGetFailure($identifier);
                        $this->appendSecurityLog('get', 'access', 'Public /get access succeeded.', array('identifier' => $this->normalizeGetIdentifier($identifier), 'ip' => $this->clientIp(), 'matches' => count($matches)));
                        foreach ($matches as $customer) {
                            $template = $this->store->find('templates', isset($customer['template_id']) ? $customer['template_id'] : '');
                            $node = $template ? $this->store->find('nodes', isset($template['node_id']) ? $template['node_id'] : '') : null;
                            $configs = $this->buildSubscriptionConfigs($customer, $template, $node);
                            $subscriptionUrl = $this->appLink('/user/' . $customer['subscription_key']);
                            $proxySubscriptionUrl = $this->buildNodeSubscriptionUrl($node, isset($customer['remote_sub_id']) ? $customer['remote_sub_id'] : (isset($customer['subscription_key']) ? $customer['subscription_key'] : ''));
                            $primarySubscriptionUrl = $proxySubscriptionUrl !== '' ? $proxySubscriptionUrl : $subscriptionUrl;
                            $fallbackSubscriptionUrl = $proxySubscriptionUrl !== '' ? $subscriptionUrl : '';
                            $entries[] = array(
                                'customer' => $customer,
                                'template' => $template,
                                'node' => $node,
                                'configs' => $configs,
                                'subscription_url' => $subscriptionUrl,
                                'proxy_subscription_url' => $proxySubscriptionUrl,
                                'primary_subscription_url' => $primarySubscriptionUrl,
                                'fallback_subscription_url' => $fallbackSubscriptionUrl,
                            );
                        }
                    }
                }
            }
        }
        $this->renderPublic('public_get.php', array(
            'title' => 'Get Configs',
            'csrf_token' => $this->csrfToken(),
            'access' => $identifier,
            'entries' => $entries,
            'errors' => $errors,
        ));
    }

    public function qrImageUrl($value)
    {
        $value = trim((string) $value);
        if ($value === '') { return ''; }
        $hash = sha1($value);
        $dir = $this->storage . '/cache/qrcodes';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $dataFile = $dir . '/' . $hash . '.txt';
        if (!is_file($dataFile) || (string) @file_get_contents($dataFile) !== $value) {
            @file_put_contents($dataFile, $value, LOCK_EX);
        }
        return $this->url('/__qr/' . $hash);
    }

    protected function serveLocalQr($token = '')
    {
        $token = trim((string) $token);
        $data = '';
        $dir = $this->storage . '/cache/qrcodes';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        if ($token !== '' && preg_match('/^[a-f0-9]{40}$/', $token)) {
            $dataFile = $dir . '/' . $token . '.txt';
            if (is_file($dataFile)) {
                $data = (string) @file_get_contents($dataFile);
            }
        }
        if ($data === '') {
            $data = trim((string) $this->input('d', ''));
            $token = $data !== '' ? sha1($data) : '';
        }
        if ($data === '' || strlen($data) > 4096) {
            return $this->serveQrFallbackSvg('QR unavailable');
        }
        $cacheFile = $dir . '/' . $token . '.svg';
        if (is_file($cacheFile) && filesize($cacheFile) > 80) {
            return $this->sendQrSvg((string) @file_get_contents($cacheFile));
        }
        $svg = $this->generateLocalQrSvg($data);
        if ($svg === '') {
            return $this->serveQrFallbackSvg('Copy the config');
        }
        @file_put_contents($cacheFile, $svg, LOCK_EX);
        return $this->sendQrSvg($svg);
    }

    protected function sendQrSvg($svg)
    {
        if (headers_sent()) { return $svg; }
        header('Content-Type: image/svg+xml; charset=UTF-8');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        echo $svg;
        return null;
    }

    protected function serveQrFallbackSvg($label)
    {
        $label = trim((string) $label);
        if ($label === '') { $label = 'QR unavailable'; }
        $safe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $svg = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="180" height="180" viewBox="0 0 180 180">'
            . '<rect width="180" height="180" fill="#ffffff"/>'
            . '<rect x="1" y="1" width="178" height="178" rx="12" fill="#ffffff" stroke="#111827" stroke-width="2"/>'
            . '<text x="90" y="88" text-anchor="middle" font-family="Arial, sans-serif" font-size="13" fill="#111827">' . $safe . '</text>'
            . '<text x="90" y="108" text-anchor="middle" font-family="Arial, sans-serif" font-size="10" fill="#6b7280">Use copy button below</text>'
            . '</svg>';
        return $this->sendQrSvg($svg);
    }

    protected function generateLocalQrSvg($data)
    {
        $data = (string) $data;
        if ($data === '' || strlen($data) > 2950) { return ''; }
        $lib = PANEL_ROOT . '/app/lib/PurePhpQr.php';
        if (!is_file($lib)) { return ''; }
        require_once $lib;
        if (!class_exists('PurePhpQr', false)) { return ''; }
        try {
            $svg = PurePhpQr::svg($data, array('scale' => 6, 'border' => 2, 'ecc' => 0));
            if (is_string($svg) && stripos($svg, '<svg') !== false) {
                return $svg;
            }
        } catch (Exception $e) {
            $this->log('qr.log', '[' . date('c') . '] php-qr failed: ' . trim((string) $e->getMessage()));
        }
        return '';
    }

    protected function runQrGeneratorProcess($binary, $script, $data)
    {
        if (!function_exists('proc_open')) { return ''; }
        $cmd = escapeshellcmd((string) $binary) . ' ' . escapeshellarg((string) $script);
        $spec = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $proc = @proc_open($cmd, $spec, $pipes, PANEL_ROOT, array('PANEL_ROOT' => PANEL_ROOT));
        if (!is_resource($proc)) { return ''; }
        @fwrite($pipes[0], $data);
        @fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        @fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        @fclose($pipes[2]);
        $status = @proc_close($proc);
        if ((int) $status !== 0) {
            $this->log('qr.log', '[' . date('c') . '] qr-generator ' . $binary . ' failed: ' . trim((string) $stderr));
            return '';
        }
        $svg = trim((string) $stdout);
        if ($svg === '' || stripos($svg, '<svg') === false) {
            return '';
        }
        return $svg;
    }

    protected function publicSubscription($subKey)
    {
        $subscriptionLimit = $this->assertSubscriptionRateAllowed($subKey);
        if (!$subscriptionLimit['ok']) { $this->abort(429, $subscriptionLimit['message']); }
        $customer = $this->store->findBy('customers', 'subscription_key', $subKey);
        if (!$customer) { $this->abort(404, 'Subscription not found.'); }
        $template = $this->store->find('templates', $customer['template_id']);
        $node = $template ? $this->store->find('nodes', $template['node_id']) : null;
        $configs = $this->buildSubscriptionConfigs($customer, $template, $node);
        $proxySubscriptionUrl = $this->buildNodeSubscriptionUrl($node, isset($customer['remote_sub_id']) ? $customer['remote_sub_id'] : (isset($customer['subscription_key']) ? $customer['subscription_key'] : ''));
        $this->renderPublic('public_subscription.php', array('title' => 'Subscription', 'customer' => $customer, 'template' => $template, 'node' => $node, 'configs' => $configs, 'proxy_subscription_url' => $proxySubscriptionUrl));
    }

    protected function publicSubscriptionExport($subKey)
    {
        $subscriptionLimit = $this->assertSubscriptionRateAllowed($subKey . ':export');
        if (!$subscriptionLimit['ok']) { $this->abort(429, $subscriptionLimit['message']); }
        $customer = $this->store->findBy('customers', 'subscription_key', $subKey);
        if (!$customer) { $this->abort(404, 'Subscription not found.'); }
        $template = $this->store->find('templates', $customer['template_id']);
        $node = $template ? $this->store->find('nodes', $template['node_id']) : null;
        $configs = $this->buildSubscriptionConfigs($customer, $template, $node);
        $this->sendCommonHeaders('text/plain; charset=utf-8');
        echo implode("\n", $configs);
        exit;
    }

    protected function ticketsPage($scope)
    {
        $tickets = $this->store->all('tickets');
        if ($scope === 'reseller') {
            $reseller = $this->currentReseller();
            $tickets = array_values(array_filter($tickets, function ($item) use ($reseller) { return isset($item['creator_role'], $item['creator_id']) && $item['creator_role'] === 'reseller' && $item['creator_id'] === $reseller['id']; }));
        }
        usort($tickets, array($this, 'sortNewest'));
        $this->renderPanel('tickets_index.php', array('title' => 'Tickets', 'scope' => $scope, 'tickets' => $tickets, 'reseller_map' => $this->resellerMap()));
    }

    protected function ticketForm($scope)
    {
        $this->renderPanel('ticket_form.php', array('title' => 'New ticket', 'scope' => $scope, 'errors' => array(), 'record' => array('subject' => '', 'priority' => 'normal', 'body' => '')));
    }

    protected function saveTicket($scope)
    {
        $subject = trim((string) $this->input('subject', ''));
        $priority = trim((string) $this->input('priority', 'normal'));
        $body = trim((string) $this->input('body', ''));
        $errors = array();
        if (strlen($subject) < 3) { $errors['subject'][] = 'Subject must be at least 3 characters.'; }
        if (strlen($body) < 3) { $errors['body'][] = 'Message must be at least 3 characters.'; }
        if (!in_array($priority, array('low', 'normal', 'high'), true)) { $priority = 'normal'; }
        if ($errors) { return $this->renderPanel('ticket_form.php', array('title' => 'New ticket', 'scope' => $scope, 'errors' => $errors, 'record' => array('subject' => $subject, 'priority' => $priority, 'body' => $body))); }
        $creatorId = $scope === 'admin' ? $this->authUser()['id'] : $this->currentReseller()['id'];
        $ticket = $this->store->insert('tickets', array('ticket_no' => 'TCK-' . gmdate('Ymd') . '-' . strtoupper(panel_random_hex(5)), 'creator_role' => $scope, 'creator_id' => $creatorId, 'subject' => $subject, 'priority' => $priority, 'status' => 'open', 'last_reply_at' => panel_now()), 'tkt');
        $this->store->insert('ticket_messages', array('ticket_id' => $ticket['id'], 'sender_role' => $scope, 'sender_id' => $creatorId, 'body' => $body, 'seen' => 0), 'msg');
        $this->flash('success', 'Ticket created.');
        $this->redirect('/' . $scope . '/tickets/' . $ticket['id']);
    }

    protected function ticketView($scope, $id)
    {
        $ticket = $this->loadTicketForScope($id, $scope);
        $messages = $this->store->filterBy('ticket_messages', function ($item) use ($id) { return isset($item['ticket_id']) && $item['ticket_id'] === $id; });
        usort($messages, array($this, 'sortOldest'));
        $this->renderPanel('ticket_show.php', array('title' => 'Ticket', 'scope' => $scope, 'ticket' => $ticket, 'messages' => $messages, 'reseller_map' => $this->resellerMap()));
    }

    protected function replyTicket($scope, $id)
    {
        $ticket = $this->loadTicketForScope($id, $scope);
        $body = trim((string) $this->input('body', ''));
        if (strlen($body) < 2) { $this->flash('error', 'Reply is too short.'); $this->redirect('/' . $scope . '/tickets/' . $id); }
        $senderId = $scope === 'admin' ? $this->authUser()['id'] : $this->currentReseller()['id'];
        $this->store->insert('ticket_messages', array('ticket_id' => $id, 'sender_role' => $scope, 'sender_id' => $senderId, 'body' => $body, 'seen' => 0), 'msg');
        $nextStatus = $scope === 'admin' ? 'waiting-reseller' : 'waiting-admin';
        $this->store->update('tickets', $id, array('status' => $nextStatus, 'last_reply_at' => panel_now()));
        $this->flash('success', 'Reply posted.');
        $this->redirect('/' . $scope . '/tickets/' . $id);
    }

    protected function ticketStatus($scope, $id)
    {
        $ticket = $this->loadTicketForScope($id, $scope);
        $status = trim((string) $this->input('status', 'open'));
        if (!in_array($status, array('open', 'waiting-admin', 'waiting-reseller', 'closed'), true)) { $status = 'open'; }
        $this->store->update('tickets', $id, array('status' => $status, 'last_reply_at' => panel_now()));
        $this->flash('success', 'Ticket status updated.');
        $this->redirect('/' . $scope . '/tickets/' . $id);
    }


    protected function deleteTicket($scope, $id)
    {
        $ticket = $this->loadTicketForScope($id, $scope);
        if ($scope !== 'admin') {
            $this->abort(403, 'Forbidden');
        }
        $messages = $this->store->filterBy('ticket_messages', function ($item) use ($id) { return isset($item['ticket_id']) && $item['ticket_id'] === $id; });
        foreach ($messages as $message) {
            if (!empty($message['id'])) { $this->store->delete('ticket_messages', $message['id']); }
        }
        $this->store->delete('tickets', $ticket['id']);
        $this->flash('success', 'Ticket deleted.');
        $this->redirect('/admin/tickets');
    }

    protected function loadTicketForScope($id, $scope)
    {
        $ticket = $this->store->find('tickets', $id);
        if (!$ticket) { $this->flash('error', 'Ticket not found.'); $this->redirect('/' . $scope . '/tickets'); }
        if ($scope === 'reseller') {
            $reseller = $this->currentReseller();
            if ($ticket['creator_role'] !== 'reseller' || $ticket['creator_id'] !== $reseller['id']) { $this->abort(403, 'Forbidden'); }
        }
        return $ticket;
    }

    protected function currentReseller()
    {
        $auth = $this->authUser();
        $reseller = $this->store->find('resellers', $auth['id']);
        if (!$reseller) { $this->logout(); }
        if (empty($reseller['api_key'])) { $reseller['api_key'] = $this->resellerApiKey($reseller); }
        return $reseller;
    }

    protected function loadCustomerForScope($id, $resellerScoped)
    {
        $customer = $this->store->find('customers', $id);
        if (!$customer) { $this->flash('error', 'Customer not found.'); $this->redirect($resellerScoped ? '/reseller/customers' : '/admin/customers'); }
        if ($resellerScoped) {
            $reseller = $this->currentReseller();
            if ($customer['reseller_id'] !== $reseller['id']) { $this->abort(403, 'Forbidden'); }
        }
        return $customer;
    }

    protected function resellerTemplates($reseller)
    {
        $templates = array();
        $allowed = isset($reseller['allowed_template_ids']) ? (array) $reseller['allowed_template_ids'] : array();
        foreach ($allowed as $id) {
            $tpl = $this->store->find('templates', $id);
            if ($tpl && isset($tpl['status']) && $tpl['status'] === 'active') { $templates[] = $tpl; }
        }
        usort($templates, array($this, 'sortTemplate'));
        return $templates;
    }

    protected function resellerCanUseTemplate($reseller, $templateId)
    {
        $allowed = isset($reseller['allowed_template_ids']) ? (array) $reseller['allowed_template_ids'] : array();
        return in_array($templateId, $allowed, true);
    }

    protected function nodeMap() { $map = array(); foreach ($this->store->all('nodes') as $n) { $map[$n['id']] = $n; } return $map; }
    protected function templateMap() { $map = array(); foreach ($this->store->all('templates') as $n) { $map[$n['id']] = $n; } return $map; }
    protected function resellerMap() { $map = array(); foreach ($this->store->all('resellers') as $n) { $map[$n['id']] = $n; } return $map; }

    protected function saveCustomerLink($customer, $template, $node, $remoteClientId, $remoteEmail, $remoteSubId)
    {
        $link = $this->findCustomerLink($customer['id']);
        $payload = array('customer_id' => $customer['id'], 'template_id' => $template['id'], 'node_id' => $node ? $node['id'] : '', 'inbound_id' => $template['inbound_id'], 'remote_client_id' => $remoteClientId, 'remote_email' => $remoteEmail, 'remote_sub_id' => $remoteSubId, 'protocol' => isset($template['protocol']) ? $template['protocol'] : 'vless');
        if ($link) { $this->store->update('customer_links', $link['id'], $payload); }
        else { $this->store->insert('customer_links', $payload, 'lnk'); }
    }

    protected function findCustomerLink($customerId)
    {
        $links = $this->store->filterBy('customer_links', function ($item) use ($customerId) { return isset($item['customer_id']) && $item['customer_id'] === $customerId; });
        return $links ? $links[0] : null;
    }

    protected function buildSubscriptionConfigs($customer, $template, $node)
    {
        if (!$template || !$node) {
            return array();
        }

        $protocol = strtolower(isset($template['protocol']) ? $template['protocol'] : 'vless');
        $stream = panel_parse_multi_json(isset($template['stream_settings_json']) ? $template['stream_settings_json'] : '');
        $settings = panel_parse_multi_json(isset($template['settings_json']) ? $template['settings_json'] : '');
        $port = isset($template['port']) && $template['port'] !== '' ? (int) $template['port'] : panel_guess_port(panel_array_get($stream, 'security', ''), 443);
        $address = $this->subscriptionSourceAddress($template, $node, $stream);
        $params = $this->buildSubscriptionParams($customer, $template, $stream, $settings, $address);
        $links = array();

        $externalLinks = array();
        $externalProxies = panel_array_get($stream, 'externalProxy', array());
        if (is_array($externalProxies)) {
            foreach ($externalProxies as $externalProxy) {
                if (!is_array($externalProxy)) {
                    continue;
                }
                $dest = trim((string) panel_array_get($externalProxy, 'dest', ''));
                $proxyPort = (int) panel_array_get($externalProxy, 'port', 0);
                if ($dest === '' || $proxyPort <= 0) {
                    continue;
                }

                $proxyParams = $params;
                $newSecurity = strtolower((string) panel_array_get($externalProxy, 'forceTls', 'same'));
                if ($newSecurity !== '' && $newSecurity !== 'same') {
                    $proxyParams['security'] = $newSecurity;
                }
                if (isset($proxyParams['security']) && $proxyParams['security'] !== 'tls' && $proxyParams['security'] !== 'reality') {
                    unset($proxyParams['alpn'], $proxyParams['sni'], $proxyParams['fp'], $proxyParams['pbk'], $proxyParams['sid'], $proxyParams['spx'], $proxyParams['pqv']);
                }

                $proxyLink = $this->buildSubscriptionEndpointLink(
                    $protocol,
                    $customer,
                    $template,
                    $settings,
                    $dest,
                    $proxyPort,
                    $proxyParams,
                    trim((string) panel_array_get($externalProxy, 'remark', ''))
                );
                if ($proxyLink !== '') {
                    $externalLinks[] = $proxyLink;
                }
            }
        }

        $externalLinks = array_values(array_unique(array_filter($externalLinks)));
        if (!empty($externalLinks)) {
            return $externalLinks;
        }

        $baseLink = $this->buildSubscriptionEndpointLink($protocol, $customer, $template, $settings, $address, $port, $params, '');
        if ($baseLink !== '') {
            $links[] = $baseLink;
        }

        $links = array_values(array_unique(array_filter($links)));
        return $links;
    }

    protected function subscriptionSourceAddress($template, $node, $stream)
    {
        $listen = isset($template['listen']) ? trim((string) $template['listen']) : '';
        if ($listen !== '' && !in_array($listen, array('0.0.0.0', '::', '::0'), true)) {
            return $listen;
        }
        return $this->nodeHostForExport($node, $stream);
    }

    protected function buildSubscriptionParams($customer, $template, $stream, $settings, $address)
    {
        $protocol = strtolower(isset($template['protocol']) ? $template['protocol'] : 'vless');
        $network = strtolower((string) panel_array_get($stream, 'network', isset($template['network']) ? $template['network'] : 'tcp'));
        $security = strtolower((string) panel_array_get($stream, 'security', isset($template['security']) ? $template['security'] : 'none'));
        $params = array();

        if ($protocol === 'vmess') {
            $params['network'] = $network;
        } else {
            $params['type'] = $network;
        }

        if ($protocol === 'vless') {
            $params['encryption'] = isset($settings['decryption']) && $settings['decryption'] !== '' ? (string) $settings['decryption'] : 'none';
        }

        switch ($network) {
            case 'tcp':
                $tcpSettings = panel_array_get($stream, 'tcpSettings', array());
                $headerType = (string) panel_array_get($tcpSettings, 'header.type', '');
                if ($headerType !== '' && $headerType !== 'none') {
                    if ($protocol === 'vmess') {
                        $params['vmess_type'] = $headerType;
                    } else {
                        $params['headerType'] = $headerType;
                    }
                    if ($headerType === 'http') {
                        $requestPath = panel_array_get($tcpSettings, 'header.request.path.0', '');
                        $requestHeaders = panel_array_get($tcpSettings, 'header.request.headers', array());
                        if ($requestPath !== '') {
                            $params['path'] = $requestPath;
                        }
                        $requestHost = '';
                        if (is_array($requestHeaders) && isset($requestHeaders['Host'])) {
                            $hostValue = $requestHeaders['Host'];
                            if (is_array($hostValue)) {
                                $requestHost = trim((string) reset($hostValue));
                            } else {
                                $requestHost = trim((string) $hostValue);
                            }
                        }
                        if ($requestHost !== '') {
                            $params['host'] = $requestHost;
                        }
                    }
                }
                if ($protocol === 'vless') {
                    $flow = (string) panel_array_get($settings, 'clients.0.flow', '');
                    if ($flow !== '') {
                        $params['flow'] = $flow;
                    }
                }
                break;

            case 'kcp':
                $kcpSettings = panel_array_get($stream, 'kcpSettings', array());
                $params['headerType'] = (string) panel_array_get($kcpSettings, 'header.type', '');
                $params['seed'] = (string) panel_array_get($kcpSettings, 'seed', '');
                break;

            case 'ws':
                $wsSettings = panel_array_get($stream, 'wsSettings', array());
                $params['path'] = (string) panel_array_get($wsSettings, 'path', '/');
                $host = (string) panel_array_get($wsSettings, 'host', '');
                if ($host === '') {
                    $host = (string) panel_array_get($wsSettings, 'headers.Host', '');
                }
                if ($host !== '') {
                    $params['host'] = $host;
                }
                break;

            case 'grpc':
                $grpcSettings = panel_array_get($stream, 'grpcSettings', array());
                $params['serviceName'] = (string) panel_array_get($grpcSettings, 'serviceName', '');
                $authority = (string) panel_array_get($grpcSettings, 'authority', '');
                if ($authority !== '') {
                    $params['authority'] = $authority;
                }
                if (panel_parse_bool(panel_array_get($grpcSettings, 'multiMode', false), false)) {
                    $params['mode'] = 'multi';
                } elseif ($protocol === 'vless' || $protocol === 'trojan') {
                    $params['mode'] = 'gun';
                }
                break;

            case 'httpupgrade':
                $httpupgrade = panel_array_get($stream, 'httpupgradeSettings', array());
                $params['path'] = (string) panel_array_get($httpupgrade, 'path', '/');
                $host = (string) panel_array_get($httpupgrade, 'host', '');
                if ($host === '') {
                    $host = (string) panel_array_get($httpupgrade, 'headers.Host', '');
                }
                if ($host !== '') {
                    $params['host'] = $host;
                }
                break;

            case 'xhttp':
                $xhttp = panel_array_get($stream, 'xhttpSettings', array());
                $params['path'] = (string) panel_array_get($xhttp, 'path', '/');
                $host = (string) panel_array_get($xhttp, 'host', '');
                if ($host === '') {
                    $host = (string) panel_array_get($xhttp, 'headers.Host', '');
                }
                if ($host !== '') {
                    $params['host'] = $host;
                }
                $mode = (string) panel_array_get($xhttp, 'mode', '');
                if ($mode !== '') {
                    $params['mode'] = $mode;
                }
                break;
        }

        $params['security'] = ($security === 'tls' || $security === 'reality') ? $security : 'none';

        if ($security === 'tls') {
            $tlsSettings = panel_array_get($stream, 'tlsSettings', array());
            $alpn = panel_array_get($tlsSettings, 'alpn', array());
            if (is_array($alpn) && !empty($alpn)) {
                $params['alpn'] = implode(',', $alpn);
            }
            $sni = (string) panel_array_get($tlsSettings, 'serverName', '');
            if ($sni !== '') {
                $params['sni'] = $sni;
            }
            $fp = (string) panel_array_get($tlsSettings, 'settings.fingerprint', '');
            if ($fp !== '') {
                $params['fp'] = $fp;
            }
        } elseif ($security === 'reality') {
            $realitySettings = panel_array_get($stream, 'realitySettings', array());
            $serverNames = panel_array_get($realitySettings, 'serverNames', array());
            if (is_array($serverNames) && !empty($serverNames)) {
                $params['sni'] = (string) reset($serverNames);
            }
            $pbk = (string) panel_array_get($realitySettings, 'settings.publicKey', '');
            if ($pbk === '') {
                $pbk = (string) panel_array_get($realitySettings, 'publicKey', '');
            }
            if ($pbk !== '') {
                $params['pbk'] = $pbk;
            }
            $shortIds = panel_array_get($realitySettings, 'shortIds', array());
            if (is_array($shortIds) && !empty($shortIds)) {
                $params['sid'] = (string) reset($shortIds);
            }
            $fp = (string) panel_array_get($realitySettings, 'settings.fingerprint', '');
            if ($fp === '') {
                $fp = (string) panel_array_get($realitySettings, 'fingerprint', '');
            }
            if ($fp !== '') {
                $params['fp'] = $fp;
            }
            $pqv = (string) panel_array_get($realitySettings, 'settings.mldsa65Verify', '');
            if ($pqv === '') {
                $pqv = (string) panel_array_get($realitySettings, 'mldsa65Verify', '');
            }
            if ($pqv !== '') {
                $params['pqv'] = $pqv;
            }
            $spiderX = (string) panel_array_get($realitySettings, 'settings.spiderX', '');
            if ($spiderX === '') {
                $spiderX = (string) panel_array_get($realitySettings, 'spiderX', '');
            }
            if ($spiderX !== '') {
                $params['spx'] = $spiderX;
            }
        }

        if (($protocol === 'vmess' || $protocol === 'trojan' || $protocol === 'shadowsocks') && isset($params['host']) && $params['host'] === '') {
            unset($params['host']);
        }

        if ((!isset($params['sni']) || $params['sni'] === '') && ($security === 'tls' || $security === 'reality')) {
            $params['sni'] = $address;
        }

        return $params;
    }

    protected function buildSubscriptionEndpointLink($protocol, $customer, $template, $settings, $address, $port, $params, $remarkSuffix)
    {
        $protocol = strtolower((string) $protocol);
        $remark = $this->buildSubscriptionRemark($customer, $remarkSuffix);

        if ($protocol === 'vmess') {
            $network = isset($params['network']) ? $params['network'] : 'tcp';
            $vmessType = isset($params['vmess_type']) && $params['vmess_type'] !== '' ? $params['vmess_type'] : 'none';
            $vmess = array(
                'v' => '2',
                'ps' => $remark,
                'add' => $address,
                'port' => (string) $port,
                'id' => isset($customer['uuid']) ? $customer['uuid'] : '',
                'aid' => '0',
                'net' => $network,
                'type' => $vmessType,
                'host' => (string) panel_array_get($params, 'host', $address),
                'path' => '',
                'tls' => isset($params['security']) && $params['security'] !== 'none' ? $params['security'] : '',
                'sni' => (string) panel_array_get($params, 'sni', ''),
            );
            if ($network === 'grpc') {
                $vmess['path'] = (string) panel_array_get($params, 'serviceName', '');
            } elseif (isset($params['path'])) {
                $vmess['path'] = (string) $params['path'];
            }
            if (isset($params['alpn'])) {
                $vmess['alpn'] = (string) $params['alpn'];
            }
            if (isset($params['fp'])) {
                $vmess['fp'] = (string) $params['fp'];
            }
            return 'vmess://' . base64_encode(json_encode($vmess, JSON_UNESCAPED_SLASHES));
        }

        if ($protocol === 'vless') {
            return 'vless://' . $customer['uuid'] . '@' . $address . ':' . (int) $port . '?' . panel_qs($params) . '#' . rawurlencode($remark);
        }

        if ($protocol === 'trojan') {
            return 'trojan://' . $customer['uuid'] . '@' . $address . ':' . (int) $port . '?' . panel_qs($params) . '#' . rawurlencode($remark);
        }

        if ($protocol === 'shadowsocks') {
            $method = (string) panel_array_get($settings, 'method', panel_array_get($settings, 'clients.0.method', 'aes-256-gcm'));
            $inboundPassword = (string) panel_array_get($settings, 'password', '');
            $encPart = $method . ':' . $customer['uuid'];
            if ($method !== '' && $method[0] === '2' && $inboundPassword !== '') {
                $encPart = $method . ':' . $inboundPassword . ':' . $customer['uuid'];
            }
            $secret = base64_encode($encPart);
            return 'ss://' . $secret . '@' . $address . ':' . (int) $port . '?' . panel_qs($params) . '#' . rawurlencode($remark);
        }

        return '';
    }

    protected function buildSubscriptionRemark($customer, $remarkSuffix)
    {
        $base = isset($customer['system_name']) ? (string) $customer['system_name'] : (isset($customer['display_name']) ? (string) $customer['display_name'] : 'subscription');
        $remarkSuffix = trim((string) $remarkSuffix);
        if ($remarkSuffix !== '') {
            return $base . '-' . $remarkSuffix;
        }
        return $base;
    }

    protected function buildNodeSubscriptionUrl($node, $remoteSubId)
    {
        if (!$node || !is_array($node)) {
            return '';
        }
        $base = trim((string) (isset($node['subscription_base']) ? $node['subscription_base'] : ''));
        $remoteSubId = trim((string) $remoteSubId);
        if ($base === '' || $remoteSubId === '') {
            return '';
        }
        return rtrim($base, '/') . '/' . rawurlencode($remoteSubId);
    }

    protected function buildXuiClientSettings($customer, $template, $expireAt)
    {
        $protocol = strtolower(isset($template['protocol']) ? $template['protocol'] : 'vless');
        $settings = array(
            'email' => isset($customer['remote_email']) ? $customer['remote_email'] : $customer['system_name'],
            'enable' => isset($customer['status']) ? $customer['status'] === 'active' : true,
            'limitIp' => isset($customer['ip_limit']) ? (int) $customer['ip_limit'] : 0,
            'expiryTime' => $this->resolveXuiExpiryMillis($customer, $expireAt),
            'totalGB' => isset($customer['traffic_bytes_total']) ? (float) $customer['traffic_bytes_total'] : panel_to_bytes_from_gb(isset($customer['traffic_gb']) ? $customer['traffic_gb'] : 0),
            'subId' => isset($customer['remote_sub_id']) ? $customer['remote_sub_id'] : (isset($customer['subscription_key']) ? $customer['subscription_key'] : ''),
            'tgId' => '',
            'reset' => 0,
        );

        if ($protocol === 'trojan') {
            $settings['password'] = $customer['uuid'];
            return $settings;
        }
        if ($protocol === 'shadowsocks') {
            $templateSettings = panel_parse_multi_json(isset($template['settings_json']) ? $template['settings_json'] : '');
            $settings['method'] = (string) panel_array_get($templateSettings, 'method', panel_array_get($templateSettings, 'clients.0.method', 'aes-256-gcm'));
            $settings['password'] = $customer['uuid'];
            return $settings;
        }
        $settings['id'] = $customer['uuid'];
        if ($protocol === 'vless') {
            $templateSettings = panel_parse_multi_json(isset($template['settings_json']) ? $template['settings_json'] : '');
            $flow = (string) panel_array_get($templateSettings, 'clients.0.flow', '');
            if ($flow !== '') { $settings['flow'] = $flow; }
        }
        return $settings;
    }

    protected function generateClientCredential($protocol)
    {
        $protocol = strtolower((string) $protocol);
        if ($protocol === 'shadowsocks') {
            return panel_random_hex(16);
        }
        return $this->makeUuid();
    }

    protected function remoteClientIdentifier($protocol, $credential, $email)
    {
        $protocol = strtolower((string) $protocol);
        if ($protocol === 'trojan') {
            return (string) $credential;
        }
        if ($protocol === 'shadowsocks') {
            return (string) $email;
        }
        return (string) $credential;
    }

    protected function nodeHostForExport($node, $stream)
    {
        $host = parse_url(isset($node['base_url']) ? $node['base_url'] : '', PHP_URL_HOST);
        if (!$host) { $host = 'example.com'; }
        $realityServer = (string) panel_array_get($stream, 'realitySettings.dest', '');
        if ($realityServer !== '' && strpos($realityServer, ':') !== false) {
            $parts = explode(':', $realityServer, 2);
            if ($parts[0] !== '') { $host = $parts[0]; }
        }
        return $host;
    }

    public function customerExpirationMode($customer)
    {
        $mode = isset($customer['duration_mode']) ? strtolower(trim((string) $customer['duration_mode'])) : '';
        if ($mode !== 'first_use') {
            $mode = 'fixed';
        }
        return $mode;
    }

    public function customerExpirationLabel($customer)
    {
        $mode = $this->customerExpirationMode($customer);
        $days = isset($customer['duration_days']) ? (int) $customer['duration_days'] : 0;
        if ($mode === 'first_use') {
            if ($days > 0) {
                return 'First use + ' . $days . ' day(s)';
            }
            return 'Unlimited';
        }
        if (!empty($customer['expires_at'])) {
            return (string) $customer['expires_at'];
        }
        if ($days > 0) {
            return $days . ' day(s) from now';
        }
        return 'Unlimited';
    }


    public function customerRuntimeState($customer)
    {
        $status = isset($customer['status']) ? strtolower(trim((string) $customer['status'])) : 'active';
        if ($status !== 'active') {
            return 'disabled';
        }
        if ($this->customerIsExpired($customer)) {
            return 'ended';
        }
        $left = isset($customer['traffic_bytes_left']) ? (float) $customer['traffic_bytes_left'] : null;
        $total = isset($customer['traffic_bytes_total']) ? (float) $customer['traffic_bytes_total'] : null;
        if ($left !== null && $total !== null && $total > 0 && $left <= 0) {
            return 'depleted';
        }
        return 'active';
    }

    protected function customerIsExpired($customer)
    {
        $expiresAt = isset($customer['expires_at']) ? trim((string) $customer['expires_at']) : '';
        if ($expiresAt === '') {
            return false;
        }
        $ts = strtotime($expiresAt);
        if ($ts === false) {
            return false;
        }
        return $ts <= time();
    }

    public function customerRuntimeStatusLabel($customer)
    {
        $state = $this->customerRuntimeState($customer);
        if ($state === 'depleted') {
            return 'Depleted';
        }
        if ($state === 'ended') {
            return 'Ended';
        }
        if ($state === 'disabled') {
            return 'Disabled';
        }
        return 'Active';
    }

    public function customerRuntimeStatusBadgeClass($customer)
    {
        $state = $this->customerRuntimeState($customer);
        if ($state === 'active') {
            return 'good';
        }
        if ($state === 'disabled') {
            return 'muted';
        }
        return 'bad';
    }

    protected function customerMatchesBucket($customer, $bucket)
    {
        $state = $this->customerRuntimeState($customer);
        if ($bucket === 'live') {
            return $state === 'active';
        }
        if ($bucket === 'ended') {
            return in_array($state, array('depleted', 'ended'), true);
        }
        return true;
    }

    protected function customerStateCounts($customers)
    {
        $counts = array('all' => 0, 'live' => 0, 'ended' => 0);
        foreach ((array) $customers as $customer) {
            $counts['all']++;
            if ($this->customerMatchesBucket($customer, 'live')) {
                $counts['live']++;
            }
            if ($this->customerMatchesBucket($customer, 'ended')) {
                $counts['ended']++;
            }
        }
        return $counts;
    }

    protected function resolveXuiExpiryMillis($customer, $expireAt)
    {
        $mode = $this->customerExpirationMode($customer);
        $days = isset($customer['duration_days']) ? (int) $customer['duration_days'] : 0;
        if ($mode === 'first_use') {
            if ($days > 0) {
                return -1 * ($days * 86400 * 1000);
            }
            return 0;
        }
        return ((int) $expireAt) > 0 ? (((int) $expireAt) * 1000) : 0;
    }

    public function appLink($path)
    {
        $cfg = $this->runtimeConfig();
        $base = panel_base_url(isset($cfg['app_url']) ? $cfg['app_url'] : '');
        if ($base === '') {
            $base = rtrim(panel_request_origin(), '/') . $this->basePath;
        }
        return panel_url_join($base, $path);
    }

    protected function changeResellerCredit($resellerId, $amountGb, $type, $note)
    {
        $reseller = $this->store->find('resellers', $resellerId);
        if (!$reseller) { return; }
        $new = round((float) $reseller['credit_gb'] + (float) $amountGb, 2);
        if ($new < 0) { $new = 0; }
        $this->store->update('resellers', $resellerId, array('credit_gb' => $new));
        $this->store->insert('credit_ledger', array('reseller_id' => $resellerId, 'amount_gb' => (float) $amountGb, 'type' => $type, 'note' => $note), 'led');
    }

    protected function renderAuth($view, $data)
    {
        $base = array('app' => $this, 'app_name' => $this->appName(), 'flash' => $this->flash(null, null), 'csrf_token' => $this->csrfToken(), 'shield_forms_enabled' => $this->shouldUsePageShieldForms(), 'active_notices' => $this->activeNotices('auth'));
        $data = array_replace($base, $data);
        $html = $this->captureView('layout_auth.php', $view, $data);
        $this->sendCommonHeaders('text/html; charset=utf-8');
        echo $this->maybeShieldHtml($html, isset($data['title']) ? $data['title'] : $this->appName());
        exit;
    }

    protected function renderPanel($view, $data)
    {
        $base = array('app' => $this, 'app_name' => $this->appName(), 'flash' => $this->flash(null, null), 'csrf_token' => $this->csrfToken(), 'auth' => $this->authUser(), 'current_path' => $this->requestPath, 'credit_unit' => $this->config('credit_unit', 'GB'), 'shield_forms_enabled' => $this->shouldUsePageShieldForms(), 'active_notices' => $this->activeNotices($this->authRole() === 'admin' ? 'admin' : 'reseller'));
        $data = array_replace($base, $data);
        $html = $this->captureView('layout_panel.php', $view, $data);
        $this->sendCommonHeaders('text/html; charset=utf-8');
        echo $this->maybeShieldHtml($html, isset($data['title']) ? $data['title'] : $this->appName());
        exit;
    }

    protected function renderPublic($view, $data)
    {
        $base = array('app' => $this, 'app_name' => $this->appName(), 'shield_forms_enabled' => $this->shouldUsePageShieldForms(), 'active_notices' => $this->activeNotices('public'));
        $data = array_replace($base, $data);
        $html = $this->captureView('layout_public.php', $view, $data);
        $this->sendCommonHeaders('text/html; charset=utf-8');
        echo $this->maybeShieldHtml($html, isset($data['title']) ? $data['title'] : $this->appName());
        exit;
    }

    public function json($data, $status)
    {
        if ($this->apiContext) { $this->apiCaptured = array('type' => 'json', 'status' => $status, 'data' => $data); throw new Exception('__API_STOP__'); }
        http_response_code($status);
        $this->sendCommonHeaders('application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }
    public function redirect($url)
    {
        $url = (string) $url;
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = $this->url($url);
        }
        if ($this->apiContext) { $this->apiCaptured = array('type' => 'redirect', 'url' => $url); throw new Exception('__API_STOP__'); }
        $this->sendCommonHeaders('text/html; charset=utf-8');
        header('Location: ' . $url);
        exit;
    }
    protected function abort($status, $message)
    {
        if ($this->apiContext) { $this->apiCaptured = array('type' => 'abort', 'status' => $status, 'message' => $message); throw new Exception('__API_STOP__'); }
        http_response_code($status);
        $this->sendCommonHeaders('text/plain; charset=utf-8');
        echo $message;
        exit;
    }


    protected function captureView($layout, $view, $data)
    {
        extract($data);
        $view_file = $this->viewPath . '/' . $view;
        ob_start();
        include $this->viewPath . '/' . $layout;
        return (string) ob_get_clean();
    }

    protected function sendCommonHeaders($contentType)
    {
        if (headers_sent()) { return; }
        header('Content-Type: ' . $contentType);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; font-src 'self' data:; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    }

    protected function requestIsSecure()
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    }

    protected function validateSameOriginPost()
    {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
        $referer = isset($_SERVER['HTTP_REFERER']) ? trim((string) $_SERVER['HTTP_REFERER']) : '';
        $fetchSite = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? strtolower(trim((string) $_SERVER['HTTP_SEC_FETCH_SITE'])) : '';
        $fetchMode = isset($_SERVER['HTTP_SEC_FETCH_MODE']) ? strtolower(trim((string) $_SERVER['HTTP_SEC_FETCH_MODE'])) : '';
        $fetchDest = isset($_SERVER['HTTP_SEC_FETCH_DEST']) ? strtolower(trim((string) $_SERVER['HTTP_SEC_FETCH_DEST'])) : '';

        if (strtolower($origin) === 'null') { $origin = ''; }
        if (strtolower($referer) === 'null') { $referer = ''; }

        $candidates = array();
        $candidates[] = panel_request_origin();
        $appUrl = (string) $this->config('app_url', '');
        if ($appUrl !== '') {
            $parts = @parse_url($appUrl);
            if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
                $originUrl = $parts['scheme'] . '://' . $parts['host'];
                if (!empty($parts['port'])) {
                    $originUrl .= ':' . $parts['port'];
                }
                $candidates[] = $originUrl;
            }
        }

        $normalize = function ($value) {
            $value = trim((string) $value);
            if ($value === '' || strtolower($value) === 'null') { return ''; }
            $parts = @parse_url($value);
            if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
                return rtrim(strtolower($value), '/');
            }
            $scheme = strtolower((string) $parts['scheme']);
            $host = strtolower((string) $parts['host']);
            $port = isset($parts['port']) ? (int) $parts['port'] : 0;
            $defaultPort = ($scheme === 'https') ? 443 : 80;
            $out = $scheme . '://' . $host;
            if ($port > 0 && $port !== $defaultPort) {
                $out .= ':' . $port;
            }
            return $out;
        };

        $expected = array();
        foreach ($candidates as $candidate) {
            $n = $normalize($candidate);
            if ($n !== '' && !in_array($n, $expected, true)) {
                $expected[] = $n;
            }
        }

        if ($origin !== '') {
            return in_array($normalize($origin), $expected, true);
        }
        if ($referer !== '') {
            $refOrigin = '';
            $parts = @parse_url($referer);
            if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
                $refOrigin = $parts['scheme'] . '://' . $parts['host'];
                if (!empty($parts['port'])) {
                    $refOrigin .= ':' . $parts['port'];
                }
            } else {
                $refOrigin = $referer;
            }
            return in_array($normalize($refOrigin), $expected, true);
        }

        // Some browsers/extensions and the optional page shield can turn navigational form posts
        // into opaque-origin requests, which surface as Origin: null and omit Referer. In that case,
        // fall back to Fetch Metadata and the existing CSRF token check instead of hard failing.
        if ($fetchSite === '' || $fetchSite === 'same-origin' || $fetchSite === 'same-site' || $fetchSite === 'none') {
            if ($fetchMode === '' || $fetchMode === 'navigate') {
                if ($fetchDest === '' || $fetchDest === 'document' || $fetchDest === 'iframe') {
                    return true;
                }
            }
        }

        return false;
    }

    protected function securitySettings()
    {
        $cfg = $this->runtimeConfig();
        return array(
            'app_name' => isset($cfg['app_name']) ? (string) $cfg['app_name'] : $this->config('app_name', 'XUI Reseller Panel'),
            'app_url' => isset($cfg['app_url']) ? (string) $cfg['app_url'] : '',
            'timezone' => isset($cfg['timezone']) ? (string) $cfg['timezone'] : 'UTC',
            'default_duration_days' => isset($cfg['default_duration_days']) ? max(1, (int) $cfg['default_duration_days']) : (int) $this->config('default_duration_days', 30),
            'login_max_attempts' => isset($cfg['login_max_attempts']) ? max(3, (int) $cfg['login_max_attempts']) : 8,
            'login_window_seconds' => isset($cfg['login_window_seconds']) ? max(60, (int) $cfg['login_window_seconds']) : 900,
            'login_lockout_seconds' => isset($cfg['login_lockout_seconds']) ? max(60, (int) $cfg['login_lockout_seconds']) : 900,
            'subscription_max_requests' => isset($cfg['subscription_max_requests']) ? max(10, (int) $cfg['subscription_max_requests']) : 60,
            'subscription_window_seconds' => isset($cfg['subscription_window_seconds']) ? max(10, (int) $cfg['subscription_window_seconds']) : 60,
            'page_shield_mode' => isset($cfg['page_shield_mode']) ? (string) $cfg['page_shield_mode'] : 'off',
            'page_shield_key' => isset($cfg['page_shield_key']) ? (string) $cfg['page_shield_key'] : '',
            'page_shield_forms' => isset($cfg['page_shield_forms']) ? (int) !!$cfg['page_shield_forms'] : 1,
            'js_hardening' => isset($cfg['js_hardening']) ? (int) !!$cfg['js_hardening'] : 1,
            'api_enabled' => isset($cfg['api_enabled']) ? (int) !!$cfg['api_enabled'] : 0,
            'api_encryption' => isset($cfg['api_encryption']) ? (int) !!$cfg['api_encryption'] : 0,
            'telegram_enabled' => isset($cfg['telegram_enabled']) ? (int) !!$cfg['telegram_enabled'] : 0,
            'telegram_bot_token' => isset($cfg['telegram_bot_token']) ? (string) $cfg['telegram_bot_token'] : '',
            'telegram_mode' => isset($cfg['telegram_mode']) ? (string) $cfg['telegram_mode'] : 'webhook',
            'telegram_webhook_secret' => isset($cfg['telegram_webhook_secret']) ? (string) $cfg['telegram_webhook_secret'] : '',
            'telegram_proxy_enabled' => isset($cfg['telegram_proxy_enabled']) ? (int) !!$cfg['telegram_proxy_enabled'] : 0,
            'telegram_proxy_type' => isset($cfg['telegram_proxy_type']) ? (string) $cfg['telegram_proxy_type'] : 'http',
            'telegram_proxy_host' => isset($cfg['telegram_proxy_host']) ? (string) $cfg['telegram_proxy_host'] : '',
            'telegram_proxy_port' => isset($cfg['telegram_proxy_port']) ? (int) $cfg['telegram_proxy_port'] : 0,
            'telegram_proxy_username' => isset($cfg['telegram_proxy_username']) ? (string) $cfg['telegram_proxy_username'] : '',
            'telegram_proxy_password' => isset($cfg['telegram_proxy_password']) ? (string) $cfg['telegram_proxy_password'] : '',
            'telegram_allow_reseller' => isset($cfg['telegram_allow_reseller']) ? (int) !!$cfg['telegram_allow_reseller'] : 1,
            'telegram_allow_client' => isset($cfg['telegram_allow_client']) ? (int) !!$cfg['telegram_allow_client'] : 1,
            'telegram_allow_admin' => isset($cfg['telegram_allow_admin']) ? (int) !!$cfg['telegram_allow_admin'] : 0,
            'telegram_poll_limit' => isset($cfg['telegram_poll_limit']) ? max(1, (int) $cfg['telegram_poll_limit']) : 20,
            'panel_sync_enabled' => isset($cfg['panel_sync_enabled']) ? (int) !!$cfg['panel_sync_enabled'] : 0,
            'panel_sync_mode' => isset($cfg['panel_sync_mode']) ? (string) $cfg['panel_sync_mode'] : 'off',
            'panel_sync_master_url' => isset($cfg['panel_sync_master_url']) ? (string) $cfg['panel_sync_master_url'] : '',
            'panel_sync_shared_secret' => isset($cfg['panel_sync_shared_secret']) ? (string) $cfg['panel_sync_shared_secret'] : '',
            'panel_sync_interval_seconds' => isset($cfg['panel_sync_interval_seconds']) ? max(60, (int) $cfg['panel_sync_interval_seconds']) : 300,
            'panel_sync_prune_missing' => isset($cfg['panel_sync_prune_missing']) ? (int) !!$cfg['panel_sync_prune_missing'] : 0,
            'panel_sync_proxy_enabled' => isset($cfg['panel_sync_proxy_enabled']) ? (int) !!$cfg['panel_sync_proxy_enabled'] : 0,
            'panel_sync_proxy_type' => isset($cfg['panel_sync_proxy_type']) ? (string) $cfg['panel_sync_proxy_type'] : 'http',
            'panel_sync_proxy_host' => isset($cfg['panel_sync_proxy_host']) ? (string) $cfg['panel_sync_proxy_host'] : '',
            'panel_sync_proxy_port' => isset($cfg['panel_sync_proxy_port']) ? (int) $cfg['panel_sync_proxy_port'] : 0,
            'panel_sync_proxy_username' => isset($cfg['panel_sync_proxy_username']) ? (string) $cfg['panel_sync_proxy_username'] : '',
            'panel_sync_proxy_password' => isset($cfg['panel_sync_proxy_password']) ? (string) $cfg['panel_sync_proxy_password'] : '',
            'install_locked' => $this->isInstallLocked() ? 1 : 0,
        );
    }


    protected function panelSyncSettings()
    {
        $cfg = $this->runtimeConfig();
        $sharedSecret = isset($cfg['panel_sync_shared_secret']) ? trim((string) $cfg['panel_sync_shared_secret']) : '';
        if ($sharedSecret === '') {
            $sharedSecret = panel_random_hex(24);
        }
        return array(
            'enabled' => !empty($cfg['panel_sync_enabled']) ? 1 : 0,
            'mode' => isset($cfg['panel_sync_mode']) && in_array($cfg['panel_sync_mode'], array('off', 'master', 'slave'), true) ? (string) $cfg['panel_sync_mode'] : 'off',
            'master_url' => isset($cfg['panel_sync_master_url']) ? rtrim((string) $cfg['panel_sync_master_url'], '/') : '',
            'shared_secret' => $sharedSecret,
            'interval_seconds' => isset($cfg['panel_sync_interval_seconds']) ? max(60, (int) $cfg['panel_sync_interval_seconds']) : 300,
            'prune_missing' => !empty($cfg['panel_sync_prune_missing']) ? 1 : 0,
            'proxy_enabled' => !empty($cfg['panel_sync_proxy_enabled']) ? 1 : 0,
            'proxy_type' => isset($cfg['panel_sync_proxy_type']) ? trim((string) $cfg['panel_sync_proxy_type']) : 'http',
            'proxy_host' => isset($cfg['panel_sync_proxy_host']) ? trim((string) $cfg['panel_sync_proxy_host']) : '',
            'proxy_port' => isset($cfg['panel_sync_proxy_port']) ? (int) $cfg['panel_sync_proxy_port'] : 0,
            'proxy_username' => isset($cfg['panel_sync_proxy_username']) ? (string) $cfg['panel_sync_proxy_username'] : '',
            'proxy_password' => isset($cfg['panel_sync_proxy_password']) ? (string) $cfg['panel_sync_proxy_password'] : '',
        );
    }

    protected function panelSyncState()
    {
        $state = $this->store->readConfig('panel_sync_state');
        return is_array($state) ? $state : array();
    }

    protected function panelSyncStateSummary()
    {
        $state = $this->panelSyncState();
        return array(
            'last_run_at' => isset($state['last_run_at']) ? (string) $state['last_run_at'] : '',
            'last_status' => isset($state['last_status']) ? (string) $state['last_status'] : 'never',
            'last_message' => isset($state['last_message']) ? (string) $state['last_message'] : 'No sync has run yet.',
            'last_counts' => isset($state['last_counts']) && is_array($state['last_counts']) ? $state['last_counts'] : array(),
            'next_due_at' => isset($state['next_due_at']) ? (string) $state['next_due_at'] : '',
        );
    }

    protected function writePanelSyncState($state)
    {
        if (!is_array($state)) { $state = array(); }
        $this->store->writeConfig('panel_sync_state', $state);
    }

    public function panelSyncExportUrl()
    {
        $settings = $this->panelSyncSettings();
        return $this->appLink('/sync/export/' . $settings['shared_secret']);
    }

    public function panelSyncRunUrl()
    {
        $settings = $this->panelSyncSettings();
        return $this->appLink('/sync/run/' . $settings['shared_secret']);
    }

    protected function panelSyncSecretMatches($provided)
    {
        $settings = $this->panelSyncSettings();
        return $provided !== '' && hash_equals((string) $settings['shared_secret'], (string) $provided);
    }

    protected function rateLimitFile($scope, $key)
    {
        return $this->storage . '/cache/rate_limits/' . sha1($scope . '|' . $key) . '.json';
    }

    protected function readRateLimit($scope, $key)
    {
        $file = $this->rateLimitFile($scope, $key);
        if (!is_file($file)) {
            return array('count' => 0, 'first_at' => 0, 'block_until' => 0, 'updated_at' => 0);
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        return is_array($decoded) ? $decoded : array('count' => 0, 'first_at' => 0, 'block_until' => 0, 'updated_at' => 0);
    }

    protected function writeRateLimit($scope, $key, $state)
    {
        $file = $this->rateLimitFile($scope, $key);
        $state['updated_at'] = time();
        @file_put_contents($file . '.tmp', json_encode($state));
        @rename($file . '.tmp', $file);
    }

    protected function clearRateLimit($scope, $key)
    {
        $file = $this->rateLimitFile($scope, $key);
        if (is_file($file)) { @unlink($file); }
    }

    protected function clientIp()
    {
        $candidates = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($candidates as $name) {
            if (empty($_SERVER[$name])) { continue; }
            $value = trim((string) $_SERVER[$name]);
            if ($name === 'HTTP_X_FORWARDED_FOR' && strpos($value, ',') !== false) {
                $parts = explode(',', $value);
                $value = trim($parts[0]);
            }
            if ($value !== '') { return $value; }
        }
        return 'unknown';
    }

    protected function assertRateLimitAllowed($scope, $key, $maxAttempts, $windowSeconds, $blockSeconds, $message)
    {
        $state = $this->readRateLimit($scope, $key);
        $now = time();
        if (isset($state['block_until']) && (int) $state['block_until'] > $now) {
            return array('ok' => false, 'message' => $message . ' Try again in ' . ((int) $state['block_until'] - $now) . ' seconds.');
        }
        if (!isset($state['first_at']) || ((int) $state['first_at']) <= 0 || ($now - (int) $state['first_at']) > $windowSeconds) {
            $state = array('count' => 0, 'first_at' => $now, 'block_until' => 0, 'updated_at' => $now);
            $this->writeRateLimit($scope, $key, $state);
        }
        if (isset($state['count']) && (int) $state['count'] >= $maxAttempts) {
            $state['block_until'] = $now + $blockSeconds;
            $this->writeRateLimit($scope, $key, $state);
            return array('ok' => false, 'message' => $message . ' Try again later.');
        }
        return array('ok' => true, 'message' => 'ok');
    }

    protected function hitRateLimit($scope, $key, $windowSeconds, $blockSeconds)
    {
        $state = $this->readRateLimit($scope, $key);
        $now = time();
        if (!isset($state['first_at']) || ((int) $state['first_at']) <= 0 || ($now - (int) $state['first_at']) > $windowSeconds) {
            $state = array('count' => 0, 'first_at' => $now, 'block_until' => 0, 'updated_at' => $now);
        }
        $state['count'] = isset($state['count']) ? ((int) $state['count'] + 1) : 1;
        if ((int) $state['count'] >= 1 && $blockSeconds > 0 && (int) $state['count'] >= 999999) {
            $state['block_until'] = $now + $blockSeconds;
        }
        $this->writeRateLimit($scope, $key, $state);
    }

    protected function assertLoginRateAllowed($role, $username)
    {
        $s = $this->securitySettings();
        $ip = $this->clientIp();
        $byIp = $this->assertRateLimitAllowed('login_ip', strtolower($ip), $s['login_max_attempts'], $s['login_window_seconds'], $s['login_lockout_seconds'], 'Too many login attempts from this IP.');
        if (!$byIp['ok']) { return $byIp; }
        $identity = strtolower($role . '|' . $username . '|' . $ip);
        return $this->assertRateLimitAllowed('login_identity', $identity, $s['login_max_attempts'], $s['login_window_seconds'], $s['login_lockout_seconds'], 'Too many login attempts for this account.');
    }

    protected function noteLoginFailure($role, $username)
    {
        $s = $this->securitySettings();
        $ip = $this->clientIp();
        $this->hitRateLimit('login_ip', strtolower($ip), $s['login_window_seconds'], $s['login_lockout_seconds']);
        $this->hitRateLimit('login_identity', strtolower($role . '|' . $username . '|' . $ip), $s['login_window_seconds'], $s['login_lockout_seconds']);
    }

    protected function clearLoginFailure($role, $username)
    {
        $ip = $this->clientIp();
        $this->clearRateLimit('login_ip', strtolower($ip));
        $this->clearRateLimit('login_identity', strtolower($role . '|' . $username . '|' . $ip));
    }

    protected function assertSubscriptionRateAllowed($subKey)
    {
        $s = $this->securitySettings();
        $ip = $this->clientIp();
        $global = $this->assertRateLimitAllowed('subscription_ip', strtolower($ip), $s['subscription_max_requests'], $s['subscription_window_seconds'], $s['subscription_window_seconds'], 'Too many subscription requests.');
        if (!$global['ok']) { return $global; }
        return $this->assertRateLimitAllowed('subscription_key', strtolower($ip . '|' . $subKey), $s['subscription_max_requests'], $s['subscription_window_seconds'], $s['subscription_window_seconds'], 'Too many requests for this subscription.');
    }

    protected function shouldUsePageShield()
    {
        if ($this->isAjax()) { return false; }
        $settings = $this->securitySettings();
        if ($settings['page_shield_mode'] === 'always') { return true; }
        if ($settings['page_shield_mode'] === 'http_only' && !$this->requestIsSecure()) { return true; }
        return false;
    }

    protected function shouldUsePageShieldForms()
    {
        $settings = $this->securitySettings();
        return $this->shouldUsePageShield() && !empty($settings['page_shield_forms']);
    }

    protected function maybeDecodeShieldPost()
    {
        if ($this->requestMethod !== 'POST') { return; }
        if (empty($_POST['__shield']) || empty($_POST['__shield_iv']) || empty($_POST['__shield_payload'])) { return; }
        $settings = $this->securitySettings();
        $keyB64 = trim((string) $settings['page_shield_key']);
        if ($keyB64 === '' || !function_exists('openssl_decrypt')) { return; }
        $key = base64_decode($keyB64, true);
        $iv = base64_decode((string) $_POST['__shield_iv'], true);
        $payload = base64_decode((string) $_POST['__shield_payload'], true);
        if ($key === false || $iv === false || $payload === false || strlen($key) < 32 || strlen($iv) !== 16) { return; }
        $plain = openssl_decrypt($payload, 'AES-256-CBC', substr($key, 0, 32), OPENSSL_RAW_DATA, $iv);
        if (!is_string($plain) || $plain === '') { return; }
        $decoded = json_decode($plain, true);
        if (!is_array($decoded) || !isset($decoded['fields']) || !is_array($decoded['fields'])) { return; }
        $fields = $decoded['fields'];
        foreach ($fields as $k => $v) {
            if (is_array($v)) {
                $clean = array();
                foreach ($v as $item) { $clean[] = is_scalar($item) ? (string) $item : ''; }
                $fields[$k] = $clean;
            } else {
                $fields[$k] = is_scalar($v) ? (string) $v : '';
            }
        }
        $_POST = $fields;
        $_REQUEST = array_merge($_GET, $_POST);
        $_SERVER['HTTP_X_PANEL_SHIELD'] = '1';
    }

    protected function ensureClientShieldAsset()
    {
        $settings = $this->securitySettings();
        $key = trim((string) $settings['page_shield_key']);
        if ($key === '') { return false; }
        $path = PANEL_ROOT . '/public/assets/key.js';
        $content = "window.__PANEL_KEY__ = '" . str_replace("'", "\'", $key) . "';
";
        if (!is_file($path) || (string) @file_get_contents($path) !== $content) {
            @file_put_contents($path, $content);
        }
        return true;
    }

    protected function maybeShieldHtml($html, $title)
    {
        if (!$this->shouldUsePageShield()) { return $html; }
        $settings = $this->securitySettings();
        $keyB64 = trim((string) $settings['page_shield_key']);
        if ($keyB64 === '') { return $html; }
        $key = base64_decode($keyB64, true);
        if ($key === false || strlen($key) < 32 || !function_exists('openssl_encrypt')) { return $html; }
        $this->ensureClientShieldAsset();
        $iv = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $cipher = openssl_encrypt($html, 'AES-256-CBC', substr($key, 0, 32), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) { return $html; }
        $payload = base64_encode($cipher);
        $ivB64 = base64_encode($iv);
        $loadingTitle = panel_e($title !== '' ? $title : $this->appName());
        $keySrc = panel_e($this->asset('key.js'));
        $bootstrap = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
<title>{$loadingTitle}</title>
<style>
html,body{margin:0;padding:0;min-height:100%;background:#081125;color:#e8edf7;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.shield-toast{position:fixed;left:16px;right:16px;bottom:16px;max-width:360px;padding:12px 14px;border-radius:14px;background:rgba(7,15,31,.92);border:1px solid rgba(71,120,255,.24);box-shadow:0 16px 40px rgba(0,0,0,.35);backdrop-filter:blur(8px);z-index:99999}
.shield-row{display:flex;align-items:center;gap:10px}
.shield-spinner{width:14px;height:14px;border-radius:50%;border:2px solid rgba(255,255,255,.22);border-top-color:#5e8fff;animation:shield-spin .85s linear infinite;flex:0 0 auto}
.shield-title{font-size:14px;font-weight:700;line-height:1.2;margin:0}
.shield-note{font-size:12px;line-height:1.35;color:#b8c5e3;margin:2px 0 0}
.shield-error{background:rgba(120,18,34,.95);border-color:rgba(255,97,122,.35)}
.shield-error .shield-note{color:#ffd5dc}
@keyframes shield-spin{to{transform:rotate(360deg)}}
@media (min-width:640px){.shield-toast{left:auto;right:20px}}
</style>
<script src="{$keySrc}"></script>
</head>
<body>
<div id="shieldToast" class="shield-toast" role="status" aria-live="polite">
  <div class="shield-row">
    <div class="shield-spinner" aria-hidden="true"></div>
    <div>
      <p class="shield-title">Secured page loading</p>
      <p class="shield-note">Decrypting this page in your browser…</p>
    </div>
  </div>
  <noscript><div class="shield-note" style="margin-top:8px">JavaScript is required for the optional page shield mode.</div></noscript>
</div>
<script>
(function(){
  function b64ToBytes(b){var s=atob(b),a=new Uint8Array(s.length),i;for(i=0;i<s.length;i++){a[i]=s.charCodeAt(i);}return a;}
  function decodeText(buf){if(window.TextDecoder){return new TextDecoder().decode(buf);}var a=new Uint8Array(buf),s='',i;for(i=0;i<a.length;i++){s+=String.fromCharCode(a[i]);}try{return decodeURIComponent(escape(s));}catch(e){return s;}}
  function fail(msg){var el=document.getElementById('shieldToast');if(!el){return;}el.className='shield-toast shield-error';el.innerHTML='<div class="shield-row"><div><p class="shield-title">Secure page mode failed</p><p class="shield-note">'+msg+'</p></div></div>';}
  if(!window.__PANEL_KEY__||!window.crypto||!window.crypto.subtle){fail('Your browser could not initialize the shield loader.');return;}
  var payload = '{$payload}';
  var iv = '{$ivB64}';
  window.crypto.subtle.importKey('raw', b64ToBytes(window.__PANEL_KEY__), {name:'AES-CBC'}, false, ['decrypt'])
    .then(function(key){ return window.crypto.subtle.decrypt({name:'AES-CBC', iv:b64ToBytes(iv)}, key, b64ToBytes(payload)); })
    .then(function(buf){ document.open(); document.write(decodeText(buf)); document.close(); })
    .catch(function(){ fail('Could not decrypt this response in the browser.'); });
})();
</script>
</body>
</html>
HTML;
        return $bootstrap;
    }


    protected function serveInternalAsset($name)
    {
        $name = basename((string) $name);
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_.\-]+$/', $name)) {
            $this->abort(404, 'Asset not found.');
        }
        $path = PANEL_ROOT . '/public/assets/' . $name;
        if ($name === 'key.js') {
            $this->ensureClientShieldAsset();
        }
        if (!is_file($path)) {
            $this->abort(404, 'Asset not found.');
        }
        $content = (string) @file_get_contents($path);
        if ($content === '') {
            $this->abort(404, 'Asset not found.');
        }
        $settings = $this->securitySettings();
        if (preg_match('/\.js$/i', $name) && !empty($settings['js_hardening'])) {
            $content = $this->obfuscateJavascript($content, $name);
        }
        $this->sendCommonHeaders('application/javascript; charset=UTF-8');
        echo $content;
        exit;
    }

    
    protected function obfuscateJavascript($code, $name)
    {
        $plain = (string) $code;
        $seed = function_exists('random_bytes') ? random_bytes(8) : openssl_random_pseudo_bytes(8);
        $keyByte = ord($seed[0]) ^ 91;
        if ($keyByte <= 0) { $keyByte = 91; }
        $xor = '';
        for ($i = 0, $len = strlen($plain); $i < $len; $i++) {
            $xor .= chr(ord($plain[$i]) ^ $keyByte);
        }
        $payload = base64_encode(strrev($xor));
        $chunks = str_split($payload, 24);
        shuffle($chunks);
        $glue = implode('|', $chunks);
        $parts = explode('|', $glue);
        sort($parts);
        $joined = implode('', $parts);
        $arr = str_split($payload, 24);
        $serialized = json_encode($arr);
        $label = panel_e($name);
        $varA = 'p' . substr(md5($name . panel_random_hex(4)), 0, 6);
        $varB = 'k' . substr(md5(panel_random_hex(4) . $name), 0, 6);
        return "/* hardened asset: {$label} */
(function(){var {$varA}={$serialized},{$varB}={$keyByte};function r(s){return s.split('').reverse().join('');}function d(x){try{return decodeURIComponent(escape(atob(x)));}catch(e){return atob(x);}}var b={$varA}.join(''),raw=r(d(b)),out='',i;for(i=0;i<raw.length;i++){out+=String.fromCharCode(raw.charCodeAt(i)^{$varB});}(0,Function)(out)();}());";
    }

    protected function adminSettings()

    {
        $settings = $this->securitySettings();
        $this->renderPanel('admin_settings.php', array(
            'title' => 'Settings',
            'settings' => $settings,
            'backups' => $this->listBackups(),
            'errors' => array(),
            'shield_asset_url' => $this->asset('key.js'),
            'install_lock_path' => $this->installLockPath(),
            'sync_state' => $this->panelSyncStateSummary(),
            'sync_script_path' => PANEL_ROOT . '/scripts/panel_sync_cron.php',
        ));
    }

    protected function saveAdminSettings()
    {
        $current = $this->runtimeConfig();
        $data = array(
            'app_name' => trim((string) $this->input('app_name', isset($current['app_name']) ? $current['app_name'] : $this->appName())),
            'app_url' => trim((string) $this->input('app_url', isset($current['app_url']) ? $current['app_url'] : '')),
            'timezone' => trim((string) $this->input('timezone', isset($current['timezone']) ? $current['timezone'] : 'UTC')),
            'default_duration_days' => trim((string) $this->input('default_duration_days', isset($current['default_duration_days']) ? $current['default_duration_days'] : '30')),
            'login_max_attempts' => trim((string) $this->input('login_max_attempts', '8')),
            'login_window_seconds' => trim((string) $this->input('login_window_seconds', '900')),
            'login_lockout_seconds' => trim((string) $this->input('login_lockout_seconds', '900')),
            'subscription_max_requests' => trim((string) $this->input('subscription_max_requests', '60')),
            'subscription_window_seconds' => trim((string) $this->input('subscription_window_seconds', '60')),
            'page_shield_mode' => trim((string) $this->input('page_shield_mode', 'off')),
            'page_shield_forms' => isset($_POST['page_shield_forms']) ? '1' : '0',
            'js_hardening' => isset($_POST['js_hardening']) ? '1' : '0',
            'api_enabled' => isset($_POST['api_enabled']) ? '1' : '0',
            'api_encryption' => isset($_POST['api_encryption']) ? '1' : '0',
            'telegram_enabled' => isset($_POST['telegram_enabled']) ? '1' : '0',
            'telegram_bot_token' => trim((string) $this->input('telegram_bot_token', isset($current['telegram_bot_token']) ? $current['telegram_bot_token'] : '')),
            'telegram_mode' => trim((string) $this->input('telegram_mode', isset($current['telegram_mode']) ? $current['telegram_mode'] : 'webhook')),
            'telegram_webhook_secret' => trim((string) $this->input('telegram_webhook_secret', isset($current['telegram_webhook_secret']) ? $current['telegram_webhook_secret'] : '')),
            'telegram_proxy_enabled' => isset($_POST['telegram_proxy_enabled']) ? '1' : '0',
            'telegram_proxy_type' => trim((string) $this->input('telegram_proxy_type', isset($current['telegram_proxy_type']) ? $current['telegram_proxy_type'] : 'http')),
            'telegram_proxy_host' => trim((string) $this->input('telegram_proxy_host', isset($current['telegram_proxy_host']) ? $current['telegram_proxy_host'] : '')),
            'telegram_proxy_port' => trim((string) $this->input('telegram_proxy_port', isset($current['telegram_proxy_port']) ? $current['telegram_proxy_port'] : '0')),
            'telegram_proxy_username' => trim((string) $this->input('telegram_proxy_username', isset($current['telegram_proxy_username']) ? $current['telegram_proxy_username'] : '')),
            'telegram_proxy_password' => trim((string) $this->input('telegram_proxy_password', isset($current['telegram_proxy_password']) ? $current['telegram_proxy_password'] : '')),
            'telegram_allow_reseller' => isset($_POST['telegram_allow_reseller']) ? '1' : '0',
            'telegram_allow_client' => isset($_POST['telegram_allow_client']) ? '1' : '0',
            'telegram_allow_admin' => isset($_POST['telegram_allow_admin']) ? '1' : '0',
            'telegram_poll_limit' => trim((string) $this->input('telegram_poll_limit', isset($current['telegram_poll_limit']) ? $current['telegram_poll_limit'] : '20')),
            'panel_sync_enabled' => isset($_POST['panel_sync_enabled']) ? '1' : '0',
            'panel_sync_mode' => trim((string) $this->input('panel_sync_mode', isset($current['panel_sync_mode']) ? $current['panel_sync_mode'] : 'off')),
            'panel_sync_master_url' => trim((string) $this->input('panel_sync_master_url', isset($current['panel_sync_master_url']) ? $current['panel_sync_master_url'] : '')),
            'panel_sync_shared_secret' => trim((string) $this->input('panel_sync_shared_secret', isset($current['panel_sync_shared_secret']) ? $current['panel_sync_shared_secret'] : '')),
            'panel_sync_interval_seconds' => trim((string) $this->input('panel_sync_interval_seconds', isset($current['panel_sync_interval_seconds']) ? $current['panel_sync_interval_seconds'] : '300')),
            'panel_sync_prune_missing' => isset($_POST['panel_sync_prune_missing']) ? '1' : '0',
            'panel_sync_proxy_enabled' => isset($_POST['panel_sync_proxy_enabled']) ? '1' : '0',
            'panel_sync_proxy_type' => trim((string) $this->input('panel_sync_proxy_type', isset($current['panel_sync_proxy_type']) ? $current['panel_sync_proxy_type'] : 'http')),
            'panel_sync_proxy_host' => trim((string) $this->input('panel_sync_proxy_host', isset($current['panel_sync_proxy_host']) ? $current['panel_sync_proxy_host'] : '')),
            'panel_sync_proxy_port' => trim((string) $this->input('panel_sync_proxy_port', isset($current['panel_sync_proxy_port']) ? $current['panel_sync_proxy_port'] : '0')),
            'panel_sync_proxy_username' => trim((string) $this->input('panel_sync_proxy_username', isset($current['panel_sync_proxy_username']) ? $current['panel_sync_proxy_username'] : '')),
            'panel_sync_proxy_password' => trim((string) $this->input('panel_sync_proxy_password', isset($current['panel_sync_proxy_password']) ? $current['panel_sync_proxy_password'] : '')),
            'regenerate_page_shield_key' => isset($_POST['regenerate_page_shield_key']) ? '1' : '0',
        );
        $errors = array();
        if (strlen($data['app_name']) < 3) { $errors['app_name'][] = 'Application name must be at least 3 characters.'; }
        if ($data['app_url'] !== '' && filter_var($data['app_url'], FILTER_VALIDATE_URL) === false) { $errors['app_url'][] = 'Application URL is not valid.'; }
        if ($data['timezone'] === '' || strlen($data['timezone']) > 100) { $errors['timezone'][] = 'Timezone is required.'; }
        if (!ctype_digit($data['default_duration_days']) || (int) $data['default_duration_days'] < 1) { $errors['default_duration_days'][] = 'Default duration must be a positive integer.'; }
        foreach (array('login_max_attempts','login_window_seconds','login_lockout_seconds','subscription_max_requests','subscription_window_seconds') as $field) {
            if (!ctype_digit($data[$field]) || (int) $data[$field] < 1) { $errors[$field][] = 'This field must be a positive integer.'; }
        }
        if (!in_array($data['page_shield_mode'], array('off', 'http_only', 'always'), true)) { $errors['page_shield_mode'][] = 'Page shield mode is invalid.'; }
        if (!in_array($data['telegram_mode'], array('webhook', 'polling'), true)) { $errors['telegram_mode'][] = 'Telegram mode is invalid.'; }
        if ($data['telegram_enabled'] === '1' && strlen($data['telegram_bot_token']) < 20) { $errors['telegram_bot_token'][] = 'Telegram bot token looks invalid.'; }
        if ($data['telegram_webhook_secret'] !== '' && !preg_match('/^[a-zA-Z0-9_-]{8,80}$/', $data['telegram_webhook_secret'])) { $errors['telegram_webhook_secret'][] = 'Telegram webhook secret is invalid.'; }
        if (!in_array($data['telegram_proxy_type'], array('http', 'https', 'socks5'), true)) { $errors['telegram_proxy_type'][] = 'Telegram proxy type is invalid.'; }
        if ($data['telegram_proxy_port'] !== '' && (!ctype_digit($data['telegram_proxy_port']) || (int) $data['telegram_proxy_port'] < 0 || (int) $data['telegram_proxy_port'] > 65535)) { $errors['telegram_proxy_port'][] = 'Telegram proxy port is invalid.'; }
        if (!ctype_digit($data['telegram_poll_limit']) || (int) $data['telegram_poll_limit'] < 1 || (int) $data['telegram_poll_limit'] > 100) { $errors['telegram_poll_limit'][] = 'Telegram poll limit must be between 1 and 100.'; }
        if (!in_array($data['panel_sync_mode'], array('off', 'master', 'slave'), true)) { $errors['panel_sync_mode'][] = 'Panel sync mode is invalid.'; }
        if ($data['panel_sync_enabled'] === '1' && $data['panel_sync_mode'] === 'slave' && ($data['panel_sync_master_url'] === '' || filter_var($data['panel_sync_master_url'], FILTER_VALIDATE_URL) === false)) { $errors['panel_sync_master_url'][] = 'Master panel URL is required in slave mode.'; }
        if ($data['panel_sync_master_url'] !== '' && filter_var($data['panel_sync_master_url'], FILTER_VALIDATE_URL) === false) { $errors['panel_sync_master_url'][] = 'Master panel URL is invalid.'; }
        if ($data['panel_sync_shared_secret'] !== '' && !preg_match('/^[a-zA-Z0-9_-]{8,120}$/', $data['panel_sync_shared_secret'])) { $errors['panel_sync_shared_secret'][] = 'Sync secret is invalid.'; }
        if (!ctype_digit($data['panel_sync_interval_seconds']) || (int) $data['panel_sync_interval_seconds'] < 60 || (int) $data['panel_sync_interval_seconds'] > 86400) { $errors['panel_sync_interval_seconds'][] = 'Sync interval must be between 60 and 86400 seconds.'; }
        if (!in_array($data['panel_sync_proxy_type'], array('http', 'https', 'socks5'), true)) { $errors['panel_sync_proxy_type'][] = 'Sync proxy type is invalid.'; }
        if ($data['panel_sync_proxy_port'] !== '' && (!ctype_digit($data['panel_sync_proxy_port']) || (int) $data['panel_sync_proxy_port'] < 0 || (int) $data['panel_sync_proxy_port'] > 65535)) { $errors['panel_sync_proxy_port'][] = 'Sync proxy port is invalid.'; }
        if ($errors) {
            return $this->renderPanel('admin_settings.php', array('title' => 'Settings', 'settings' => array_merge($this->securitySettings(), $data), 'backups' => $this->listBackups(), 'errors' => $errors, 'shield_asset_url' => $this->asset('key.js'), 'install_lock_path' => $this->installLockPath(), 'sync_state' => $this->panelSyncStateSummary(), 'sync_script_path' => PANEL_ROOT . '/scripts/panel_sync_cron.php'));
        }
        $current['app_name'] = $data['app_name'];
        $current['app_url'] = $data['app_url'];
        $current['timezone'] = $data['timezone'];
        $current['default_duration_days'] = (int) $data['default_duration_days'];
        $current['login_max_attempts'] = (int) $data['login_max_attempts'];
        $current['login_window_seconds'] = (int) $data['login_window_seconds'];
        $current['login_lockout_seconds'] = (int) $data['login_lockout_seconds'];
        $current['subscription_max_requests'] = (int) $data['subscription_max_requests'];
        $current['subscription_window_seconds'] = (int) $data['subscription_window_seconds'];
        $current['page_shield_mode'] = $data['page_shield_mode'];
        $current['page_shield_forms'] = $data['page_shield_forms'] === '1' ? 1 : 0;
        $current['js_hardening'] = $data['js_hardening'] === '1' ? 1 : 0;
        $current['api_enabled'] = $data['api_enabled'] === '1' ? 1 : 0;
        $current['api_encryption'] = $data['api_encryption'] === '1' ? 1 : 0;
        $current['telegram_enabled'] = $data['telegram_enabled'] === '1' ? 1 : 0;
        $current['telegram_bot_token'] = $data['telegram_bot_token'];
        $current['telegram_mode'] = $data['telegram_mode'];
        $current['telegram_webhook_secret'] = $data['telegram_webhook_secret'] !== '' ? $data['telegram_webhook_secret'] : (!empty($current['telegram_webhook_secret']) ? $current['telegram_webhook_secret'] : panel_random_hex(24));
        $current['telegram_proxy_enabled'] = $data['telegram_proxy_enabled'] === '1' ? 1 : 0;
        $current['telegram_proxy_type'] = $data['telegram_proxy_type'];
        $current['telegram_proxy_host'] = $data['telegram_proxy_host'];
        $current['telegram_proxy_port'] = (int) $data['telegram_proxy_port'];
        $current['telegram_proxy_username'] = $data['telegram_proxy_username'];
        $current['telegram_proxy_password'] = $data['telegram_proxy_password'];
        $current['telegram_allow_reseller'] = $data['telegram_allow_reseller'] === '1' ? 1 : 0;
        $current['telegram_allow_client'] = $data['telegram_allow_client'] === '1' ? 1 : 0;
        $current['telegram_allow_admin'] = $data['telegram_allow_admin'] === '1' ? 1 : 0;
        $current['telegram_poll_limit'] = (int) $data['telegram_poll_limit'];
        $current['panel_sync_enabled'] = $data['panel_sync_enabled'] === '1' ? 1 : 0;
        $current['panel_sync_mode'] = $data['panel_sync_enabled'] === '1' ? $data['panel_sync_mode'] : 'off';
        $current['panel_sync_master_url'] = rtrim($data['panel_sync_master_url'], '/');
        $current['panel_sync_shared_secret'] = $data['panel_sync_shared_secret'] !== '' ? $data['panel_sync_shared_secret'] : (!empty($current['panel_sync_shared_secret']) ? $current['panel_sync_shared_secret'] : panel_random_hex(24));
        $current['panel_sync_interval_seconds'] = (int) $data['panel_sync_interval_seconds'];
        $current['panel_sync_prune_missing'] = $data['panel_sync_prune_missing'] === '1' ? 1 : 0;
        $current['panel_sync_proxy_enabled'] = $data['panel_sync_proxy_enabled'] === '1' ? 1 : 0;
        $current['panel_sync_proxy_type'] = $data['panel_sync_proxy_type'];
        $current['panel_sync_proxy_host'] = $data['panel_sync_proxy_host'];
        $current['panel_sync_proxy_port'] = (int) $data['panel_sync_proxy_port'];
        $current['panel_sync_proxy_username'] = $data['panel_sync_proxy_username'];
        $current['panel_sync_proxy_password'] = $data['panel_sync_proxy_password'];
        if ($data['regenerate_page_shield_key'] === '1' || empty($current['page_shield_key'])) {
            $current['page_shield_key'] = base64_encode(function_exists('random_bytes') ? random_bytes(32) : openssl_random_pseudo_bytes(32));
        }
        $this->store->writeConfig('app', $current);
        $this->writeInstallLock();
        $this->ensureClientShieldAsset();
        $this->flash('success', 'Settings updated.');
        $this->redirect('/admin/settings');
    }

    protected function listBackups()
    {
        $dir = $this->storage . '/backups';
        $items = array();
        if (!is_dir($dir)) { return $items; }
        $files = glob($dir . '/*');
        if (!is_array($files)) { return $items; }
        foreach ($files as $file) {
            if (!is_file($file)) { continue; }
            $items[] = array('name' => basename($file), 'size' => filesize($file), 'time' => filemtime($file));
        }
        usort($items, function ($a, $b) { return (int) $b['time'] - (int) $a['time']; });
        return $items;
    }

    protected function createAdminBackup()
    {
        $created = $this->createBackupArchive();
        if (!$created['ok']) {
            $this->flash('error', $created['message']);
            $this->redirect('/admin/settings');
        }
        $this->flash('success', 'Backup created: ' . $created['name']);
        $this->redirect('/admin/settings');
    }

    protected function downloadAdminBackup()
    {
        $name = basename((string) $this->input('file', ''));
        if ($name === '' || strpos($name, '..') !== false) { $this->abort(404, 'Backup not found.'); }
        $path = $this->storage . '/backups/' . $name;
        if (!is_file($path)) { $this->abort(404, 'Backup not found.'); }
        $mime = preg_match('/\.zip$/i', $name) ? 'application/zip' : 'application/octet-stream';
        $this->sendCommonHeaders($mime);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }


protected function deleteAdminBackup()
{
    $name = basename((string) $this->input('file', ''));
    if ($name === '' || strpos($name, '..') !== false) { $this->flash('error', 'Backup not found.'); $this->redirect('/admin/settings'); }
    $path = $this->storage . '/backups/' . $name;
    if (!is_file($path)) { $this->flash('error', 'Backup not found.'); $this->redirect('/admin/settings'); }
    @unlink($path);
    $this->flash('success', 'Backup deleted.');
    $this->redirect('/admin/settings');
}

protected function createBackupArchive()
{

        $dir = $this->storage . '/backups';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $stamp = gmdate('Ymd-His');
        $root = PANEL_ROOT;
        $name = 'panel-backup-' . $stamp . '.zip';
        $path = $dir . '/' . $name;
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                return array('ok' => false, 'message' => 'Could not create zip backup archive.');
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $file) {
                $filePath = (string) $file;
                $local = str_replace('\\', '/', substr($filePath, strlen($root) + 1));
                if ($local === false || $local === '') { continue; }
                if (strpos($local, 'storage/backups/') === 0) { continue; }
                if ($file->isDir()) {
                    $zip->addEmptyDir($local);
                } else {
                    $zip->addFile($filePath, $local);
                }
            }
            $zip->addFromString('storage/backups/manifest.json', json_encode(array('created_at' => panel_now(), 'app' => $this->appName(), 'base_path' => $this->basePath), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->close();
            return array('ok' => true, 'name' => $name, 'path' => $path);
        }
        $fallbackName = 'panel-backup-' . $stamp . '.json';
        $fallbackPath = $dir . '/' . $fallbackName;
        $payload = array(
            'created_at' => panel_now(),
            'config' => $this->runtimeConfig(),
            'collections' => array(),
            'logs' => array(),
        );
        foreach (array('admins','resellers','nodes','templates','customers','customer_links','tickets','ticket_messages','credit_ledger','activity') as $collection) {
            $payload['collections'][$collection] = $this->store->all($collection);
        }
        foreach (glob($this->storage . '/logs/*.log') ?: array() as $logFile) {
            $payload['logs'][basename($logFile)] = (string) @file_get_contents($logFile);
        }
        @file_put_contents($fallbackPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return array('ok' => true, 'name' => $fallbackName, 'path' => $fallbackPath);
    }

    protected function appName() { $cfg = $this->runtimeConfig(); return !empty($cfg['app_name']) ? $cfg['app_name'] : $this->config('app_name', 'XUI Reseller Panel'); }
    protected function runtimeConfig() { return $this->store->readConfig('app'); }
    protected function defaultDurationDays() { $cfg = $this->runtimeConfig(); return isset($cfg['default_duration_days']) ? (int) $cfg['default_duration_days'] : (int) $this->config('default_duration_days', 30); }
    protected function resellerMaxExpirationDays($reseller)
    {
        if (isset($reseller['max_expiration_days'])) { return max(0, (int) $reseller['max_expiration_days']); }
        if (isset($reseller['fixed_duration_days'])) { return max(0, (int) $reseller['fixed_duration_days']); }
        return $this->defaultDurationDays();
    }
    protected function resellerMaxIpLimit($reseller)
    {
        return isset($reseller['max_ip_limit']) ? max(0, (int) $reseller['max_ip_limit']) : 0;
    }
    protected function resellerDefaultCustomerDurationDays($reseller)
    {
        $max = $this->resellerMaxExpirationDays($reseller);
        return $max > 0 ? $max : $this->defaultDurationDays();
    }

    protected function encrypt($plain)
    {
        $cfg = $this->runtimeConfig(); $appKey = isset($cfg['app_key']) ? $cfg['app_key'] : '';
        if ($appKey === '' || !function_exists('openssl_encrypt')) { return base64_encode($plain); }
        $iv = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $key = hash('sha256', $appKey, true); $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv); return base64_encode($iv . $cipher);
    }
    protected function decrypt($payload)
    {
        $cfg = $this->runtimeConfig(); $appKey = isset($cfg['app_key']) ? $cfg['app_key'] : '';
        if ($appKey === '' || !function_exists('openssl_decrypt')) { return base64_decode($payload); }
        $raw = base64_decode($payload); if ($raw === false || strlen($raw) < 17) { return ''; }
        $iv = substr($raw, 0, 16); $cipher = substr($raw, 16); $key = hash('sha256', $appKey, true); return (string) openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    }

    protected function nodeAdapter($node)
    {
        $node['panel_password_plain'] = isset($node['panel_password']) ? $this->decrypt($node['panel_password']) : '';
        return new XuiAdapter($node, $this->storage);
    }

    protected function makeUuid()
    {
        $data = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    protected function log($event, $context)
    {
        $this->store->appendLog('audit', array('time' => panel_now(), 'event' => $event, 'context' => $context));
    }


    protected function appendSecurityLog($channel, $level, $message, $context)
    {
        $channel = preg_replace('/[^a-z0-9_-]+/i', '', strtolower((string) $channel));
        $level = preg_replace('/[^a-z0-9_-]+/i', '', strtolower((string) $level));
        if ($channel === '') { $channel = 'app'; }
        if ($level === '') { $level = 'access'; }
        $name = $channel . '_' . $level;
        $this->rotateLogFileIfNeeded($name);
        $this->store->appendLog($name, array(
            'time' => panel_now(),
            'channel' => $channel,
            'level' => $level,
            'path' => $this->requestPath,
            'message' => (string) $message,
            'context' => (array) $context,
        ));
    }

    protected function rotateLogFileIfNeeded($name)
    {
        $dir = $this->storage . '/logs';
        $file = $dir . '/' . $name . '.log';
        if (!is_file($file)) { return; }
        $maxBytes = 512 * 1024;
        if (@filesize($file) < $maxBytes) { return; }
        for ($i = 4; $i >= 1; $i--) {
            $src = $file . '.' . $i;
            $dst = $file . '.' . ($i + 1);
            if (is_file($src)) { @rename($src, $dst); }
        }
        @rename($file, $file . '.1');
    }

    protected function availableSystemLogNames()
    {
        return array('login_access','login_error','get_access','get_error','firewall_error');
    }

    protected function readSystemLogRows($name, $limit)
    {
        $name = preg_replace('/[^a-z0-9_.-]+/i', '', (string) $name);
        $files = array();
        $base = $this->storage . '/logs/' . $name . '.log';
        if (is_file($base)) { $files[] = $base; }
        for ($i = 1; $i <= 5; $i++) {
            $f = $base . '.' . $i;
            if (is_file($f)) { $files[] = $f; }
        }
        $rows = array();
        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) { continue; }
            foreach ($lines as $line) {
                $row = json_decode($line, true);
                if (is_array($row)) { $rows[] = $row; }
            }
        }
        usort($rows, function ($a, $b) {
            return strcmp(isset($b['time']) ? $b['time'] : '', isset($a['time']) ? $a['time'] : '');
        });
        return array_slice($rows, 0, max(1, (int) $limit));
    }

    protected function adminSystemLogs()
    {
        $name = trim((string) $this->input('name', 'login_error'));
        if (!in_array($name, $this->availableSystemLogNames(), true)) { $name = 'login_error'; }
        $limit = trim((string) $this->input('limit', '200'));
        if (!ctype_digit($limit)) { $limit = '200'; }
        $limit = max(20, min(500, (int) $limit));
        $this->renderPanel('admin_logs.php', array(
            'title' => 'System logs',
            'log_names' => $this->availableSystemLogNames(),
            'selected_log_name' => $name,
            'limit' => $limit,
            'rows' => $this->readSystemLogRows($name, $limit),
        ));
    }

    protected function clearAdminLog()
    {
        $name = trim((string) $this->input('name', ''));
        if (!in_array($name, $this->availableSystemLogNames(), true)) {
            $this->flash('error', 'Log selection is invalid.');
            $this->redirect('/admin/logs');
        }
        $base = $this->storage . '/logs/' . $name . '.log';
        @unlink($base);
        for ($i = 1; $i <= 5; $i++) { @unlink($base . '.' . $i); }
        $this->flash('success', 'Selected log was cleared.');
        $this->redirect('/admin/logs?name=' . rawurlencode($name));
    }

    protected function adminTransactions()
    {
        $items = $this->store->all('credit_ledger');
        $resellerId = trim((string) $this->input('reseller_id', ''));
        $type = trim((string) $this->input('type', ''));
        if ($resellerId !== '') {
            $items = array_values(array_filter($items, function ($item) use ($resellerId) { return isset($item['reseller_id']) && $item['reseller_id'] === $resellerId; }));
        }
        if ($type !== '') {
            $items = array_values(array_filter($items, function ($item) use ($type) { return isset($item['type']) && (string) $item['type'] === (string) $type; }));
        }
        usort($items, array($this, 'sortNewest'));
        $types = array();
        foreach ($this->store->all('credit_ledger') as $item) {
            if (!empty($item['type'])) { $types[(string) $item['type']] = (string) $item['type']; }
        }
        ksort($types);
        $this->renderPanel('admin_transactions.php', array(
            'title' => 'Transactions',
            'items' => $items,
            'resellers' => $this->store->all('resellers'),
            'selected_reseller_id' => $resellerId,
            'selected_type' => $type,
            'types' => array_values($types),
        ));
    }


protected function apiEnabled()
{
    $s = $this->securitySettings();
    return !empty($s['api_enabled']);
}

protected function apiEncryptionEnabled()
{
    $s = $this->securitySettings();
    return !empty($s['api_encryption']);
}

protected function resellerApiKey($reseller)
{
    if (!is_array($reseller)) { return ''; }
    if (!empty($reseller['api_key'])) { return (string) $reseller['api_key']; }
    $generated = panel_random_hex(48);
    $this->store->update('resellers', $reseller['id'], array('api_key' => $generated));
    return $generated;
}

protected function deriveApiCryptoKey($apiKey)
{
    return hash('sha256', 'panel-api|' . (string) $apiKey, true);
}

protected function apiRequireReseller()
{
    if (!$this->apiEnabled()) {
        $this->json(array('ok' => false, 'message' => 'API is disabled.'), 403);
    }
    $key = trim((string) (isset($_SERVER['HTTP_X_RESELLER_API_KEY']) ? $_SERVER['HTTP_X_RESELLER_API_KEY'] : ''));
    if ($key === '' && !empty($_SERVER['HTTP_AUTHORIZATION']) && stripos((string) $_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
        $key = trim(substr((string) $_SERVER['HTTP_AUTHORIZATION'], 7));
    }
    if ($key === '') { $this->json(array('ok' => false, 'message' => 'Missing API key.'), 401); }
    $reseller = $this->store->findBy('resellers', 'api_key', $key);
    if (!$reseller || (isset($reseller['status']) && $reseller['status'] !== 'active')) {
        $this->json(array('ok' => false, 'message' => 'Invalid API key.'), 401);
    }
    return array($reseller, $key);
}

protected function apiReadPayload($apiKey, $method)
{
    $raw = (string) @file_get_contents('php://input');
    if ($method === 'GET') { return array(); }
    if ($raw === '') {
        return $_POST;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $_POST;
    }
    if ($this->apiEncryptionEnabled()) {
        if (empty($decoded['iv']) || empty($decoded['payload'])) {
            $this->json(array('ok' => false, 'message' => 'Encrypted API payload required.'), 400);
        }
        $iv = base64_decode((string) $decoded['iv'], true);
        $payload = base64_decode((string) $decoded['payload'], true);
        if ($iv === false || $payload === false || strlen($iv) !== 16) {
            $this->json(array('ok' => false, 'message' => 'Encrypted API payload is invalid.'), 400);
        }
        $plain = function_exists('openssl_decrypt') ? openssl_decrypt($payload, 'AES-256-CBC', $this->deriveApiCryptoKey($apiKey), OPENSSL_RAW_DATA, $iv) : false;
        if (!is_string($plain) || $plain === '') {
            $this->json(array('ok' => false, 'message' => 'Encrypted API payload could not be decrypted.'), 400);
        }
        $decoded = json_decode($plain, true);
        if (!is_array($decoded)) {
            $this->json(array('ok' => false, 'message' => 'Encrypted API payload JSON is invalid.'), 400);
        }
        return $decoded;
    }
    return $decoded;
}

protected function apiRespond($data, $status, $apiKey)
{
    if ($this->apiEncryptionEnabled()) {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        $iv = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $cipher = openssl_encrypt($json, 'AES-256-CBC', $this->deriveApiCryptoKey($apiKey), OPENSSL_RAW_DATA, $iv);
        if ($cipher !== false) {
            $this->json(array('ok' => true, 'encrypted' => 1, 'iv' => base64_encode($iv), 'payload' => base64_encode($cipher)), $status);
        }
    }
    $this->json($data, $status);
}

protected function handleResellerApi($path, $method)
{
    list($reseller, $apiKey) = $this->apiRequireReseller();
    $payload = $this->apiReadPayload($apiKey, $method);
    $oldPost = $_POST; $oldGet = $_GET; $oldReq = $_REQUEST;
    if ($method !== 'GET') {
        $_POST = is_array($payload) ? $payload : array();
        $_REQUEST = array_merge($_GET, $_POST);
    }
    if ($path === '/api/reseller/profile' && $method === 'GET') {
        $out = array('ok' => true, 'reseller' => $this->apiResellerSummary($reseller));
        $this->apiRespond($out, 200, $apiKey);
    }
    if ($path === '/api/reseller/templates' && $method === 'GET') {
        $templates = array();
        foreach ($this->resellerTemplates($reseller) as $tpl) {
            $node = $this->store->find('nodes', $tpl['node_id']);
            $templates[] = array('id' => $tpl['id'], 'title' => $tpl['public_label'], 'inbound_name' => $tpl['inbound_name'], 'protocol' => $tpl['protocol'], 'node' => $node ? $node['title'] : '', 'node_id' => $tpl['node_id']);
        }
        $this->apiRespond(array('ok' => true, 'templates' => $templates), 200, $apiKey);
    }
    if ($path === '/api/reseller/customers' && $method === 'GET') {
        $items = $this->store->filterBy('customers', function ($item) use ($reseller) { return isset($item['reseller_id']) && $item['reseller_id'] === $reseller['id']; });
        usort($items, array($this, 'sortNewest'));
        $out = array();
        foreach ($items as $item) { $out[] = $this->apiCustomerSummary($item); }
        $this->apiRespond(array('ok' => true, 'customers' => $out), 200, $apiKey);
    }
    if (preg_match('#^/api/reseller/customers/([^/]+)$#', $path, $m) && $method === 'GET') {
        $customer = $this->loadCustomerForApi($m[1], $reseller['id']);
        $this->apiRespond(array('ok' => true, 'customer' => $this->apiCustomerSummary($customer, true)), 200, $apiKey);
    }
    if ($path === '/api/reseller/customers/create' && $method === 'POST') {
        $result = $this->saveCustomerApi($reseller, null);
        $this->apiRespond($result, !empty($result['ok']) ? 200 : 422, $apiKey);
    }
    if (preg_match('#^/api/reseller/customers/([^/]+)/edit$#', $path, $m) && $method === 'POST') {
        $result = $this->saveCustomerApi($reseller, $m[1]);
        $this->apiRespond($result, !empty($result['ok']) ? 200 : 422, $apiKey);
    }
    if (preg_match('#^/api/reseller/customers/([^/]+)/toggle$#', $path, $m) && $method === 'POST') {
        $result = $this->toggleCustomerApi($reseller, $m[1]);
        $this->apiRespond($result, !empty($result['ok']) ? 200 : 422, $apiKey);
    }
    if (preg_match('#^/api/reseller/customers/([^/]+)/delete$#', $path, $m) && $method === 'POST') {
        $result = $this->deleteCustomerApi($reseller, $m[1]);
        $this->apiRespond($result, !empty($result['ok']) ? 200 : 422, $apiKey);
    }
    if (preg_match('#^/api/reseller/customers/([^/]+)/sync$#', $path, $m) && $method === 'POST') {
        $result = $this->syncCustomerApi($reseller, $m[1]);
        $this->apiRespond($result, !empty($result['ok']) ? 200 : 422, $apiKey);
    }
    if ($path === '/api/reseller/password' && $method === 'POST') {
        $result = $this->changeResellerPasswordApi($reseller);
        $this->apiRespond($result, !empty($result['ok']) ? 200 : 422, $apiKey);
    }
    $_POST = $oldPost; $_GET = $oldGet; $_REQUEST = $oldReq;
    $this->apiRespond(array('ok' => false, 'message' => 'API route not found.'), 404, $apiKey);
}

protected function apiResellerSummary($reseller)
{
    return array(
        'id' => $reseller['id'],
        'username' => $reseller['username'],
        'display_name' => $reseller['display_name'],
        'credit_gb' => (float) $reseller['credit_gb'],
        'max_expiration_days' => $this->resellerMaxExpirationDays($reseller),
        'max_ip_limit' => $this->resellerMaxIpLimit($reseller),
        'min_customer_traffic_gb' => isset($reseller['min_customer_traffic_gb']) ? (float) $reseller['min_customer_traffic_gb'] : 0.0,
        'max_customer_traffic_gb' => isset($reseller['max_customer_traffic_gb']) ? (float) $reseller['max_customer_traffic_gb'] : 0.0,
        'restrict' => !empty($reseller['restrict']) ? 1 : 0,
        'api_enabled' => $this->apiEnabled() ? 1 : 0,
        'api_encryption' => $this->apiEncryptionEnabled() ? 1 : 0,
    );
}

protected function apiCustomerSummary($customer, $includeLinks)
{
    $row = array(
        'id' => $customer['id'], 'display_name' => $customer['display_name'], 'system_name' => $customer['system_name'], 'status' => $customer['status'], 'phone' => isset($customer['phone']) ? $customer['phone'] : '', 'email' => isset($customer['email']) ? $customer['email'] : '',
        'traffic_gb' => (float) $customer['traffic_gb'], 'used_gb' => panel_to_gb_from_bytes(isset($customer['traffic_bytes_used']) ? $customer['traffic_bytes_used'] : 0),
        'left_gb' => panel_to_gb_from_bytes(isset($customer['traffic_bytes_left']) ? $customer['traffic_bytes_left'] : 0), 'expires_at' => isset($customer['expires_at']) ? $customer['expires_at'] : '',
        'expiration_mode' => $this->customerExpirationMode($customer), 'expires_label' => $this->customerExpirationLabel($customer),
        'subscription_key' => isset($customer['subscription_key']) ? $customer['subscription_key'] : '', 'remote_sub_id' => isset($customer['remote_sub_id']) ? $customer['remote_sub_id'] : '',
        'ip_limit' => isset($customer['ip_limit']) ? (int) $customer['ip_limit'] : 0, 'duration_days' => isset($customer['duration_days']) ? (int) $customer['duration_days'] : 0,
    );
    if ($includeLinks) {
        $tpl = $this->store->find('templates', $customer['template_id']);
        $node = $tpl ? $this->store->find('nodes', $tpl['node_id']) : null;
        $row['subscription_url'] = $this->buildNodeSubscriptionUrl($node, !empty($customer['remote_sub_id']) ? $customer['remote_sub_id'] : $customer['subscription_key']);
        $row['fallback_subscription_url'] = $this->appLink('/user/' . $customer['subscription_key']);
        $row['export_url'] = $this->appLink('/user/' . $customer['subscription_key'] . '/export');
    }
    return $row;
}

protected function loadCustomerForApi($id, $resellerId)
{
    $customer = $this->store->find('customers', $id);
    if (!$customer || $customer['reseller_id'] !== $resellerId) {
        $this->json(array('ok' => false, 'message' => 'Customer not found.'), 404);
    }
    return $customer;
}

protected function saveCustomerApi($reseller, $id)
{
    $oldAuth = isset($_SESSION['auth']) ? $_SESSION['auth'] : null;
    $_SESSION['auth'] = array('id' => $reseller['id'], 'role' => 'reseller', 'display_name' => $reseller['display_name']);
    $flashBefore = isset($_SESSION['_flash']) ? $_SESSION['_flash'] : null;
    ob_start();
    try {
        $this->saveCustomer($id);
    } catch (Exception $e) {
    }
    ob_end_clean();
    $flash = isset($_SESSION['_flash']) ? $_SESSION['_flash'] : $flashBefore;
    if ($oldAuth !== null) { $_SESSION['auth'] = $oldAuth; } else { unset($_SESSION['auth']); }
    if ($flash && isset($flash['message'])) {
        if ($flash['type'] === 'success') {
            $customers = $this->store->filterBy('customers', function ($item) use ($reseller, $id) { return isset($item['reseller_id']) && $item['reseller_id'] === $reseller['id']; });
            usort($customers, array($this, 'sortNewest'));
            $customer = null;
            if ($id) { $customer = $this->store->find('customers', $id); }
            if (!$customer && !empty($customers)) { $customer = $customers[0]; }
            unset($_SESSION['_flash']);
            return array('ok' => true, 'message' => $flash['message'], 'customer' => $customer ? $this->apiCustomerSummary($customer, true) : null);
        }
        unset($_SESSION['_flash']);
        return array('ok' => false, 'message' => $flash['message']);
    }
    return array('ok' => false, 'message' => 'Customer operation could not be completed.');
}

protected function toggleCustomerApi($reseller, $id)
{
    $oldAuth = isset($_SESSION['auth']) ? $_SESSION['auth'] : null;
    $_SESSION['auth'] = array('id' => $reseller['id'], 'role' => 'reseller', 'display_name' => $reseller['display_name']);
    ob_start();
    try { $this->toggleCustomer($id, true); } catch (Exception $e) {}
    ob_end_clean();
    $flash = isset($_SESSION['_flash']) ? $_SESSION['_flash'] : null;
    if ($oldAuth !== null) { $_SESSION['auth'] = $oldAuth; } else { unset($_SESSION['auth']); }
    if ($flash) { unset($_SESSION['_flash']); }
    $customer = $this->store->find('customers', $id);
    if ($flash && $flash['type'] === 'success') { return array('ok' => true, 'message' => $flash['message'], 'customer' => $customer ? $this->apiCustomerSummary($customer, true) : null); }
    return array('ok' => false, 'message' => $flash ? $flash['message'] : 'Customer status update failed.');
}

protected function deleteCustomerApi($reseller, $id)
{
    $oldAuth = isset($_SESSION['auth']) ? $_SESSION['auth'] : null;
    $_SESSION['auth'] = array('id' => $reseller['id'], 'role' => 'reseller', 'display_name' => $reseller['display_name']);
    ob_start();
    try { $this->deleteCustomer($id, true); } catch (Exception $e) {}
    ob_end_clean();
    $flash = isset($_SESSION['_flash']) ? $_SESSION['_flash'] : null;
    if ($oldAuth !== null) { $_SESSION['auth'] = $oldAuth; } else { unset($_SESSION['auth']); }
    if ($flash) { unset($_SESSION['_flash']); }
    if ($flash && $flash['type'] === 'success') { return array('ok' => true, 'message' => $flash['message']); }
    return array('ok' => false, 'message' => $flash ? $flash['message'] : 'Customer delete failed.');
}

protected function syncCustomerApi($reseller, $id)
{
    $oldAuth = isset($_SESSION['auth']) ? $_SESSION['auth'] : null;
    $_SESSION['auth'] = array('id' => $reseller['id'], 'role' => 'reseller', 'display_name' => $reseller['display_name']);
    ob_start();
    try { $this->syncCustomer($id, true); } catch (Exception $e) {}
    ob_end_clean();
    $flash = isset($_SESSION['_flash']) ? $_SESSION['_flash'] : null;
    if ($oldAuth !== null) { $_SESSION['auth'] = $oldAuth; } else { unset($_SESSION['auth']); }
    if ($flash) { unset($_SESSION['_flash']); }
    $customer = $this->store->find('customers', $id);
    if ($flash && $flash['type'] === 'success') { return array('ok' => true, 'message' => $flash['message'], 'customer' => $customer ? $this->apiCustomerSummary($customer, true) : null); }
    return array('ok' => false, 'message' => $flash ? $flash['message'] : 'Customer sync failed.');
}

protected function changeResellerPasswordApi($reseller)
{
    $current = trim((string) $this->input('current_password', ''));
    $new = trim((string) $this->input('new_password', ''));
    $confirm = trim((string) $this->input('confirm_password', ''));
    if ($current === '' || $new === '') { return array('ok' => false, 'message' => 'Current and new password are required.'); }
    if (!password_verify($current, isset($reseller['password_hash']) ? $reseller['password_hash'] : '')) { return array('ok' => false, 'message' => 'Current password is incorrect.'); }
    if (strlen($new) < 8) { return array('ok' => false, 'message' => 'New password must be at least 8 characters.'); }
    if ($new !== $confirm) { return array('ok' => false, 'message' => 'Password confirmation does not match.'); }
    $this->store->update('resellers', $reseller['id'], array('password_hash' => panel_password_hash($new)));
    return array('ok' => true, 'message' => 'Password updated.');
}

protected function activeNotices($audience)
{
    $items = $this->store->all('notices');
    $out = array();
    $now = time();
    foreach ($items as $item) {
        if (isset($item['status']) && $item['status'] !== 'active') { continue; }
        $target = isset($item['target']) ? $item['target'] : 'reseller';
        if ($target !== 'all' && $target !== $audience) { continue; }
        $startAt = !empty($item['start_at']) ? strtotime($item['start_at']) : 0;
        $endAt = !empty($item['end_at']) ? strtotime($item['end_at']) : 0;
        if ($startAt && $now < $startAt) { continue; }
        if ($endAt && $now > $endAt) { continue; }
        $out[] = $item;
    }
    usort($out, array($this, 'sortNewest'));
    return $out;
}

protected function adminNotices()
{
    $items = $this->store->all('notices');
    usort($items, array($this, 'sortNewest'));
    $this->renderPanel('admin_notices.php', array('title' => 'Notices', 'notices' => $items));
}

protected function adminNoticeForm($mode, $id = null)
{
    $record = array('title' => '', 'body' => '', 'target' => 'reseller', 'start_at' => '', 'end_at' => '', 'status' => 'active');
    if ($mode === 'edit') {
        $found = $this->store->find('notices', $id);
        if (!$found) { $this->flash('error', 'Notice not found.'); $this->redirect('/admin/notices'); }
        $record = array_merge($record, $found);
    }
    $this->renderPanel('admin_notice_form.php', array('title' => $mode === 'edit' ? 'Edit notice' : 'Create notice', 'mode' => $mode, 'record' => $record, 'errors' => array()));
}

protected function saveNotice($id = null)
{
    $mode = $id ? 'edit' : 'create';
    $record = $id ? $this->store->find('notices', $id) : null;
    if ($id && !$record) { $this->flash('error', 'Notice not found.'); $this->redirect('/admin/notices'); }
    $data = array('title' => trim((string) $this->input('title', '')), 'body' => trim((string) $this->input('body', '')), 'target' => trim((string) $this->input('target', 'reseller')), 'start_at' => trim((string) $this->input('start_at', '')), 'end_at' => trim((string) $this->input('end_at', '')), 'status' => trim((string) $this->input('status', 'active')));
    $errors = array();
    if (strlen($data['title']) < 2) { $errors['title'][] = 'Title must be at least 2 characters.'; }
    if (strlen($data['body']) < 2) { $errors['body'][] = 'Notice body must be at least 2 characters.'; }
    if (!in_array($data['target'], array('reseller','public','all'), true)) { $errors['target'][] = 'Target is invalid.'; }
    if (!in_array($data['status'], array('active','disabled'), true)) { $errors['status'][] = 'Status is invalid.'; }
    if ($data['start_at'] !== '' && strtotime($data['start_at']) === false) { $errors['start_at'][] = 'Start time is invalid.'; }
    if ($data['end_at'] !== '' && strtotime($data['end_at']) === false) { $errors['end_at'][] = 'End time is invalid.'; }
    if ($errors) { return $this->renderPanel('admin_notice_form.php', array('title' => $mode === 'edit' ? 'Edit notice' : 'Create notice', 'mode' => $mode, 'record' => $data, 'errors' => $errors)); }
    if ($id) { $this->store->update('notices', $id, $data); } else { $this->store->insert('notices', $data, 'ntc'); }
    $this->flash('success', $id ? 'Notice updated.' : 'Notice created.');
    $this->redirect('/admin/notices');
}

protected function deleteNotice($id)
{
    $item = $this->store->find('notices', $id);
    if (!$item) { $this->flash('error', 'Notice not found.'); $this->redirect('/admin/notices'); }
    $this->store->delete('notices', $id);
    $this->flash('success', 'Notice deleted.');
    $this->redirect('/admin/notices');
}

protected function adminActivity()
{
    $items = $this->store->all('activity');
    $resellerId = trim((string) $this->input('reseller_id', ''));
    if ($resellerId !== '') {
        $items = array_values(array_filter($items, function ($item) use ($resellerId) { return isset($item['reseller_id']) && $item['reseller_id'] === $resellerId; }));
    }
    usort($items, array($this, 'sortNewest'));
    $this->renderPanel('admin_activity.php', array('title' => 'Reseller activity', 'items' => $items, 'resellers' => $this->store->all('resellers'), 'selected_reseller_id' => $resellerId));
}

protected function logResellerActivity($resellerId, $action, $customer, $extra)
{
    $row = array(
        'reseller_id' => $resellerId,
        'action' => $action,
        'customer_id' => is_array($customer) && isset($customer['id']) ? $customer['id'] : '',
        'customer_name' => is_array($customer) && isset($customer['display_name']) ? $customer['display_name'] : '',
        'system_name' => is_array($customer) && isset($customer['system_name']) ? $customer['system_name'] : '',
        'context' => (array) $extra,
        'ip' => $this->clientIp(),
    );
    $this->store->insert('activity', $row, 'act');
}

protected function resellerProfile()
{
    $reseller = $this->currentReseller();
    $apiKey = $this->resellerApiKey($reseller);
    $reseller = $this->store->find('resellers', $reseller['id']);
    $linkToken = $this->resellerTelegramLinkToken($reseller, false);
    $reseller = $this->store->find('resellers', $reseller['id']);
    $this->renderPanel('reseller_profile.php', array(
        'title' => 'Profile',
        'reseller' => $reseller,
        'errors' => array(),
        'api_enabled' => $this->apiEnabled(),
        'api_encryption' => $this->apiEncryptionEnabled(),
        'api_key' => $apiKey,
        'telegram_settings' => $this->telegramSettings(),
        'telegram_link_token' => $linkToken,
    ));
}

protected function saveResellerPassword()
{
    $reseller = $this->currentReseller();
    $section = trim((string) $this->input('profile_section', 'password'));
    if ($section === 'telegram') {
        $tgid = trim((string) $this->input('telegram_user_id', isset($reseller['telegram_user_id']) ? $reseller['telegram_user_id'] : ''));
        $errors = array();
        if ($tgid !== '' && !ctype_digit($tgid)) {
            $errors['telegram_user_id'][] = 'Telegram user ID must contain digits only.';
        }
        if ($errors) {
            return $this->renderPanel('reseller_profile.php', array('title' => 'Profile', 'reseller' => array_replace($reseller, array('telegram_user_id' => $tgid)), 'errors' => $errors, 'api_enabled' => $this->apiEnabled(), 'api_encryption' => $this->apiEncryptionEnabled(), 'api_key' => $this->resellerApiKey($reseller), 'telegram_settings' => $this->telegramSettings(), 'telegram_link_token' => $this->resellerTelegramLinkToken($reseller, false)));
        }
        $payload = array('telegram_user_id' => $tgid);
        if (isset($_POST['regenerate_telegram_link'])) {
            $payload['telegram_link_token'] = panel_random_hex(20);
            $payload['telegram_link_expires_at'] = gmdate('c', time() + 7 * 86400);
        }
        $this->store->update('resellers', $reseller['id'], $payload);
        $this->flash('success', 'Telegram profile settings updated.');
        $this->redirect('/reseller/profile');
    }
    $current = (string) $this->input('current_password', '');
    $new = (string) $this->input('new_password', '');
    $confirm = (string) $this->input('confirm_password', '');
    $errors = array();
    if (!password_verify($current, isset($reseller['password_hash']) ? $reseller['password_hash'] : '')) { $errors['current_password'][] = 'Current password is incorrect.'; }
    if (strlen($new) < 8) { $errors['new_password'][] = 'New password must be at least 8 characters.'; }
    if ($new !== $confirm) { $errors['confirm_password'][] = 'Password confirmation does not match.'; }
    if ($errors) { return $this->renderPanel('reseller_profile.php', array('title' => 'Profile', 'reseller' => $reseller, 'errors' => $errors, 'api_enabled' => $this->apiEnabled(), 'api_encryption' => $this->apiEncryptionEnabled(), 'api_key' => $this->resellerApiKey($reseller), 'telegram_settings' => $this->telegramSettings(), 'telegram_link_token' => $this->resellerTelegramLinkToken($reseller, false))); }
    $this->store->update('resellers', $reseller['id'], array('password_hash' => panel_password_hash($new)));
    $this->flash('success', 'Password updated.');
    $this->redirect('/reseller/profile');
}

    protected function sortedTemplates() { $items = $this->store->all('templates'); usort($items, array($this, 'sortTemplate')); return $items; }
    protected function sortedNodes() { $items = $this->store->all('nodes'); usort($items, array($this, 'sortTitle')); return $items; }
    protected function sortNewest($a, $b) { return strcmp(isset($b['created_at']) ? $b['created_at'] : '', isset($a['created_at']) ? $a['created_at'] : ''); }
    protected function sortOldest($a, $b) { return strcmp(isset($a['created_at']) ? $a['created_at'] : '', isset($b['created_at']) ? $b['created_at'] : ''); }
    protected function sortTitle($a, $b) { return strcmp(isset($a['title']) ? $a['title'] : '', isset($b['title']) ? $b['title'] : ''); }
    protected function sortDisplayName($a, $b) { return strcmp(isset($a['display_name']) ? $a['display_name'] : '', isset($b['display_name']) ? $b['display_name'] : ''); }
    protected function sortByTrafficLeft($a, $b) { return (float) (isset($b['traffic_bytes_left']) ? $b['traffic_bytes_left'] : 0) - (float) (isset($a['traffic_bytes_left']) ? $a['traffic_bytes_left'] : 0); }
    protected function sortTemplate($a, $b) { $as = isset($a['sort_order']) ? (int) $a['sort_order'] : 0; $bs = isset($b['sort_order']) ? (int) $b['sort_order'] : 0; if ($as === $bs) { return strcmp(isset($a['title']) ? $a['title'] : '', isset($b['title']) ? $b['title'] : ''); } return $as > $bs ? 1 : -1; }


protected function telegramSettings()
{
    $cfg = $this->runtimeConfig();
    return array(
        'enabled' => !empty($cfg['telegram_enabled']) ? 1 : 0,
        'bot_token' => isset($cfg['telegram_bot_token']) ? trim((string) $cfg['telegram_bot_token']) : '',
        'mode' => isset($cfg['telegram_mode']) && in_array($cfg['telegram_mode'], array('webhook', 'polling'), true) ? (string) $cfg['telegram_mode'] : 'webhook',
        'webhook_secret' => isset($cfg['telegram_webhook_secret']) && trim((string) $cfg['telegram_webhook_secret']) !== '' ? trim((string) $cfg['telegram_webhook_secret']) : panel_random_hex(24),
        'proxy_enabled' => !empty($cfg['telegram_proxy_enabled']) ? 1 : 0,
        'proxy_type' => isset($cfg['telegram_proxy_type']) ? trim((string) $cfg['telegram_proxy_type']) : 'http',
        'proxy_host' => isset($cfg['telegram_proxy_host']) ? trim((string) $cfg['telegram_proxy_host']) : '',
        'proxy_port' => isset($cfg['telegram_proxy_port']) ? (int) $cfg['telegram_proxy_port'] : 0,
        'proxy_username' => isset($cfg['telegram_proxy_username']) ? (string) $cfg['telegram_proxy_username'] : '',
        'proxy_password' => isset($cfg['telegram_proxy_password']) ? (string) $cfg['telegram_proxy_password'] : '',
        'allow_reseller' => !array_key_exists('telegram_allow_reseller', $cfg) || !empty($cfg['telegram_allow_reseller']) ? 1 : 0,
        'allow_client' => !array_key_exists('telegram_allow_client', $cfg) || !empty($cfg['telegram_allow_client']) ? 1 : 0,
        'allow_admin' => !empty($cfg['telegram_allow_admin']) ? 1 : 0,
        'poll_limit' => isset($cfg['telegram_poll_limit']) ? max(1, min(100, (int) $cfg['telegram_poll_limit'])) : 20,
        'update_offset' => isset($cfg['telegram_update_offset']) ? (int) $cfg['telegram_update_offset'] : 0,
    );
}

public function telegramWebhookUrl()
{
    $s = $this->telegramSettings();
    return $this->appLink('/telegram/webhook/' . $s['webhook_secret']);
}

public function telegramPollUrl()
{
    $s = $this->telegramSettings();
    return $this->appLink('/telegram/poll/' . $s['webhook_secret']);
}

protected function telegramProxyCurlOptions(&$ch, $settings)
{
    if (empty($settings['proxy_enabled']) || empty($settings['proxy_host']) || empty($settings['proxy_port'])) {
        return;
    }
    curl_setopt($ch, CURLOPT_PROXY, $settings['proxy_host']);
    curl_setopt($ch, CURLOPT_PROXYPORT, (int) $settings['proxy_port']);
    $ptype = strtolower((string) $settings['proxy_type']);
    if ($ptype === 'socks5') {
        if (defined('CURLPROXY_SOCKS5_HOSTNAME')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        } elseif (defined('CURLPROXY_SOCKS5')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        }
    } else {
        if (defined('CURLPROXY_HTTP')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }
    }
    if (!empty($settings['proxy_username'])) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $settings['proxy_username'] . ':' . $settings['proxy_password']);
    }
}

protected function telegramApiRequest($method, $payload)
{
    $settings = $this->telegramSettings();
    if (empty($settings['bot_token'])) {
        return array('ok' => false, 'message' => 'Telegram bot token is empty.');
    }
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'message' => 'cURL is required for Telegram bot support.');
    }
    $url = 'https://api.telegram.org/bot' . $settings['bot_token'] . '/' . $method;
    $ch = curl_init($url);
    $headers = array('Accept: application/json');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $tgTimeout = 40;
    if ((string) $method === 'getUpdates' && isset($payload['timeout'])) {
        $tgTimeout = max(15, min(70, ((int) $payload['timeout']) + 10));
    }
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, $tgTimeout);
    curl_setopt($ch, CURLOPT_USERAGENT, 'XUIResellerTelegramBot/1.0');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode((array) $payload));
    $headers[] = 'Content-Type: application/json';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $this->telegramProxyCurlOptions($ch, $settings);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $err !== '') {
        return array('ok' => false, 'message' => 'Telegram transport error: ' . $err);
    }
    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        return array('ok' => false, 'message' => 'Telegram returned a non-JSON response.');
    }
    if ($code >= 400 || empty($decoded['ok'])) {
        return array('ok' => false, 'message' => isset($decoded['description']) ? $decoded['description'] : 'Telegram API request failed.', 'result' => isset($decoded['result']) ? $decoded['result'] : null);
    }
    return array('ok' => true, 'message' => 'ok', 'result' => isset($decoded['result']) ? $decoded['result'] : null);
}

protected function telegramSendMessage($chatId, $text, $replyMarkup, $disablePreview)
{
    $payload = array('chat_id' => $chatId, 'text' => (string) $text);
    if ($replyMarkup !== null) {
        $payload['reply_markup'] = $replyMarkup;
    }
    if ($disablePreview) {
        $payload['disable_web_page_preview'] = true;
    }
    return $this->telegramApiRequest('sendMessage', $payload);
}

protected function telegramEditMessage($chatId, $messageId, $text, $replyMarkup)
{
    $payload = array('chat_id' => $chatId, 'message_id' => $messageId, 'text' => (string) $text);
    if ($replyMarkup !== null) {
        $payload['reply_markup'] = $replyMarkup;
    }
    return $this->telegramApiRequest('editMessageText', $payload);
}

protected function telegramAnswerCallback($callbackId, $text)
{
    $payload = array('callback_query_id' => $callbackId);
    if ($text !== '') {
        $payload['text'] = $text;
        $payload['show_alert'] = false;
    }
    return $this->telegramApiRequest('answerCallbackQuery', $payload);
}

protected function telegramStateRecord($chatId)
{
    return $this->store->findBy('telegram_states', 'chat_id', (string) $chatId);
}

protected function telegramStateSet($chatId, $tgUserId, $flow, $step, $data)
{
    $existing = $this->telegramStateRecord($chatId);
    $payload = array('chat_id' => (string) $chatId, 'tg_user_id' => (string) $tgUserId, 'flow' => (string) $flow, 'step' => (string) $step, 'data' => (array) $data);
    if ($existing) {
        $this->store->update('telegram_states', $existing['id'], $payload);
        return $this->store->find('telegram_states', $existing['id']);
    }
    return $this->store->insert('telegram_states', $payload, 'tgs');
}

protected function telegramStateClear($chatId)
{
    $existing = $this->telegramStateRecord($chatId);
    if ($existing) {
        $this->store->delete('telegram_states', $existing['id']);
    }
}

protected function telegramBindingRecord($type, $tgUserId)
{
    $matches = $this->store->filterBy('telegram_bindings', function ($item) use ($type, $tgUserId) {
        return isset($item['type']) && $item['type'] === $type && isset($item['tg_user_id']) && (string) $item['tg_user_id'] === (string) $tgUserId;
    });
    return !empty($matches) ? $matches[0] : null;
}

protected function telegramBindEntity($type, $entityId, $tgUserId, $chatId, $username, $firstName)
{
    $existing = $this->telegramBindingRecord($type, $tgUserId);
    $payload = array(
        'type' => $type,
        'entity_id' => (string) $entityId,
        'tg_user_id' => (string) $tgUserId,
        'chat_id' => (string) $chatId,
        'tg_username' => (string) $username,
        'tg_first_name' => (string) $firstName,
        'status' => 'active',
    );
    if ($existing) {
        $this->store->update('telegram_bindings', $existing['id'], $payload);
        return $this->store->find('telegram_bindings', $existing['id']);
    }
    return $this->store->insert('telegram_bindings', $payload, 'tgb');
}

protected function telegramUnbindByUser($type, $tgUserId)
{
    $items = $this->store->filterBy('telegram_bindings', function ($item) use ($type, $tgUserId) {
        return isset($item['type']) && $item['type'] === $type && isset($item['tg_user_id']) && (string) $item['tg_user_id'] === (string) $tgUserId;
    });
    foreach ($items as $item) {
        $this->store->delete('telegram_bindings', $item['id']);
    }
}

protected function telegramFindResellerByTelegram($tgUserId)
{
    $binding = $this->telegramBindingRecord('reseller', $tgUserId);
    if ($binding && !empty($binding['entity_id'])) {
        $reseller = $this->store->find('resellers', $binding['entity_id']);
        if ($reseller && isset($reseller['status']) && $reseller['status'] === 'active') {
            return $reseller;
        }
    }
    $items = $this->store->filterBy('resellers', function ($item) use ($tgUserId) {
        return isset($item['telegram_user_id']) && (string) $item['telegram_user_id'] === (string) $tgUserId && isset($item['status']) && $item['status'] === 'active';
    });
    return !empty($items) ? $items[0] : null;
}

protected function telegramFindCustomerByTelegram($tgUserId)
{
    $binding = $this->telegramBindingRecord('customer', $tgUserId);
    if ($binding && !empty($binding['entity_id'])) {
        return $this->store->find('customers', $binding['entity_id']);
    }
    return null;
}

protected function telegramCustomerByCode($code)
{
    $code = trim((string) $code);
    if ($code === '') { return null; }
    $matches = $this->store->filterBy('customers', function ($item) use ($code) {
        return (isset($item['subscription_key']) && (string) $item['subscription_key'] === $code)
            || (isset($item['remote_sub_id']) && (string) $item['remote_sub_id'] === $code)
            || (isset($item['uuid']) && (string) $item['uuid'] === $code)
            || (isset($item['remote_email']) && (string) $item['remote_email'] === $code);
    });
    return !empty($matches) ? $matches[0] : null;
}

protected function resellerTelegramLinkToken($reseller, $force)
{
    $needsNew = $force || empty($reseller['telegram_link_token']) || empty($reseller['telegram_link_expires_at']) || strtotime($reseller['telegram_link_expires_at']) < time();
    if (!$needsNew) {
        return (string) $reseller['telegram_link_token'];
    }
    $token = panel_random_hex(20);
    $this->store->update('resellers', $reseller['id'], array(
        'telegram_link_token' => $token,
        'telegram_link_expires_at' => gmdate('c', time() + 7 * 86400),
    ));
    return $token;
}

protected function telegramFindResellerByLinkToken($token)
{
    $items = $this->store->filterBy('resellers', function ($item) use ($token) {
        return isset($item['telegram_link_token']) && (string) $item['telegram_link_token'] === (string) $token;
    });
    if (empty($items)) {
        return null;
    }
    $reseller = $items[0];
    if (!empty($reseller['telegram_link_expires_at']) && strtotime($reseller['telegram_link_expires_at']) < time()) {
        return null;
    }
    return $reseller;
}


protected function telegramNormalizeMenuCommand($text)
{
    $trimmed = trim((string) $text);
    $map = array(
        '🏠 Menu' => '/menu',
        '👥 Customers' => '/customers',
        '➕ Create' => '/create',
        '💳 Balance' => '/balance',
        '📢 Notices' => '/notices',
        '❓ Help' => '/help',
        '📊 Status' => '/status',
        '🔗 Subscriptions' => '/sub',
        '🔓 Unbind' => '/unbind',
        '🔗 Link Reseller' => '/link',
        '👤 Bind Client' => '/client',
    );
    return isset($map[$trimmed]) ? $map[$trimmed] : $trimmed;
}

protected function telegramReplyKeyboardReseller()
{
    return array(
        'keyboard' => array(
            array(array('text' => '👥 Customers'), array('text' => '➕ Create'), array('text' => '💳 Balance')),
            array(array('text' => '📢 Notices'), array('text' => '❓ Help'), array('text' => '🏠 Menu')),
        ),
        'resize_keyboard' => true,
        'is_persistent' => true,
        'input_field_placeholder' => 'Choose an action or type a command',
    );
}

protected function telegramReplyKeyboardClient()
{
    return array(
        'keyboard' => array(
            array(array('text' => '📊 Status'), array('text' => '🔗 Subscriptions')),
            array(array('text' => '🔓 Unbind'), array('text' => '❓ Help')),
        ),
        'resize_keyboard' => true,
        'is_persistent' => true,
        'input_field_placeholder' => 'Choose an action or type a command',
    );
}

protected function telegramReplyKeyboardGuest()
{
    return array(
        'keyboard' => array(
            array(array('text' => '🔗 Link Reseller'), array('text' => '👤 Bind Client')),
            array(array('text' => '❓ Help')),
        ),
        'resize_keyboard' => true,
        'is_persistent' => true,
        'input_field_placeholder' => 'Use a link or bind command',
    );
}

protected function telegramMainMenuText($reseller)
{
    return "✨ Telegram control is linked to your reseller account.

" . $this->telegramResellerSummaryText($reseller) . "

Use the buttons below or send /help for all commands.";
}

protected function telegramClientMenuText($customer)
{
    return "✅ This chat is linked to your account.

" . $this->telegramCustomerSummaryText($customer) . "

Use the buttons below to view status or subscription links.";
}

protected function telegramKeyboardCustomerActions($customer, $reseller)
{
    $buttons = array(
        array(
            array('text' => '🔄 Sync', 'callback_data' => 'rsync:' . $customer['id']),
            array('text' => '🔗 Subscriptions', 'callback_data' => 'rsub:' . $customer['id']),
        ),
        array(
            array('text' => '📋 Customers', 'callback_data' => 'rlist:1'),
            array('text' => '➕ Create', 'callback_data' => 'rcreate:1'),
        ),
    );
    if (!$reseller || empty($reseller['restrict'])) {
        $buttons[] = array(
            array('text' => ($customer['status'] === 'active' ? '⛔ Disable' : '✅ Enable'), 'callback_data' => 'rtoggle:' . $customer['id']),
            array('text' => '🗑 Delete', 'callback_data' => 'rdelete:' . $customer['id']),
        );
    }
    return array('inline_keyboard' => $buttons);
}

protected function telegramCustomerSummaryText($customer)
{
    $tpl = $this->store->find('templates', $customer['template_id']);
    $node = $tpl ? $this->store->find('nodes', $tpl['node_id']) : null;
    $lines = array();
    $lines[] = 'Customer: ' . $customer['display_name'];
    $lines[] = 'ID: ' . $customer['id'];
    $lines[] = 'Status: ' . $customer['status'];
    $lines[] = 'Traffic: ' . panel_format_gb($customer['traffic_gb']) . ' GB';
    $lines[] = 'Used: ' . panel_format_gb(panel_to_gb_from_bytes(isset($customer['traffic_bytes_used']) ? $customer['traffic_bytes_used'] : 0)) . ' GB';
    $lines[] = 'Left: ' . panel_format_gb(panel_to_gb_from_bytes(isset($customer['traffic_bytes_left']) ? $customer['traffic_bytes_left'] : 0)) . ' GB';
    $lines[] = 'IP limit: ' . (isset($customer['ip_limit']) ? (int) $customer['ip_limit'] : 0);
    $lines[] = 'Expiration: ' . $this->customerExpirationLabel($customer);
    if ($tpl) { $lines[] = 'Template: ' . $tpl['public_label']; }
    if ($node) { $lines[] = 'Server: ' . $node['title']; }
    return implode("\n", $lines);
}

protected function telegramResellerSummaryText($reseller)
{
    $lines = array();
    $lines[] = 'Reseller: ' . $reseller['display_name'];
    $lines[] = 'Credit: ' . panel_format_gb($reseller['credit_gb']) . ' GB';
    $lines[] = 'Max IP limit: ' . ($this->resellerMaxIpLimit($reseller) > 0 ? $this->resellerMaxIpLimit($reseller) : 'Unlimited');
    $lines[] = 'Max expiration days: ' . ($this->resellerMaxExpirationDays($reseller) > 0 ? $this->resellerMaxExpirationDays($reseller) : 'Unlimited');
    $lines[] = 'Restriction: ' . (!empty($reseller['restrict']) ? 'On' : 'Off');
    return implode("\n", $lines);
}

protected function telegramResolveResellerCustomer($reseller, $token)
{
    $token = trim((string) $token);
    $items = $this->store->filterBy('customers', function ($item) use ($reseller, $token) {
        if (!isset($item['reseller_id']) || $item['reseller_id'] !== $reseller['id']) {
            return false;
        }
        return (isset($item['id']) && (string) $item['id'] === $token)
            || (isset($item['system_name']) && (string) $item['system_name'] === $token)
            || (isset($item['display_name']) && strtolower((string) $item['display_name']) === strtolower($token))
            || (isset($item['subscription_key']) && (string) $item['subscription_key'] === $token);
    });
    return !empty($items) ? $items[0] : null;
}

protected function telegramCustomerEditPayload($customer, $changes)
{
    return array(
        'display_name' => isset($changes['display_name']) ? $changes['display_name'] : $customer['display_name'],
        'template_id' => isset($changes['template_id']) ? $changes['template_id'] : $customer['template_id'],
        'traffic_gb' => isset($changes['traffic_gb']) ? $changes['traffic_gb'] : $customer['traffic_gb'],
        'ip_limit' => isset($changes['ip_limit']) ? $changes['ip_limit'] : (isset($customer['ip_limit']) ? $customer['ip_limit'] : 0),
        'duration_days' => isset($changes['duration_days']) ? $changes['duration_days'] : (isset($customer['duration_days']) ? $customer['duration_days'] : 0),
        'duration_mode' => isset($changes['duration_mode']) ? $changes['duration_mode'] : $this->customerExpirationMode($customer),
        'status' => isset($changes['status']) ? $changes['status'] : (isset($customer['status']) ? $customer['status'] : 'active'),
        'notes' => isset($changes['notes']) ? $changes['notes'] : (isset($customer['notes']) ? $customer['notes'] : ''),
    );
}

protected function telegramRunCustomerEdit($reseller, $customer, $changes)
{
    $payload = $this->telegramCustomerEditPayload($customer, $changes);
    $oldPost = $_POST; $oldReq = $_REQUEST;
    $_POST = $payload; $_REQUEST = array_merge($_GET, $_POST);
    $result = $this->saveCustomerApi($reseller, $customer['id']);
    $_POST = $oldPost; $_REQUEST = $oldReq;
    return $result;
}

protected function telegramCommandHelpText($isReseller, $isClient)
{
    $lines = array();
    $lines[] = '✨ Available commands:';
    $lines[] = '/start or /menu - show the main menu';
    $lines[] = '/help - show this help';
    if ($isReseller) {
        $lines[] = '/balance - reseller balance and limits';
        $lines[] = '/customers [page] - list your newest customers';
        $lines[] = '/customer <id> - show one customer';
        $lines[] = '/create - guided customer creation';
        $lines[] = '/addtraffic <id> <gb> - add GB to a customer';
        $lines[] = '/settraffic <id> <total_gb> - set the total traffic';
        $lines[] = '/setip <id> <limit> - change IP limit';
        $lines[] = '/setdays <id> <days> [fixed|first_use] - change expiration';
        $lines[] = '/sync <id> - sync usage from 3x-ui';
        if (empty($isReseller['restrict'])) {
            $lines[] = '/toggle <id> - enable or disable the customer';
            $lines[] = '/delete <id> - delete the customer';
        }
        $lines[] = '/sub <id> - show subscription URLs';
        $lines[] = '/notices - show active reseller notices';
    } else {
        $lines[] = '/link <token> - link your reseller account to this Telegram user';
    }
    if ($isClient) {
        $lines[] = '/status - show your bound customer usage and expiry';
        $lines[] = '/sub - show your subscription URLs';
        $lines[] = '/unbind - unlink the customer from this Telegram chat';
    } else {
        $lines[] = '/client <subscription_key_or_uuid> - bind a customer to this Telegram chat';
    }
    $lines[] = '';
    $lines[] = 'Tip: you can use the menu buttons too.';
    return implode("
", $lines);
}

protected function telegramSendSubscriptionInfo($chatId, $customer)
{
    $tpl = $this->store->find('templates', $customer['template_id']);
    $node = $tpl ? $this->store->find('nodes', $tpl['node_id']) : null;
    $primary = $this->buildNodeSubscriptionUrl($node, !empty($customer['remote_sub_id']) ? $customer['remote_sub_id'] : $customer['subscription_key']);
    $fallback = $this->appLink('/user/' . $customer['subscription_key']);
    $export = $this->appLink('/user/' . $customer['subscription_key'] . '/export');
    $lines = array();
    $lines[] = 'Subscription links for ' . $customer['display_name'] . ':';
    if ($primary !== '') { $lines[] = 'Primary: ' . $primary; }
    $lines[] = 'Fallback: ' . $fallback;
    $lines[] = 'Export: ' . $export;
    $configs = $this->buildSubscriptionConfigs($customer, $tpl, $node);
    if (!empty($configs)) {
        $lines[] = '';
        $lines[] = 'Configs:';
        foreach ($configs as $cfg) {
            $lines[] = $cfg;
        }
    }
    return $this->telegramSendMessage($chatId, implode("\n", $lines), null, true);
}

protected function telegramHandleCreateConversation($chatId, $userId, $username, $firstName, $text, $reseller, $state)
{
    $data = isset($state['data']) && is_array($state['data']) ? $state['data'] : array();
    $step = isset($state['step']) ? $state['step'] : 'name';
    if ($step === 'name') {
        $data['display_name'] = trim((string) $text);
        if (strlen($data['display_name']) < 2) {
            return $this->telegramSendMessage($chatId, 'Please send a customer name with at least 2 characters.', null, true);
        }
        $templates = $this->resellerTemplates($reseller);
        if (empty($templates)) {
            $this->telegramStateClear($chatId);
            return $this->telegramSendMessage($chatId, 'No allowed templates are configured for this reseller.', null, true);
        }
        $list = array();
        foreach ($templates as $tpl) {
            $node = $this->store->find('nodes', $tpl['node_id']);
            $list[] = $tpl['id'] . ' = ' . $tpl['public_label'] . ' / ' . $tpl['inbound_name'] . ($node ? ' / ' . $node['title'] : '');
        }
        $this->telegramStateSet($chatId, $userId, 'create_customer', 'template', $data);
        return $this->telegramSendMessage($chatId, "Send the template ID for this customer:\n" . implode("\n", $list), null, true);
    }
    if ($step === 'template') {
        $tpl = $this->store->find('templates', trim((string) $text));
        if (!$tpl || !$this->resellerCanUseTemplate($reseller, $tpl['id'])) {
            return $this->telegramSendMessage($chatId, 'Template ID is invalid or not permitted for your reseller account.', null, true);
        }
        $data['template_id'] = $tpl['id'];
        $this->telegramStateSet($chatId, $userId, 'create_customer', 'traffic', $data);
        return $this->telegramSendMessage($chatId, 'Send total traffic in GB, for example: 5', null, true);
    }
    if ($step === 'traffic') {
        if (!is_numeric($text) || (float) $text <= 0) {
            return $this->telegramSendMessage($chatId, 'Traffic must be greater than 0 GB.', null, true);
        }
        $data['traffic_gb'] = round((float) $text, 2);
        $maxIp = $this->resellerMaxIpLimit($reseller);
        $hint = $maxIp > 0 ? ('Send IP limit between 1 and ' . $maxIp . '.') : 'Send IP limit. 0 means unlimited.';
        $this->telegramStateSet($chatId, $userId, 'create_customer', 'ip_limit', $data);
        return $this->telegramSendMessage($chatId, $hint, null, true);
    }
    if ($step === 'ip_limit') {
        if (!ctype_digit(trim((string) $text))) {
            return $this->telegramSendMessage($chatId, 'IP limit must be a whole number.', null, true);
        }
        $ip = (int) $text;
        $maxIp = $this->resellerMaxIpLimit($reseller);
        if ($maxIp > 0 && ($ip < 1 || $ip > $maxIp)) {
            return $this->telegramSendMessage($chatId, 'IP limit must be between 1 and ' . $maxIp . ' for this reseller.', null, true);
        }
        if ($maxIp <= 0 && $ip < 0) {
            return $this->telegramSendMessage($chatId, 'IP limit is invalid.', null, true);
        }
        $data['ip_limit'] = $ip;
        $maxDays = $this->resellerMaxExpirationDays($reseller);
        $hint = $maxDays > 0 ? ('Send expiration days between 1 and ' . $maxDays . '.') : 'Send expiration days. 0 means unlimited.';
        $this->telegramStateSet($chatId, $userId, 'create_customer', 'duration_days', $data);
        return $this->telegramSendMessage($chatId, $hint, null, true);
    }
    if ($step === 'duration_days') {
        if (!ctype_digit(trim((string) $text))) {
            return $this->telegramSendMessage($chatId, 'Expiration days must be a whole number.', null, true);
        }
        $days = (int) $text;
        $maxDays = $this->resellerMaxExpirationDays($reseller);
        if ($maxDays > 0 && ($days < 1 || $days > $maxDays)) {
            return $this->telegramSendMessage($chatId, 'Expiration days must be between 1 and ' . $maxDays . ' for this reseller.', null, true);
        }
        if ($maxDays <= 0 && $days < 0) {
            return $this->telegramSendMessage($chatId, 'Expiration days are invalid.', null, true);
        }
        $data['duration_days'] = $days;
        $this->telegramStateSet($chatId, $userId, 'create_customer', 'duration_mode', $data);
        return $this->telegramSendMessage($chatId, 'Send expiration mode: fixed or first_use', null, true);
    }
    if ($step === 'duration_mode') {
        $mode = strtolower(trim((string) $text));
        if (!in_array($mode, array('fixed', 'first_use'), true)) {
            return $this->telegramSendMessage($chatId, 'Expiration mode must be fixed or first_use.', null, true);
        }
        $data['duration_mode'] = $mode;
        $this->telegramStateClear($chatId);
        $oldPost = $_POST; $oldReq = $_REQUEST;
        $_POST = array(
            'display_name' => $data['display_name'],
            'phone' => isset($data['phone']) ? $data['phone'] : '',
            'access_pin' => isset($data['access_pin']) ? $data['access_pin'] : '',
            'template_id' => $data['template_id'],
            'traffic_gb' => $data['traffic_gb'],
            'ip_limit' => $data['ip_limit'],
            'duration_days' => $data['duration_days'],
            'duration_mode' => $data['duration_mode'],
            'status' => 'active',
            'notes' => 'Created via Telegram bot',
        );
        $_REQUEST = array_merge($_GET, $_POST);
        $result = $this->saveCustomerApi($reseller, null);
        $_POST = $oldPost; $_REQUEST = $oldReq;
        if (!empty($result['ok']) && !empty($result['customer'])) {
            $this->logResellerActivity($reseller['id'], 'telegram.customer.create', $this->store->find('customers', $result['customer']['id']), array('source' => 'telegram'));
            return $this->telegramSendMessage($chatId, 'Customer created.' . "\n\n" . $this->telegramCustomerSummaryText($this->store->find('customers', $result['customer']['id'])), $this->telegramKeyboardCustomerActions($this->store->find('customers', $result['customer']['id']), $reseller), true);
        }
        return $this->telegramSendMessage($chatId, 'Create failed: ' . (!empty($result['message']) ? $result['message'] : 'Unknown error.'), null, true);
    }
    $this->telegramStateClear($chatId);
    return $this->telegramSendMessage($chatId, 'The create flow was reset. Send /create to start again.', null, true);
}

protected function telegramSendCustomersPage($chatId, $reseller, $page)
{
    $items = $this->store->filterBy('customers', function ($item) use ($reseller) {
        return isset($item['reseller_id']) && $item['reseller_id'] === $reseller['id'];
    });
    usort($items, array($this, 'sortNewest'));
    $page = max(1, (int) $page);
    $perPage = 8;
    $total = count($items);
    $offset = ($page - 1) * $perPage;
    $slice = array_slice($items, $offset, $perPage);
    if (empty($slice)) {
        return $this->telegramSendMessage($chatId, 'No customers found on this page.', null, true);
    }
    $lines = array();
    $lines[] = 'Customers page ' . $page . ' / ' . max(1, ceil($total / $perPage));
    $keyboardRows = array();
    foreach ($slice as $item) {
        $lines[] = '- ' . $item['id'] . ' | ' . $item['display_name'] . ' | ' . panel_format_gb(panel_to_gb_from_bytes(isset($item['traffic_bytes_left']) ? $item['traffic_bytes_left'] : 0)) . ' GB left';
        $keyboardRows[] = array(array('text' => $item['display_name'], 'callback_data' => 'rcust:' . $item['id']));
    }
    $nav = array();
    if ($page > 1) { $nav[] = array('text' => '⬅️ Prev', 'callback_data' => 'rlist:' . ($page - 1)); }
    if ($offset + $perPage < $total) { $nav[] = array('text' => 'Next ➡️', 'callback_data' => 'rlist:' . ($page + 1)); }
    if (!empty($nav)) { $keyboardRows[] = $nav; }
    $keyboardRows[] = array(array('text' => '➕ Create customer', 'callback_data' => 'rcreate:1'));
    return $this->telegramSendMessage($chatId, implode("\n", $lines), array('inline_keyboard' => $keyboardRows), true);
}

protected function telegramProcessResellerCommand($chatId, $userId, $username, $firstName, $text, $reseller, $state)
{
    $trimmed = $this->telegramNormalizeMenuCommand($text);
    if ($state && isset($state['flow']) && $state['flow'] === 'create_customer' && $trimmed !== '' && substr($trimmed, 0, 1) !== '/') {
        return $this->telegramHandleCreateConversation($chatId, $userId, $username, $firstName, $trimmed, $reseller, $state);
    }
    $parts = preg_split('/\s+/', $trimmed);
    $cmd = strtolower(isset($parts[0]) ? $parts[0] : '');
    $arg1 = isset($parts[1]) ? $parts[1] : '';
    $arg2 = isset($parts[2]) ? $parts[2] : '';
    $arg3 = isset($parts[3]) ? $parts[3] : '';
    if ($cmd === '/start' || $cmd === '/menu') {
        return $this->telegramSendMessage($chatId, $this->telegramMainMenuText($reseller), $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/help') {
        return $this->telegramSendMessage($chatId, $this->telegramCommandHelpText($reseller, false), $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/balance') {
        return $this->telegramSendMessage($chatId, "💳 Reseller balance and limits

" . $this->telegramResellerSummaryText($reseller), $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/customers') {
        return $this->telegramSendCustomersPage($chatId, $reseller, $arg1 !== '' ? (int) $arg1 : 1);
    }
    if ($cmd === '/customer') {
        $customer = $this->telegramResolveResellerCustomer($reseller, $arg1);
        if (!$customer) { return $this->telegramSendMessage($chatId, 'Customer not found for this reseller.', $this->telegramReplyKeyboardReseller(), true); }
        return $this->telegramSendMessage($chatId, $this->telegramCustomerSummaryText($customer), $this->telegramKeyboardCustomerActions($customer, $reseller), true);
    }
    if ($cmd === '/create') {
        $this->telegramStateSet($chatId, $userId, 'create_customer', 'name', array());
        return $this->telegramSendMessage($chatId, '➕ Send the new customer name.', $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/sync') {
        $customer = $this->telegramResolveResellerCustomer($reseller, $arg1);
        if (!$customer) { return $this->telegramSendMessage($chatId, 'Customer not found.', $this->telegramReplyKeyboardReseller(), true); }
        $result = $this->syncCustomerApi($reseller, $customer['id']);
        if (!empty($result['ok']) && !empty($result['customer'])) {
            $fresh = $this->store->find('customers', $customer['id']);
            return $this->telegramSendMessage($chatId, '🔄 Usage synced.' . "

" . $this->telegramCustomerSummaryText($fresh), $this->telegramKeyboardCustomerActions($fresh, $reseller), true);
        }
        return $this->telegramSendMessage($chatId, 'Sync failed: ' . $result['message'], $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/sub') {
        $customer = $arg1 !== '' ? $this->telegramResolveResellerCustomer($reseller, $arg1) : null;
        if (!$customer) { return $this->telegramSendMessage($chatId, 'Send /sub <customer_id> to view subscription links.', $this->telegramReplyKeyboardReseller(), true); }
        return $this->telegramSendSubscriptionInfo($chatId, $customer);
    }
    if ($cmd === '/toggle') {
        $customer = $this->telegramResolveResellerCustomer($reseller, $arg1);
        if (!$customer) { return $this->telegramSendMessage($chatId, 'Customer not found.', $this->telegramReplyKeyboardReseller(), true); }
        $result = $this->toggleCustomerApi($reseller, $customer['id']);
        return $this->telegramSendMessage($chatId, !empty($result['ok']) ? ('✅ Status updated.

' . $this->telegramCustomerSummaryText($this->store->find('customers', $customer['id']))) : ('Toggle failed: ' . $result['message']), $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/delete') {
        $customer = $this->telegramResolveResellerCustomer($reseller, $arg1);
        if (!$customer) { return $this->telegramSendMessage($chatId, 'Customer not found.', $this->telegramReplyKeyboardReseller(), true); }
        $result = $this->deleteCustomerApi($reseller, $customer['id']);
        return $this->telegramSendMessage($chatId, !empty($result['ok']) ? '🗑 Customer deleted.' : ('Delete failed: ' . $result['message']), $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/addtraffic') {
        $customer = $this->telegramResolveResellerCustomer($reseller, $arg1);
        if (!$customer || !is_numeric($arg2) || (float) $arg2 <= 0) { return $this->telegramSendMessage($chatId, 'Usage: /addtraffic <customer_id> <gb>', $this->telegramReplyKeyboardReseller(), true); }
        $result = $this->telegramRunCustomerEdit($reseller, $customer, array('traffic_gb' => round((float) $customer['traffic_gb'] + (float) $arg2, 2)));
        return $this->telegramSendMessage($chatId, !empty($result['ok']) ? ('✅ Traffic updated.

' . $this->telegramCustomerSummaryText($this->store->find('customers', $customer['id']))) : ('Update failed: ' . $result['message']), $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/settraffic') {
        $customer = $this->telegramResolveResellerCustomer($reseller, $arg1);
        if (!$customer || !is_numeric($arg2) || (float) $arg2 <= 0) { return $this->telegramSendMessage($chatId, 'Usage: /settraffic <customer_id> <total_gb>', $this->telegramReplyKeyboardReseller(), true); }
        $result = $this->telegramRunCustomerEdit($reseller, $customer, array('traffic_gb' => round((float) $arg2, 2)));
        return $this->telegramSendMessage($chatId, !empty($result['ok']) ? ('✅ Traffic updated.

' . $this->telegramCustomerSummaryText($this->store->find('customers', $customer['id']))) : ('Update failed: ' . $result['message']), $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/setip') {
        $customer = $this->telegramResolveResellerCustomer($reseller, $arg1);
        if (!$customer || !ctype_digit((string) $arg2)) { return $this->telegramSendMessage($chatId, 'Usage: /setip <customer_id> <limit>', $this->telegramReplyKeyboardReseller(), true); }
        $result = $this->telegramRunCustomerEdit($reseller, $customer, array('ip_limit' => (int) $arg2));
        return $this->telegramSendMessage($chatId, !empty($result['ok']) ? ('✅ IP limit updated.

' . $this->telegramCustomerSummaryText($this->store->find('customers', $customer['id']))) : ('Update failed: ' . $result['message']), $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/setdays') {
        $customer = $this->telegramResolveResellerCustomer($reseller, $arg1);
        if (!$customer || !ctype_digit((string) $arg2)) { return $this->telegramSendMessage($chatId, 'Usage: /setdays <customer_id> <days> [fixed|first_use]', $this->telegramReplyKeyboardReseller(), true); }
        $mode = $arg3 !== '' ? strtolower($arg3) : $this->customerExpirationMode($customer);
        if (!in_array($mode, array('fixed', 'first_use'), true)) { $mode = $this->customerExpirationMode($customer); }
        $result = $this->telegramRunCustomerEdit($reseller, $customer, array('duration_days' => (int) $arg2, 'duration_mode' => $mode));
        return $this->telegramSendMessage($chatId, !empty($result['ok']) ? ('✅ Expiration updated.

' . $this->telegramCustomerSummaryText($this->store->find('customers', $customer['id']))) : ('Update failed: ' . $result['message']), $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/notices') {
        $notices = $this->activeNotices('reseller');
        if (empty($notices)) { return $this->telegramSendMessage($chatId, '📢 No active reseller notices.', $this->telegramReplyKeyboardReseller(), true); }
        $lines = array('📢 Active notices:');
        foreach ($notices as $n) { $lines[] = '• ' . $n['title'] . ': ' . preg_replace('/\s+/', ' ', trim((string) $n['body'])); }
        return $this->telegramSendMessage($chatId, implode("
", $lines), $this->telegramReplyKeyboardReseller(), true);
    }
    return $this->telegramSendMessage($chatId, $this->telegramCommandHelpText($reseller, false), $this->telegramReplyKeyboardReseller(), true);
}

protected function telegramProcessClientCommand($chatId, $userId, $username, $firstName, $text, $customer)
{
    $trimmed = $this->telegramNormalizeMenuCommand($text);
    $parts = preg_split('/\s+/', $trimmed);
    $cmd = strtolower(isset($parts[0]) ? $parts[0] : '');
    $arg1 = isset($parts[1]) ? $parts[1] : '';
    if ($cmd === '/start' || $cmd === '/menu') {
        return $this->telegramSendMessage($chatId, $this->telegramClientMenuText($customer), $this->telegramReplyKeyboardClient(), true);
    }
    if ($cmd === '/status') {
        return $this->telegramSendMessage($chatId, "📊 Account status

" . $this->telegramCustomerSummaryText($customer), $this->telegramReplyKeyboardClient(), true);
    }
    if ($cmd === '/help') {
        return $this->telegramSendMessage($chatId, $this->telegramCommandHelpText(false, true), $this->telegramReplyKeyboardClient(), true);
    }
    if ($cmd === '/sub') {
        return $this->telegramSendSubscriptionInfo($chatId, $customer);
    }
    if ($cmd === '/unbind') {
        $this->telegramUnbindByUser('customer', $userId);
        return $this->telegramSendMessage($chatId, '🔓 Customer binding removed from this Telegram user.', $this->telegramReplyKeyboardGuest(), true);
    }
    if ($cmd === '/client' && $arg1 !== '') {
        $found = $this->telegramCustomerByCode($arg1);
        if (!$found) { return $this->telegramSendMessage($chatId, 'Customer code was not found.', $this->telegramReplyKeyboardGuest(), true); }
        $this->telegramBindEntity('customer', $found['id'], $userId, $chatId, $username, $firstName);
        return $this->telegramSendMessage($chatId, '✅ This chat is now linked to ' . $found['display_name'] . ".

" . $this->telegramCustomerSummaryText($found), $this->telegramReplyKeyboardClient(), true);
    }
    return $this->telegramSendMessage($chatId, $this->telegramCommandHelpText(false, true), $this->telegramReplyKeyboardClient(), true);
}

protected function telegramHandleCallback($callback)
{
    $settings = $this->telegramSettings();
    if (empty($settings['enabled'])) { return; }
    $data = isset($callback['data']) ? (string) $callback['data'] : '';
    $from = isset($callback['from']) ? (array) $callback['from'] : array();
    $chatId = panel_array_get($callback, 'message.chat.id', '');
    $messageId = panel_array_get($callback, 'message.message_id', 0);
    $userId = isset($from['id']) ? (string) $from['id'] : '';
    $reseller = $this->telegramFindResellerByTelegram($userId);
    if (!$reseller) {
        $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', 'Not linked to a reseller account.');
        return;
    }
    if (strpos($data, 'rmenu:') === 0) {
        $action = substr($data, 6);
        $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', 'Opening...');
        if ($action === 'home') {
            $this->telegramEditMessage($chatId, $messageId, $this->telegramMainMenuText($reseller), array('inline_keyboard' => array(
                array(array('text' => '👥 Customers', 'callback_data' => 'rlist:1'), array('text' => '➕ Create', 'callback_data' => 'rcreate:1')),
                array(array('text' => '💳 Balance', 'callback_data' => 'rmenu:balance'), array('text' => '📢 Notices', 'callback_data' => 'rmenu:notices')),
            )));
            return;
        }
        if ($action === 'balance') {
            $this->telegramEditMessage($chatId, $messageId, "💳 Reseller balance and limits

" . $this->telegramResellerSummaryText($reseller), array('inline_keyboard' => array(array(array('text' => '🏠 Menu', 'callback_data' => 'rmenu:home'), array('text' => '👥 Customers', 'callback_data' => 'rlist:1')))));
            return;
        }
        if ($action === 'notices') {
            $notices = $this->activeNotices('reseller');
            $lines = array('📢 Active reseller notices');
            if (empty($notices)) { $lines[] = 'No active reseller notices.'; }
            else { foreach ($notices as $n) { $lines[] = '• ' . $n['title'] . ': ' . preg_replace('/\s+/', ' ', trim((string) $n['body'])); } }
            $this->telegramEditMessage($chatId, $messageId, implode("
", $lines), array('inline_keyboard' => array(array(array('text' => '🏠 Menu', 'callback_data' => 'rmenu:home'), array('text' => '👥 Customers', 'callback_data' => 'rlist:1')))));
            return;
        }
        return;
    }
    if (strpos($data, 'rlist:') === 0) {
        $page = (int) substr($data, 6);
        $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', 'Loading list...');
        $this->telegramSendCustomersPage($chatId, $reseller, $page > 0 ? $page : 1);
        return;
    }
    if (strpos($data, 'rcust:') === 0) {
        $customer = $this->telegramResolveResellerCustomer($reseller, substr($data, 6));
        $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', '');
        if ($customer) {
            $this->telegramEditMessage($chatId, $messageId, $this->telegramCustomerSummaryText($customer), $this->telegramKeyboardCustomerActions($customer, $reseller));
        }
        return;
    }
    if (strpos($data, 'rsync:') === 0) {
        $customer = $this->telegramResolveResellerCustomer($reseller, substr($data, 6));
        $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', 'Syncing...');
        if ($customer) {
            $this->syncCustomerApi($reseller, $customer['id']);
            $fresh = $this->store->find('customers', $customer['id']);
            $this->telegramEditMessage($chatId, $messageId, $this->telegramCustomerSummaryText($fresh), $this->telegramKeyboardCustomerActions($fresh, $reseller));
        }
        return;
    }
    if (strpos($data, 'rsub:') === 0) {
        $customer = $this->telegramResolveResellerCustomer($reseller, substr($data, 5));
        $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', '');
        if ($customer) { $this->telegramSendSubscriptionInfo($chatId, $customer); }
        return;
    }
    if (strpos($data, 'rtoggle:') === 0) {
        $customer = $this->telegramResolveResellerCustomer($reseller, substr($data, 8));
        $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', 'Updating...');
        if ($customer) {
            $this->toggleCustomerApi($reseller, $customer['id']);
            $fresh = $this->store->find('customers', $customer['id']);
            if ($fresh) {
                $this->telegramEditMessage($chatId, $messageId, $this->telegramCustomerSummaryText($fresh), $this->telegramKeyboardCustomerActions($fresh, $reseller));
            }
        }
        return;
    }
    if (strpos($data, 'rdelete:') === 0) {
        $customer = $this->telegramResolveResellerCustomer($reseller, substr($data, 8));
        $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', 'Deleting...');
        if ($customer) {
            $result = $this->deleteCustomerApi($reseller, $customer['id']);
            $this->telegramEditMessage($chatId, $messageId, !empty($result['ok']) ? 'Customer deleted.' : ('Delete failed: ' . $result['message']), null);
        }
        return;
    }
    if (strpos($data, 'rcreate:') === 0) {
        $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', '');
        $this->telegramStateSet($chatId, $userId, 'create_customer', 'name', array());
        $this->telegramSendMessage($chatId, '➕ Send the new customer name.', $this->telegramReplyKeyboardReseller(), true);
        return;
    }
    $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', 'Unknown action.');
}

protected function telegramHandleMessage($message)
{
    $settings = $this->telegramSettings();
    if (empty($settings['enabled'])) { return; }
    $chatId = panel_array_get($message, 'chat.id', '');
    $from = isset($message['from']) ? (array) $message['from'] : array();
    $userId = isset($from['id']) ? (string) $from['id'] : '';
    $username = isset($from['username']) ? (string) $from['username'] : '';
    $firstName = isset($from['first_name']) ? (string) $from['first_name'] : '';
    $text = isset($message['text']) ? (string) $message['text'] : '';
    if ($chatId === '' || $userId === '' || $text === '') { return; }
    $state = $this->telegramStateRecord($chatId);
    if (preg_match('/^\/link\s+([a-zA-Z0-9]+)$/i', $text, $m)) {
        if (empty($settings['allow_reseller'])) {
            return $this->telegramSendMessage($chatId, 'Reseller bot access is disabled.', null, true);
        }
        $reseller = $this->telegramFindResellerByLinkToken($m[1]);
        if (!$reseller) {
            return $this->telegramSendMessage($chatId, 'This link token is invalid or expired.', null, true);
        }
        $this->store->update('resellers', $reseller['id'], array('telegram_user_id' => (string) $userId, 'telegram_chat_id' => (string) $chatId, 'telegram_username' => $username, 'telegram_link_token' => '', 'telegram_link_expires_at' => ''));
        $this->telegramBindEntity('reseller', $reseller['id'], $userId, $chatId, $username, $firstName);
        return $this->telegramSendMessage($chatId, 'Telegram is now linked to reseller ' . $reseller['display_name'] . ".\n\n" . $this->telegramResellerSummaryText($reseller), null, true);
    }
    if (preg_match('/^\/(client|bind)\s+(.+)$/i', $text, $m)) {
        if (empty($settings['allow_client'])) {
            return $this->telegramSendMessage($chatId, 'Client bot access is disabled.', null, true);
        }
        $customer = $this->telegramCustomerByCode(trim((string) $m[2]));
        if (!$customer) {
            return $this->telegramSendMessage($chatId, 'Customer subscription ID, sub ID, UUID, or email was not found.', null, true);
        }
        $this->telegramBindEntity('customer', $customer['id'], $userId, $chatId, $username, $firstName);
        return $this->telegramSendMessage($chatId, 'This chat is now linked to ' . $customer['display_name'] . ".\n\n" . $this->telegramCustomerSummaryText($customer), null, true);
    }
    $reseller = $this->telegramFindResellerByTelegram($userId);
    if ($reseller && !empty($settings['allow_reseller'])) {
        return $this->telegramProcessResellerCommand($chatId, $userId, $username, $firstName, $text, $reseller, $state);
    }
    $customer = $this->telegramFindCustomerByTelegram($userId);
    if ($customer && !empty($settings['allow_client'])) {
        return $this->telegramProcessClientCommand($chatId, $userId, $username, $firstName, $text, $customer);
    }
    return $this->telegramSendMessage($chatId, "👋 Welcome.\nUse /link <token> to link a reseller account, or /client <subscription_key_or_uuid> to link a customer.\n\nYou can also use the quick buttons below.", $this->telegramReplyKeyboardGuest(), true);
}

protected function telegramProcessUpdate($update)
{
    if (!is_array($update)) { return; }
    if (isset($update['callback_query']) && is_array($update['callback_query'])) {
        $this->telegramHandleCallback($update['callback_query']);
        return;
    }
    if (isset($update['message']) && is_array($update['message'])) {
        $this->telegramHandleMessage($update['message']);
        return;
    }
}

protected function telegramWebhook($secret)
{
    $settings = $this->telegramSettings();
    if ((string) $secret !== (string) $settings['webhook_secret']) {
        $this->abort(403, 'Forbidden');
    }
    if ($this->requestMethod === 'GET') {
        $this->sendCommonHeaders('text/plain; charset=utf-8');
        echo 'telegram webhook ready';
        exit;
    }
    $body = file_get_contents('php://input');
    $decoded = json_decode((string) $body, true);
    $this->telegramProcessUpdate(is_array($decoded) ? $decoded : array());
    $this->sendCommonHeaders('application/json; charset=utf-8');
    echo json_encode(array('ok' => true));
    exit;
}

protected function telegramPollRun($timeoutSeconds)
{
    $settings = $this->telegramSettings();
    $timeoutSeconds = max(0, min(55, (int) $timeoutSeconds));
    $payload = array('offset' => $settings['update_offset'], 'timeout' => $timeoutSeconds, 'limit' => $settings['poll_limit']);
    $result = $this->telegramApiRequest('getUpdates', $payload);
    if (empty($result['ok'])) {
        return $result;
    }
    $updates = is_array($result['result']) ? $result['result'] : array();
    $max = $settings['update_offset'];
    foreach ($updates as $update) {
        if (isset($update['update_id'])) {
            $max = max($max, (int) $update['update_id'] + 1);
        }
        $this->telegramProcessUpdate($update);
    }
    $cfg = $this->runtimeConfig();
    $cfg['telegram_update_offset'] = $max;
    $this->store->writeConfig('app', $cfg);
    return array('ok' => true, 'message' => 'Processed ' . count($updates) . ' update(s).', 'count' => count($updates));
}

protected function telegramPollEndpoint($secret)
{
    $settings = $this->telegramSettings();
    if ((string) $secret !== (string) $settings['webhook_secret']) {
        $this->abort(403, 'Forbidden');
    }
    $timeout = isset($_GET['timeout']) ? (int) $_GET['timeout'] : 0;
    $result = $this->telegramPollRun($timeout);
    $this->sendCommonHeaders('application/json; charset=utf-8');
    echo json_encode($result);
    exit;
}

protected function adminTelegramPoll()
{
    $result = $this->telegramPollRun(0);
    $this->flash(!empty($result['ok']) ? 'success' : 'error', isset($result['message']) ? $result['message'] : 'Telegram poll failed.');
    $this->redirect('/admin/settings');
}

protected function adminTelegramSetWebhook()
{
    $result = $this->telegramApiRequest('setWebhook', array('url' => $this->telegramWebhookUrl()));
    $this->flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok']) ? 'Telegram webhook configured.' : ('Telegram webhook setup failed: ' . $result['message']));
    $this->redirect('/admin/settings');
}

protected function adminTelegramDeleteWebhook()
{
    $result = $this->telegramApiRequest('deleteWebhook', array('drop_pending_updates' => false));
    $this->flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok']) ? 'Telegram webhook removed.' : ('Telegram webhook delete failed: ' . $result['message']));
    $this->redirect('/admin/settings');
}



protected function panelSyncExportEndpoint($secret)
{
    $settings = $this->panelSyncSettings();
    if (empty($settings['enabled']) || $settings['mode'] !== 'master' || !$this->panelSyncSecretMatches($secret)) {
        return $this->json(array('ok' => false, 'message' => 'Forbidden.'), 403);
    }
    return $this->json(array(
        'ok' => true,
        'generated_at' => panel_now(),
        'source' => $this->appName(),
        'collections' => $this->buildPanelSyncExportCollections(),
    ), 200);
}

protected function panelSyncRunEndpoint($secret)
{
    if (!$this->panelSyncSecretMatches($secret)) {
        return $this->json(array('ok' => false, 'message' => 'Forbidden.'), 403);
    }
    $result = $this->performRemotePanelSync(false);
    return $this->json($result, !empty($result['ok']) ? 200 : 500);
}

protected function adminRunPanelSync()
{
    $result = $this->performRemotePanelSync(true);
    $this->flash(!empty($result['ok']) ? 'success' : 'error', isset($result['message']) ? $result['message'] : 'Panel sync failed.');
    $this->redirect('/admin/settings');
}

protected function buildPanelSyncExportCollections()
{
    $collections = array(
        'nodes' => array(),
        'templates' => array(),
        'resellers' => array(),
        'customers' => array(),
        'customer_links' => array(),
    );
    foreach ($this->store->all('nodes') as $row) {
        $row['panel_password_plain'] = isset($row['panel_password']) ? $this->decrypt($row['panel_password']) : '';
        unset($row['panel_password']);
        $collections['nodes'][] = $row;
    }
    foreach (array('templates', 'resellers', 'customers', 'customer_links') as $collection) {
        foreach ($this->store->all($collection) as $row) {
            $collections[$collection][] = $row;
        }
    }
    return $collections;
}

protected function performRemotePanelSync($force)
{
    $settings = $this->panelSyncSettings();
    if (empty($settings['enabled']) || $settings['mode'] !== 'slave') {
        return array('ok' => false, 'message' => 'Panel sync is not enabled in slave mode.');
    }
    if ($settings['master_url'] === '') {
        return array('ok' => false, 'message' => 'Master panel URL is empty.');
    }
    $state = $this->panelSyncState();
    $lastRun = !empty($state['last_run_at']) ? strtotime($state['last_run_at']) : 0;
    $interval = max(60, (int) $settings['interval_seconds']);
    if (!$force && $lastRun > 0 && (time() - $lastRun) < $interval) {
        $nextDue = gmdate('c', $lastRun + $interval);
        $state['next_due_at'] = $nextDue;
        $this->writePanelSyncState($state);
        return array('ok' => true, 'skipped' => true, 'message' => 'Skipped. Next sync is due at ' . $nextDue . '.', 'next_due_at' => $nextDue);
    }

    $lockFile = $this->storage . '/locks/panel_sync.lock';
    $lock = @fopen($lockFile, 'c+');
    if (!$lock) {
        return array('ok' => false, 'message' => 'Could not open panel sync lock file.');
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return array('ok' => true, 'skipped' => true, 'message' => 'Panel sync is already running.');
    }

    $result = $this->fetchRemotePanelSyncPayload($settings);
    if (!$result['ok']) {
        flock($lock, LOCK_UN);
        fclose($lock);
        $state['last_run_at'] = panel_now();
        $state['last_status'] = 'error';
        $state['last_message'] = $result['message'];
        $state['next_due_at'] = gmdate('c', time() + $interval);
        $this->writePanelSyncState($state);
        return $result;
    }

    $merge = $this->mergeRemotePanelSyncCollections(isset($result['data']['collections']) ? $result['data']['collections'] : array(), $settings);
    flock($lock, LOCK_UN);
    fclose($lock);

    $state['last_run_at'] = panel_now();
    $state['last_status'] = !empty($merge['ok']) ? 'success' : 'error';
    $state['last_message'] = isset($merge['message']) ? $merge['message'] : 'Panel sync completed.';
    $state['last_counts'] = isset($merge['counts']) ? $merge['counts'] : array();
    $state['next_due_at'] = gmdate('c', time() + $interval);
    $this->writePanelSyncState($state);
    if (!empty($merge['ok'])) {
        $this->log('panel_sync.completed', array('master_url' => $settings['master_url'], 'counts' => $state['last_counts']));
    }
    return $merge;
}

protected function fetchRemotePanelSyncPayload($settings)
{
    $url = rtrim((string) $settings['master_url'], '/') . '/sync/export/' . rawurlencode((string) $settings['shared_secret']);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'User-Agent: XUI-PanelSync/1.0'));
        $this->panelSyncProxyCurlOptions($ch, $settings);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $body === '' || $err !== '') {
            return array('ok' => false, 'message' => 'Could not fetch master sync payload. ' . ($err !== '' ? $err : 'Empty response.'));
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return array('ok' => false, 'message' => 'Master sync response is not valid JSON. HTTP ' . $code . '.');
        }
        if (empty($decoded['ok'])) {
            return array('ok' => false, 'message' => isset($decoded['message']) ? $decoded['message'] : 'Master sync rejected the request.');
        }
        return array('ok' => true, 'data' => $decoded);
    }

    $opts = array('http' => array('method' => 'GET', 'timeout' => 90, 'header' => "Accept: application/json
User-Agent: XUI-PanelSync/1.0
"));
    if (!empty($settings['proxy_enabled']) && !empty($settings['proxy_host']) && !empty($settings['proxy_port']) && in_array($settings['proxy_type'], array('http', 'https'), true)) {
        $opts['http']['proxy'] = 'tcp://' . $settings['proxy_host'] . ':' . (int) $settings['proxy_port'];
        $opts['http']['request_fulluri'] = true;
    }
    $context = stream_context_create($opts);
    $body = @file_get_contents($url, false, $context);
    if ($body === false || $body === '') {
        return array('ok' => false, 'message' => 'Could not fetch master sync payload.');
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return array('ok' => false, 'message' => 'Master sync response is not valid JSON.');
    }
    if (empty($decoded['ok'])) {
        return array('ok' => false, 'message' => isset($decoded['message']) ? $decoded['message'] : 'Master sync rejected the request.');
    }
    return array('ok' => true, 'data' => $decoded);
}

protected function panelSyncProxyCurlOptions(&$ch, $settings)
{
    if (empty($settings['proxy_enabled']) || empty($settings['proxy_host']) || empty($settings['proxy_port'])) {
        return;
    }
    curl_setopt($ch, CURLOPT_PROXY, $settings['proxy_host']);
    curl_setopt($ch, CURLOPT_PROXYPORT, (int) $settings['proxy_port']);
    $ptype = strtolower((string) $settings['proxy_type']);
    if ($ptype === 'socks5') {
        if (defined('CURLPROXY_SOCKS5_HOSTNAME')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        } elseif (defined('CURLPROXY_SOCKS5')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        }
    } else {
        if (defined('CURLPROXY_HTTP')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }
    }
    if (!empty($settings['proxy_username'])) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $settings['proxy_username'] . ':' . $settings['proxy_password']);
    }
}

protected function mergeRemotePanelSyncCollections($collections, $settings)
{
    $counts = array('nodes' => 0, 'templates' => 0, 'resellers' => 0, 'customers' => 0, 'customer_links' => 0, 'deleted' => 0);
    $allowed = array('nodes', 'templates', 'resellers', 'customers', 'customer_links');
    foreach ($allowed as $collection) {
        $seen = array();
        $rows = isset($collections[$collection]) && is_array($collections[$collection]) ? $collections[$collection] : array();
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['id'])) { continue; }
            $id = $this->sanitizeIdentifier($row['id'], 80);
            if ($id === '') { continue; }
            $prepared = $this->preparePanelSyncRecord($collection, $row, $settings);
            if (!$prepared) { continue; }
            $this->store->write($collection, $id, $prepared);
            $seen[$id] = 1;
            $counts[$collection]++;
        }
        if (!empty($settings['prune_missing'])) {
            foreach ($this->store->all($collection) as $local) {
                if (empty($local['synced_from_panel']) || $local['synced_from_panel'] !== 'master') { continue; }
                if (!isset($seen[$local['id']])) {
                    $this->store->delete($collection, $local['id']);
                    $counts['deleted']++;
                }
            }
        }
    }
    return array('ok' => true, 'message' => 'Panel sync completed successfully.', 'counts' => $counts);
}

protected function preparePanelSyncRecord($collection, $row, $settings)
{
    if (!is_array($row) || empty($row['id'])) { return null; }
    if ($collection === 'nodes') {
        $plain = isset($row['panel_password_plain']) ? (string) $row['panel_password_plain'] : '';
        unset($row['panel_password_plain']);
        if ($plain !== '') {
            $row['panel_password'] = $this->encrypt($plain);
        }
    }
    $row['synced_from_panel'] = 'master';
    $row['synced_from_url'] = isset($settings['master_url']) ? (string) $settings['master_url'] : '';
    $row['synced_at'] = panel_now();
    return $row;
}

}
