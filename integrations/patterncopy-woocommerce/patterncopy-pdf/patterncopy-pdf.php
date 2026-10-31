<?php
/**
 * Plugin Name: PatternCopy PDF for WooCommerce
 * Description: Personalize authorized PDF downloads through PatternCopy while retaining supported pattern layers and navigation.
 * Version: 0.1.1
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 10.3
 * WC tested up to: 11.2.1
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: patterncopy-pdf
 *
 * @package PatternCopy
 */

defined( 'ABSPATH' ) || exit;

define( 'PATTERNCOPY_VERSION', '0.1.1' );
require_once __DIR__ . '/includes/class-patterncopy-service.php';
require_once __DIR__ . '/includes/class-patterncopy-plugin.php';

add_action( 'plugins_loaded', array( 'PatternCopy_Plugin', 'init' ), 30 );
register_deactivation_hook( __FILE__, static function () {
    wp_clear_scheduled_hook( 'patterncopy_cleanup' );
} );
