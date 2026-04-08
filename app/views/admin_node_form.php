<form method="post" class="stack-form card">
  <label>Title<input type="text" name="title" value="<?php echo panel_e($record['title']); ?>"></label>
  <label>Slug<input type="text" name="slug" value="<?php echo panel_e($record['slug']); ?>"></label>
  <label>Base URL<input type="text" name="base_url" value="<?php echo panel_e($record['base_url']); ?>" placeholder="https://server.example.com"></label>
  <label>Panel Path<input type="text" name="panel_path" value="<?php echo panel_e($record['panel_path']); ?>" placeholder="/panel"></label>
  <label>Subscription Base<input type="text" name="subscription_base" value="<?php echo panel_e(isset($record['subscription_base']) ? $record['subscription_base'] : ''); ?>" placeholder="https://sub.domain.tld/user/"></label>
  <label>Panel Username<input type="text" name="panel_username" value="<?php echo panel_e($record['panel_username']); ?>"></label>
  <label>Panel Password<input type="password" name="panel_password" placeholder="<?php echo $mode === 'edit' ? 'Leave empty to keep current password' : ''; ?>"></label>
  <div class="grid two-col">
    <label>Request Timeout (sec)<input type="number" min="5" name="request_timeout" value="<?php echo panel_e(isset($record['request_timeout']) ? $record['request_timeout'] : '20'); ?>"></label>
    <label>Connect Timeout (sec)<input type="number" min="3" name="connect_timeout" value="<?php echo panel_e(isset($record['connect_timeout']) ? $record['connect_timeout'] : '8'); ?>"></label>
  </div>
  <div class="grid two-col">
    <label>Retry Attempts<input type="number" min="1" max="5" name="retry_attempts" value="<?php echo panel_e(isset($record['retry_attempts']) ? $record['retry_attempts'] : '2'); ?>"></label>
    <label class="check"><input type="checkbox" name="allow_insecure_tls" value="1" <?php echo !empty($record['allow_insecure_tls']) ? 'checked' : ''; ?>> Allow insecure TLS (only for self-signed/internal nodes)</label>
  </div>
  <label>Status<select name="status"><option value="active" <?php echo $record['status'] === 'active' ? 'selected' : ''; ?>>Active</option><option value="disabled" <?php echo $record['status'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option></select></label>
  <label>Notes<textarea name="notes"><?php echo panel_e($record['notes']); ?></textarea></label>
  <?php if (!empty($errors)): ?><div class="form-errors"><?php foreach ($errors as $group): foreach ($group as $err): ?><div><?php echo panel_e($err); ?></div><?php endforeach; endforeach; ?></div><?php endif; ?>
  <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
  <button class="btn btn-primary" type="submit"><?php echo $mode === 'edit' ? 'Save Changes' : 'Save Server'; ?></button>
</form>
