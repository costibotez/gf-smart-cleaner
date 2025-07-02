<?php
// admin-ui.php

if ( ! defined( 'ABSPATH' ) ) exit;

// 1) Add submenu
add_action( 'admin_menu', 'gf_spam_cleaner_menu' );
function gf_spam_cleaner_menu() {
    add_submenu_page(
        'gf_edit_forms',
        'Spam Cleaner',
        'Spam Cleaner',
        'manage_options',
        'gf-spam-cleaner',
        'gf_spam_cleaner_page'
    );
}

// 2) Render page
function gf_spam_cleaner_page() {
    ?>
    <div class="wrap">
      <h1>Spam Cleaner</h1>
      <select id="gf-form-select">
        <?php foreach ( GFAPI::get_forms() as $f ): ?>
          <option value="<?php echo esc_attr( $f['id'] ); ?>"><?php echo esc_html( $f['title'] ); ?></option>
        <?php endforeach; ?>
      </select>
      <button id="run-preview" class="button">Run Preview</button>
      <button id="run-cleanup" class="button button-primary">Run Cleanup</button>

      <div style="margin:20px 0;">
        <progress id="progress-bar" value="0" max="1" style="width:100%; height:20px"></progress>
      </div>
      <div id="log" style="height:300px; overflow:auto; background:#fff; padding:10px; border:1px solid #ccc;"></div>
    </div>

    <script>
    jQuery(function($){
      function run(preview){
        var formId = $('#gf-form-select').val();
        $('#log').empty();
        $('#progress-bar').attr('value',0).attr('max',1);

        // get total entries
        $.post(ajaxurl, {action:'gf_spam_count', form_id:formId}, function(r){
          var total = r.data.total, done=0;
          $('#progress-bar').attr('max', total);

          function batch(offset){
            $.post(ajaxurl,{
              action: 'gf_spam_run',
              form_id: formId,
              preview: preview?1:0,
              batch_limit: 100,
              offset: offset
            }, function(res){
              var d = res.data;
              done += d.processed;
              $('#progress-bar').attr('value', done);
              $('#log').append(
                '<div>Batch '+offset+': deleted='+d.deleted+', blocked='+d.blocked+'</div>'
              );
              if(offset + d.processed < total){
                batch(offset + d.processed);
              } else {
                $('#log').append('<div><strong>All done!</strong></div>');
              }
            });
          }
          batch(0);
        });
      }
      $('#run-preview').click(function(){ run(true); });
      $('#run-cleanup').click(function(){ run(false); });
    });
    </script>
    <?php
}

// 3) Count handler
add_action( 'wp_ajax_gf_spam_count', 'gf_spam_cleaner_count' );
function gf_spam_cleaner_count(){
    $total = GFAPI::count_entries( intval( $_POST['form_id'] ) );
    wp_send_json_success( array( 'total' => intval( $total ) ) );
}

// 4) Run handler
add_action( 'wp_ajax_gf_spam_run', 'gf_spam_cleaner_ajax_run' );
function gf_spam_cleaner_ajax_run(){
    $form_id     = intval( $_POST['form_id'] );
    $preview     = boolval( intval( $_POST['preview'] ) );
    $batch_limit = intval( $_POST['batch_limit'] );
    $offset      = intval( $_POST['offset'] );

    $result = gf_smart_spam_cleaner_run( $form_id, $preview, $batch_limit, $offset );
    wp_send_json_success( $result );
}
