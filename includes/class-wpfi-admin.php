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
    add_action('wp_ajax_wpfi_trigger_feed',[$this,'ajax_trigger']);
    add_action('wp_ajax_nopriv_wpfi_trigger_feed',[$this,'ajax_trigger']);
  }
  public function menu(){
    add_submenu_page('woocommerce','XML Feed Importer','XML Feed Importer','manage_woocommerce','wpfi-feeds',[$this,'page']);
    add_submenu_page('woocommerce','XML Feed Importer Logs','XML Feed Importer Logs','manage_woocommerce','wpfi-logs',[$this,'logs_page']);
  }
  private function can(){ return current_user_can('manage_woocommerce'); }
  private function url($a=[]){ return add_query_arg(array_merge(['page'=>'wpfi-feeds'],$a),admin_url('admin.php')); }
  private function log($message, $level='info', $context=[]){
    $timestamp=date('Y-m-d H:i:s');
    $user=wp_get_current_user();
    $user_login=$user->user_login??'unknown';
    $log_message="[$timestamp] [$level] [$user_login] $message";
    if(!empty($context)) $log_message .= " | Context: ".wp_json_encode($context);
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
    if($clear){ if(file_exists($log_file)){@unlink($log_file);} $this->log('Log file cleared by admin', 'warning'); echo '<div class="wrap"><h1>XML Feed Importer Logs</h1><p>Log file cleared.</p><p><a href="'.esc_url(admin_url('admin.php?page=wpfi-logs')).'">Back to logs</a></p></div>'; return; }
    $lines=[]; if(file_exists($log_file)){ $contents=file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); if(is_array($contents)) $lines=array_reverse($contents); }
    $filtered=[]; foreach($lines as $line){ $match=true; if($level!=='all' && stripos($line,'['.$level.']')===false){$match=false;} if($match && $search!=='' && stripos($line,$search)===false){$match=false;} if($match){$filtered[]=$line;} }
    echo '<div class="wrap"><h1>XML Feed Importer Logs</h1>';
    echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'">';
    echo '<input type="hidden" name="page" value="wpfi-logs">';
    echo '<select name="level"><option value="all" '.selected($level,'all',false).'>All</option><option value="info" '.selected($level,'info',false).'>Info</option><option value="warning" '.selected($level,'warning',false).'>Warning</option><option value="error" '.selected($level,'error',false).'>Error</option></select>';
    echo ' <input type="search" name="q" value="'.esc_attr($search).'" placeholder="Search logs...">';
    echo ' <input type="submit" class="button" value="Filter">';
    echo ' <a class="button" href="'.esc_url(admin_url('admin.php?page=wpfi-logs&clear=1')).'" onclick="return confirm(\'Clear log file?\')">Clear Logs</a>';
    echo '</form>';
    echo '<pre style="background:#fff;border:1px solid #ddd;padding:12px;max-height:700px;overflow:auto;white-space:pre-wrap;word-break:break-word;">';
    if(empty($filtered)){ echo 'No log entries found.'; } else { $count=0; foreach(array_slice($filtered,0,200) as $line){ $count++; $color='inherit'; if(stripos($line,'[error]')!==false)$color='#b00'; elseif(stripos($line,'[warning]')!==false)$color='#c76a00'; echo '<span style="color:'.$color.';">'.esc_html($line).'</span>\n'; } }
    echo '</pre></div>';
  }
  private function list(){
    echo '<div class="wrap">';
    echo '<h1>XML Feed Importer</h1>';
    echo '<p><a class="button button-primary" href="'.esc_url($this->url(['action'=>'new'])).'">Add Feed</a> <a class="button" href="'.esc_url($this->url(['action'=>'new','preset'=>'pinnacle'])).'">Add Pinnacle Feed</a></p>';
    echo '<style> .feed-table { width: 100%; border-collapse: collapse; margin-top: 20px; } .feed-table th, .feed-table td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; } .feed-table th { background: #f8f8f8; } .trigger-btn { display:inline-block; margin-right:6px; } .trigger-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:99999; align-items:center; justify-content:center; } .trigger-modal.active { display:flex; } .modal-content { background:#fff; width:min(700px,90vw); max-height:90vh; overflow:auto; border-radius:8px; box-shadow:0 8px 30px rgba(0,0,0,0.2); } .modal-header { padding:16px 20px; font-size:18px; font-weight:600; border-bottom:1px solid #ddd; } .modal-body { padding:16px 20px; } .modal-field { margin-bottom:12px; } .modal-field label { display:block; font-weight:600; margin-bottom:6px; } .modal-field input, .modal-field textarea { width:100%; box-sizing:border-box; padding:8px 10px; } .modal-footer { border-top:1px solid #ddd; padding:16px 20px; display:flex; justify-content:flex-end; gap:10px; } .trigger-status { display:none; margin-top:12px; padding:10px; border-radius:4px; } .trigger-status.success { display:block; background:#edf7ed; color:#1e7a1e; border:1px solid #b9e5b9; } .trigger-status.error { display:block; background:#fdf0f0; color:#9f2d2d; border:1px solid #f2b7b7; } .trigger-spinner { display:none; margin-right:6px; } </style>';
    echo '<table class="feed-table"><thead><tr><th>Feed Name</th><th>Configuration</th><th>Status</th><th>Frequency</th><th>Actions</th></tr></thead><tbody>';
    $feeds=$this->repo->all();
    if(empty($feeds)){ echo '<tr><td colspan="5">No feeds configured yet.</td></tr>'; } else { foreach($feeds as $f){ $feed_id=$f['id']??''; $feed_json = wp_json_encode($f, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); $name=esc_html($f['name']??'Unnamed Feed'); $enabled = !empty($f['enabled']) ? 'Enabled' : 'Disabled'; $schedule = esc_html($f['schedule'] ?? 'manual'); $config = '<strong>URL:</strong> '.esc_html(substr((string)($f['url']??''),0,80)); echo '<tr><td>'.$name.'</td><td>'.$config.'</td><td>'.$enabled.'</td><td>'.$schedule.'</td><td><a class="button button-small" href="'.esc_url($this->url(['action'=>'edit','id'=>$feed_id])).'">Edit</a> <button type="button" class="button button-small trigger-btn" data-feed-id="'.esc_attr($feed_id).'" data-feed-name="'.esc_attr($name).'" data-feed="'.esc_attr($feed_json).'">Trigger</button> <a class="button button-small" href="'.esc_url($this->url(['action'=>'delete','id'=>$feed_id])).'" onclick="return confirm(\'Delete feed?\')">Delete</a></td></tr>'; } }
    echo '</tbody></table>';
    $this->render_trigger_modal();
    echo '</div>';
    $this->render_trigger_script();
  }
  private function render_trigger_modal(){ ?>
   <div id="triggerModal" class="trigger-modal">
    <div class="modal-content">
     <div class="modal-header">Trigger Feed Import</div>
     <div class="modal-body">
      <div class="modal-field"><label>Feed Name:</label><input type="text" id="modalFeedName" readonly style="background-color: #f5f5f5;"></div>
      <div class="modal-field"><label>Feed URL:</label><textarea id="modalFeedUrl" readonly style="background-color: #f5f5f5;"></textarea></div>
      <div class="modal-field"><label>Format:</label><input type="text" id="modalFormat" readonly style="background-color: #f5f5f5;"></div>
      <div class="modal-field"><label>Authentication Type:</label><input type="text" id="modalAuthType" readonly style="background-color: #f5f5f5;"></div>
      <div id="modalPathParams" style="display:none;"><div class="modal-field"><label>Path Parameters:</label><textarea id="modalPathParamsText" readonly style="background-color: #f5f5f5;"></textarea></div></div>
      <div id="modalQueryParams" style="display:none;"><div class="modal-field"><label>Query Parameters:</label><textarea id="modalQueryParamsText" readonly style="background-color: #f5f5f5;"></textarea></div></div>
      <div class="modal-field"><label>Mappings:</label><textarea id="modalMappings" readonly style="background-color: #f5f5f5;"></textarea></div>
      <div class="modal-field"><label><input type="checkbox" id="modalSkipZeroStock" disabled> Skip zero stock</label></div>
      <div id="triggerStatus" class="trigger-status"></div>
     </div>
     <div class="modal-footer">
      <button type="button" class="button" id="cancelBtn">Cancel</button>
      <button type="button" class="button button-primary" id="startBtn"><span class="trigger-spinner" id="triggerSpinner">⏳</span>Start Import</button>
     </div>
    </div>
   </div>
  <?php }
  private function render_trigger_script(){ $nonce = wp_create_nonce('wpfi_trigger_nonce'); ?>
  <script>
  window.WPFITriggerModal = window.WPFITriggerModal || {};
  (function() {
    var currentFeedId = '';
    var triggerNonce = <?php echo wp_json_encode($nonce); ?>;

    function openTriggerModal(feedId, feedName, feedData) {
      currentFeedId = feedId;
      document.getElementById('modalFeedName').value = feedName || '';
      document.getElementById('modalFeedUrl').value = (feedData && feedData.url) ? feedData.url : '';
      document.getElementById('modalFormat').value = ((feedData && feedData.format) ? feedData.format : 'xml').toUpperCase();

      var authType = (feedData && feedData.auth && feedData.auth.type) ? feedData.auth.type : 'none';
      document.getElementById('modalAuthType').value = authType.charAt(0).toUpperCase() + authType.slice(1).replace('_', ' ');

      var pathParams = (feedData && feedData.auth && feedData.auth.path_params) ? feedData.auth.path_params : {};
      if (Object.keys(pathParams).length > 0) {
        document.getElementById('modalPathParams').style.display = 'block';
        var pathParamsText = '';
        for (var key in pathParams) {
          if (Object.prototype.hasOwnProperty.call(pathParams, key)) {
            pathParamsText += key + ' = ' + pathParams[key] + '\n';
          }
        }
        document.getElementById('modalPathParamsText').value = pathParamsText.trim();
      } else {
        document.getElementById('modalPathParams').style.display = 'none';
      }

      var queryParams = (feedData && feedData.auth && feedData.auth.query_params) ? feedData.auth.query_params : {};
      if (Object.keys(queryParams).length > 0) {
        document.getElementById('modalQueryParams').style.display = 'block';
        var queryParamsText = '';
        for (var key in queryParams) {
          if (Object.prototype.hasOwnProperty.call(queryParams, key)) {
            queryParamsText += key + ' = ' + queryParams[key] + '\n';
          }
        }
        document.getElementById('modalQueryParamsText').value = queryParamsText.trim();
      } else {
        document.getElementById('modalQueryParams').style.display = 'none';
      }

      var mappings = (feedData && feedData.map) ? feedData.map : {};
      var mappingsText = '';
      for (var key in mappings) {
        if (Object.prototype.hasOwnProperty.call(mappings, key)) {
          mappingsText += key + ' = ' + mappings[key] + '\n';
        }
      }
      document.getElementById('modalMappings').value = mappingsText.trim();
      document.getElementById('modalSkipZeroStock').checked = !!(feedData && feedData.skip_zero_stock === 1);
      document.getElementById('triggerStatus').style.display = 'none';
      document.getElementById('triggerStatus').innerHTML = '';
      document.getElementById('triggerModal').classList.add('active');
    }

    function closeTriggerModal() {
      var modal = document.getElementById('triggerModal');
      if (modal) {
        modal.classList.remove('active');
      }
      currentFeedId = '';
    }

    function confirmTrigger() {
      if (!currentFeedId) {
        alert('Feed ID not set');
        return;
      }

      var startBtn = document.getElementById('startBtn');
      var cancelBtn = document.getElementById('cancelBtn');
      var spinner = document.getElementById('triggerSpinner');
      var statusDiv = document.getElementById('triggerStatus');

      startBtn.disabled = true;
      cancelBtn.disabled = true;
      spinner.style.display = 'inline';

      fetch(ajaxurl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'action=wpfi_trigger_feed&feed_id=' + encodeURIComponent(currentFeedId) + '&nonce=' + encodeURIComponent(triggerNonce)
      })
      .then(function(response) {
        return response.text().then(function(text) {
          if (!text) {
            throw new Error('Empty AJAX response from server.');
          }
          var parsed = null;
          try {
            parsed = JSON.parse(text);
          } catch (e) {
            throw new Error('Non-JSON AJAX response: ' + text);
          }
          return parsed;
        });
      })
      .then(function(data) {
        spinner.style.display = 'none';
        statusDiv.style.display = 'block';

        if (data && data.success) {
          statusDiv.className = 'trigger-status success';
          var message = (data && data.data && data.data.message) ? data.data.message : 'Feed import has been initiated.';
          statusDiv.innerHTML = '<strong>✓ Import Started</strong><br>' + message;
          setTimeout(function() {
            closeTriggerModal();
            location.reload();
          }, 2000);
          return;
        }

        var errorMessage = (data && data.data && data.data.message) ? data.data.message : 'Unknown AJAX error';
        statusDiv.className = 'trigger-status error';
        statusDiv.innerHTML = '<strong>✗ Import Failed</strong><br>' + errorMessage;
        startBtn.disabled = false;
        cancelBtn.disabled = false;
      })
      .catch(function(error) {
        console.error('Trigger error:', error);
        spinner.style.display = 'none';
        statusDiv.style.display = 'block';
        statusDiv.className = 'trigger-status error';
        statusDiv.innerHTML = '<strong>✗ Import Failed</strong><br>' + (error && error.message ? error.message : 'Unknown AJAX error');
        startBtn.disabled = false;
        cancelBtn.disabled = false;
      });
    }

    window.WPFITriggerModal.open = openTriggerModal;
    window.WPFITriggerModal.close = closeTriggerModal;
    window.WPFITriggerModal.confirm = confirmTrigger;
    window.openTriggerModal = openTriggerModal;
    window.closeTriggerModal = closeTriggerModal;
    window.confirmTrigger = confirmTrigger;

    document.addEventListener('DOMContentLoaded', function() {
      var modal = document.getElementById('triggerModal');
      var cancelBtn = document.getElementById('cancelBtn');
      var startBtn = document.getElementById('startBtn');

      if (modal) {
        modal.addEventListener('click', function(e) { if (e.target === this) { closeTriggerModal(); } });
      }

      if (cancelBtn) {
        cancelBtn.addEventListener('click', closeTriggerModal);
      }

      if (startBtn) {
        startBtn.addEventListener('click', confirmTrigger);
      }

      document.querySelectorAll('.trigger-btn').forEach(function(button) {
        button.addEventListener('click', function() {
          var feedId = button.getAttribute('data-feed-id') || '';
          var feedName = button.getAttribute('data-feed-name') || '';
          var feedData = button.getAttribute('data-feed') || '{}';
          try {
            feedData = JSON.parse(feedData);
          } catch (e) {
            feedData = {};
          }
          openTriggerModal(feedId, feedName, feedData);
        });
      });
    });
  })();
  </script>
  <?php }
  public function ajax_trigger(){
    if(!$this->can()){ error_log('[WPFI] Trigger denied: no permission'); wp_send_json_error(['message'=>'Permission denied'], 403); }
    if(!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'],'wpfi_trigger_nonce')){ error_log('[WPFI] Trigger denied: bad nonce'); wp_send_json_error(['message'=>'Security check failed'], 403); }
    $feed_id=sanitize_text_field($_POST['feed_id']??'');
    error_log('[WPFI] Trigger request for feed_id=' . $feed_id);
    if(empty($feed_id)){ wp_send_json_error(['message'=>'Feed ID is required'], 400); }
    $feed=$this->repo->get($feed_id); if(!$feed){ error_log('[WPFI] Trigger failed: feed not found ' . $feed_id); wp_send_json_error(['message'=>'Feed not found'], 404); }
    if(empty($feed['enabled'])){ error_log('[WPFI] Trigger failed: feed disabled ' . $feed_id); wp_send_json_error(['message'=>'Feed is disabled'], 400); }
    try{
      $this->log("Trigger feed via AJAX", 'info', ['feed_id'=>$feed_id, 'feed_name'=>$feed['name']??'']);
      if(!is_callable([$this->importer,'import'])){ error_log('[WPFI] Importer not callable for feed ' . $feed_id); wp_send_json_error(['message'=>'Importer error'], 500); }
      $result=$this->importer->import($feed);
      if($result){ $this->log("Feed import completed successfully", 'info', ['feed_id'=>$feed_id]); wp_send_json_success(['message'=>'Feed import completed successfully']); }
      $this->log("Feed import failed", 'error', ['feed_id'=>$feed_id]);
      error_log('[WPFI] Manual trigger returned false for feed ' . $feed_id);
      wp_send_json_error(['message'=>'Feed import failed'], 500);
    } catch(Throwable $e){
      $error_msg='Error triggering feed: '.$e->getMessage();
      $this->log($error_msg, 'error', ['feed_id'=>$feed_id,'exception'=>$e->getTraceAsString()]);
      error_log('[WPFI] Trigger exception: ' . $error_msg);
      wp_send_json_error(['message'=>$error_msg], 500);
    }
  }
  private function edit(){
    $id=sanitize_text_field($_GET['id']??''); $preset=sanitize_key($_GET['preset']??'');
    if($preset==='pinnacle') { $f=$this->repo->pinnacle_preset(); } else { $f=wp_parse_args($id?($this->repo->get($id)??[]):[],$this->repo->defaults()); }
    $a=$f['auth']??[]; $this->log("Feed edit form opened", 'info', ['feed_id'=>$id??'new','preset'=>$preset]);
    echo '<div class="wrap"><h1>'.($id?'Edit':'Add').' XML Feed'.($preset==='pinnacle'?' (Pinnacle)':'').'</h1><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data">';
    wp_nonce_field('wpfi_save_feed');
    if($id){ echo '<input type="hidden" name="id" value="'.esc_attr($id).'">'; }
    echo '<h2>Feed Settings</h2><table class="form-table"><tbody><tr><th><label for="name">Feed Name</label></th><td><input type="text" id="name" name="name" value="'.esc_attr($f['name']??'').'" class="regular-text" required></td></tr><tr><th><label for="url">Feed URL</label></th><td><input type="url" id="url" name="url" value="'.esc_attr($f['url']??'').'" class="regular-text" required></td></tr><tr><th><label for="enabled">Enabled</label></th><td><input type="checkbox" id="enabled" name="enabled" value="1" '.checked(!empty($f['enabled']),true,false).'></td></tr><tr><th><label for="format">Format</label></th><td><select id="format" name="format"><option value="xml" '.selected(($f['format']??'xml'),'xml',false).'>XML</option><option value="csv" '.selected(($f['format']??'xml'),'csv',false).'>CSV</option></select></td></tr><tr><th><label for="schedule">Schedule</label></th><td><select id="schedule" name="schedule"><option value="manual" '.selected(($f['schedule']??'manual'),'manual',false).'>Manual</option><option value="hourly" '.selected(($f['schedule']??'manual'),'hourly',false).'>Hourly</option><option value="daily" '.selected(($f['schedule']??'manual'),'daily',false).'>Daily</option></select></td></tr></tbody></table>';
    echo '<h2>Authentication</h2><table class="form-table"><tbody><tr><th><label for="auth_type">Authentication Type</label></th><td><select id="auth_type" name="auth[type]"><option value="none" '.selected(($a['type']??'none'),'none',false).'>None</option><option value="api_key" '.selected(($a['type']??'none'),'api_key',false).'>API Key</option><option value="basic" '.selected(($a['type']??'none'),'basic',false).'>Basic</option></select></td></tr><tr><th><label for="auth_api_key_name">API Key Header</label></th><td><input type="text" id="auth_api_key_name" name="auth[api_key_name]" value="'.esc_attr($a['api_key_name']??'X-API-Key').'" class="regular-text"></td></tr><tr><th><label for="auth_api_key">API Key</label></th><td><input type="text" id="auth_api_key" name="auth[api_key]" value="'.esc_attr($a['api_key']??'').'" class="regular-text"></td></tr><tr><th><label for="auth_username">Username</label></th><td><input type="text" id="auth_username" name="auth[username]" value="'.esc_attr($a['username']??'').'" class="regular-text"></td></tr><tr><th><label for="auth_password">Password</label></th><td><input type="password" id="auth_password" name="auth[password]" value="'.esc_attr($a['password']??'').'" class="regular-text"></td></tr></tbody></table>';
    echo '<h2>Field Mappings</h2><table class="form-table"><tbody><tr><th><label for="map">Field Mappings</label></th><td><textarea id="map" name="map" rows="10" class="large-text code" placeholder="sku=sku\nname=name\ndescription=description">'.esc_textarea($this->format_map($f['map']??[])).'</textarea></td></tr></tbody></table>';
    submit_button();
    echo '</form></div>';
  }
  public function save(){ try { $this->log("Save feed initiated"); if(!$this->can()) { $this->log("Permission denied on save", 'error'); wp_die('Permission denied.'); } if(!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'],'wpfi_save_feed')) { $this->log("Security check failed on save", 'error'); wp_die('Security check failed.'); } $f=$this->repo->defaults(); $f['id']=sanitize_text_field($_POST['id']??''); if(empty($f['id'])) { $f['id']=wp_generate_uuid4(); $this->log("New feed created with ID: ".$f['id']); } else { $this->log("Updating existing feed: ".$f['id']); } $f['name']=sanitize_text_field($_POST['name']??''); $f['url']=esc_url_raw($_POST['url']??''); $f['enabled']=!empty($_POST['enabled'])?1:0; $f['format']=sanitize_key($_POST['format']??'xml'); $f['schedule']=sanitize_key($_POST['schedule']??'manual'); $f['auth']=is_array($_POST['auth']??null)?$_POST['auth']:[]; $f['auth']['type']=sanitize_key($f['auth']['type']??'none'); $f['auth']['api_key_name']=sanitize_text_field($f['auth']['api_key_name']??'X-API-Key'); $f['auth']['api_key']=sanitize_text_field($f['auth']['api_key']??''); $f['auth']['username']=sanitize_text_field($f['auth']['username']??''); $f['auth']['password']=sanitize_text_field($f['auth']['password']??''); if(!empty($_POST['map'])) { $map_raw=sanitize_textarea_field(wp_unslash($_POST['map']??'')); $f['map']=$this->parse_map($map_raw); } $this->repo->save($f); $this->log("Feed saved", 'info', ['feed_id'=>$f['id']]); wp_safe_redirect($this->url(['notice'=>'saved'])); exit; } catch(Exception $e) { $error_msg='Error saving feed: '.$e->getMessage(); $this->log($error_msg, 'error', ['exception'=>$e->getTraceAsString()]); wp_die($error_msg); } }
  public function delete(){ try { $id=sanitize_text_field($_GET['id']??''); $this->log("Delete feed initiated", 'info', ['feed_id'=>$id]); if(!$this->can()) { $this->log("Permission denied on delete", 'error'); wp_die('Permission denied.'); } if(!empty($id)){ $this->repo->delete($id); } wp_safe_redirect($this->url(['notice'=>'deleted'])); exit; } catch(Exception $e) { $error_msg='Error deleting feed: '.$e->getMessage(); $this->log($error_msg, 'error', ['exception'=>$e->getTraceAsString()]); wp_die($error_msg); } }
  public function trigger(){ wp_die('Please use the modal interface to trigger imports.'); }
  private function format_params($params){ if(empty($params))return ''; $lines=[]; foreach((array)$params as $k=>$v) { if(!empty($k)) { $lines[]=$k.'='.(string)$v; } } return implode("\n",$lines); }
  private function parse_params($raw){ $raw=(string)$raw; if(empty($raw))return []; $lines=preg_split('/\r\n|\r|\n/',$raw); $params=[]; foreach($lines as $line){ $line=trim($line); if($line==='')continue; $parts=explode('=', $line, 2); if(count($parts)===2){ $k=trim($parts[0]); $v=trim($parts[1]); if($k!=='') $params[$k]=$v; } } return $params; }
  private function format_map($map){ if(empty($map))return ''; $lines=[]; foreach((array)$map as $k=>$v) { if(!empty($k)) { $lines[]=$k.'='.(string)$v; } } return implode("\n",$lines); }
  private function parse_map($raw){ $raw=(string)$raw; if(empty($raw))return []; $lines=preg_split('/\r\n|\r|\n/',$raw); $map=[]; foreach($lines as $line){ $line=trim($line); if($line==='')continue; $parts=explode('=', $line, 2); if(count($parts)===2){ $k=trim($parts[0]); $v=trim($parts[1]); if($k!=='') $map[$k]=$v; } } return $map; }
}
