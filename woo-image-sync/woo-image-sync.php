<?php
/**
 * Plugin Name: WooCommerce Image Sync
 * Plugin URI: https://github.com/Mohamedf25/WebMag
 * Description: Sincroniza automaticamente las imagenes de productos desde una carpeta local o del servidor a WooCommerce. Asocia imagenes por SKU y evita duplicados.
 * Version: 1.0.0
 * Author: Mohamed Fares
 * Author URI: https://github.com/Mohamedf25
 * Text Domain: woo-image-sync
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 4.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'WIS_VERSION', '1.0.0' );
define( 'WIS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WIS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WIS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check if WooCommerce is active before initializing.
 */
function wis_check_woocommerce() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'wis_woocommerce_missing_notice' );
        return false;
    }
    return true;
}

/**
 * Admin notice when WooCommerce is not active.
 */
function wis_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><strong>WooCommerce Image Sync</strong> requiere que WooCommerce este instalado y activado.</p>
    </div>
    <?php
}

/**
 * Initialize the plugin.
 */
function wis_init() {
    if ( ! wis_check_woocommerce() ) {
        return;
    }

    // Load text domain
    load_plugin_textdomain( 'woo-image-sync', false, dirname( WIS_PLUGIN_BASENAME ) . '/languages' );

    // Include required files
    require_once WIS_PLUGIN_DIR . 'includes/class-wis-sync-engine.php';
    require_once WIS_PLUGIN_DIR . 'includes/class-wis-admin.php';
    require_once WIS_PLUGIN_DIR . 'includes/class-wis-ajax-handler.php';
    require_once WIS_PLUGIN_DIR . 'includes/class-wis-rest-api.php';

    // Initialize classes
    new WIS_Admin();
    new WIS_Ajax_Handler();
    new WIS_REST_API();
}
add_action( 'plugins_loaded', 'wis_init' );

/**
 * Activation hook - create default options.
 */
function wis_activate() {
    $defaults = array(
        'server_folder'    => '',
        'match_by'         => 'sku',
        'skip_existing'    => 'yes',
        'image_type'       => 'featured',
        'allowed_formats'  => array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ),
    );

    if ( ! get_option( 'wis_settings' ) ) {
        add_option( 'wis_settings', $defaults );
    }

    // Create upload directory for temporary files
    $upload_dir = wp_upload_dir();
    $wis_dir = $upload_dir['basedir'] . '/wis-temp';
    if ( ! file_exists( $wis_dir ) ) {
        wp_mkdir_p( $wis_dir );
    }
}
register_activation_hook( __FILE__, 'wis_activate' );

/**
 * Deactivation hook - cleanup.
 */
function wis_deactivate() {
    // Clean up temp directory
    $upload_dir = wp_upload_dir();
    $wis_dir = $upload_dir['basedir'] . '/wis-temp';
    if ( file_exists( $wis_dir ) ) {
        array_map( 'unlink', glob( $wis_dir . '/*' ) );
        rmdir( $wis_dir );
    }
}
register_deactivation_hook( __FILE__, 'wis_deactivate' );
