<?php
/**
 * E2E phase 1: install WordPress on SQLite and configure the plugin.
 *
 * Requires the AI Post Creator plugin in {E2E_WP_ROOT}/wp-content/plugins/.
 */
error_reporting( E_ALL & ~E_DEPRECATED );

$root = getenv( 'E2E_WP_ROOT' );
if ( ! $root ) {
	$root = '/home/user/.cache/e2e/wordpress';
}

// Fresh state.
@unlink( $root . '/wp-config.php' );
$aipc_db_dir = $root . '/wp-content/database';
if ( is_dir( $aipc_db_dir ) ) {
	foreach ( scandir( $aipc_db_dir ) as $f ) {
		if ( '.' !== $f && '..' !== $f ) {
			@unlink( $aipc_db_dir . '/' . $f );
		}
	}
	@rmdir( $aipc_db_dir );
}
@unlink( $root . '/wp-content/mock-api-log.jsonl' );

$keys = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );
$salt_lines = '';
foreach ( $keys as $key ) {
	$salt_lines .= "define( '" . $key . "', 'e2e-" . bin2hex( random_bytes( 8 ) ) . "' );\n";
}

file_put_contents( $root . '/wp-config.php', "<?php
define( 'DB_NAME', 'wp' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
" . $salt_lines . "
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', false );

\$table_prefix = 'wp_';

require_once __DIR__ . '/wp-settings.php';
" );

$_SERVER['HTTP_HOST']     = 'localhost';
$_SERVER['SERVER_NAME']   = 'localhost';
$_SERVER['REQUEST_URI']   = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

define( 'WP_INSTALLING', true );

require $root . '/wp-load.php';

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$result = wp_install( 'E2E Test Site', 'admin', 'admin@example.com', false, '', 'password123' );
if ( is_wp_error( $result ) ) {
	echo json_encode( array( 'install_error' => $result->get_error_message() ) );
	exit( 1 );
}

update_option( 'siteurl', 'http://localhost' );
update_option( 'home', 'http://localhost' );

// Activate the plugin through the proper API (fires activation hooks).
$activate_result = activate_plugin( 'wp-ai-post-creator/wp-ai-post-creator.php' );
if ( is_wp_error( $activate_result ) ) {
	echo json_encode( array( 'activate_error' => $activate_result->get_error_message() ) );
	exit( 1 );
}

// Point the plugin at the mock provider + a site prompt.
update_option( 'aipc_settings', array_merge( AIPC_Settings::defaults(), array(
	'api_key'       => 'sk-mock-key',
	'api_base_url'  => 'https://mock.invalid/v1',
	'chat_model'    => 'mock-mini',
	'image_model'   => 'dall-e-3',
	'content_language' => 'fa',
	'site_prompt'   => 'یک وبلاگ فارسی درباره باغبانی خانگی و کشاورزی شهری برای مبتدیان.',
) ) );

// Categories for the agent to choose from.
foreach ( array( 'باغبانی', 'آشپزی', 'فناوری' ) as $aipc_cat ) {
	if ( ! term_exists( $aipc_cat, 'category' ) ) {
		wp_insert_term( $aipc_cat, 'category' );
	}
}

echo json_encode( array(
	'installed'      => true,
	'user_id'        => $result['user_id'],
	'active_plugins' => get_option( 'active_plugins' ),
	'model'          => AIPC_Settings::get( 'chat_model' ),
	'site_prompt'    => AIPC_Settings::get( 'site_prompt' ),
	'categories'     => wp_list_pluck( get_categories( array( 'hide_empty' => false ) ), 'name' ),
	'php'            => PHP_VERSION,
	'gd'             => extension_loaded( 'gd' ),
	'sqlite'         => extension_loaded( 'pdo_sqlite' ),
), JSON_UNESCAPED_UNICODE );
