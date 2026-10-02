<?php
/** Focused final-review regressions; run only on disposable WordPress via wp eval-file. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
$failures = array();
function cs_review_test( $condition, $message ) {
	global $failures;
	WP_CLI::log( ( $condition ? 'PASS ' : 'FAIL ' ) . $message );
	if ( ! $condition ) {
		$failures[] = $message;
	}
}
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
	cs_review_test( null === cs_preview_staged_index(), 'Invalid public token exposes no staged content index' );
	cs_review_test( array() === cs_previewing_admin_body_class( array() ), 'Invalid public token has no preview UI alias' );
	ob_start();
	cs_render_changeset_bar();
	cs_review_test( '' === ob_get_clean(), 'Invalid public token renders no changeset metadata' );
	unset( $_GET['changeset'], $_COOKIE['changeset'] );
	wp_delete_post( $cs, true );
	if ( $failures ) {
		WP_CLI::error( 'Invalid preview regression failed.' );
	}
	return;
}
$cs = cs_create_changeset( 'Review regression' );
$draft = cs_create_staged_content( $cs, 'post', 'Review staged post' );
$term = wp_insert_term( 'Review ' . wp_generate_uuid4(), 'category' )['term_id'];
cs_approve_changeset( $cs );
wp_set_object_terms( $draft, array( $term ), 'category' );
cs_review_test( ! cs_is_changeset_approved( $cs ), 'Native taxonomy assignment revokes approval' );
cs_approve_changeset( $cs );
wp_remove_object_terms( $draft, array( $term ), 'category' );
cs_review_test( ! cs_is_changeset_approved( $cs ), 'Native taxonomy removal revokes approval' );
$destination = cs_create_changeset( 'Review destination' );
cs_approve_changeset( $cs );
cs_approve_changeset( $destination );
update_post_meta( $draft, '_changeset_id', $destination );
cs_review_test( ! cs_is_changeset_approved( $cs ), 'Moving staged content revokes source approval' );
cs_review_test( ! cs_is_changeset_approved( $destination ), 'Moving staged content revokes destination approval' );
wp_delete_post( $draft, true );
wp_delete_post( $cs, true );
wp_delete_post( $destination, true );
wp_delete_term( $term, 'category' );
$live_role = get_option( 'default_role' );
foreach ( array( array( 'value' => 'administrator', 'store' => 'unknown' ), array( 'store' => 'theme_mod' ) ) as $item ) {
	$cs = cs_create_changeset( 'Legacy bag review' );
	$draft = cs_create_staged_content( $cs, 'page', 'Must not publish' );
	update_post_meta( $cs, '_changeset_staged_options', array( 'default_role' => $item ) );
	cs_approve_changeset( $cs );
	$result = cs_publish_changeset( $cs );
	// Restore policy even when running this test against the vulnerable baseline.
	update_option( 'default_role', $live_role );
	cs_review_test( is_wp_error( $result ) && 'cs_denylisted_option' === $result->get_error_code(), 'Malformed legacy storage rejected before publication' );
	cs_review_test( 'draft' === get_post_status( $draft ), 'Malformed legacy bag has no content side effects' );
	wp_delete_post( $draft, true );
	wp_delete_post( $cs, true );
}
$cs = cs_create_changeset( 'Malformed preview bag' );
update_post_meta( $cs, '_changeset_staged_options', array( 'default_role' => array( 'value' => 'administrator', 'store' => 'unknown' ), 'users_can_register' => array( 'store' => 'theme_mod' ) ) );
$_GET['changeset'] = cs_get_changeset_uuid( $cs );
wp_set_current_user( 0 );
cs_preview_init_dynamic_filters();
cs_review_test( ! has_filter( 'pre_option_default_role', 'cs_preview_filter_option' ) && ! has_filter( 'pre_option_users_can_register', 'cs_preview_filter_option' ), 'Malformed legacy options cannot install preview overlays' );
unset( $_GET['changeset'] );
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
wp_delete_post( $cs, true );
$cs = cs_create_changeset( 'Safe theme mod' );
cs_review_test( true === cs_stage_option( $cs, 'custom_logo', 0 ), 'Admin can still stage a valid theme mod' );
cs_approve_changeset( $cs );
cs_review_test( ! is_wp_error( cs_publish_changeset( $cs ) ), 'Admin can still publish a valid theme mod' );
wp_delete_post( $cs, true );
if ( $failures ) {
	WP_CLI::error( count( $failures ) . ' final-review regressions failed.' );
}
WP_CLI::success( 'Final-review regressions passed.' );
