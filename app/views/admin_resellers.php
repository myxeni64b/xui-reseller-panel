<div class="card">
  <div class="card-head"><h3>Resellers</h3><a class="btn btn-primary" href="<?php echo panel_e($app->url('/admin/resellers/create')); ?>">Create reseller</a></div>
  <table class="table">
    <tr><th>Name</th><th>Username</th><th>Prefix</th><th>Credit GB</th><th>Traffic Rules</th><th>Max Expiration</th><th>Max IP Limit</th><th>API</th><th>Templates</th><th>Restrict</th><th>Status</th><th>Actions</th></tr>
    <?php foreach ($resellers as $item): ?>
      <tr>
        <td><?php echo panel_e($item['display_name']); ?></td>
        <td><?php echo panel_e($item['username']); ?></td>
        <td><?php echo panel_e($item['prefix']); ?></td>
        <td><?php echo panel_e(panel_format_gb($item['credit_gb'])); ?></td>
        <td>Min: <?php echo panel_e(panel_format_gb(isset($item['min_customer_traffic_gb']) ? $item['min_customer_traffic_gb'] : 0)); ?> GB<br><small>Max: <?php echo panel_e(panel_format_gb(isset($item['max_customer_traffic_gb']) ? $item['max_customer_traffic_gb'] : 0)); ?> GB<?php echo (!isset($item['max_customer_traffic_gb']) || (float) $item['max_customer_traffic_gb'] == 0.0) ? ' (unlimited)' : ''; ?></small></td>
        <td><?php echo panel_e(isset($item['max_expiration_days']) ? $item['max_expiration_days'] : $item['fixed_duration_days']); ?><?php echo (isset($item['max_expiration_days']) ? (int) $item['max_expiration_days'] : (int) $item['fixed_duration_days']) === 0 ? ' unlimited' : ' days'; ?></td>
        <td><?php echo panel_e(isset($item['max_ip_limit']) ? $item['max_ip_limit'] : 0); ?><?php echo (!isset($item['max_ip_limit']) || (int) $item['max_ip_limit'] === 0) ? ' unlimited' : ''; ?></td>
        <td><code class="inline-code"><?php echo panel_e(!empty($item['api_key']) ? substr($item['api_key'], 0, 8) . '…' : 'auto'); ?></code></td>
        <td><?php $names=array(); foreach ((array)$item['allowed_template_ids'] as $tid){ if(isset($template_map[$tid])) $names[]=$template_map[$tid]['public_label']; } echo panel_e(implode(', ', $names)); ?></td>
        <td><span class="badge <?php echo !empty($item['restrict']) ? 'bad' : 'muted'; ?>"><?php echo !empty($item['restrict']) ? 'Restricted' : 'Open'; ?></span></td>
        <td><span class="badge <?php echo $item['status'] === 'active' ? 'good' : 'bad'; ?>"><?php echo panel_e($item['status']); ?></span></td>
        <td class="actions">
          <a class="btn" href="<?php echo panel_e($app->url('/admin/resellers/' . $item['id'] . '/edit')); ?>">Edit</a>
          <form method="post" action="<?php echo panel_e($app->url('/admin/resellers/' . $item['id'] . '/toggle')); ?>"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn" type="submit">Toggle</button></form>
          <form method="post" action="<?php echo panel_e($app->url('/admin/resellers/' . $item['id'] . '/delete')); ?>" onsubmit="return confirm('Delete reseller?');"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn btn-danger" type="submit">Delete</button></form>
        </td>
      </tr>
      <tr><td colspan="12">
        <form method="post" action="<?php echo panel_e($app->url('/admin/resellers/' . $item['id'] . '/credit')); ?>" class="inline-form">
          <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
          <input type="number" step="0.01" name="amount_gb" placeholder="+ or - GB">
          <input type="text" name="note" placeholder="Adjustment note">
          <button class="btn btn-primary" type="submit">Adjust credit</button>
        </form>
      </td></tr>
    <?php endforeach; ?>
  </table>
</div>
