<?php
/** WooCommerce integration and explicit administrator controls. */
defined( 'ABSPATH' ) || exit;

final class PatternCopy_Plugin {
    private static $context;
    private static $service;

    public static function init() {
        if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Download_Handler' ) ) {
            add_action( 'admin_notices', static function () {
                if ( current_user_can( 'activate_plugins' ) ) {
                    echo '<div class="notice notice-error"><p>' . esc_html__( 'PatternCopy PDF requires WooCommerce.', 'patterncopy-pdf' ) . '</p></div>';
                }
            } );
            return;
        }
        self::$service = new PatternCopy_Service();
        // IMPORTANT: This first filter runs before Woo's authorization checks. Capture
        // context only: no file reads, cache reads, network calls or personalization.
        add_filter( 'woocommerce_download_product_filepath', array( __CLASS__, 'capture' ), PHP_INT_MAX, 5 );
        add_action( 'woocommerce_download_product', array( __CLASS__, 'authorized_download' ), -1000, 6 );
        add_filter( 'woocommerce_file_download_method', array( __CLASS__, 'download_method' ), PHP_INT_MAX, 3 );
        add_action( 'woocommerce_download_file_patterncopy', array( __CLASS__, 'serve' ), 0, 2 );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_post_patterncopy_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_patterncopy_purge', array( __CLASS__, 'purge' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
        add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ) );
        add_action( 'patterncopy_cleanup', array( __CLASS__, 'cleanup' ) );
        if ( ! wp_next_scheduled( 'patterncopy_cleanup' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'patterncopy_cleanup' );
        }
    }

    public static function capture( $path, $email, $order, $product, $download ) {
        self::$context = null;
        if ( $product && 'yes' === $product->get_meta( '_patterncopy_enabled' ) ) {
            self::$context = array( 'original' => $path, 'order' => $order, 'product' => $product, 'download' => $download, 'prepared' => null );
        }
        return $path;
    }

    private static function download_error( $code ) {
        // Static messages only: do not include source paths, buyer data or upstream errors.
        $message = __( 'Your personalized PDF could not be delivered. Please retry shortly or contact the store.', 'patterncopy-pdf' );
        $message .= ' ' . sprintf( __( 'Reference: %s', 'patterncopy-pdf' ), preg_replace( '/[^a-z0-9_]/', '', $code ) );
        wp_die( esc_html( $message ), esc_html__( 'PatternCopy PDF', 'patterncopy-pdf' ), array( 'response' => 503 ) );
    }

    /** This action is after Woo authorization and before track_download()/quota changes. */
    public static function authorized_download( $email, $order_key, $product_id, $user_id, $download_id, $order_id ) {
        if ( ! self::$context ) {
            return;
        }
        if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            self::download_error( 'full_get_required' );
        }
        $context = self::$context;
        $order = $context['order'];
        $download = $context['download'];
        if ( ! $order instanceof WC_Order || ! $download instanceof WC_Customer_Download || ! $order->is_download_permitted() || (int) $order_id !== (int) $order->get_id() || (int) $product_id !== (int) $context['product']->get_id() || (string) $download_id !== (string) $download->get_download_id() || (int) $order_id !== (int) $download->get_order_id() || (int) $user_id !== (int) $download->get_user_id() || ! hash_equals( (string) $order->get_order_key(), (string) $order_key ) || ! hash_equals( (string) $download->get_user_email(), (string) $email ) ) {
            self::download_error( 'authorization_context_mismatch' );
        }
        try {
            self::$context['prepared'] = self::$service->stamp_for_download( $context['product'], $order, $download, $context['original'] );
        } catch ( PatternCopy_Failure $error ) {
            self::$service->release();
            self::download_error( $error->getMessage() );
        } catch ( Throwable $error ) {
            self::$service->release();
            self::download_error( 'personalization_failed' );
        }
    }

    public static function download_method( $method, $product_id, $file_path ) {
        $product = wc_get_product( $product_id );
        if ( ! $product || 'yes' !== $product->get_meta( '_patterncopy_enabled' ) ) {
            return $method;
        }
        if ( ! self::$context || empty( self::$context['prepared'] ) || (int) self::$context['product']->get_id() !== (int) $product_id || self::$context['original'] !== $file_path ) {
            self::download_error( 'authorized_copy_required' );
        }
        return 'patterncopy';
    }

    public static function serve( $ignored_path, $ignored_filename ) {
        if ( ! self::$context || empty( self::$context['prepared'] ) || self::$context['original'] !== $ignored_path ) {
            self::download_error( 'authorized_copy_required' );
        }
        $path = self::$context['prepared'];
        $real = realpath( $path );
        if ( ! $real || $real !== $path || dirname( $real ) !== PatternCopy_Service::private_dir() || ! is_readable( $real ) ) {
            self::download_error( 'private_copy_unavailable' );
        }
        // The core force handler supports single-range/resume requests. It receives only
        // a private local file, never the original or a remotely controlled URL. Keep
        // the cache lock alive until PHP shutdown so cleanup cannot remove this copy.
        WC_Download_Handler::download_file_force( $real, 'personalized-pattern.pdf' );
        exit;
    }

    public static function admin_menu() {
        add_submenu_page( 'woocommerce', 'PatternCopy PDF', 'PatternCopy PDF', 'manage_woocommerce', 'patterncopy-pdf', array( __CLASS__, 'settings_page' ) );
    }

    private static function admin_check( $nonce ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You cannot manage these settings.', 'patterncopy-pdf' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( $nonce );
    }

    private static function posted( $name, $fallback = '' ) {
        return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : $fallback;
    }

    private static function admin_result( $code ) {
        set_transient( 'patterncopy_notice_' . get_current_user_id(), $code, MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=patterncopy-pdf' ) );
        exit;
    }

    public static function save_settings() {
        self::admin_check( 'patterncopy_settings' );
        $old = PatternCopy_Service::settings();
        $key = self::posted( 'patterncopy_key' );
        $settings = array(
            'endpoint' => rtrim( esc_url_raw( trim( self::posted( 'patterncopy_endpoint' ) ), array( 'https', 'http' ) ), '/' ),
            'mode' => self::posted( 'patterncopy_mode' ),
            'rapidapi_host' => strtolower( trim( self::posted( 'patterncopy_host' ) ) ),
            'key' => '1' === self::posted( 'patterncopy_forget_key' ) ? '' : ( '' === $key ? $old['key'] : $key ),
            'consent' => '1' === self::posted( 'patterncopy_consent' ),
            'local_http' => '1' === self::posted( 'patterncopy_local_http' ),
            'cache_mb' => max( 32, min( 2048, (int) self::posted( 'patterncopy_cache_mb', '256' ) ) ),
            'cache_days' => max( 1, min( 30, (int) self::posted( 'patterncopy_cache_days', '7' ) ) ),
        );
        try {
            // Explicit key removal is allowed. Opted-in products remain fail-closed.
            if ( '' !== $settings['key'] ) {
                PatternCopy_Service::validate_settings( $settings );
            }
            update_option( PatternCopy_Service::OPTION, $settings, false );
            self::admin_result( 'settings_saved' );
        } catch ( PatternCopy_Failure $error ) {
            self::admin_result( $error->getMessage() );
        }
    }

    public static function settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $s = PatternCopy_Service::settings();
        $notice = get_transient( 'patterncopy_notice_' . get_current_user_id() );
        delete_transient( 'patterncopy_notice_' . get_current_user_id() );
        echo '<div class="wrap"><h1>PatternCopy PDF</h1>';
        if ( $notice ) {
            echo '<div class="notice notice-info"><p>' . esc_html( str_replace( '_', ' ', $notice ) ) . '</p></div>';
        }
        echo '<p>' . esc_html__( 'The selected service receives the PDF and your chosen buyer/order stamp text. Original files remain in your store. Configure the connection, then run a compatibility check and opt in each simple downloadable product.', 'patterncopy-pdf' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="patterncopy_settings">';
        wp_nonce_field( 'patterncopy_settings' );
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="pc-endpoint">API base URL</label></th><td><input class="regular-text" id="pc-endpoint" type="url" name="patterncopy_endpoint" value="' . esc_attr( $s['endpoint'] ) . '" required><p class="description">HTTPS base URL, without /v1/inspect or /v1/stamp.</p></td></tr>';
        echo '<tr><th><label for="pc-mode">Authentication</label></th><td><select id="pc-mode" name="patterncopy_mode"><option value="direct" ' . selected( $s['mode'], 'direct', false ) . '>Direct API key</option><option value="rapidapi" ' . selected( $s['mode'], 'rapidapi', false ) . '>RapidAPI</option></select></td></tr>';
        echo '<tr><th><label for="pc-host">RapidAPI host</label></th><td><input id="pc-host" class="regular-text" name="patterncopy_host" value="' . esc_attr( $s['rapidapi_host'] ) . '"><p class="description">Required for RapidAPI; must equal the base URL hostname.</p></td></tr>';
        echo '<tr><th><label for="pc-key">API key</label></th><td><input id="pc-key" class="regular-text" type="password" name="patterncopy_key" value="" autocomplete="new-password" placeholder="' . esc_attr( $s['key'] ? 'Key stored — leave blank to keep' : 'Enter API key' ) . '"><p><label><input type="checkbox" name="patterncopy_forget_key" value="1"> Remove stored key</label></p><p class="description">Stored keys are never displayed. Removing the key blocks opted-in downloads.</p></td></tr>';
        echo '<tr><th>Private cache</th><td><label>Maximum MiB <input type="number" min="32" max="2048" name="patterncopy_cache_mb" value="' . esc_attr( $s['cache_mb'] ) . '"></label> <label>Keep for days <input type="number" min="1" max="30" name="patterncopy_cache_days" value="' . esc_attr( $s['cache_days'] ) . '"></label><p class="description">Private system temporary directory, outside the web root. Reusing a copy avoids another stamp request; expired or evicted copies require processing again.</p></td></tr>';
        echo '<tr><th>Service authorization</th><td><label><input type="checkbox" name="patterncopy_consent" value="1" ' . checked( $s['consent'], true, false ) . '> I authorize sending product PDFs and configured buyer/order text to this API for compatibility checks and personalization.</label></td></tr>';
        echo '<tr><th>Local development</th><td><label><input type="checkbox" name="patterncopy_local_http" value="1" ' . checked( $s['local_http'], true, false ) . '> Permit HTTP to loopback only when WP_ENVIRONMENT_TYPE is local or development.</label><p class="description">Production always requires HTTPS. Credentials are never forwarded across redirects.</p></td></tr>';
        echo '</tbody></table>';
        submit_button( 'Save connection' );
        echo '</form><hr><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="patterncopy_purge">';
        wp_nonce_field( 'patterncopy_purge' );
        submit_button( 'Delete private cached copies', 'secondary' );
        echo '</form><p>Limits: five local PDF files per simple product; 20 MiB and 100 pages per source. External storage URLs, ZIP bundles and product variations are not supported by this connector version.</p></div>';
    }

    public static function meta_box() {
        add_meta_box( 'patterncopy-product', 'PatternCopy PDF', array( __CLASS__, 'product_box' ), 'product', 'normal', 'default' );
    }

    public static function product_box( $post ) {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }
        $p = wc_get_product( $post->ID );
        if ( ! $p ) {
            return;
        }
        wp_nonce_field( 'patterncopy_product_' . $post->ID, 'patterncopy_product_nonce' );
        echo '<p><label><input type="checkbox" name="patterncopy_enabled" value="1" ' . checked( $p->get_meta( '_patterncopy_enabled' ), 'yes', false ) . '> Require a personalized PDF for every authorized download</label></p>';
        echo '<p><label for="patterncopy-text">Stamp text template</label><br><input id="patterncopy-text" name="patterncopy_text" class="widefat" maxlength="160" value="' . esc_attr( $p->get_meta( '_patterncopy_text' ) ?: 'Licensed to {name} | Order #{order}' ) . '"></p>';
        echo '<p>Placeholders: <code>{name}</code>, <code>{email}</code>, <code>{order}</code>, <code>{quantity}</code>. The final text must fit 160 Unicode characters. Position: bottom-left, 9pt, 12pt margin.</p>';
        echo '<p><label><input type="checkbox" name="patterncopy_preflight" value="1"> Run compatibility checks on the current downloadable PDFs when saving</label></p>';
        $check = $p->get_meta( PatternCopy_Service::PREFLIGHT );
        $error = $p->get_meta( '_patterncopy_last_error' );
        if ( is_array( $check ) && isset( $check['checked'], $check['files'] ) ) {
            echo '<p>Last successful check: ' . esc_html( gmdate( 'Y-m-d H:i', $check['checked'] ) ) . ' UTC. ' . esc_html( count( $check['files'] ) ) . ' PDF(s).</p>';
            foreach ( $check['files'] as $file ) {
                echo '<p>' . esc_html( $file['pages'] ) . ' pages; ' . esc_html( $file['layers'] ) . ' layers.</p>';
            }
        }
        if ( $error ) {
            echo '<p><strong>Check required:</strong> ' . esc_html( str_replace( '_', ' ', $error ) ) . '.</p>';
        }
        echo '<p>Requires a configured connection and simple downloadable product with up to five local PDFs. Changed files or connection credentials require a new check. When enabled, errors block delivery and never send the original. Uncheck this option to explicitly restore ordinary WooCommerce delivery.</p>';
    }

    public static function save_product( $product ) {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_post', $product->get_id() ) || ! wp_verify_nonce( self::posted( 'patterncopy_product_nonce' ), 'patterncopy_product_' . $product->get_id() ) ) {
            return;
        }
        $enabled = '1' === self::posted( 'patterncopy_enabled' );
        // An explicitly enabled product remains protected even if its preflight fails.
        // Silently disabling it on failure would expose the unpersonalized original.
        $product->update_meta_data( '_patterncopy_enabled', $enabled ? 'yes' : 'no' );
        try {
            $text = sanitize_text_field( self::posted( 'patterncopy_text', 'Licensed to {name} | Order #{order}' ) );
            $product->update_meta_data( '_patterncopy_text', PatternCopy_Service::template( $text ) );
            if ( '1' === self::posted( 'patterncopy_preflight' ) ) {
                $product->update_meta_data( PatternCopy_Service::PREFLIGHT, self::$service->preflight( $product ) );
            }
            $product->delete_meta_data( '_patterncopy_last_error' );
        } catch ( PatternCopy_Failure $error ) {
            $product->delete_meta_data( PatternCopy_Service::PREFLIGHT );
            $product->update_meta_data( '_patterncopy_last_error', $error->getMessage() );
            if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
                WC_Admin_Meta_Boxes::add_error( 'PatternCopy: ' . str_replace( '_', ' ', $error->getMessage() ) . '. Personalized downloads remain blocked until a successful compatibility check.' );
            }
        }
    }

    public static function purge() {
        self::admin_check( 'patterncopy_purge' );
        try {
            self::$service->cleanup( true );
            self::admin_result( 'private_cache_deleted' );
        } catch ( PatternCopy_Failure $error ) {
            self::admin_result( $error->getMessage() );
        }
    }

    public static function cleanup() {
        try {
            self::$service->cleanup();
        } catch ( Throwable $error ) {
            // No customer information, filenames or upstream error bodies are logged.
        }
    }
}
