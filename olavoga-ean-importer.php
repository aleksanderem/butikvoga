<?php
/**
 * Plugin Name: Olavoga EAN Importer
 * Description: Import EAN from CSV, generate SKU, move EAN to parent products.
 * Version: 3.5.0
 * Author: Kolabo IT
 * Text Domain: olavoga-ean-importer
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'OEI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OEI_VERSION', '3.5.0' );

require_once OEI_PLUGIN_DIR . 'includes/class-csv-parser.php';
require_once OEI_PLUGIN_DIR . 'includes/class-product-matcher.php';
require_once OEI_PLUGIN_DIR . 'includes/class-sku-generator.php';
require_once OEI_PLUGIN_DIR . 'includes/class-ean-mover.php';
require_once OEI_PLUGIN_DIR . 'includes/class-github-updater.php';
require_once OEI_PLUGIN_DIR . 'includes/class-admin-page.php';

// GitHub auto-updater
new OEI_GitHub_Updater( __FILE__ );

add_action( 'plugins_loaded', function() {
    if ( class_exists( 'WooCommerce' ) ) {
        new OEI_Admin_Page();
    } else {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Olavoga EAN Importer</strong> wymaga aktywnego WooCommerce.</p></div>';
        } );
    }
} );
