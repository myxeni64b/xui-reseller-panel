<form method="post" class="stack-form card">
  <label>Username<input type="text" name="username" value="<?php echo panel_e($record['username']); ?>"></label>
  <label>Display Name<input type="text" name="display_name" value="<?php echo panel_e($record['display_name']); ?>"></label>
  <label>Password<input type="password" name="password"></label>
  <label>Prefix<input type="text" name="prefix" value="<?php echo panel_e($record['prefix']); ?>"></label>
  <label>Credit GB<input type="number" step="0.01" min="0" name="credit_gb" value="<?php echo panel_e($record['credit_gb']); ?>"></label>
  <label>Default Duration Days<input type="number" min="1" name="fixed_duration_days" value="<?php echo panel_e($record['fixed_duration_days']); ?>"></label>
  <label>Max Expiration Days Allowed<input type="number" min="0" name="max_expiration_days" value="<?php echo panel_e(isset($record['max_expiration_days']) ? $record['max_expiration_days'] : $record['fixed_duration_days']); ?>"></label>
  <label>Max User IP Limit Allowed<input type="number" min="0" name="max_ip_limit" value="<?php echo panel_e(isset($record['max_ip_limit']) ? $record['max_ip_limit'] : 0); ?>"></label>
  <label>Telegram User ID<input type="text" name="telegram_user_id" value="<?php echo panel_e(isset($record['telegram_user_id']) ? $record['telegram_user_id'] : ''); ?>"></label>
  <label class="check"><input type="checkbox" name="restrict" value="1" <?php echo !empty($record['restrict']) ? 'checked' : ''; ?>> Restrict reseller (cannot delete, disable, or lower user traffic; only add more GB)</label>
  <label>Status<select name="status"><option value="active" <?php echo $record['status'] === 'active' ? 'selected' : ''; ?>>Active</option><option value="disabled" <?php echo $record['status'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option></select></label>
  <label>API Key<input type="text" name="api_key" value="<?php echo panel_e(isset($record['api_key']) ? $record['api_key'] : ''); ?>"></label>
  <label class="check"><input type="checkbox" name="regenerate_api_key" value="1"> Regenerate reseller API key</label>
  <label>Notes<textarea name="notes"><?php echo panel_e($record['notes']); ?></textarea></label>
  <fieldset><legend>Allowed Templates</legend>
    <div class="checkbox-grid">
      <?php foreach ($templates as $tpl): ?>
        <label class="check"><input type="checkbox" name="allowed_template_ids[]" value="<?php echo panel_e($tpl['id']); ?>" <?php echo in_array($tpl['id'], (array) $record['allowed_template_ids'], true) ? 'checked' : ''; ?>> <?php echo panel_e($tpl['public_label']); ?> (<?php echo panel_e($tpl['inbound_name']); ?>)</label>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <?php if (!empty($errors)): ?><div class="form-errors"><?php foreach ($errors as $group): foreach ($group as $err): ?><div><?php echo panel_e($err); ?></div><?php endforeach; endforeach; ?></div><?php endif; ?>
  <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
  <button class="btn btn-primary" type="submit"><?php echo $mode === 'edit' ? 'Save Changes' : 'Create Reseller'; ?></button>
</form>
