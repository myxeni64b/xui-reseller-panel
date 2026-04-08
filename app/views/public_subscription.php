<div class="card public-card">
  <h1><?php echo panel_e($customer['display_name']); ?></h1>
  <?php $runtimeStatus = $app->customerRuntimeStatusLabel($customer); $runtimeBadge = $app->customerRuntimeStatusBadgeClass($customer); $rawStatus = isset($customer['status']) ? strtolower(trim((string) $customer['status'])) : 'active'; ?>
  <p class="muted">Last sync: <?php echo panel_e($app->customerLastSyncAgo($customer)); ?></p>
  <p>Status: <span class="badge <?php echo panel_e($runtimeBadge); ?>"><?php echo panel_e($runtimeStatus); ?></span><?php if ($rawStatus !== strtolower($runtimeStatus)): ?> <span class="muted">(local: <?php echo panel_e($rawStatus); ?>)</span><?php endif; ?></p>
  <?php if ($runtimeStatus !== 'Active'): ?>
  <div class="alert alert-info">
    <?php if ($runtimeStatus === 'Removed'): ?>This customer was removed from the remote 3x-ui server and is kept here only for record and visibility.<?php elseif ($runtimeStatus === 'Ended'): ?>This customer validity period has ended.<?php elseif ($runtimeStatus === 'Depleted'): ?>This customer traffic quota is fully used.<?php else: ?>This customer is not currently active.<?php endif; ?>
  </div>
  <?php endif; ?>
  <p>Traffic left: <strong><?php echo panel_e(panel_format_gb(panel_to_gb_from_bytes($customer['traffic_bytes_left']))); ?> GB</strong></p>
  <p>Expires at: <strong><?php echo panel_e($app->customerExpirationLabel($customer)); ?></strong></p>
  <p>Inbound: <strong><?php echo panel_e($template ? $template['inbound_name'] : 'Unknown'); ?></strong></p>
  <p>Server: <strong><?php echo panel_e($node ? $node['title'] : 'Unknown'); ?></strong></p>
  <?php $primarySubscriptionUrl = !empty($proxy_subscription_url) ? $proxy_subscription_url : $app->appLink('/user/' . $customer['subscription_key']); $fallbackSubscriptionUrl = !empty($proxy_subscription_url) ? $app->appLink('/user/' . $customer['subscription_key']) : ''; ?>
  <p>Primary subscription URL: <strong><?php echo panel_e($primarySubscriptionUrl); ?></strong></p>
  <?php if ($fallbackSubscriptionUrl !== ''): ?><p>Fallback subscription URL: <strong><?php echo panel_e($fallbackSubscriptionUrl); ?></strong></p><?php endif; ?>
  <div class="public-actions actions">
    <a class="btn btn-primary" href="<?php echo panel_e($app->url('/user/' . $customer['subscription_key'] . '/export')); ?>">Export all links</a>
    <button class="btn" type="button" data-copy="<?php echo panel_e(implode("
", $configs)); ?>">Copy all</button>
    <button class="btn" type="button" data-copy="<?php echo panel_e($primarySubscriptionUrl); ?>">Copy Primary URL</button>
    <?php if ($fallbackSubscriptionUrl !== ''): ?><button class="btn" type="button" data-copy="<?php echo panel_e($fallbackSubscriptionUrl); ?>">Copy Fallback URL</button><?php endif; ?>
  </div>
  <div class="config-grid" style="margin-bottom:16px">
    <div class="config-card">
      <div class="card-head config-head"><h4>Primary Subscription QR</h4><button class="btn" type="button" data-copy="<?php echo panel_e($primarySubscriptionUrl); ?>">Copy URL</button></div>
      <?php $primaryQr = $app->qrImageUrl($primarySubscriptionUrl); if ($primaryQr !== ''): ?>
      <div class="config-qr-wrap"><img class="config-qr" src="<?php echo panel_e($primaryQr); ?>" alt="Primary subscription QR"></div>
      <?php endif; ?>
      <textarea rows="3" class="full-text" readonly><?php echo panel_e($primarySubscriptionUrl); ?></textarea>
    </div>
    <?php if ($fallbackSubscriptionUrl !== ''): ?>
    <div class="config-card">
      <div class="card-head config-head"><h4>Fallback Subscription QR</h4><button class="btn" type="button" data-copy="<?php echo panel_e($fallbackSubscriptionUrl); ?>">Copy URL</button></div>
      <?php $fallbackQr = $app->qrImageUrl($fallbackSubscriptionUrl); if ($fallbackQr !== ''): ?>
      <div class="config-qr-wrap"><img class="config-qr" src="<?php echo panel_e($fallbackQr); ?>" alt="Fallback subscription QR"></div>
      <?php endif; ?>
      <textarea rows="3" class="full-text" readonly><?php echo panel_e($fallbackSubscriptionUrl); ?></textarea>
    </div>
    <?php endif; ?>
  </div>
  <textarea rows="10" class="full-text" readonly><?php echo panel_e(implode("
", $configs)); ?></textarea>
</div>
