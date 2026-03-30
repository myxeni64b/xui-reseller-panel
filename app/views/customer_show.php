<div class="grid two-col">
  <div class="card">
    <h3><?php echo panel_e($customer['display_name']); ?></h3>
    <p><strong>System Name:</strong> <?php echo panel_e($customer['system_name']); ?></p>
    <p><strong>Phone:</strong> <?php echo panel_e(isset($customer['phone']) ? $customer['phone'] : '-'); ?></p>
    <p><strong>Client Config Access URL:</strong> <code><?php echo panel_e($app->appLink('/get')); ?></code></p>
    <p><strong>Server:</strong> <?php echo panel_e($node ? $node['title'] : 'Unknown'); ?></p>
    <p><strong>Inbound:</strong> <?php echo panel_e($template ? $template['inbound_name'] : 'Unknown'); ?></p>
    <p><strong>Traffic:</strong> <?php echo panel_e(panel_format_gb($customer['traffic_gb'])); ?> GB</p>
    <p><strong>Used:</strong> <?php echo panel_e(panel_format_gb(panel_to_gb_from_bytes($customer['traffic_bytes_used']))); ?> GB</p>
    <p><strong>Left:</strong> <?php echo panel_e(panel_format_gb(panel_to_gb_from_bytes($customer['traffic_bytes_left']))); ?> GB</p>
    <p><strong>Expires:</strong> <?php echo panel_e($app->customerExpirationLabel($customer)); ?></p>
    <p><strong>Expiration Mode:</strong> <?php echo $app->customerExpirationMode($customer) === 'first_use' ? 'Start after first connection' : 'Fixed from now'; ?></p>
    <p><strong>Last Sync:</strong> <?php echo panel_e(isset($customer['last_synced_at']) ? $customer['last_synced_at'] : '-'); ?></p>
    <?php $runtimeStatus = $app->customerRuntimeStatusLabel($customer); $runtimeBadge = $app->customerRuntimeStatusBadgeClass($customer); $rawStatus = isset($customer['status']) ? strtolower(trim((string) $customer['status'])) : 'active'; ?>
    <p><strong>Status:</strong> <span class="badge <?php echo panel_e($runtimeBadge); ?>"><?php echo panel_e($runtimeStatus); ?></span><?php if ($rawStatus !== strtolower($runtimeStatus)): ?> <span class="muted">(local: <?php echo panel_e($rawStatus); ?>)</span><?php endif; ?></p>
    <?php if (!empty($customer['last_error'])): ?><div class="alert alert-error"><?php echo panel_e($customer['last_error']); ?></div><?php endif; ?>
  </div>
  <div class="card">
    <h3>Subscription</h3>
    <p><strong>Access ID / Secret:</strong> <code><?php echo panel_e($customer['uuid']); ?></code></p>
    <?php $primarySubscriptionUrl = !empty($proxy_subscription_url) ? $proxy_subscription_url : $subscription_url; $fallbackSubscriptionUrl = !empty($proxy_subscription_url) ? $subscription_url : ''; ?>
    <p><strong>Primary URL:</strong> <code><?php echo panel_e($primarySubscriptionUrl); ?></code></p>
    <?php if ($fallbackSubscriptionUrl !== ''): ?><p><strong>Fallback URL:</strong> <code><?php echo panel_e($fallbackSubscriptionUrl); ?></code></p><?php endif; ?>
    <div class="actions">
      <button class="btn" type="button" data-copy="<?php echo panel_e($primarySubscriptionUrl); ?>">Copy Primary URL</button>
      <?php if ($fallbackSubscriptionUrl !== ''): ?><button class="btn" type="button" data-copy="<?php echo panel_e($fallbackSubscriptionUrl); ?>">Copy Fallback URL</button><?php endif; ?>
      <a class="btn" target="_blank" href="<?php echo panel_e($app->url('/user/' . $customer['subscription_key'])); ?>">Open Public Page</a>
      <a class="btn" target="_blank" href="<?php echo panel_e($app->url('/get')); ?>">Open /get Lookup</a>
      <a class="btn btn-primary" target="_blank" href="<?php echo panel_e($app->url('/user/' . $customer['subscription_key'] . '/export')); ?>">Export Links</a>
    </div>
    <?php if ($link): ?>
      <hr>
      <p><strong>Remote Email:</strong> <?php echo panel_e($link['remote_email']); ?></p>
      <p><strong>Remote Client ID:</strong> <?php echo panel_e($link['remote_client_id']); ?></p>
      <p><strong>Remote Sub ID:</strong> <?php echo panel_e($link['remote_sub_id']); ?></p>
    <?php endif; ?>
  </div>
</div>
