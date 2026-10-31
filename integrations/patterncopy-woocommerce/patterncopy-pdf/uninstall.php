<?php
/** Remove this connector's private copies and settings after explicit uninstall. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}
require_once __DIR__ . '/includes/class-patterncopy-service.php';
try {
    ( new PatternCopy_Service() )->cleanup( true );
} catch ( Throwable $error ) {
    // The host may have already removed the system temporary directory.
}
wp_clear_scheduled_hook( 'patterncopy_cleanup' );
delete_option( PatternCopy_Service::OPTION );
foreach ( array( '_patterncopy_enabled', '_patterncopy_text', '_patterncopy_preflight', '_patterncopy_last_error' ) as $key ) {
    delete_post_meta_by_key( $key );
}
