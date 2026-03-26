<div class="card public-card">
  <h1><?php echo panel_e($customer['display_name']); ?></h1>
  <p>Status: <span class="badge <?php echo $customer['status'] === 'active' ? 'good' : 'bad'; ?>"><?php echo panel_e($customer['status']); ?></span></p>
  <p>Traffic left: <strong><?php echo panel_e(panel_format_gb(panel_to_gb_from_bytes($customer['traffic_bytes_left']))); ?> GB</strong></p>
  <p>Expires at: <strong><?php echo panel_e($customer['expires_at']); ?></strong></p>
  <p>Inbound: <strong><?php echo panel_e($template ? $template['inbound_name'] : 'Unknown'); ?></strong></p>
  <p>Server: <strong><?php echo panel_e($node ? $node['title'] : 'Unknown'); ?></strong></p>
  <?php $primarySubscriptionUrl = !empty($proxy_subscription_url) ? $proxy_subscription_url : $app->url('/user/' . $customer['subscription_key']); $fallbackSubscriptionUrl = !empty($proxy_subscription_url) ? $app->url('/user/' . $customer['subscription_key']) : ''; ?>
  <p>Primary subscription URL: <strong><?php echo panel_e($primarySubscriptionUrl); ?></strong></p>
  <?php if ($fallbackSubscriptionUrl !== ''): ?><p>Fallback subscription URL: <strong><?php echo panel_e($fallbackSubscriptionUrl); ?></strong></p><?php endif; ?>
  <div class="public-actions actions">
    <a class="btn btn-primary" href="<?php echo panel_e($app->url('/user/' . $customer['subscription_key'] . '/export')); ?>">Export all links</a>
    <button class="btn" type="button" data-copy="<?php echo panel_e(implode("\n", $configs)); ?>">Copy all</button>
    <button class="btn" type="button" data-copy="<?php echo panel_e($primarySubscriptionUrl); ?>">Copy Primary URL</button>
    <?php if ($fallbackSubscriptionUrl !== ''): ?><button class="btn" type="button" data-copy="<?php echo panel_e($fallbackSubscriptionUrl); ?>">Copy Fallback URL</button><?php endif; ?>
  </div>
  <textarea rows="10" class="full-text" readonly><?php echo panel_e(implode("\n", $configs)); ?></textarea>
</div>
