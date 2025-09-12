<?php

/**
 * Plugin Name: ! CityPaints ERP Sync
 * Description: Sync products and orders between WooCommerce and CityPaints ERP.
 * Version: 0.1.0
 * Author: Cubixsol
 * Text Domain: citypaints-erp-sync
 */

if (!defined('ABSPATH')) {
    exit; // No direct access
}

ini_set('display_errors', 0);
ini_set('memory_limit', '-1');
ini_set('max_execution_time', '6000');
ini_set('upload_max_size', '10240M');
ini_set('post_max_size', '10240M');

/**
 * PSR-4 Autoloader for src/ classes
 */
spl_autoload_register(function ($class) {
    $prefix = 'CityPaintsERP\\';
    $base_dir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return; // Not our namespace
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

///**
// * Bootstrap plugin
// */
//function citypaints_erp_sync_init()
//{
//    // Example: check WooCommerce dependency
//    if (!class_exists('WooCommerce')) {
//        add_action('admin_notices', function () {
//            echo '<div class="notice notice-error"><p>'
//                . esc_html__('CityPaints ERP Sync requires WooCommerce.', 'citypaints-erp-sync')
//                . '</p></div>';
//        });
//        return;
//    }
//
//    require_once __DIR__ . '/src/Helpers/Logger.php';
//
//    global $CLOGGER;
//    $CLOGGER = new CityPaintsERP\Helpers\Logger('citypaints');
//
//    // Kick off plugin core
//    $core = new CityPaintsERP\Core();
//    $core->init();
//}

/**
 * Bootstrap plugin
 */
function citypaints_erp_sync_init(): void
{
    // Example: check WooCommerce dependency
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('CityPaints ERP Sync requires WooCommerce.', 'citypaints-erp-sync')
                . '</p></div>';
        });
        return;
    }

    require_once __DIR__ . '/src/Helpers/Logger.php';

    // Instantiate logger once, store in both global + $GLOBALS
    $logger = new CityPaintsERP\Helpers\Logger('citypaints');

    global $CLOGGER;
    $CLOGGER = $logger;

    echo "<pre>";
    print_r($logger);
    echo "</pre>";
    wp_die();

    $GLOBALS['CLOGGER'] = $logger;

    // Kick off plugin core
    $core = new CityPaintsERP\Core();
    $core->init();
}


add_action('plugins_loaded', 'citypaints_erp_sync_init');
