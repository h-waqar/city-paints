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
//add_action( 'admin_init', 'citypaints_erp_sync_init', 20 );


function wpRemoteApiCall() {
	$url = 'https://213.123.198.147:446/api/products';

	$token = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCIsImtpZCI6IjdlYTQzZjlhLTVhNDAtNDFlMi1iZWRiLWM1N2RjYTQzZWIyNSJ9.eyJodHRwOi8vc2NoZW1hcy54bWxzb2FwLm9yZy93cy8yMDA1LzA1L2lkZW50aXR5L2NsYWltcy9uYW1lIjoiRDRXZWJEZXNpZ24iLCJodHRwOi8vc2NoZW1hcy5taWNyb3NvZnQuY29tL3dzLzIwMDgvMDYvaWRlbnRpdHkvY2xhaW1zL3JvbGUiOlsiU3RhdGljIiwiV2ViT3JkZXJzIl0sImV4cCI6MTc1Nzc2NTYwMSwiaXNzIjoid3d3LnByb2Z0ZWNrLmllIiwiYXVkIjoiN2VhNDNmOWEtNWE0MC00MWUyLWJlZGItYzU3ZGNhNDNlYjI1In0.e_GULOD09AG7Ly2XQr46MKpC_C0SgDDv8WZNujOIfbQ";

	$response = wp_remote_request( $url, [
		'method'    => 'get',
		'headers'   => [
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'x-api-key'     => '7ea43f9a-5a40-41e2-bedb-c57dca43eb25',
			'Authorization' => "Bearer $token",
		],
		'body'      => ! empty( $body ) ? wp_json_encode( $body ) : null,
		'timeout'   => 20,
		'sslverify' => false, // 🔥 dev only
	] );


	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	echo "<pre>";
//	print_r( $response );
	print_r( $data );
	echo "<pre>";

	wp_die();
}

//add_action( 'init', 'wpRemoteApiCall', 20 );

// Prevent WordPress from adding <p> or <br> around shortcodes
remove_filter( 'the_content', 'wpautop' );
remove_filter( 'the_content', 'wptexturize' );
remove_filter( 'the_excerpt', 'wpautop' );

// Also stop autop just on shortcode output
remove_filter( 'the_content', 'do_shortcode', 11 );
add_filter( 'the_content', function ( $content ) {
	return do_shortcode( shortcode_unautop( $content ) );
}, 11 );
