<form method="post" class="stack-form card">
  <label>Customer Name<input type="text" name="display_name" value="<?php echo panel_e($record['display_name']); ?>"></label>
  <div class="grid two-col form-grid-tight">
    <label>Phone Number <small class="label-hint">Optional &middot; digits only</small>
      <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="20" name="phone" value="<?php echo panel_e(isset($record['phone']) ? $record['phone'] : ''); ?>" placeholder="Leave blank to disable /get access">
    </label>
    <label><?php echo $mode === 'edit' ? 'Access PIN' : 'Access PIN'; ?> <small class="label-hint"><?php echo $mode === 'edit' ? 'Optional &middot; leave blank to keep current PIN' : 'Optional &middot; set together with phone'; ?></small>
      <input type="text" maxlength="6" name="access_pin" value="" placeholder="1 to 6 letters or numbers">
    </label>
  </div>
  <label>Server / Inbound Template
    <select name="template_id">
      <option value="">Select one</option>
      <?php foreach ($templates as $tpl): $node = isset($node_map[$tpl['node_id']]) ? $node_map[$tpl['node_id']] : null; ?>
        <option value="<?php echo panel_e($tpl['id']); ?>" <?php echo $record['template_id'] === $tpl['id'] ? 'selected' : ''; ?>><?php echo panel_e($tpl['public_label']); ?> - <?php echo panel_e($node ? $node['title'] : 'Unknown'); ?> / <?php echo panel_e($tpl['inbound_name']); ?> (<?php echo panel_e($tpl['protocol']); ?><?php echo !empty($tpl['port']) ? ' :' . panel_e($tpl['port']) : ''; ?>)</option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Initial Traffic GB<input type="number" step="0.01" min="<?php echo (!empty($record['id']) && !empty($reseller['restrict'])) ? panel_e(panel_format_gb($record['traffic_gb'])) : '0.1'; ?>" name="traffic_gb" value="<?php echo panel_e($record['traffic_gb']); ?>"></label>
  <label>IP Limit<input type="number" min="<?php echo !empty($max_ip_limit) ? '1' : '0'; ?>" <?php echo !empty($max_ip_limit) ? 'max="' . panel_e($max_ip_limit) . '"' : ''; ?> name="ip_limit" value="<?php echo panel_e(isset($record['ip_limit']) ? $record['ip_limit'] : (!empty($max_ip_limit) ? 1 : 0)); ?>"></label>
  <label>Expiration Days<input type="number" min="<?php echo !empty($max_expiration_days) ? '1' : '0'; ?>" <?php echo !empty($max_expiration_days) ? 'max="' . panel_e($max_expiration_days) . '"' : ''; ?> name="duration_days" value="<?php echo panel_e(isset($record['duration_days']) ? $record['duration_days'] : (!empty($max_expiration_days) ? $max_expiration_days : 0)); ?>"></label>
  <label>Expiration Mode
    <select name="duration_mode">
      <option value="fixed" <?php echo (!isset($record['duration_mode']) || $record['duration_mode'] === 'fixed') ? 'selected' : ''; ?>>Fixed from now</option>
      <option value="first_use" <?php echo (isset($record['duration_mode']) && $record['duration_mode'] === 'first_use') ? 'selected' : ''; ?>>Start after first connection</option>
    </select>
  </label>
  <label>Status<select name="status"><option value="active" <?php echo $record['status'] === 'active' ? 'selected' : ''; ?>>Active</option><?php if (empty($reseller['restrict'])): ?><option value="disabled" <?php echo $record['status'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option><?php endif; ?></select></label>
  <label>Notes<textarea name="notes"><?php echo panel_e($record['notes']); ?></textarea></label>
  <div class="muted-box">Reseller credit left: <strong><?php echo panel_e(panel_format_gb($reseller['credit_gb'])); ?> GB</strong> &middot; Max expiration: <strong><?php echo !empty($max_expiration_days) ? panel_e($max_expiration_days) . ' days' : 'Unlimited (0 allowed)'; ?></strong> &middot; Max user IP limit: <strong><?php echo !empty($max_ip_limit) ? panel_e($max_ip_limit) : 'Unlimited (0 allowed)'; ?></strong> &middot; Public config lookup: <strong><?php echo panel_e($app->appLink('/get')); ?></strong> &middot; Phone + PIN are optional. Only customers with both values set can access <code class="inline-code">/get</code>. &middot; One user = one selected server/inbound template. &middot; Expiration mode can be fixed from now or start after first connection.<?php if (!empty($reseller['restrict'])): ?> &middot; <strong>Restricted mode:</strong> delete and disable are blocked and traffic can only be increased.<?php endif; ?></div>
  <?php if (!empty($errors)): ?><div class="form-errors"><?php foreach ($errors as $group): foreach ($group as $err): ?><div><?php echo panel_e($err); ?></div><?php endforeach; endforeach; ?></div><?php endif; ?>
  <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
  <button class="btn btn-primary" type="submit"><?php echo $mode === 'edit' ? 'Save Changes' : 'Create Customer'; ?></button>
</form>
