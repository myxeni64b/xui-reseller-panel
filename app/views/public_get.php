<div class="card public-card">
  <h1>Get Your Configs</h1>
  <p class="muted-box">Enter your phone number and PIN to view all matching client subscriptions, configs, usage, and QR codes. Only customers that have phone + PIN access enabled can use this page.</p>
  <form method="post" class="stack-form public-get-form">
    <div class="grid two-col form-grid-tight">
      <label>Phone Number
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="20" name="phone" value="<?php echo panel_e($phone); ?>" placeholder="Only numbers">
      </label>
      <label>PIN
        <input type="password" maxlength="6" name="pin" value="" placeholder="1 to 6 letters or numbers">
      </label>
    </div>
    <?php if (!empty($errors)): ?><div class="form-errors"><?php foreach ($errors as $group): foreach ($group as $err): ?><div><?php echo panel_e($err); ?></div><?php endforeach; endforeach; ?></div><?php endif; ?>
    <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
    <div class="actions">
      <button class="btn btn-primary" type="submit">Show My Configs</button>
      <a class="btn" href="<?php echo panel_e($app->url('/get')); ?>">Reset</a>
    </div>
  </form>
</div>

<?php foreach ($entries as $entry):
  $customer = $entry['customer'];
  $template = $entry['template'];
  $node = $entry['node'];
  $configs = isset($entry['configs']) ? (array) $entry['configs'] : array();
?>
<div class="card public-card access-card">
  <div class="card-head"><h3><?php echo panel_e($customer['display_name']); ?></h3><span class="badge <?php echo $customer['status'] === 'active' ? 'good' : 'bad'; ?>"><?php echo panel_e($customer['status']); ?></span></div>
  <div class="grid two-col access-summary">
    <div>
      <p><strong>Server:</strong> <?php echo panel_e($node ? $node['title'] : 'Unknown'); ?></p>
      <p><strong>Inbound:</strong> <?php echo panel_e($template ? $template['inbound_name'] : 'Unknown'); ?></p>
      <p><strong>Used:</strong> <?php echo panel_e(panel_format_gb(panel_to_gb_from_bytes(isset($customer['traffic_bytes_used']) ? $customer['traffic_bytes_used'] : 0))); ?> GB</p>
      <p><strong>Left:</strong> <?php echo panel_e(panel_format_gb(panel_to_gb_from_bytes(isset($customer['traffic_bytes_left']) ? $customer['traffic_bytes_left'] : 0))); ?> GB</p>
      <p><strong>Expires:</strong> <?php echo panel_e($app->customerExpirationLabel($customer)); ?></p>
    </div>
    <div>
      <p><strong>Primary subscription URL</strong></p>
      <div class="sub-line"><code><?php echo panel_e($entry['primary_subscription_url']); ?></code></div>
      <div class="actions compact-actions">
        <button class="btn" type="button" data-copy="<?php echo panel_e($entry['primary_subscription_url']); ?>">Copy Primary URL</button>
        <a class="btn" target="_blank" href="<?php echo panel_e($app->url('/user/' . $customer['subscription_key'] . '/export')); ?>">Export Text</a>
      </div>
      <?php if (!empty($entry['fallback_subscription_url'])): ?>
        <p style="margin-top:12px"><strong>Fallback URL</strong></p>
        <div class="sub-line"><code><?php echo panel_e($entry['fallback_subscription_url']); ?></code></div>
        <div class="actions compact-actions">
          <button class="btn" type="button" data-copy="<?php echo panel_e($entry['fallback_subscription_url']); ?>">Copy Fallback URL</button>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($configs)): ?>
    <div class="config-grid">
      <?php foreach ($configs as $i => $config): ?>
        <div class="config-card">
          <div class="card-head config-head"><h4>Config <?php echo (int) ($i + 1); ?></h4><button class="btn" type="button" data-copy="<?php echo panel_e($config); ?>">Copy Config</button></div>
          <textarea rows="5" class="full-text" readonly><?php echo panel_e($config); ?></textarea>
          <?php $qrUrl = $app->qrImageUrl($config); ?>
          <?php if ($qrUrl !== ''): ?>
            <div class="config-qr-wrap">
              <img class="config-qr" src="<?php echo panel_e($qrUrl); ?>" alt="QR code for config <?php echo (int) ($i + 1); ?>" loading="lazy" onerror="this.style.display='none'; if(this.nextElementSibling){this.nextElementSibling.style.display='block';}">
              <div class="qr-fallback-note" style="display:none">QR preview unavailable. Use the copy button.</div>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="alert alert-info">No configs could be built for this client yet.</div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
