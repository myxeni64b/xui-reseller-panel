<?php
class XuiAdapter
{
    protected $node;
    protected $storageRoot;
    protected $cookieFile;
    protected $timeout;
    protected $connectTimeout;
    protected $retryAttempts;
    protected $baseUrl;
    protected $panelPath;
    protected $apiBase;
    protected $lastError = '';
    protected $verifyPeer = true;
    protected $verifyHost = 2;
    protected $lastResponseHeaders = array();
    protected $lastStatusCode = 0;
    protected $lastCurlErrno = 0;
    protected $transportProfiles = array();

    public function __construct($node, $storageRoot)
    {
        $this->node = is_array($node) ? $node : array();
        $this->storageRoot = rtrim((string) $storageRoot, '/');
        $this->timeout = isset($this->node['request_timeout']) ? max(5, (int) $this->node['request_timeout']) : 20;
        $this->connectTimeout = isset($this->node['connect_timeout']) ? max(3, (int) $this->node['connect_timeout']) : 8;
        $this->retryAttempts = isset($this->node['retry_attempts']) ? max(1, (int) $this->node['retry_attempts']) : 2;

        $baseUrl = trim(isset($this->node['base_url']) ? (string) $this->node['base_url'] : '');
        $panelPath = trim(isset($this->node['panel_path']) ? (string) $this->node['panel_path'] : '');
        $normalized = $this->normalizePaths($baseUrl, $panelPath);
        $this->baseUrl = $normalized['base_url'];
        $this->panelPath = $normalized['panel_path'];
        $this->apiBase = $this->buildApiBase('/panel/api/inbounds');
        $this->transportProfiles = $this->buildTransportProfiles();

        $cookieDir = $this->storageRoot . '/cache/cookies';
        if (!is_dir($cookieDir)) {
            @mkdir($cookieDir, 0775, true);
        }
        $nodeId = isset($this->node['id']) ? $this->node['id'] : md5($this->baseUrl . '|' . $this->panelPath);
        $this->cookieFile = $cookieDir . '/' . $nodeId . '.cookie';
        $allowInsecure = isset($this->node['allow_insecure_tls']) ? panel_parse_bool($this->node['allow_insecure_tls'], false) : false;
        $this->verifyPeer = !$allowInsecure;
        $this->verifyHost = $allowInsecure ? 0 : 2;
    }

    public function error()
    {
        return $this->lastError;
    }

    public function ping()
    {
        $login = $this->login(true);
        if (!$login['ok']) {
            return $login;
        }
        $status = $this->raw('GET', $this->buildApiBase('/panel/api/server/status'), null, true, false, true);
        if (!$status['ok']) {
            $status = $this->request('GET', '/list', null, true, false);
        }
        if (!$status['ok']) {
            return $status;
        }
        return array(
            'ok' => true,
            'message' => 'Node connection successful.',
            'data' => array(
                'inbounds' => is_array($status['data']) ? $status['data'] : array(),
                'status_code' => $this->lastStatusCode,
            ),
        );
    }

    public function listInbounds()
    {
        return $this->request('GET', '/list', null, true, false);
    }

    public function getInbound($inboundId)
    {
        return $this->request('GET', '/get/' . rawurlencode((string) $inboundId), null, true, false);
    }

    public function addClient($inboundId, $settings)
    {
        $payload = array(
            'id' => (int) $inboundId,
            'settings' => json_encode(array('clients' => array($settings)), JSON_UNESCAPED_SLASHES),
        );
        return $this->request('POST', '/addClient', $payload, true, true);
    }

    public function updateClient($clientId, $inboundId, $settings)
    {
        $payload = array(
            'id' => (int) $inboundId,
            'settings' => json_encode(array('clients' => array($settings)), JSON_UNESCAPED_SLASHES),
        );
        return $this->request('POST', '/updateClient/' . rawurlencode((string) $clientId), $payload, true, true);
    }

    public function deleteClient($inboundId, $clientId, $email)
    {
        $attempts = array(
            array('method' => 'POST', 'path' => '/' . rawurlencode((string) $inboundId) . '/delClient/' . rawurlencode((string) $clientId), 'body' => null),
            array('method' => 'POST', 'path' => '/delClient/' . rawurlencode((string) $clientId), 'body' => array('id' => (int) $inboundId)),
        );
        if ($email !== '') {
            $attempts[] = array('method' => 'POST', 'path' => '/' . rawurlencode((string) $inboundId) . '/delClientByEmail/' . rawurlencode((string) $email), 'body' => null);
        }
        $last = array('ok' => false, 'message' => 'Delete client failed on all known routes.');
        foreach ($attempts as $attempt) {
            $last = $this->request($attempt['method'], $attempt['path'], $attempt['body'], true, true);
            if ($last['ok']) {
                return $last;
            }
        }
        return $last;
    }

    public function getClientTraffic($email)
    {
        return $this->request('GET', '/getClientTraffics/' . rawurlencode((string) $email), null, true, false);
    }

    public function updateClientTraffic($email, $totalBytes, $expiryMillis)
    {
        return $this->request('POST', '/updateClientTraffic/' . rawurlencode((string) $email), array(
            'total' => (float) $totalBytes,
            'expiryTime' => (int) $expiryMillis,
        ), true, true);
    }

    public function getOnlines()
    {
        return $this->request('POST', '/onlines', array(), true, true);
    }

    public function lastOnline($emails)
    {
        return $this->request('POST', '/lastOnline', array('email' => array_values((array) $emails)), true, true);
    }

    public function ensureClientState($inboundId, $clientId, $settings, $email)
    {
        $result = $this->addClient($inboundId, $settings);
        if (!$result['ok']) {
            $check = $this->getClientTraffic($email);
            if ($check['ok']) {
                return array('ok' => true, 'message' => 'Client already exists remotely; traffic lookup succeeded.', 'data' => $check['data'], 'raw' => isset($check['raw']) ? $check['raw'] : array());
            }
            return $result;
        }
        return $result;
    }

    protected function login($force)
    {
        $username = isset($this->node['panel_username']) ? trim((string) $this->node['panel_username']) : '';
        $password = isset($this->node['panel_password_plain']) ? (string) $this->node['panel_password_plain'] : '';
        if ($username === '' || $password === '') {
            return array('ok' => false, 'message' => 'Node credentials are missing.');
        }

        $metaFile = $this->cookieFile . '.meta';
        if (!$force && is_file($this->cookieFile) && is_file($metaFile)) {
            $meta = panel_safe_json_decode(@file_get_contents($metaFile));
            if (!empty($meta['logged_at']) && (time() - (int) $meta['logged_at'] < 900)) {
                return array('ok' => true, 'message' => 'Existing node session reused.');
            }
        }

        $urls = $this->loginUrls();
        $last = array('ok' => false, 'message' => 'Node login failed.');
        foreach ($urls as $url) {
            $last = $this->raw('POST', $url, array('username' => $username, 'password' => $password), true, true, true);
            if ($last['ok']) {
                @file_put_contents($metaFile, json_encode(array('logged_at' => time(), 'url' => $url), JSON_UNESCAPED_SLASHES));
                return $last;
            }
        }
        if (!empty($urls)) {
            $last['message'] .= ' Tried: ' . implode(', ', $urls);
        }
        return $last;
    }

    protected function request($method, $path, $body, $decodeJson, $allowForm)
    {
        $login = $this->login(false);
        if (!$login['ok']) {
            $login = $this->login(true);
            if (!$login['ok']) {
                return $login;
            }
        }

        $result = $this->raw($method, $this->apiBase . $path, $body, $decodeJson, $allowForm, true);
        if (!$result['ok'] && ($this->lastStatusCode === 401 || $this->lastStatusCode === 403)) {
            $login = $this->login(true);
            if ($login['ok']) {
                $result = $this->raw($method, $this->apiBase . $path, $body, $decodeJson, $allowForm, true);
            }
        }
        return $result;
    }

    protected function raw($method, $url, $body, $decodeJson, $allowForm, $attachCookies)
    {
        if (!function_exists('curl_init')) {
            return array('ok' => false, 'message' => 'cURL is required on the server.');
        }

        $method = strtoupper((string) $method);
        $attempt = 0;
        $last = array('ok' => false, 'message' => 'Unknown request failure.');

        while ($attempt < $this->retryAttempts) {
            $attempt++;
            $profiles = $this->transportProfiles;
            foreach ($profiles as $profile) {
                $result = $this->execCurlProfile($method, $url, $body, $decodeJson, $allowForm, $attachCookies, $profile);
                $last = $result;
                if ($result['ok']) {
                    return $result;
                }

                $errno = $this->lastCurlErrno;
                $httpCode = $this->lastStatusCode;
                $transportRetryErrnos = array(6, 7, 28, 35, 52, 56);
                $retryNextProfile = ($errno > 0 && in_array($errno, $transportRetryErrnos, true));

                // Do not hide a valid application-level response (for example,
                // a missing-client message from 3x-ui) behind a later transport
                // failure on another address family such as IPv6.
                if (!$retryNextProfile) {
                    return $result;
                }

                // If a transport profile reached the server and got an HTTP
                // response, keep that result instead of masking it with a later
                // address-family retry.
                if ($errno === 0 && $httpCode > 0) {
                    return $result;
                }
            }

            if ($attempt >= $this->retryAttempts) {
                break;
            }
            usleep(250000 * $attempt);
        }

        return $last;
    }

    protected function execCurlProfile($method, $url, $body, $decodeJson, $allowForm, $attachCookies, $profile)
    {
        $headers = array(
            'Accept: application/json, text/plain, */*',
            'X-Requested-With: XMLHttpRequest',
            'Expect:',
        );
        if ($this->panelPath !== '') {
            $headers[] = 'Referer: ' . $this->baseUrl . $this->panelPath . '/';
            $headers[] = 'Origin: ' . $this->baseUrl;
        }

        $raw = '';
        $code = 0;
        $err = '';
        $errno = 0;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifyPeer);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifyHost);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, array($this, 'captureHeader'));
        curl_setopt($ch, CURLOPT_USERAGENT, 'XUI-Reseller-Panel/1.2');
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);
        if (defined('CURLOPT_ENCODING')) {
            @curl_setopt($ch, CURLOPT_ENCODING, '');
        }
        if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        if (defined('CURL_HTTP_VERSION_1_1')) {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        }
        if (defined('CURLOPT_SSL_ENABLE_ALPN')) {
            @curl_setopt($ch, CURLOPT_SSL_ENABLE_ALPN, false);
        }
        if (defined('CURLOPT_SSL_ENABLE_NPN')) {
            @curl_setopt($ch, CURLOPT_SSL_ENABLE_NPN, false);
        }
        if (!empty($profile['ipresolve']) && defined('CURLOPT_IPRESOLVE')) {
            @curl_setopt($ch, CURLOPT_IPRESOLVE, $profile['ipresolve']);
        }

        if ($attachCookies) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if ($method !== 'GET' && $body !== null) {
            if ($allowForm) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query((array) $body));
                $headers[] = 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8';
            } else {
                $payload = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                $headers[] = 'Content-Type: application/json; charset=UTF-8';
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $this->lastResponseHeaders = array();
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $errno = (int) curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->lastStatusCode = $code;
        $this->lastCurlErrno = $errno;

        if ($raw === false || $err !== '') {
            $transportLabel = isset($profile['label']) ? $profile['label'] : 'default';
            $this->lastError = $err !== '' ? $err : 'Unknown cURL error';
            $result = array(
                'ok' => false,
                'message' => 'Node request failed [' . $transportLabel . ']: ' . $this->lastError,
                'curl_errno' => $errno,
                'status_code' => $code,
            );
            $this->logRequestResult('error', $method, $url, $body, $result['message'], $code, $errno);
            return $result;
        }

        $result = $decodeJson ? $this->decodeResponse($raw, $code) : array(
            'ok' => $code >= 200 && $code < 300,
            'message' => $code >= 200 && $code < 300 ? 'OK' : 'HTTP ' . $code,
            'data' => array('raw' => $raw),
            'raw' => $raw,
        );
        $result['curl_errno'] = $errno;
        $result['status_code'] = $code;
        $this->logRequestResult(!empty($result['ok']) ? 'access' : 'error', $method, $url, $body, isset($result['message']) ? $result['message'] : '', $code, $errno);
        return $result;
    }

    protected function normalizePaths($baseUrl, $panelPath)
    {
        $baseUrl = rtrim((string) $baseUrl, '/');
        $panelPath = trim((string) $panelPath);
        if ($panelPath === '/' || strtolower($panelPath) === '/login') {
            $panelPath = '';
        }
        if ($panelPath !== '') {
            if ($panelPath[0] !== '/') {
                $panelPath = '/' . $panelPath;
            }
            $panelPath = rtrim($panelPath, '/');
        }

        $parts = @parse_url($baseUrl);
        if (is_array($parts) && isset($parts['scheme']) && isset($parts['host'])) {
            $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
            if ($path === '/') {
                $path = '';
            }
            if ($panelPath !== '' && $path !== '' && strtolower($path) === strtolower($panelPath)) {
                $panelPath = '';
            } elseif ($panelPath === '' && $path !== '' && strtolower(substr($path, -6)) === '/panel') {
                $panelPath = '';
            }
            $baseUrl = $parts['scheme'] . '://' . $parts['host'];
            if (isset($parts['port'])) {
                $baseUrl .= ':' . $parts['port'];
            }
            $baseUrl .= $path;
        }

        return array('base_url' => rtrim($baseUrl, '/'), 'panel_path' => $panelPath);
    }

    protected function loginUrls()
    {
        $urls = array();
        if ($this->panelPath !== '') {
            $urls[] = $this->baseUrl . $this->panelPath . '/login';
        }
        $urls[] = $this->baseUrl . '/login';
        $urls = array_values(array_unique(array_filter($urls)));
        return $urls;
    }

    protected function buildApiBase($suffix)
    {
        $suffix = '/' . ltrim((string) $suffix, '/');
        return $this->baseUrl . $this->panelPath . $suffix;
    }

    protected function buildTransportProfiles()
    {
        $profiles = array(
            array('label' => 'default', 'ipresolve' => 0),
        );
        if (defined('CURL_IPRESOLVE_V4')) {
            $profiles[] = array('label' => 'ipv4', 'ipresolve' => CURL_IPRESOLVE_V4);
        }
        if (defined('CURL_IPRESOLVE_V6')) {
            $profiles[] = array('label' => 'ipv6', 'ipresolve' => CURL_IPRESOLVE_V6);
        }
        return $profiles;
    }

    protected function decodeResponse($raw, $code)
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $contentType = isset($this->lastResponseHeaders['content-type']) ? (is_array($this->lastResponseHeaders['content-type']) ? implode(', ', $this->lastResponseHeaders['content-type']) : $this->lastResponseHeaders['content-type']) : '';
            $location = isset($this->lastResponseHeaders['location']) ? (is_array($this->lastResponseHeaders['location']) ? implode(', ', $this->lastResponseHeaders['location']) : $this->lastResponseHeaders['location']) : '';
            $snippet = trim(preg_replace('/\s+/', ' ', strip_tags(substr((string) $raw, 0, 260))));
            $message = 'Received non-JSON response.';
            if ($contentType !== '') {
                $message .= ' Content-Type: ' . $contentType . '.';
            }
            if ($location !== '') {
                $message .= ' Redirect: ' . $location . '.';
            }
            if ($snippet !== '') {
                $message .= ' Snippet: ' . $snippet;
            }
            return array(
                'ok' => false,
                'message' => $message,
                'data' => array('raw' => $raw),
                'raw' => $raw,
            );
        }

        $ok = $code >= 200 && $code < 300;
        if (isset($decoded['success'])) {
            $ok = (bool) $decoded['success'];
        } elseif (isset($decoded['status'])) {
            $status = strtolower((string) $decoded['status']);
            if (in_array($status, array('success', 'ok'), true)) {
                $ok = true;
            } elseif (in_array($status, array('error', 'fail', 'failed'), true)) {
                $ok = false;
            }
        }

        $message = '';
        if (isset($decoded['msg']) && $decoded['msg'] !== '') {
            $message = (string) $decoded['msg'];
        } elseif (isset($decoded['message']) && $decoded['message'] !== '') {
            $message = (string) $decoded['message'];
        } else {
            $message = $ok ? 'Request successful.' : 'Request failed.';
        }

        $data = isset($decoded['obj']) ? $decoded['obj'] : $decoded;
        return array(
            'ok' => $ok,
            'message' => $message,
            'data' => $data,
            'raw' => $decoded,
        );
    }


    protected function appendLog($name, $row)
    {
        $dir = $this->storageRoot . '/logs';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $file = $dir . '/' . $name . '.log';
        if (is_file($file) && @filesize($file) > 512 * 1024) {
            for ($i = 4; $i >= 1; $i--) {
                $src = $file . '.' . $i;
                $dst = $file . '.' . ($i + 1);
                if (is_file($src)) { @rename($src, $dst); }
            }
            @rename($file, $file . '.1');
        }
        @file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }

    protected function logRequestResult($level, $method, $url, $body, $message, $code, $errno)
    {
        $name = $level === 'error' ? 'xui_error' : 'xui_access';
        $payload = is_array($body) ? array_keys($body) : (is_string($body) && $body !== '' ? 'raw' : '');
        $this->appendLog($name, array(
            'time' => panel_now(),
            'channel' => 'xui',
            'level' => $level,
            'node_id' => isset($this->node['id']) ? $this->node['id'] : '',
            'node_title' => isset($this->node['title']) ? $this->node['title'] : '',
            'method' => $method,
            'url' => $url,
            'status_code' => (int) $code,
            'curl_errno' => (int) $errno,
            'message' => (string) $message,
            'payload_keys' => $payload,
        ));
    }

    protected function captureHeader($ch, $headerLine)
    {
        $len = strlen($headerLine);
        $header = trim($headerLine);
        if ($header === '' || strpos($header, ':') === false) {
            return $len;
        }
        list($name, $value) = explode(':', $header, 2);
        $name = strtolower(trim($name));
        $value = trim($value);
        if (!isset($this->lastResponseHeaders[$name])) {
            $this->lastResponseHeaders[$name] = $value;
        } else {
            if (!is_array($this->lastResponseHeaders[$name])) {
                $this->lastResponseHeaders[$name] = array($this->lastResponseHeaders[$name]);
            }
            $this->lastResponseHeaders[$name][] = $value;
        }
        return $len;
    }
}
