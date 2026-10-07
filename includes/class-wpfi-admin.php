<?php
if (!defined('ABSPATH')) exit;
final class WPFI_Admin {
 private $repo,$scheduler,$importer,$logger;
 public function __construct($r,$s,$i,$l){
  $this->repo=$r;
  $this->scheduler=$s;
  $this->importer=$i;
  $this->logger=$l;
  add_action('admin_menu',[$this,'menu']);
  add_action('admin_post_wpfi_save_feed',[$this,'save']);
  add_action('admin_post_wpfi_delete_feed',[$this,'delete']);
  add_action('admin_post_wpfi_trigger_feed',[$this,'trigger']);
 }
 public function menu(){
  add_submenu_page('woocommerce','XML Feed Importer','XML Feed Importer','manage_woocommerce','wpfi-feeds',[$this,'page']);
  add_submenu_page('woocommerce','XML Feed Importer Logs','XML Feed Importer Logs','manage_woocommerce','wpfi-logs',[$this,'logs_page']);
 }
 private function can(){
  return current_user_can('manage_woocommerce');
 }
 private function url($a=[]){
  return add_query_arg(array_merge(['page'=>'wpfi-feeds'],$a),admin_url('admin.php'));
 }
 private function log($message, $level='info', $context=[]){
  $timestamp=date('Y-m-d H:i:s');
  $user=wp_get_current_user();
  $user_login=$user->user_login??'unknown';
  $log_message="[$timestamp] [$level] [$user_login] $message";
  if(!empty($context)){
   $log_message.=" | Context: ".wp_json_encode($context);
  }
  error_log($log_message,3,WP_CONTENT_DIR.'/wpfi-admin.log');
 }
 public function page(){
  if(!$this->can())return;
  $action=sanitize_key($_GET['action']??'list');
  $this->log("Page accessed: $action");
  if($action==='trigger')$this->trigger();
  elseif($action==='edit'||$action==='new')$this->edit();
  else $this->list();
 }
 public function logs_page(){
  if(!$this->can())return;
  $this->log("Log viewer accessed");
  $log_file=WP_CONTENT_DIR.'/wpfi-admin.log';
  $level=(isset($_GET['level'])?sanitize_key($_GET['level']):'all');
  $search=(isset($_GET['q'])?sanitize_text_field(wp_unslash($_GET['q'])):'');
  $clear=(isset($_GET['clear']) && $_GET['clear']==='1');
  if($clear){
   if(file_exists($log_file)){@unlink($log_file);} 
   $this->log('Log file cleared by admin', 'warning');
   echo '<div class="wrap"><h1>XML Feed Importer Logs</h1><p>Log file cleared.</p><p><a href="'.esc_url(admin_url('admin.php?page=wpfi-logs')).'">Return to logs</a></p></div>';
   return;
  }
  $lines=[];
  if(file_exists($log_file)){
   $contents=file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
   if(is_array($contents)){
    $lines=array_reverse($contents);
   }
  }
  $filtered=[];
  foreach($lines as $line){
   $match=true;
   if($level!=='all' && stripos($line,'['.$level.']')===false){$match=false;}
   if($match && $search!=='' && stripos($line,$search)===false){$match=false;}
   if($match)$filtered[]=$line;
  }
  echo '<div class="wrap"><h1>XML Feed Importer Logs</h1>';
  echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'">';
  echo '<input type="hidden" name="page" value="wpfi-logs">';
  echo '<select name="level"><option value="all" '.selected($level,'all',false).'>All</option><option value="info" '.selected($level,'info',false).'>Info</option><option value="warning" '.selected($level,'warning',false).'>Warning</option><option value="error" '.selected($level,'error',false).'>Error</option></select>';
  echo ' <input type="search" name="q" value="'.esc_attr($search).'" placeholder="Search logs...">';
  echo ' <input type="submit" class="button" value="Filter">';
  echo ' <a class="button" href="'.esc_url(admin_url('admin.php?page=wpfi-logs&clear=1')).'" onclick="return confirm(\'Clear log file?\')">Clear Logs</a>';
  echo '</form>';
  echo '<pre style="background:#fff;border:1px solid #ddd;padding:12px;max-height:700px;overflow:auto;white-space:pre-wrap;word-break:break-word;">';
  if(empty($filtered)){
   echo 'No log entries found.';
  }else{
   $count=0;
   foreach(array_slice($filtered,0,200) as $line){
    $count++;
    $color='inherit';
    if(stripos($line,'[error]')!==false)$color='#b00020';
    elseif(stripos($line,'[warning]')!==false)$color='#b7791f';
    elseif(stripos($line,'[info]')!==false)$color='#0050b3';
    echo '<div style="color:'.esc_attr($color).';">'.esc_html($count.'. '.$line).'</div>';
   }
  }
  echo '</pre></div>';
 }
 private function list(){
  echo '<div class="wrap">';
  echo '<h1>XML Feed Importer</h1>';
  echo '<p><a class="button button-primary" href="'.esc_url($this->url(['action'=>'new'])).'">Add Feed</a> <a class="button" href="'.esc_url($this->url(['action'=>'new','preset'=>'pinnacle'])).'">Add Pinnacle Feed</a></p>';
  echo '<style>
    .feed-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    .feed-table th, .feed-table td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
    .feed-table th { background-color: #f5f5f5; font-weight: bold; }
    .feed-table tr:hover { background-color: #f9f9f9; }
    .feed-actions { white-space: nowrap; }
    .feed-actions a { margin-right: 10px; padding: 5px 10px; text-decoration: none; }
    .trigger-btn { background-color: #0073aa; color: white; border-radius: 3px; }
    .trigger-btn:hover { background-color: #005a87; }
    .feed-status { font-weight: bold; padding: 5px 10px; border-radius: 3px; }
    .status-enabled { background-color: #d4edda; color: #155724; }
    .status-disabled { background-color: #f8d7da; color: #721c24; }
    .feed-details { font-size: 12px; color: #666; }
    .trigger-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; }
    .trigger-modal.active { display: flex; align-items: center; justify-content: center; }
    .modal-content { background: white; padding: 30px; border-radius: 5px; max-width: 500px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    .modal-header { font-size: 18px; font-weight: bold; margin-bottom: 20px; }
    .modal-body { margin-bottom: 20px; }
    .modal-field { margin-bottom: 15px; }
    .modal-field label { display: block; font-weight: bold; margin-bottom: 5px; }
    .modal-field input, .modal-field textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 3px; box-sizing: border-box; }
    .modal-field textarea { resize: vertical; min-height: 80px; font-family: monospace; font-size: 12px; }
    .modal-footer { text-align: right; }
    .modal-footer button { margin-left: 10px; padding: 8px 15px; }
  </style>';
  echo '<table class="feed-table"><thead><tr><th>Feed Name</th><th>Configuration</th><th>Status</th><th>Frequency</th><th>Actions</th></tr></thead><tbody>';
  $feeds=$this->repo->all();
  if(empty($feeds)){
   echo '<tr><td colspan="5">No feeds configured yet.</td></tr>';
  }else{
   foreach($feeds as $f){
    $feed_id=$f['id']??'';
    echo '<tr>';
    echo '<td><strong>'.esc_html($f['name']??'Untitled').'</strong><div class="feed-details">ID: '.esc_html($feed_id).'</div></td>';
    echo '<td>';
    echo '<div class="feed-details"><strong>URL:</strong> <code>'.esc_html(substr($f['url']??'',0,60)).(strlen($f['url']??'')>60?'...':'').'</code></div>';
    echo '<div class="feed-details"><strong>Format:</strong> '.strtoupper(esc_html($f['format']??'xml')).'</div>';
    if(!empty($f['auth']['path_params'])){
     echo '<div class="feed-details"><strong>Path Params:</strong> '.esc_html(implode(', ',array_keys($f['auth']['path_params']))).'</div>';
    }
    echo '</td>';
    echo '<td><span class="feed-status '.($f['enabled']?'status-enabled':'status-disabled').'">'.($f['enabled']?'✓ Enabled':'✗ Disabled').'</span></td>';
    echo '<td>'.esc_html($f['frequency']??'daily').'</td>';
    echo '<td class="feed-actions">';
    echo '<a href="'.esc_url($this->url(['action'=>'edit','id'=>$feed_id])).'" class="button">Edit</a>';
    echo '<button class="button trigger-btn" onclick="openTriggerModal(\''.esc_attr($feed_id).'\', \''.esc_attr($f['name']).'\', '.wp_json_encode($f).')">Trigger</button>';
    echo '<a href="'.esc_url(wp_nonce_url($this->url(['action'=>'delete','id'=>$feed_id]),'wpfi_delete_'.$feed_id)).'" class="button" onclick="return confirm(\'Delete this feed?\')">Delete</a>';
    echo '</td>';
    echo '</tr>';
   }
  }
  echo '</tbody></table>';
  $this->render_trigger_modal();
  echo '</div>';
  $this->render_trigger_script();
 }
 private function render_trigger_modal(){
  ?>
  <div id="triggerModal" class="trigger-modal">
   <div class="modal-content">
    <div class="modal-header">Trigger Feed Import</div>
    <div class="modal-body">
     <div class="modal-field">
      <label>Feed Name:</label>
      <input type="text" id="modalFeedName" readonly style="background-color: #f5f5f5;">
     </div>
     <div class="modal-field">
      <label>Feed URL:</label>
      <textarea id="modalFeedUrl" readonly style="background-color: #f5f5f5;"></textarea>
     </div>
     <div class="modal-field">
      <label>Format:</label>
      <input type="text" id="modalFormat" readonly style="background-color: #f5f5f5;">
     </div>
     <div class="modal-field">
      <label>Authentication Type:</label>
      <input type="text" id="modalAuthType" readonly style="background-color: #f5f5f5;">
     </div>
     <div id="modalPathParams" style="display:none;">
      <div class="modal-field">
       <label>Path Parameters:</label>
       <textarea id="modalPathParamsText" readonly style="background-color: #f5f5f5;"></textarea>
      </div>
     </div>
     <div id="modalQueryParams" style="display:none;">
      <div class="modal-field">
       <label>Query Parameters:</label>
       <textarea id="modalQueryParamsText" readonly style="background-color: #f5f5f5;"></textarea>
      </div>
     </div>
     <div class="modal-field">
      <label>Field Mappings:</label>
      <textarea id="modalMappings" readonly style="background-color: #f5f5f5; height: 150px;"></textarea>
     </div>
     <div class="modal-field">
      <label style="margin-bottom: 10px;">Import Options:</label>
      <div>
       <input type="checkbox" id="modalSkipZeroStock" disabled> 
       <label for="modalSkipZeroStock" style="display: inline; font-weight: normal;">Skip Zero Stock Items</label>
      </div>
     </div>
    </div>
    <div class="modal-footer">
     <button class="button" onclick="closeTriggerModal()">Cancel</button>
     <button class="button button-primary" onclick="confirmTrigger()">Start Import</button>
    </div>
   </div>
  </div>
  <?php
 }
 private function render_trigger_script(){
  ?>
  <script>
   let currentFeedId = '';
   function openTriggerModal(feedId, feedName, feedData) {
    currentFeedId = feedId;
    document.getElementById('modalFeedName').value = feedName;
    document.getElementById('modalFeedUrl').value = feedData.url || '';
    document.getElementById('modalFormat').value = (feedData.format || 'xml').toUpperCase();
    
    const authType = feedData.auth?.type || 'none';
    document.getElementById('modalAuthType').value = authType.charAt(0).toUpperCase() + authType.slice(1).replace('_', ' ');
    
    // Display path parameters if present
    const pathParams = feedData.auth?.path_params || {};
    if (Object.keys(pathParams).length > 0) {
     document.getElementById('modalPathParams').style.display = 'block';
     let pathParamsText = '';
     for (const [key, value] of Object.entries(pathParams)) {
      pathParamsText += key + ' = ' + value + '\n';
     }
     document.getElementById('modalPathParamsText').value = pathParamsText.trim();
    } else {
     document.getElementById('modalPathParams').style.display = 'none';
    }
    
    // Display query parameters if present
    const queryParams = feedData.auth?.query_params || {};
    if (Object.keys(queryParams).length > 0) {
     document.getElementById('modalQueryParams').style.display = 'block';
     let queryParamsText = '';
     for (const [key, value] of Object.entries(queryParams)) {
      queryParamsText += key + ' = ' + value + '\n';
     }
     document.getElementById('modalQueryParamsText').value = queryParamsText.trim();
    } else {
     document.getElementById('modalQueryParams').style.display = 'none';
    }
    
    // Display field mappings
    const mappings = feedData.map || {};
    let mappingsText = '';
    for (const [key, value] of Object.entries(mappings)) {
     mappingsText += key + ' = ' + value + '\n';
    }
    document.getElementById('modalMappings').value = mappingsText.trim();
    
    // Set checkbox state
    document.getElementById('modalSkipZeroStock').checked = feedData.skip_zero_stock === 1;
    
    // Show modal
    document.getElementById('triggerModal').classList.add('active');
   }
   
   function closeTriggerModal() {
    document.getElementById('triggerModal').classList.remove('active');
    currentFeedId = '';
   }
   
   function confirmTrigger() {
    if (!currentFeedId) {
     alert('Feed ID not set');
     return;
    }
    closeTriggerModal();
    // Redirect to trigger action with nonce
    window.location.href = '<?php echo admin_url('admin.php'); ?>?page=wpfi-feeds&action=trigger&id=' + encodeURIComponent(currentFeedId) + '&_wpnonce=' + encodeURIComponent('<?php echo wp_create_nonce('wpfi_trigger_'); ?>' + currentFeedId);
   }
   
   // Close modal when clicking outside
   document.getElementById('triggerModal').addEventListener('click', function(e) {
    if (e.target === this) {
     closeTriggerModal();
    }
   });
  </script>
  <?php
 }
 private function edit(){
  $id=sanitize_text_field($_GET['id']??'');
  $preset=sanitize_key($_GET['preset']??'');
  
  if($preset==='pinnacle') {
   $f=$this->repo->pinnacle_preset();
  } else {
   $f=wp_parse_args($id?($this->repo->get($id)??[]):[],$this->repo->defaults());
  }
  
  $a=$f['auth']??[];
  $this->log("Feed edit form opened", 'info', ['feed_id'=>$id??'new','preset'=>$preset]);
  echo '<div class="wrap">';
  echo '<h1>'.($id?'Edit':'Add').' XML Feed'.($preset==='pinnacle'?' (Pinnacle)':'').'</h1>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data">';
  echo '<input type="hidden" name="action" value="wpfi_save_feed">';
  echo '<input type="hidden" name="id" value="'.esc_attr($id).'">';
  wp_nonce_field('wpfi_save_feed');
  
  echo '<h2>Feed Settings</h2>';
  echo '<table class="form-table"><tbody>';
  echo '<tr><th><label for="name">Feed Name</label></th><td><input type="text" id="name" name="name" value="'.esc_attr($f['name']??'').'" class="regular-text" required></td></tr>';
  echo '<tr><th><label for="url">Base URL</label></th><td><input type="url" id="url" name="url" value="'.esc_attr($f['url']??'').'" class="regular-text" required></td></tr>';
  echo '<tr><th><label for="format">Format</label></th><td>';
  echo '<select id="format" name="format">';
  echo '<option value="xml" '.selected($f['format']??'xml','xml',false).'>XML</option>';
  echo '<option value="csv" '.selected($f['format']??'xml','csv',false).'>CSV</option>';
  echo '</select>';
  echo '</td></tr>';
  echo '<tr><th><label for="product_xpath">Product XPath</label></th><td><input type="text" id="product_xpath" name="product_xpath" value="'.esc_attr($f['product_xpath']??'').'" class="regular-text" placeholder="/products/product"></td></tr>';
  echo '<tr><th><label for="frequency">Import Frequency</label></th><td>';
  echo '<select id="frequency" name="frequency">';
  echo '<option value="daily" '.selected($f['frequency']??'daily','daily',false).'>Daily</option>';
  echo '<option value="twicedaily" '.selected($f['frequency']??'daily','twicedaily',false).'>Twice Daily</option>';
  echo '<option value="hourly" '.selected($f['frequency']??'daily','hourly',false).'>Hourly</option>';
  echo '</select>';
  echo '</td></tr>';
  echo '<tr><th><label for="enabled">Enabled</label></th><td><input type="checkbox" id="enabled" name="enabled" value="1" '.checked($f['enabled']??1,1,false).'></td></tr>';
  echo '<tr><th><label for="skip_zero_stock">Skip Zero Stock Items</label></th><td><input type="checkbox" id="skip_zero_stock" name="skip_zero_stock" value="1" '.checked($f['skip_zero_stock']??0,1,false).'></td></tr>';
  echo '</tbody></table>';
  
  echo '<h2>Authentication</h2>';
  echo '<table class="form-table"><tbody>';
  echo '<tr><th><label for="auth_type">Authentication Type</label></th><td>';
  echo '<select id="auth_type" name="auth[type]">';
  echo '<option value="none" '.selected($a['type']??'none','none',false).'>None</option>';
  echo '<option value="api_key" '.selected($a['type']??'none','api_key',false).'>API Key</option>';
  echo '<option value="basic" '.selected($a['type']??'none','basic',false).'>Basic Auth</option>';
  echo '<option value="bearer" '.selected($a['type']??'none','bearer',false).'>Bearer Token</option>';
  echo '<option value="custom" '.selected($a['type']??'none','custom',false).'>Custom Header</option>';
  echo '</select>';
  echo '</td></tr>';
  echo '<tr><th><label for="auth_api_key">API Key</label></th><td><input type="text" id="auth_api_key" name="auth[api_key]" value="'.esc_attr($a['api_key']??'').'" class="regular-text"></td></tr>';
  echo '<tr><th><label for="auth_api_key_name">API Key Header</label></th><td><input type="text" id="auth_api_key_name" name="auth[api_key_name]" value="'.esc_attr($a['api_key_name']??'X-API-Key').'" class="regular-text"></td></tr>';
  echo '<tr><th><label for="auth_username">Username</label></th><td><input type="text" id="auth_username" name="auth[username]" value="'.esc_attr($a['username']??'').'" class="regular-text"></td></tr>';
  echo '<tr><th><label for="auth_password">Password</label></th><td><input type="password" id="auth_password" name="auth[password]" value="'.esc_attr($a['password']??'').'" class="regular-text"><br><small>Leave blank to keep existing password</small></td></tr>';
  echo '<tr><th><label for="auth_token">Bearer Token</label></th><td><input type="text" id="auth_token" name="auth[token]" value="'.esc_attr($a['token']??'').'" class="regular-text"></td></tr>';
  echo '<tr><th><label for="auth_header_name">Custom Header Name</label></th><td><input type="text" id="auth_header_name" name="auth[header_name]" value="'.esc_attr($a['header_name']??'').'" class="regular-text"></td></tr>';
  echo '<tr><th><label for="auth_header_value">Custom Header Value</label></th><td><input type="text" id="auth_header_value" name="auth[header_value]" value="'.esc_attr($a['header_value']??'').'" class="regular-text"></td></tr>';
  echo '<tr><th><label for="auth_query_params">Query Parameters</label></th><td>';
  echo '<textarea id="auth_query_params" name="auth[query_params]" rows="4" class="large-text code" placeholder="key1=value1&#10;key2=value2">'.esc_textarea($this->format_params($a['query_params']??[])).'</textarea>';
  echo '<p class="description">One key=value pair per line for query string parameters</p>';
  echo '</td></tr>';
  echo '<tr><th><label for="auth_path_params">Path Parameters</label></th><td>';
  echo '<textarea id="auth_path_params" name="auth[path_params]" rows="4" class="large-text code" placeholder="id=11305&#10;uid=bf672543-bc4c-40a9-a8c6-0ac6259bb4de">'.esc_textarea($this->format_params($a['path_params']??[])).'</textarea>';
  echo '<p class="description">One key=value pair per line. For Pinnacle feeds use:<br><strong>id=11305</strong><br><strong>uid=bf672543-bc4c-40a9-a8c6-0ac6259bb4de</strong></p>';
  echo '</td></tr>';
  echo '</tbody></table>';
  
  echo '<h2>Field Mappings</h2>';
  echo '<table class="form-table"><tbody>';
  echo '<tr><th><label for="map">Field Mappings</label></th><td>';
  echo '<textarea id="map" name="map" rows="10" class="large-text code" placeholder="name=name&#10;sku=sku&#10;price=price">'.esc_textarea($this->format_map($f['map']??[])).'</textarea>';
  echo '<p class="description">One mapping per line: WooCommerce_field=XML_selector<br><strong>For Pinnacle feeds, use these field names from their XML:</strong><br>name=ProdName<br>sku=StockCode<br>description=TopCat<br>price=ProdPriceExclVAT<br>stock_quantity=ProdQty<br>image=ProdImg<br>category=category_tree</p>';
  echo '</td></tr>';
  echo '</tbody></table>';
  
  submit_button();
  echo '</form>';
  echo '</div>';
 }
 public function save(){
  try {
   $this->log("Save feed initiated");
   
   if(!$this->can()) {
    $this->log("Permission denied on save", 'error');
    wp_die('Permission denied.');
   }
   
   if(!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'wpfi_save_feed')) {
    $this->log("Nonce verification failed on save", 'error');
    wp_die('Security check failed.');
   }
   
   if(!isset($_POST['name']) || empty(trim($_POST['name']))) {
    $this->log("Feed name missing on save", 'error');
    wp_die('Feed name is required.');
   }
   
   if(!isset($_POST['url']) || empty(trim($_POST['url']))) {
    $this->log("Feed URL missing on save", 'error');
    wp_die('Feed URL is required.');
   }

   $f=$this->repo->defaults();
   
   $f['id']=sanitize_text_field($_POST['id']??'');
   if(empty($f['id'])) {
    $f['id']=wp_generate_uuid4();
    $this->log("New feed created with ID: ".$f['id']);
   } else {
    $this->log("Updating existing feed: ".$f['id']);
   }
   
   $f['name']=sanitize_text_field($_POST['name']??'');
   $f['url']=esc_url_raw($_POST['url']??'');
   
   $format=$_POST['format']??'xml';
   $f['format']=in_array($format,['xml','csv'],true)?sanitize_key($format):'xml';
   
   $f['product_xpath']=sanitize_text_field($_POST['product_xpath']??'');
   
   $frequency=$_POST['frequency']??'daily';
   $f['frequency']=in_array($frequency,['daily','twicedaily','hourly'],true)?sanitize_key($frequency):'daily';
   
   $f['enabled']=isset($_POST['enabled']) && $_POST['enabled']==='1'?1:0;
   $f['skip_zero_stock']=isset($_POST['skip_zero_stock']) && $_POST['skip_zero_stock']==='1'?1:0;
   
   $this->log("Feed basic settings saved", 'info', ['feed'=>$f['name'], 'format'=>$f['format'], 'enabled'=>$f['enabled']]);
   
   $auth=$f['auth']??[];
   $auth['type']=sanitize_key($_POST['auth']['type']??'none');
   $auth['api_key']=sanitize_text_field($_POST['auth']['api_key']??'');
   $auth['api_key_name']=sanitize_text_field($_POST['auth']['api_key_name']??'X-API-Key');
   $auth['username']=sanitize_user($_POST['auth']['username']??'');
   $auth['password']=sanitize_text_field($_POST['auth']['password']??'');
   $auth['token']=sanitize_text_field($_POST['auth']['token']??'');
   $auth['header_name']=sanitize_text_field($_POST['auth']['header_name']??'');
   $auth['header_value']=sanitize_text_field($_POST['auth']['header_value']??'');
   
   if(!empty($_POST['auth']['query_params'])) {
    $query_params=sanitize_textarea_field(wp_unslash($_POST['auth']['query_params']??''));
    $auth['query_params']=$this->parse_params($query_params);
    $this->log("Query parameters set", 'info', ['count'=>count($auth['query_params'])]);
   } else {
    $auth['query_params']=[];
   }
   
   if(!empty($_POST['auth']['path_params'])) {
    $path_params=sanitize_textarea_field(wp_unslash($_POST['auth']['path_params']??''));
    $auth['path_params']=$this->parse_params($path_params);
    $this->log("Path parameters set", 'info', ['count'=>count($auth['path_params']), 'keys'=>implode(',',array_keys($auth['path_params']))]);
   } else {
    $auth['path_params']=[];
   }
   
   $f['auth']=$auth;
   $this->log("Authentication settings saved", 'info', ['type'=>$auth['type']]);
   
   if(!empty($_POST['map'])) {
    $map_raw=sanitize_textarea_field(wp_unslash($_POST['map']??''));
    $f['map']=$this->parse_map($map_raw);
    $this->log("Field mappings saved", 'info', ['count'=>count($f['map']), 'fields'=>implode(',',array_keys($f['map']))]);
   } else {
    $f['map']=[];
   }
   
   $this->repo->save($f);
   $this->log("Feed saved to repository", 'info', ['feed_id'=>$f['id']]);
   
   if(is_callable([$this->scheduler,'schedule'])) {
    $this->scheduler->schedule($f);
    $this->log("Feed scheduled", 'info', ['frequency'=>$f['frequency']]);
   }
   
   wp_safe_redirect($this->url(['notice'=>'saved']));
   exit;
  } catch(Exception $e) {
   $error_msg='Error saving feed: '.$e->getMessage();
   $this->log($error_msg, 'error', ['exception'=>$e->getTraceAsString()]);
   wp_die($error_msg);
  }
 }
 public function delete(){
  try {
   $id=sanitize_text_field($_GET['id']??'');
   $this->log("Delete feed initiated", 'info', ['feed_id'=>$id]);
   
   if(!$this->can()) {
    $this->log("Permission denied on delete", 'error');
    wp_die('Permission denied.');
   }
   
   if(empty($id)) {
    $this->log("Feed ID missing on delete", 'error');
    wp_die('Feed ID is required.');
   }
   
   if(!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wpfi_delete_'.$id)) {
    $this->log("Nonce verification failed on delete", 'error', ['feed_id'=>$id]);
    wp_die('Security check failed.');
   }
   
   $this->repo->delete($id);
   $this->log("Feed deleted from repository", 'info', ['feed_id'=>$id]);
   
   if(is_callable([$this->scheduler,'unschedule'])) {
    $this->scheduler->unschedule($id);
    $this->log("Feed unscheduled", 'info', ['feed_id'=>$id]);
   }
   
   wp_safe_redirect($this->url(['notice'=>'deleted']));
   exit;
  } catch(Exception $e) {
   $error_msg='Error deleting feed: '.$e->getMessage();
   $this->log($error_msg, 'error', ['exception'=>$e->getTraceAsString()]);
   wp_die($error_msg);
  }
 }
 public function trigger(){
  try {
   $id=sanitize_text_field($_GET['id']??'');
   $this->log("Trigger feed initiated", 'info', ['feed_id'=>$id]);
   
   if(!$this->can()) {
    $this->log("Permission denied on trigger", 'error');
    wp_die('Permission denied.');
   }
   
   if(empty($id)) {
    $this->log("Feed ID missing on trigger", 'error');
    wp_die('Feed ID is required.');
   }
   
   if(!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wpfi_trigger_'.$id)) {
    $this->log("Nonce verification failed on trigger", 'error', ['feed_id'=>$id]);
    wp_die('Security check failed.');
   }
   
   $feed=$this->repo->get($id);
   if(!$feed) {
    $this->log("Feed not found on trigger", 'error', ['feed_id'=>$id]);
    wp_die('Feed not found.');
   }
   
   if(empty($feed['enabled'])) {
    $this->log("Cannot trigger disabled feed", 'warning', ['feed_id'=>$id]);
    wp_die('Feed is disabled.');
   }
   
   $this->log("Triggering feed import", 'info', ['feed_id'=>$id, 'feed_name'=>$feed['name']]);
   
   if(is_callable([$this->importer,'import'])) {
    $result=$this->importer->import($feed);
    $this->log("Feed import completed", 'info', ['feed_id'=>$id, 'result'=>$result?'success':'failed']);
   } else {
    $this->log("Importer not callable", 'error', ['feed_id'=>$id]);
    wp_die('Importer error.');
   }
   
   wp_safe_redirect($this->url(['notice'=>'triggered']));
   exit;
  } catch(Exception $e) {
   $error_msg='Error triggering feed: '.$e->getMessage();
   $this->log($error_msg, 'error', ['exception'=>$e->getTraceAsString()]);
   wp_die($error_msg);
  }
 }
 private function format_params($params){
  if(empty($params))return '';
  $lines=[];
  foreach((array)$params as $k=>$v) {
   if(!empty($k)) {
    $lines[]=$k.'='.(string)$v;
   }
  }
  return implode("\n",$lines);
 }
 private function parse_params($raw){
  $raw=(string)$raw;
  if(empty($raw))return [];
  $lines=preg_split('/\r\n|\r|\n/',$raw);
  $params=[];
  foreach($lines as $line){
   $line=trim($line);
   if($line==='')continue;
   if(strpos($line,'=')===false)continue;
   $parts=explode('=',$line,2);
   if(count($parts)!==2)continue;
   $key=trim($parts[0]);
   $value=trim($parts[1]);
   if($key!=='')$params[$key]=$value;
  }
  return $params;
 }
 private function format_map($map){
  if(empty($map))return '';
  $lines=[];
  foreach((array)$map as $k=>$v) {
   if(!empty($k)) {
    $lines[]=$k.'='.(string)$v;
   }
  }
  return implode("\n",$lines);
 }
 private function parse_map($raw){
  $raw=(string)$raw;
  if(empty($raw))return [];
  $lines=preg_split('/\r\n|\r|\n/',$raw);
  $map=[];
  foreach($lines as $line){
   $line=trim($line);
   if($line==='')continue;
   if(strpos($line,'=')===false)continue;
   $parts=explode('=',$line,2);
   if(count($parts)!==2)continue;
   $key=trim($parts[0]);
   $value=trim($parts[1]);
   if($key!=='')$map[$key]=$value;
  }
  return $map;
 }
}
