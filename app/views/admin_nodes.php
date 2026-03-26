<div class="card">
  <div class="card-head"><h3>Servers</h3><a class="btn btn-primary" href="<?php echo panel_e($app->url('/admin/nodes/create')); ?>">Add server</a></div>
  <table class="table">
    <tr><th>Title</th><th>Base URL</th><th>Panel Path</th><th>Subscription Base</th><th>Timeout / Retry</th><th>TLS</th><th>Status</th><th>Actions</th></tr>
    <?php foreach ($nodes as $item): ?>
      <tr>
        <td><?php echo panel_e($item['title']); ?><br><small><?php echo panel_e($item['slug']); ?></small></td>
        <td><?php echo panel_e($item['base_url']); ?></td>
        <td><?php echo panel_e($item['panel_path']); ?></td>
        <td><?php echo panel_e(isset($item['subscription_base']) ? $item['subscription_base'] : '-'); ?></td>
        <td><?php echo panel_e(isset($item['connect_timeout']) ? $item['connect_timeout'] : 8); ?>s / <?php echo panel_e(isset($item['request_timeout']) ? $item['request_timeout'] : 20); ?>s<br><small><?php echo panel_e(isset($item['retry_attempts']) ? $item['retry_attempts'] : 2); ?> tries</small></td>
        <td><?php echo !empty($item['allow_insecure_tls']) ? '<span class="badge bad">Insecure</span>' : '<span class="badge good">Verified</span>'; ?></td>
        <td><span class="badge <?php echo $item['status'] === 'active' ? 'good' : 'bad'; ?>"><?php echo panel_e($item['status']); ?></span></td>
        <td class="actions">
          <a class="btn" href="<?php echo panel_e($app->url('/admin/nodes/' . $item['id'] . '/edit')); ?>">Edit</a>
          <form method="post" action="<?php echo panel_e($app->url('/admin/nodes/' . $item['id'] . '/test')); ?>"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn" type="submit">Test</button></form>
          <form method="post" action="<?php echo panel_e($app->url('/admin/nodes/' . $item['id'] . '/import-inbounds')); ?>"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn" type="submit">Import Inbounds</button></form>
          <form method="post" action="<?php echo panel_e($app->url('/admin/nodes/' . $item['id'] . '/toggle')); ?>"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn" type="submit">Toggle</button></form>
          <form method="post" action="<?php echo panel_e($app->url('/admin/nodes/' . $item['id'] . '/delete')); ?>" onsubmit="return confirm('Delete node?');"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn btn-danger" type="submit">Delete</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
