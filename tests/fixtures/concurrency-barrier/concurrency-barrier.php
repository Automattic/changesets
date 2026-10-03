<?php
if ( ! defined( 'ABSPATH' ) || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1', '::1' ), true ) ) { return; }
/** Plugin Name: Changesets isolated concurrency test barrier */
add_filter( 'wp_insert_post_data', function( $data ) {
 global $cs_publishing_changeset;
 $barrier = WP_PLUGIN_DIR . '/changesets/.local/publish-barrier';
 if ( $cs_publishing_changeset && isset($_GET['cs_pause_publish']) && current_user_can('publish_changesets') && 'publish' === $data['post_status'] && ! file_exists($barrier) ) {
  file_put_contents($barrier, 'snapshot ready');
  usleep(1500000);
 }
 return $data;
} );
