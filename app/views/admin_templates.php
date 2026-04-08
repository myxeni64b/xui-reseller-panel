<div class="card">
  <div class="card-head"><h3>Inbound Templates</h3><a class="btn btn-primary" href="<?php echo panel_e($app->url('/admin/templates/create')); ?>">Add template</a></div>
  <table class="table">
    <tr><th>Title</th><th>Server</th><th>Inbound</th><th>Proto / Net / TLS</th><th>Status</th><th>Actions</th></tr>
    <?php foreach ($templates as $item): $node = isset($node_map[$item['node_id']]) ? $node_map[$item['node_id']] : null; ?>
      <tr>
        <td><?php echo panel_e($item['public_label']); ?></td>
        <td><?php echo panel_e($node ? $node['title'] : 'Unknown'); ?></td>
        <td><?php echo panel_e($item['inbound_name']); ?> (#<?php echo panel_e($item['inbound_id']); ?>)<br><small><?php echo panel_e(isset($item['port']) ? $item['port'] : ''); ?></small></td>
        <td><?php echo panel_e($item['protocol']); ?><br><small><?php echo panel_e(isset($item['network']) ? $item['network'] : '-'); ?> / <?php echo panel_e(isset($item['security']) ? $item['security'] : '-'); ?></small></td>
        <td><span class="badge <?php echo $item['status'] === 'active' ? 'good' : 'bad'; ?>"><?php echo panel_e($item['status']); ?></span></td>
        <td class="actions">
          <a class="btn" href="<?php echo panel_e($app->url('/admin/templates/' . $item['id'] . '/edit')); ?>">Edit</a>
          <form method="post" action="<?php echo panel_e($app->url('/admin/templates/' . $item['id'] . '/toggle')); ?>"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn" type="submit">Toggle</button></form>
          <form method="post" action="<?php echo panel_e($app->url('/admin/templates/' . $item['id'] . '/delete')); ?>" onsubmit="return confirm('Delete template?');"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn btn-danger" type="submit">Delete</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
