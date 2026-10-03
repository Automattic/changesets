<?php
/** Disposable localhost integration tests: wp eval-file <file> [private|invalid-preview TOKEN]. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
if ( ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1', '::1' ), true ) ) { WP_CLI::error( 'Use a disposable localhost installation.' ); }
$count = 0;
function cs_test( $condition, $message ) {
 global $count;
 if ( ! $condition ) { throw new RuntimeException( $message ); }
 ++$count;
 WP_CLI::log( 'PASS ' . $message );
}
function cs_test_error( $result, $code, $message ) {
 cs_test( is_wp_error( $result ) && $code === $result->get_error_code(), $message );
}
// Clean up test-created records even when an assertion fails.
global $wpdb;
$before_name = get_option( 'blogname' );
$before_posts = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" );
$before_users = $wpdb->get_col( "SELECT ID FROM {$wpdb->users}" );
$before_terms = $wpdb->get_col( "SELECT term_id FROM {$wpdb->terms}" );
register_shutdown_function( function() use ( $wpdb, $before_posts, $before_users, $before_terms, $before_name ) {
 update_option( 'blogname', $before_name );
 require_once ABSPATH . 'wp-admin/includes/user.php';
 foreach ( array_diff( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ), $before_posts ) as $id ) { wp_delete_post( $id, true ); }
 foreach ( array_diff( $wpdb->get_col( "SELECT ID FROM {$wpdb->users}" ), $before_users ) as $id ) { wp_delete_user( $id ); }
 foreach ( array_diff( $wpdb->get_col( "SELECT term_id FROM {$wpdb->terms}" ), $before_terms ) as $id ) { wp_delete_term( $id, 'category' ); }
} );
register_shutdown_function( function() { global $count; WP_CLI::log( "Completed $count integration assertions." ); } );
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
if ( isset( $args[0] ) && 'invalid-preview' === $args[0] ) {
	$cs = cs_create_changeset( 'Invalid public token' );
	cs_create_staged_content( $cs, 'page', 'Hidden staged page' );
	wp_set_current_user( 0 );
	$token = isset( $args[1] ) && 'unknown' === $args[1] ? wp_generate_uuid4() : (string) $cs;
	if ( isset( $args[1] ) && 'array' === $args[1] ) {
		$token = array( cs_get_changeset_uuid( $cs ) );
	}
	if ( isset( $args[1] ) && 'cookie' === $args[1] ) {
		$_COOKIE['changeset'] = $token;
	} else {
		$_GET['changeset'] = $token;
	}
	cs_test( null === cs_preview_staged_index(), 'Invalid public token exposes no staged content index' );
	cs_test( array() === cs_previewing_admin_body_class( array() ), 'Invalid public token has no preview UI alias' );
	ob_start();
	cs_render_changeset_bar();
	cs_test( '' === ob_get_clean(), 'Invalid public token renders no changeset metadata' );
	unset( $_GET['changeset'], $_COOKIE['changeset'] );
	return;
}

// Permissions, approval mutation and public preview boundaries.
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
wp_set_current_user( $admin );
$cs = cs_create_changeset( 'Security regression ' . wp_generate_uuid4() );
$uuid = cs_get_changeset_uuid( $cs );
$live_registration = get_option( 'users_can_register' );
$live_role = get_option( 'default_role' );
cs_test( null === cs_get_preview_changeset( (string) $cs ), 'Public resolver rejects internal numeric ID' );
cs_test( null === cs_get_preview_changeset( wp_generate_uuid4() ), 'Unknown UUID cannot resolve staged content' );
cs_test( $cs === cs_get_preview_changeset( $uuid )->ID, 'Valid UUID resolves open changeset' );
$private = isset( $args[0] ) && 'private' === $args[0];
if ( $private ) {
	define( 'CHANGESETS_PRIVATE_PREVIEWS', true );
	wp_set_current_user( 0 );
	$_GET['changeset'] = $uuid;
	cs_test( null === cs_get_active_preview_uuid(), 'Private UUID preview denied to anonymous visitor' );
	wp_set_current_user( $admin );
	cs_test( $uuid === cs_get_active_preview_uuid(), 'Private UUID preview available to Administrator' );
	return;
}
foreach ( array( 'contributor', 'author', 'editor' ) as $role ) {
	$id = wp_insert_user( array( 'user_login' => 'cs-test-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => $role ) );
	wp_set_current_user( $id );
	cs_test( cs_user_can_manage_changesets(), "$role can propose changes" );
	cs_test_error( cs_stage_option( $cs, 'blogname', 'Proposal' ), 'cs_forbidden', "$role cannot stage settings" );
	cs_test( ! cs_ability_can_save( array( 'type' => 'setting' ) ), "$role setting ability denied" );
	if ( 'editor' !== $role ) {
		cs_test_error( cs_approve_changeset( $cs ), 'cs_forbidden', "$role cannot approve" );
		cs_test_error( cs_publish_changeset( $cs ), 'cs_forbidden', "$role cannot publish directly" );
	} else {
		wp_set_current_user( $admin );
		cs_stage_option( $cs, 'blogname', 'Admin setting' );
		wp_set_current_user( $id );
		cs_test_error( cs_approve_changeset( $cs ), 'cs_forbidden', 'Editor cannot approve settings' );
		wp_set_current_user( $admin );
		cs_approve_changeset( $cs );
		wp_set_current_user( $id );
		cs_test_error( cs_publish_changeset( $cs ), 'cs_forbidden', 'Editor cannot publish Admin-approved settings' );
		wp_set_current_user( $admin );
		delete_post_meta( $cs, '_changeset_staged_options' );
		wp_set_current_user( $id );
		cs_test( true === cs_approve_changeset( $cs ), 'Editor can approve content' );
		cs_test( ! is_wp_error( cs_publish_changeset( $cs ) ), 'Editor can publish approved content' );
		wp_set_current_user( $admin );
		$cs = cs_create_changeset( 'Admin regression' );
		$uuid = cs_get_changeset_uuid( $cs );
	}
	wp_set_current_user( $admin );
}
cs_test_error( cs_stage_option( $cs, 'users_can_register', 1 ), 'cs_denylisted_option', 'Registration option denied even to Admin' );
cs_test_error( cs_stage_option( $cs, 'default_role', 'administrator' ), 'cs_denylisted_option', 'Default role denied even to Admin' );
cs_stage_option( $cs, 'blogname', 'Safe preview' );
cs_approve_changeset( $cs );
cs_stage_option( $cs, 'blogdescription', 'Changed' );
cs_test( ! cs_is_changeset_approved( $cs ), 'Settings mutation revokes approval' );
cs_test_error( cs_publish_changeset( $cs ), 'cs_not_approved', 'Direct publish requires fresh approval' );
$draft = cs_create_staged_content( $cs, 'page', 'Staged', 'Secret' );
cs_approve_changeset( $cs );
cs_update_staged_content( $draft, array( 'content' => 'New secret' ) );
cs_test( ! cs_is_changeset_approved( $cs ), 'Content mutation revokes approval' );
cs_approve_changeset( $cs );
update_post_meta( $draft, '_thumbnail_id', 123 );
cs_test( ! cs_is_changeset_approved( $cs ), 'Media reference mutation revokes approval' );
cs_approve_changeset( $cs );
cs_set_staged_global_styles( $cs, array( 'version' => 3, 'styles' => array() ) );
cs_test( ! cs_is_changeset_approved( $cs ), 'Styles mutation revokes approval' );
cs_approve_changeset( $cs );
cs_create_staged_content( $cs, 'page', 'Added after approval' );
cs_test( ! cs_is_changeset_approved( $cs ), 'Adding content revokes approval' );
cs_approve_changeset( $cs );
wp_delete_post( $draft, true );
cs_test( ! cs_is_changeset_approved( $cs ), 'Deleting staged content revokes approval' );
$draft = cs_create_staged_content( $cs, 'page', 'Staged again' );
// Seed an old-format dangerous bag to exercise upgrade protection.
update_post_meta( $cs, '_changeset_staged_options', array( 'users_can_register' => '1', 'default_role' => array( 'value' => 'administrator', 'store' => 'option' ), 'blogname' => 'Safe preview' ) );
cs_approve_changeset( $cs );
cs_test_error( cs_publish_changeset( $cs ), 'cs_denylisted_option', 'Old dangerous bags rejected before publishing content' );
cs_test( 'draft' === get_post_status( $draft ), 'Rejected publish has no content side effects' );
foreach ( array( (string) $cs, '1e2', '+1', 'bad', array( $uuid ), $uuid . 'x' ) as $token ) {
	$_GET['changeset'] = $token;
	$_COOKIE['changeset'] = $uuid;
	cs_test( null === cs_get_active_preview_uuid(), 'Invalid query token rejected without cookie fallback' );
	unset( $_GET['changeset'] );
	$_COOKIE['changeset'] = $token;
	cs_test( null === cs_get_active_preview_uuid(), 'Invalid cookie token rejected' );
}
wp_set_current_user( 0 );
$_GET['changeset'] = $uuid;
cs_preview_init_dynamic_filters();
cs_test( ! has_filter( 'pre_option_users_can_register', 'cs_preview_filter_option' ), 'Old registration option not hooked for query preview' );
cs_test( ! has_filter( 'pre_option_default_role', 'cs_preview_filter_option' ), 'Old role option not hooked for query preview' );
cs_test( 'Safe preview' === get_option( 'blogname' ), 'Valid shared UUID overlays safe setting anonymously' );
cs_test( in_array( $draft, cs_preview_staged_index()['new_ids'], true ), 'Valid UUID preview includes staged content' );
cs_test( $live_registration === get_option( 'users_can_register' ) && $live_role === get_option( 'default_role' ), 'Query preview preserves live registration policy' );
cs_test( false === cs_preview_filter_option( false, 'default_role' ), 'Direct unsafe option overlay blocked' );
unset( $_GET['changeset'] );
$_COOKIE['changeset'] = $uuid;
cs_test( $uuid === cs_get_active_preview_uuid(), 'Valid UUID cookie preview preserved' );
cs_test( 'Safe preview' === get_option( 'blogname' ), 'Cookie safe option preview preserved' );
cs_test( $live_registration === get_option( 'users_can_register' ) && $live_role === get_option( 'default_role' ), 'Cookie preview preserves live registration policy' );
wp_set_current_user( $admin );
unset( $_COOKIE['changeset'] );
cs_clear_preview_cookie();
update_post_meta( $cs, '_changeset_staged_options', array() );
cs_approve_changeset( $cs );
cs_test( ! is_wp_error( cs_publish_changeset( $cs ) ), 'Admin approved content publishes successfully' );
cs_test( null === cs_get_preview_changeset( $uuid ), 'Published changeset is no longer previewable' );
cs_test_error( cs_approve_changeset( $cs ), 'cs_closed_changeset', 'Published changeset cannot be reapproved' );


// Taxonomy, membership reassignment and legacy storage.
$cs = cs_create_changeset( 'Review regression' );
$draft = cs_create_staged_content( $cs, 'post', 'Review staged post' );
$term = wp_insert_term( 'Review ' . wp_generate_uuid4(), 'category' )['term_id'];
cs_approve_changeset( $cs );
wp_set_object_terms( $draft, array( $term ), 'category' );
cs_test( ! cs_is_changeset_approved( $cs ), 'Native taxonomy assignment revokes approval' );
cs_approve_changeset( $cs );
wp_remove_object_terms( $draft, array( $term ), 'category' );
cs_test( ! cs_is_changeset_approved( $cs ), 'Native taxonomy removal revokes approval' );
$destination = cs_create_changeset( 'Review destination' );
cs_approve_changeset( $cs );
cs_approve_changeset( $destination );
update_post_meta( $draft, '_changeset_id', $destination );
cs_test( ! cs_is_changeset_approved( $cs ), 'Moving staged content revokes source approval' );
cs_test( ! cs_is_changeset_approved( $destination ), 'Moving staged content revokes destination approval' );
$live_role = get_option( 'default_role' );
foreach ( array( array( 'value' => 'administrator', 'store' => 'unknown' ), array( 'store' => 'theme_mod' ) ) as $item ) {
	$cs = cs_create_changeset( 'Legacy bag review' );
	$draft = cs_create_staged_content( $cs, 'page', 'Must not publish' );
	update_post_meta( $cs, '_changeset_staged_options', array( 'default_role' => $item ) );
	cs_approve_changeset( $cs );
	$result = cs_publish_changeset( $cs );
	// Restore policy even when running this test against the vulnerable baseline.
	update_option( 'default_role', $live_role );
	cs_test( is_wp_error( $result ) && 'cs_denylisted_option' === $result->get_error_code(), 'Malformed legacy storage rejected before publication' );
	cs_test( 'draft' === get_post_status( $draft ), 'Malformed legacy bag has no content side effects' );
}
$cs = cs_create_changeset( 'Malformed preview bag' );
update_post_meta( $cs, '_changeset_staged_options', array( 'default_role' => array( 'value' => 'administrator', 'store' => 'unknown' ), 'users_can_register' => array( 'store' => 'theme_mod' ) ) );
$_GET['changeset'] = cs_get_changeset_uuid( $cs );
wp_set_current_user( 0 );
cs_preview_init_dynamic_filters();
cs_test( ! has_filter( 'pre_option_default_role', 'cs_preview_filter_option' ) && ! has_filter( 'pre_option_users_can_register', 'cs_preview_filter_option' ), 'Malformed legacy options cannot install preview overlays' );
unset( $_GET['changeset'] );
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
$cs = cs_create_changeset( 'Safe theme mod' );
cs_test( true === cs_stage_option( $cs, 'custom_logo', 0 ), 'Admin can still stage a valid theme mod' );
cs_approve_changeset( $cs );
cs_test( ! is_wp_error( cs_publish_changeset( $cs ) ), 'Admin can still publish a valid theme mod' );

// Option aliases, payload integrity, locks and global styles.
$admin = get_users( array( 'role'=>'administrator','number'=>1 ) )[0]->ID;
wp_set_current_user( $admin );
$cs = cs_create_changeset( 'Plugin review' );
foreach ( array( ' default_role ', 'DEFAULT_ROLE', 'défault_role', 'Users_Can_Register', $GLOBALS['wpdb']->prefix . 'user_roles', 'cron' ) as $key ) {
 $result = cs_stage_option( $cs, $key, 'administrator' );
 cs_test( is_wp_error( $result ) && 'cs_denylisted_option' === $result->get_error_code(), 'Unsafe canonical/alias key denied: ' . $key );
 update_post_meta( $cs, '_changeset_staged_options', array( $key=>array('value'=>'administrator','store'=>'option') ) );
 cs_approve_changeset( $cs );
 $result = cs_publish_changeset( $cs );
 cs_test( is_wp_error( $result ) && 'cs_denylisted_option' === $result->get_error_code(), 'Legacy canonical/alias bag denied before publish' );
}
cs_test( ! get_option( '_changeset_publish_lock_' . $cs ), 'Validation failure releases publication lock' );
delete_post_meta( $cs, '_changeset_staged_options' );
$source = wp_insert_post( array( 'post_type'=>'post','post_status'=>'publish','post_title'=>'Source','post_content'=>'Reviewed' ) );
$result = cs_stage_content( $cs, $source, 'page' );
cs_test( is_wp_error( $result ) && 'cs_source_type_mismatch' === $result->get_error_code(), 'Source type mismatch rejected' );
$result = cs_ability_save_content( $cs, array('source_id'=>$source) );
cs_test( ! is_wp_error( $result ) && 'post' === $result['post_type'], 'Omitted source type inferred correctly' );
$staged = $result['staged_id'];
cs_stage_option( $cs, 'blogname', 'Approved snapshot setting' );
cs_approve_changeset( $cs );
// Bypass mutation hooks deliberately to verify the payload fingerprint itself.
global $wpdb;
$wpdb->update( $wpdb->posts, array('post_content'=>'Unreviewed direct mutation'), array('ID'=>$staged) );
clean_post_cache( $staged );
$result = cs_publish_changeset( $cs );
cs_test( is_wp_error( $result ) && 'cs_not_approved' === $result->get_error_code(), 'Payload fingerprint rejects mutation without hooks' );
cs_test( 'Reviewed' === get_post($source)->post_content, 'Fingerprint rejection has no live content side effects' );
cs_test( ! cs_is_changeset_approved($cs), 'Fingerprint rejection clears approval' );
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
cs_test( ! is_wp_error($result) && 'Approved frozen content' === get_post($source)->post_content, 'Publication uses frozen approved content' );
cs_test( 'Approved snapshot setting' === get_option('blogname'), 'Publication uses frozen approved option bag' );
update_option('blogname',$old_name);
cs_test( ! get_option('_changeset_publish_lock_'.$cs), 'Successful publication releases lock' );
$cs=cs_create_changeset('Legacy CSS');
$bad=array('version'=>3,'styles'=>array('css'=>'</style><script id="CS_FULL_MARKUP">/* inert */</script>','color'=>array('text'=>'#112233')));
cs_test( is_wp_error(cs_set_staged_global_styles($cs,$bad)), 'Markup custom CSS rejected at staging' );
$safe=cs_sanitize_global_styles($bad);
cs_test( ! isset($safe['styles']['css']), 'Legacy custom CSS markup removed for every reader' );
cs_test( '#112233' === $safe['styles']['color']['text'], 'Safe visual style preserved' );
cs_test( true === cs_set_staged_global_styles($cs,array('version'=>3,'styles'=>array('css'=>'body { color: red; }'))), 'Admin safe custom CSS still stageable' );
cs_test( ! cs_is_previewable_option('unknown_auth_plugin_setting'), 'Unknown plugin options cannot affect public preview' );
cs_test( cs_is_previewable_option('blogname'), 'Presentation option still previewable' );
