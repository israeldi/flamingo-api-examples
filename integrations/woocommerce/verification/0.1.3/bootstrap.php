<?php
/**
 * Isolated native-PHP upload test bootstrap.
 *
 * Uses unmodified WordPress 7.1.5 hook, upload, MIME, filesystem and filename
 * implementations downloaded from https://wordpress.org/latest.tar.gz.
 * Database, localization, login and the WooCommerce job workflow are not loaded.
 * Those environment boundaries are explicit stubs; authentication is NOT covered
 * by invoking ShelfRelay::uploaded_feed through ReflectionMethod.
 */
$core = getenv('SHELFRELAY_WP_SOURCE');
if (!$core || !is_file(rtrim($core, '/\\') . '/wp-includes/version.php')) {
    throw new RuntimeException('Set SHELFRELAY_WP_SOURCE to an extracted official WordPress source directory.');
}
define('ABSPATH', rtrim($core, '/\\') . '/');
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('WP_CONTENT_URL', 'http://127.0.0.1/wp-content');
define('WP_SETUP_CONFIG', true);
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('MB_IN_BYTES', 1024 * 1024);
define('KB_IN_BYTES', 1024);
define('GB_IN_BYTES', 1024 * 1024 * 1024);

function __(string $text, string $domain = 'default'): string { return $text; }
function _x(string $text, string $context, string $domain = 'default'): string { return $text; }
function current_user_can($capability, ...$args): bool { return false; }
function user_can($user, $capability, ...$args): bool { return false; }
function wp_salt($scheme = 'auth'): string { return 'shelfrelay-isolated-upload-test-not-a-production-secret'; }
function get_locale(): string { return 'en_US'; }

require ABSPATH . WPINC . '/load.php';
require ABSPATH . WPINC . '/version.php';
require ABSPATH . WPINC . '/compat-utf8.php';
require ABSPATH . WPINC . '/compat.php';
require ABSPATH . WPINC . '/utf8.php';
require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/class-wp-error.php';
require ABSPATH . WPINC . '/functions.php';
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . '/pluggable.php';
require ABSPATH . WPINC . '/shortcodes.php';
require ABSPATH . WPINC . '/media.php';
require ABSPATH . 'wp-admin/includes/file.php';
require ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

add_filter('pre_option', static function ($pre, $option) {
    $options = array(
        'siteurl' => 'http://127.0.0.1',
        'home' => 'http://127.0.0.1',
        'upload_path' => '',
        'upload_url_path' => '',
        'uploads_use_yearmonth_folders' => 0,
        'blog_charset' => 'UTF-8',
    );
    return $options[$option] ?? $pre;
}, 10, 2);

$plugin = getenv('SHELFRELAY_PLUGIN_SOURCE');
if (!$plugin || !is_file(rtrim($plugin, '/\\') . '/includes/class-shelfrelay.php')) {
    throw new RuntimeException('Set SHELFRELAY_PLUGIN_SOURCE to the ShelfRelay plugin directory.');
}
require rtrim($plugin, '/\\') . '/includes/class-shelfrelay-feed.php';
require rtrim($plugin, '/\\') . '/includes/class-shelfrelay.php';
