<?php
/** Run only in a disposable WordPress installation: wp eval-file <this file> [private]. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
function cs_test( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	WP_CLI::log( 'PASS ' . $message );
}
function cs_test_error( $result, $code, $message ) {
	cs_test( is_wp_error( $result ) && $code === $result->get_error_code(), $message );
}
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
	wp_delete_post( $cs, true );
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
		wp_delete_post( $cs, true );
		$cs = cs_create_changeset( 'Admin regression' );
		$uuid = cs_get_changeset_uuid( $cs );
	}
	wp_set_current_user( $admin );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $id );
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
foreach ( cs_get_staged_drafts( $cs ) as $staged_id ) {
	wp_delete_post( $staged_id, true );
}
wp_delete_post( $cs, true );
WP_CLI::success( 'Security regressions passed.' );
