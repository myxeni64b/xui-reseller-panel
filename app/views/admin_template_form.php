<form method="post" class="stack-form card">
  <label>Title<input type="text" name="title" value="<?php echo panel_e($record['title']); ?>"></label>
  <label>Public Label<input type="text" name="public_label" value="<?php echo panel_e($record['public_label']); ?>"></label>
  <label>Server
    <select name="node_id">
      <option value="">Select server</option>
      <?php foreach ($nodes as $node): ?>
        <option value="<?php echo panel_e($node['id']); ?>" <?php echo $record['node_id'] === $node['id'] ? 'selected' : ''; ?>><?php echo panel_e($node['title']); ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <div class="grid two-col">
    <label>Inbound ID<input type="text" name="inbound_id" value="<?php echo panel_e($record['inbound_id']); ?>"></label>
    <label>Inbound Name<input type="text" name="inbound_name" value="<?php echo panel_e($record['inbound_name']); ?>"></label>
  </div>
  <div class="grid two-col">
    <label>Protocol<input type="text" name="protocol" value="<?php echo panel_e($record['protocol']); ?>"></label>
    <label>Sort Order<input type="number" name="sort_order" value="<?php echo panel_e($record['sort_order']); ?>"></label>
  </div>
  <div class="grid two-col">
    <label>Listen<input type="text" name="listen" value="<?php echo panel_e(isset($record['listen']) ? $record['listen'] : ''); ?>"></label>
    <label>Port<input type="text" name="port" value="<?php echo panel_e(isset($record['port']) ? $record['port'] : ''); ?>"></label>
  </div>
  <div class="grid two-col">
    <label>Network<input type="text" name="network" value="<?php echo panel_e(isset($record['network']) ? $record['network'] : ''); ?>"></label>
    <label>Security<input type="text" name="security" value="<?php echo panel_e(isset($record['security']) ? $record['security'] : ''); ?>"></label>
  </div>
  <label>Status<select name="status"><option value="active" <?php echo $record['status'] === 'active' ? 'selected' : ''; ?>>Active</option><option value="disabled" <?php echo $record['status'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option></select></label>
  <details>
    <summary>Advanced imported JSON</summary>
    <label>Settings JSON<textarea rows="6" name="settings_json"><?php echo panel_e(isset($record['settings_json']) ? $record['settings_json'] : ''); ?></textarea></label>
    <label>Stream Settings JSON<textarea rows="8" name="stream_settings_json"><?php echo panel_e(isset($record['stream_settings_json']) ? $record['stream_settings_json'] : ''); ?></textarea></label>
    <label>Sniffing JSON<textarea rows="4" name="sniffing_json"><?php echo panel_e(isset($record['sniffing_json']) ? $record['sniffing_json'] : ''); ?></textarea></label>
  </details>
  <label>Notes<textarea name="notes"><?php echo panel_e($record['notes']); ?></textarea></label>
  <?php if (!empty($errors)): ?><div class="form-errors"><?php foreach ($errors as $group): foreach ($group as $err): ?><div><?php echo panel_e($err); ?></div><?php endforeach; endforeach; ?></div><?php endif; ?>
  <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
  <button class="btn btn-primary" type="submit"><?php echo $mode === 'edit' ? 'Save Changes' : 'Create Template'; ?></button>
</form>
