<h2>Sign in</h2>
<form method="post" class="stack-form">
  <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
  <label>Role
    <select name="role">
      <option value="admin" <?php echo (isset($old['role']) && $old['role'] === 'admin') ? 'selected' : ''; ?>>Admin</option>
      <option value="reseller" <?php echo (isset($old['role']) && $old['role'] === 'reseller') ? 'selected' : ''; ?>>Reseller</option>
    </select>
  </label>
  <label>Username<input type="text" name="username" value="<?php echo panel_e(isset($old['username']) ? $old['username'] : ''); ?>"></label>
  <label>Password<input type="password" name="password"></label>
  <?php if (!empty($errors)): ?><div class="form-errors"><?php foreach ($errors as $group): foreach ($group as $err): ?><div><?php echo panel_e($err); ?></div><?php endforeach; endforeach; ?></div><?php endif; ?>
  <button class="btn btn-primary" type="submit">Login</button>
</form>
