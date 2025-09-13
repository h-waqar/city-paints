<?php
/**
 * Plugin Name: CityPaints ERP Sync
 * Description: Sync products and orders between WooCommerce and CityPaints ERP.
 * Version: 0.1.0
 * Author: Cubixsol
 * Text Domain: citypaints-erp-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access
}

ini_set( 'display_errors', 0 );
ini_set( 'memory_limit', '-1' );
ini_set( 'max_execution_time', '6000' );
ini_set( 'upload_max_size', '10240M' );
ini_set( 'post_max_size', '10240M' );

if ( ! defined( 'CITYPAINTS_ENABLE_LOGS' ) ) {
	define( 'CITYPAINTS_ENABLE_LOGS', true );
	define( 'CITYPAINTS_PLUGIN_FILE', __FILE__ );
	define( 'CITYPAINTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
	define( 'CITYPAINTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * PSR-4 Autoloader
 */
spl_autoload_register( function ( $class ) {
	$prefix   = 'CityPaintsERP\\';
	$base_dir = __DIR__ . '/src/';

	if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, strlen( $prefix ) );
	$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require $file;
	}
} );

/**
 * Bootstrap plugin
 */
function citypaints_erp_sync_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>'
			     . esc_html__( 'CityPaints ERP Sync requires WooCommerce.', 'citypaints-erp-sync' )
			     . '</p></div>';
		} );

		return;
	}

	require_once CITYPAINTS_PLUGIN_DIR . 'src/Helpers/Logger.php';

	global $CLOGGER;
	$CLOGGER = new CityPaintsERP\Helpers\Logger( 'citypaints' );

	$core                       = new CityPaintsERP\Core();
	$GLOBALS['CITYPAINTS_CORE'] = $core;
//	global $CLOGGER;
	$core->init();
}

add_action( 'plugins_loaded', 'citypaints_erp_sync_init' );

