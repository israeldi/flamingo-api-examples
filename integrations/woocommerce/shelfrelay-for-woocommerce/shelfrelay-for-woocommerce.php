<?php
/**
 * Plugin Name: ShelfRelay for WooCommerce
 * Description: Preview and schedule supported ONIX book-feed updates through the ShelfRelay normalization service.
 * Version: 0.1.2
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author: ShelfRelay
 * License: GPL-2.0-or-later
 * Text Domain: shelfrelay-for-woocommerce
 */

defined('ABSPATH') || exit;
define('SHELFRELAY_VERSION', '0.1.2');
define('SHELFRELAY_API_BASE', 'https://shelfrelay-onix.p.rapidapi.com');
define('SHELFRELAY_FILE', __FILE__);
define('SHELFRELAY_PATH', plugin_dir_path(__FILE__));
require_once SHELFRELAY_PATH . 'includes/class-shelfrelay-api.php';
require_once SHELFRELAY_PATH . 'includes/class-shelfrelay-feed.php';
require_once SHELFRELAY_PATH . 'includes/class-shelfrelay-store.php';
require_once SHELFRELAY_PATH . 'includes/class-shelfrelay.php';

register_activation_hook(__FILE__, array('ShelfRelay', 'activate'));
register_deactivation_hook(__FILE__, array('ShelfRelay', 'deactivate'));
add_filter('cron_schedules', function ($schedules) {
    $schedules['shelfrelay_minute'] = array('interval' => 60, 'display' => 'Every minute (ShelfRelay queue)');
    return $schedules;
});
add_action('plugins_loaded', function () { ShelfRelay::instance(); });
