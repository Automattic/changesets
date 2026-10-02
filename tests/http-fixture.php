<?php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
if ( ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1', '::1' ), true ) ) { WP_CLI::error( 'Use only a disposable localhost installation.' ); }
$fixture = array();
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
file_put_contents( CS_PATH . '.local/http-fixture-secrets.php', '<?php exit; ?>' . PHP_EOL . wp_json_encode( $fixture ) );
WP_CLI::success( 'Local fixture and temporary application passwords created; secrets saved locally.' );
