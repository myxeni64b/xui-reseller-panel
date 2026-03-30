<div class="grid cards-4">
  <div class="stat-card"><span>Credit</span><strong><?php echo panel_e(panel_format_gb($reseller['credit_gb'])); ?> GB</strong></div>
  <div class="stat-card"><span>Max Expiration</span><strong><?php echo !empty($reseller['max_expiration_days']) ? panel_e($reseller['max_expiration_days']) . ' days' : 'Unlimited'; ?></strong></div>
  <div class="stat-card"><span>Max IP Limit</span><strong><?php echo !empty($reseller['max_ip_limit']) ? panel_e($reseller['max_ip_limit']) : 'Unlimited'; ?></strong></div>
  <div class="stat-card"><span>Users</span><strong><?php echo count($customers); ?></strong></div>
</div>
<div class="card">
  <div class="card-head"><h3>Account</h3><a class="btn" href="<?php echo panel_e($app->url('/reseller/profile')); ?>">Open profile</a></div>
  <div class="grid two-col">
    <div class="muted-box"><strong>API</strong><br><?php echo !empty($api_enabled) ? 'Enabled by admin' : 'Disabled by admin'; ?></div>
    <div class="muted-box"><strong>Restriction</strong><br><?php echo !empty($reseller['restrict']) ? 'Restricted account' : 'Open account'; ?></div>
  </div>
</div>
<div class="card">
  <div class="card-head"><h3>Allowed server / inbound list</h3><a class="btn btn-primary" href="<?php echo panel_e($app->url('/reseller/customers/create')); ?>">Create customer</a></div>
  <table class="table">
    <tr><th>Template</th><th>Server</th><th>Inbound</th><th>Protocol</th></tr>
    <?php foreach ($templates as $tpl): $node = isset($node_map[$tpl['node_id']]) ? $node_map[$tpl['node_id']] : null; ?>
      <tr>
        <td><?php echo panel_e($tpl['public_label']); ?></td>
        <td><?php echo panel_e($node ? $node['title'] : 'Unknown'); ?></td>
        <td><?php echo panel_e($tpl['inbound_name']); ?><?php echo !empty($tpl['port']) ? ' :' . panel_e($tpl['port']) : ''; ?></td>
        <td><?php echo panel_e($tpl['protocol']); ?><br><small><?php echo panel_e(isset($tpl['network']) ? $tpl['network'] : '-'); ?> / <?php echo panel_e(isset($tpl['security']) ? $tpl['security'] : '-'); ?></small></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<div class="card">
  <div class="card-head"><h3>Recent customers</h3><a class="btn" href="<?php echo panel_e($app->url('/reseller/customers')); ?>">View all</a></div>
  <table class="table">
    <tr><th>Name</th><th>Traffic</th><th>Status</th><th>Sub</th></tr>
    <?php foreach ($customers as $item):
      $subUrl = $app->appLink('/user/' . $item['subscription_key']);
      $proxySubUrl = '';
      if (!empty($item['template_id']) && isset($template_map[$item['template_id']])) {
          $tpl = $template_map[$item['template_id']];
          $node = isset($node_map[$tpl['node_id']]) ? $node_map[$tpl['node_id']] : null;
          if ($node && !empty($node['subscription_base'])) {
              $remoteSubId = !empty($item['remote_sub_id']) ? $item['remote_sub_id'] : $item['subscription_key'];
              if ($remoteSubId !== '') {
                  $proxySubUrl = rtrim($node['subscription_base'], '/') . '/' . rawurlencode($remoteSubId);
              }
          }
      }
      $primarySubUrl = $proxySubUrl !== '' ? $proxySubUrl : $subUrl;
    ?>
      <tr><td><a href="<?php echo panel_e($app->url('/reseller/customers/' . $item['id'])); ?>"><?php echo panel_e($item['display_name']); ?></a></td><td><?php echo panel_e(panel_format_gb($item['traffic_gb'])); ?> GB</td><td><span class="badge <?php echo $item['status'] === 'active' ? 'good' : 'bad'; ?>"><?php echo panel_e($item['status']); ?></span></td><td><code><?php echo panel_e($primarySubUrl); ?></code></td></tr>
    <?php endforeach; ?>
  </table>
</div>
