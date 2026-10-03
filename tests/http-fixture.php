<?php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
if ( ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1', '::1' ), true ) ) { WP_CLI::error( 'Use only a disposable localhost installation.' ); }
$path = CS_PATH . '.local/http-fixture-secrets.php';
if ( isset( $args[0] ) && 'cleanup' === $args[0] ) {
 if ( ! file_exists( $path ) ) { return; }
 $fixture = json_decode( explode( "\n", file_get_contents( $path ), 2 )[1], true );
 require_once ABSPATH . 'wp-admin/includes/user.php';
 foreach ( $fixture['users'] as $user ) {
  $posts = get_posts( array( 'post_type'=>array_values( get_post_types() ), 'post_status'=>'any', 'author'=>$user['id'], 'numberposts'=>-1 ) );
  foreach ( $posts as $post ) { wp_delete_post( $post->ID, true ); }
  wp_delete_user( $user['id'] );
 }
 foreach ( $fixture['options'] as $key=>$value ) { update_option( $key, $value ); }
 unlink( $path );
 if ( file_exists( dirname( $path ) . '/publish-barrier' ) ) { unlink( dirname( $path ) . '/publish-barrier' ); }
 WP_CLI::success( 'Test users, application passwords, posts and secrets removed; options restored.' );
 return;
}
if ( file_exists( $path ) ) { WP_CLI::error( 'Clean up the previous fixture first.' ); }
wp_mkdir_p( dirname( $path ) );
$fixture = array( 'options'=>array( 'users_can_register'=>get_option( 'users_can_register' ), 'default_role'=>get_option( 'default_role' ) ) );
foreach ( array( 'administrator', 'editor', 'author', 'contributor' ) as $role ) {
 $username = 'cs-http-' . $role . '-' . wp_generate_uuid4();
 $login_password = wp_generate_password();
 $id = wp_insert_user( array( 'user_login' => $username, 'user_pass' => $login_password, 'role' => $role ) );
 $app = WP_Application_Passwords::create_new_application_password( $id, array( 'name' => 'Isolated Changesets security test' ) );
 $fixture['users'][$role] = array( 'id'=>$id, 'username'=>$username, 'password'=>$app[0], 'login_password'=>$login_password );
}
wp_set_current_user( $fixture['users']['administrator']['id'] );
update_option( 'users_can_register', 0 );
update_option( 'default_role', 'subscriber' );
$cs = cs_create_changeset( 'Admin private draft' );
$draft = cs_create_staged_content( $cs, 'page', 'HTTP secret page', 'CS_HTTP_SECRET_MARKER', 'cs-http-secret' );
$fixture['admin_changeset'] = $cs;
$fixture['draft'] = $draft;
$fixture['uuid'] = cs_get_changeset_uuid( $cs );
update_post_meta( $cs, '_changeset_staged_options', array( 'users_can_register'=>'1', 'default_role'=>array('value'=>'administrator','store'=>'option') ) );
$style_cs = cs_create_changeset( 'Unsafe legacy CSS' );
update_post_meta( $style_cs, '_changeset_staged_global_styles', array( 'version'=>3, 'styles'=>array( 'css'=>'body{color:red;}</style><script id="CS_STYLE_INJECTION_MARKER">/* inert test marker */</script><style>' ) ) );
$fixture['style_uuid'] = cs_get_changeset_uuid( $style_cs );
$fixture['style_changeset'] = $style_cs;
file_put_contents( $path, '<?php exit; ?>' . PHP_EOL . wp_json_encode( $fixture ) );
WP_CLI::success( 'Local fixture and temporary application passwords created; secrets saved locally.' );
