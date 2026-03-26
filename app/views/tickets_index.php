<div class="card">
  <div class="card-head"><h3>Tickets</h3><a class="btn btn-primary" href="<?php echo panel_e($app->url('/' . $scope . '/tickets/create')); ?>">Create ticket</a></div>
  <table class="table">
    <tr><th>No</th><th>Subject</th><th>Priority</th><th>Status</th><th>Owner</th><th>Updated</th></tr>
    <?php foreach ($tickets as $item): $r = isset($reseller_map[$item['creator_id']]) ? $reseller_map[$item['creator_id']] : null; ?>
      <tr>
        <td><a href="<?php echo panel_e($app->url('/' . $scope . '/tickets/' . $item['id'])); ?>"><?php echo panel_e($item['ticket_no']); ?></a></td>
        <td><?php echo panel_e($item['subject']); ?></td>
        <td><?php echo panel_e($item['priority']); ?></td>
        <td><span class="badge <?php echo $item['status'] === 'closed' ? 'bad' : 'good'; ?>"><?php echo panel_e($item['status']); ?></span></td>
        <td><?php echo panel_e($item['creator_role'] === 'admin' ? 'Admin' : ($r ? $r['display_name'] : 'Reseller')); ?></td>
        <td><?php echo panel_e($item['last_reply_at']); ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
