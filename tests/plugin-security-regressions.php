<?php
/** Disposable WordPress integration tests for the plugin-wide review. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
function cs_full_test( $ok, $message ) {
 if ( ! $ok ) { throw new RuntimeException( $message ); }
 WP_CLI::log( 'PASS ' . $message );
}
$admin = get_users( array( 'role'=>'administrator','number'=>1 ) )[0]->ID;
wp_set_current_user( $admin );
$cs = cs_create_changeset( 'Plugin review' );
foreach ( array( ' default_role ', 'DEFAULT_ROLE', 'défault_role', 'Users_Can_Register', $GLOBALS['wpdb']->prefix . 'user_roles', 'cron' ) as $key ) {
 $result = cs_stage_option( $cs, $key, 'administrator' );
 cs_full_test( is_wp_error( $result ) && 'cs_denylisted_option' === $result->get_error_code(), 'Unsafe canonical/alias key denied: ' . $key );
 update_post_meta( $cs, '_changeset_staged_options', array( $key=>array('value'=>'administrator','store'=>'option') ) );
 cs_approve_changeset( $cs );
 $result = cs_publish_changeset( $cs );
 cs_full_test( is_wp_error( $result ) && 'cs_denylisted_option' === $result->get_error_code(), 'Legacy canonical/alias bag denied before publish' );
 cs_full_test( ! get_option( '_changeset_publish_lock_' . $cs ), 'Validation failure releases publication lock' );
}
delete_post_meta( $cs, '_changeset_staged_options' );
$source = wp_insert_post( array( 'post_type'=>'post','post_status'=>'publish','post_title'=>'Source','post_content'=>'Reviewed' ) );
$result = cs_stage_content( $cs, $source, 'page' );
cs_full_test( is_wp_error( $result ) && 'cs_source_type_mismatch' === $result->get_error_code(), 'Source type mismatch rejected' );
$result = cs_ability_save_content( $cs, array('source_id'=>$source) );
cs_full_test( ! is_wp_error( $result ) && 'post' === $result['post_type'], 'Omitted source type inferred correctly' );
$staged = $result['staged_id'];
cs_stage_option( $cs, 'blogname', 'Approved snapshot setting' );
cs_approve_changeset( $cs );
// Bypass mutation hooks deliberately to verify the payload fingerprint itself.
global $wpdb;
$wpdb->update( $wpdb->posts, array('post_content'=>'Unreviewed direct mutation'), array('ID'=>$staged) );
clean_post_cache( $staged );
$result = cs_publish_changeset( $cs );
cs_full_test( is_wp_error( $result ) && 'cs_not_approved' === $result->get_error_code(), 'Payload fingerprint rejects mutation without hooks' );
cs_full_test( 'Reviewed' === get_post($source)->post_content, 'Fingerprint rejection has no live content side effects' );
cs_full_test( ! cs_is_changeset_approved($cs), 'Fingerprint rejection clears approval' );
wp_update_post( array('ID'=>$staged,'post_content'=>'Approved frozen content') );
cs_approve_changeset( $cs );
// Deterministic interleaving after snapshot: data applied must stay reviewed.
$mutate = function( $id ) use ( $source, $staged, $cs ) {
 if ( $id === $source ) {
  wp_update_post( array('ID'=>$staged,'post_content'=>'Injected after snapshot') );
  update_post_meta( $cs, '_changeset_staged_options', array('blogname'=>array('value'=>'Unreviewed option','store'=>'option')) );
 }
};
add_action( 'post_updated', $mutate );
$old_name = get_option('blogname');
$result = cs_publish_changeset($cs);
remove_action( 'post_updated', $mutate );
cs_full_test( ! is_wp_error($result) && 'Approved frozen content' === get_post($source)->post_content, 'Publication uses frozen approved content' );
cs_full_test( 'Approved snapshot setting' === get_option('blogname'), 'Publication uses frozen approved option bag' );
update_option('blogname',$old_name);
cs_full_test( ! get_option('_changeset_publish_lock_'.$cs), 'Successful publication releases lock' );
wp_delete_post($source,true);wp_delete_post($cs,true);
$cs=cs_create_changeset('Legacy CSS');
$bad=array('version'=>3,'styles'=>array('css'=>'</style><script id="CS_FULL_MARKUP">/* inert */</script>','color'=>array('text'=>'#112233')));
cs_full_test( is_wp_error(cs_set_staged_global_styles($cs,$bad)), 'Markup custom CSS rejected at staging' );
$safe=cs_sanitize_global_styles($bad);
cs_full_test( ! isset($safe['styles']['css']), 'Legacy custom CSS markup removed for every reader' );
cs_full_test( '#112233' === $safe['styles']['color']['text'], 'Safe visual style preserved' );
cs_full_test( true === cs_set_staged_global_styles($cs,array('version'=>3,'styles'=>array('css'=>'body { color: red; }'))), 'Admin safe custom CSS still stageable' );
wp_delete_post($cs,true);
cs_full_test( ! cs_is_previewable_option('unknown_auth_plugin_setting'), 'Unknown plugin options cannot affect public preview' );
cs_full_test( cs_is_previewable_option('blogname'), 'Presentation option still previewable' );
WP_CLI::success('Plugin-wide security regressions passed.');
