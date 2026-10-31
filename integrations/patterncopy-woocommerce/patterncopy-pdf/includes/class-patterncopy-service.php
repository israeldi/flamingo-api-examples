<?php
/** Bounded API transport, local sources and private personalization cache. */
defined( 'ABSPATH' ) || exit;

final class PatternCopy_Failure extends RuntimeException {}

final class PatternCopy_Service {
    const MAX_INPUT = 20971520;
    const MAX_OUTPUT = 25165824;
    const MAX_PAGES = 100;
    const MAX_FILES = 5;
    const OPTION = 'patterncopy_settings';
    const PREFLIGHT = '_patterncopy_preflight';
    private $lock;

    public static function defaults() {
        return array( 'endpoint' => '', 'mode' => 'rapidapi', 'rapidapi_host' => '', 'key' => '', 'consent' => false, 'local_http' => false, 'cache_mb' => 256, 'cache_days' => 7 );
    }

    public static function settings() {
        $saved = get_option( self::OPTION, array() );
        return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
    }

    public static function fail( $code ) {
        throw new PatternCopy_Failure( $code );
    }

    /** Only an administrator-configured API origin is contacted; redirects are forbidden. */
    public static function validate_settings( $settings ) {
        $url = wp_parse_url( $settings['endpoint'] );
        if ( ! is_array( $url ) || empty( $url['host'] ) || isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['query'] ) || isset( $url['fragment'] ) ) {
            self::fail( 'invalid_endpoint' );
        }
        $host = strtolower( $url['host'] );
        $local = in_array( $host, array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true );
        $development = in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
        if ( 'https' !== ( $url['scheme'] ?? '' ) && ! ( 'http' === ( $url['scheme'] ?? '' ) && $local && $development && ! empty( $settings['local_http'] ) ) ) {
            self::fail( 'https_required' );
        }
        if ( ! preg_match( '/\A[\x21-\x7e]{1,256}\z/D', (string) $settings['key'] ) || empty( $settings['consent'] ) ) {
            self::fail( 'service_not_configured' );
        }
        if ( ! in_array( $settings['mode'], array( 'direct', 'rapidapi' ), true ) ) {
            self::fail( 'invalid_auth_mode' );
        }
        if ( 'rapidapi' === $settings['mode'] && ( ! preg_match( '/\A[a-z0-9.-]+\z/iD', $settings['rapidapi_host'] ) || strtolower( $settings['rapidapi_host'] ) !== $host || ! empty( $settings['local_http'] ) ) ) {
            self::fail( 'invalid_rapidapi_host' );
        }
        return $settings;
    }

    public static function fingerprint() {
        $settings = self::validate_settings( self::settings() );
        return hash_hmac( 'sha256', wp_json_encode( array( $settings['endpoint'], $settings['mode'], $settings['rapidapi_host'], $settings['key'], PATTERNCOPY_VERSION ) ), wp_salt( 'auth' ) );
    }

    private static function inside( $path, $root ) {
        return $path === $root || str_starts_with( wp_normalize_path( $path ), trailingslashit( wp_normalize_path( $root ) ) );
    }

    /** No arbitrary URLs, remote fetching, traversal or files outside this site's uploads. */
    public static function local_source( $file ) {
        if ( ! is_string( $file ) || '' === $file || str_contains( $file, "\0" ) || str_starts_with( $file, '//' ) ) {
            self::fail( 'unsupported_source' );
        }
        $uploads = wp_get_upload_dir();
        $base = realpath( $uploads['basedir'] );
        if ( false === $base ) {
            self::fail( 'unsupported_source' );
        }
        $url = wp_parse_url( $file );
        if ( is_array( $url ) && isset( $url['scheme'] ) ) {
            $allowed = wp_parse_url( $uploads['baseurl'] );
            if ( ! in_array( strtolower( $url['scheme'] ), array( 'http', 'https' ), true ) || ! is_array( $allowed ) || strtolower( $url['host'] ?? '' ) !== strtolower( $allowed['host'] ?? '' ) || ( $url['port'] ?? null ) !== ( $allowed['port'] ?? null ) || isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['query'] ) || isset( $url['fragment'] ) ) {
                self::fail( 'unsupported_source' );
            }
            $prefix = trailingslashit( $allowed['path'] ?? '' );
            $path = rawurldecode( $url['path'] ?? '' );
            if ( ! str_starts_with( $path, $prefix ) ) {
                self::fail( 'unsupported_source' );
            }
            $file = trailingslashit( $base ) . substr( $path, strlen( $prefix ) );
        }
        $path = realpath( $file );
        if ( false === $path || ! self::inside( $path, $base ) || ! is_file( $path ) || ! is_readable( $path ) || 'pdf' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
            self::fail( 'unsupported_source' );
        }
        clearstatcache( true, $path );
        $size = filesize( $path );
        if ( ! is_int( $size ) || $size < 8 || $size > self::MAX_INPUT ) {
            self::fail( 'source_size_limit' );
        }
        $handle = fopen( $path, 'rb' );
        $header = $handle ? fread( $handle, 5 ) : false;
        if ( $handle ) {
            fclose( $handle );
        }
        if ( '%PDF-' !== $header ) {
            self::fail( 'invalid_pdf' );
        }
        $hash = hash_file( 'sha256', $path );
        if ( false === $hash ) {
            self::fail( 'source_read_failed' );
        }
        return array( 'path' => $path, 'hash' => $hash, 'bytes' => $size );
    }

    public static function sources( $product ) {
        if ( ! $product || ! $product->is_type( 'simple' ) || ! $product->is_downloadable() ) {
            self::fail( 'unsupported_product' );
        }
        $downloads = $product->get_downloads();
        if ( count( $downloads ) < 1 || count( $downloads ) > self::MAX_FILES ) {
            self::fail( 'source_count_limit' );
        }
        $sources = array();
        foreach ( $downloads as $id => $download ) {
            if ( method_exists( $download, 'get_enabled' ) && ! $download->get_enabled() ) {
                continue;
            }
            $sources[ $id ] = self::local_source( $download->get_file() );
        }
        if ( ! $sources ) {
            self::fail( 'unsupported_source' );
        }
        return $sources;
    }

    /** POSIX private directory, outside every known web root. Never rely on .htaccess alone. */
    public static function private_dir() {
        if ( '/' !== DIRECTORY_SEPARATOR ) {
            self::fail( 'private_cache_unavailable' );
        }
        $dir = defined( 'PATTERNCOPY_PRIVATE_DIR' ) ? PATTERNCOPY_PRIVATE_DIR : rtrim( sys_get_temp_dir(), '/' ) . '/patterncopy-' . substr( hash_hmac( 'sha256', home_url( '/' ), wp_salt( 'auth' ) ), 0, 24 );
        if ( ! is_string( $dir ) || ! str_starts_with( $dir, '/' ) || str_contains( $dir, "\0" ) || is_link( $dir ) ) {
            self::fail( 'private_cache_unavailable' );
        }
        if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) ) {
            self::fail( 'private_cache_unavailable' );
        }
        $real = realpath( $dir );
        $uploads = wp_get_upload_dir();
        $roots = array( ABSPATH, WP_CONTENT_DIR, $uploads['basedir'] );
        if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
            $roots[] = $_SERVER['DOCUMENT_ROOT'];
        }
        foreach ( $roots as $root ) {
            $root = realpath( $root );
            if ( $root && $real && self::inside( $real, $root ) ) {
                self::fail( 'private_cache_in_webroot' );
            }
        }
        if ( ! $real || ! is_writable( $real ) ) {
            self::fail( 'private_cache_unavailable' );
        }
        // A mistaken custom path must never let cleanup/uninstall alter unrelated
        // files. Claim only an empty directory; bind ownership to this site/path.
        $marker = $real . '/.patterncopy-owner';
        $owner = hash_hmac( 'sha256', 'patterncopy-private-cache-v1|' . $real . '|' . home_url( '/' ), wp_salt( 'auth' ) );
        if ( ! file_exists( $marker ) && ! is_link( $marker ) ) {
            $entries = @scandir( $real );
            if ( false === $entries || array_diff( $entries, array( '.', '..' ) ) ) {
                self::fail( 'private_cache_unowned' );
            }
            $mask = umask( 0077 );
            try {
                $handle = @fopen( $marker, 'x' );
            } finally {
                umask( $mask );
            }
            if ( $handle ) {
                $written = fwrite( $handle, $owner );
                fclose( $handle );
                if ( 64 !== $written || ! @chmod( $marker, 0600 ) ) {
                    @unlink( $marker );
                    self::fail( 'private_cache_unavailable' );
                }
            }
        }
        $stored_owner = is_file( $marker ) && ! is_link( $marker ) ? @file_get_contents( $marker, false, null, 0, 65 ) : false;
        if ( ! is_string( $stored_owner ) || ! hash_equals( $owner, $stored_owner ) ) {
            self::fail( 'private_cache_unowned' );
        }
        if ( ! @chmod( $real, 0700 ) ) {
            self::fail( 'private_cache_unavailable' );
        }
        clearstatcache( true, $real );
        if ( 0 !== ( fileperms( $real ) & 0077 ) ) {
            self::fail( 'private_cache_unavailable' );
        }
        return $real;
    }

    public function acquire() {
        if ( is_resource( $this->lock ) ) {
            return;
        }
        $this->lock = @fopen( self::private_dir() . '/.lock', 'c' );
        if ( ! $this->lock || ! @flock( $this->lock, LOCK_EX | LOCK_NB ) ) {
            $this->release();
            self::fail( 'personalization_busy' );
        }
        @chmod( self::private_dir() . '/.lock', 0600 );
    }

    public function release() {
        if ( is_resource( $this->lock ) ) {
            flock( $this->lock, LOCK_UN );
            fclose( $this->lock );
        }
        $this->lock = null;
    }

    public function __destruct() {
        $this->release();
    }

    private function pending() {
        $path = self::private_dir() . '/job-' . bin2hex( random_bytes( 16 ) ) . '.part';
        $handle = @fopen( $path, 'x' );
        if ( ! $handle ) {
            self::fail( 'private_cache_unavailable' );
        }
        fclose( $handle );
        if ( ! @chmod( $path, 0600 ) ) {
            @unlink( $path );
            self::fail( 'private_cache_unavailable' );
        }
        return $path;
    }

    private function request( $operation, $source, $options = null ) {
        $settings = self::validate_settings( self::settings() );
        $body = @file_get_contents( $source['path'], false, null, 0, self::MAX_INPUT + 1 );
        if ( false === $body || strlen( $body ) > self::MAX_INPUT || ! hash_equals( $source['hash'], hash( 'sha256', $body ) ) ) {
            self::fail( 'source_changed' );
        }
        $headers = array( 'Content-Type' => 'application/pdf', 'Accept' => 'stamp' === $operation ? 'application/pdf' : 'application/json' );
        if ( 'rapidapi' === $settings['mode'] ) {
            $headers['X-RapidAPI-Key'] = $settings['key'];
            $headers['X-RapidAPI-Host'] = $settings['rapidapi_host'];
        } else {
            $headers['X-API-Key'] = $settings['key'];
        }
        if ( null !== $options ) {
            $headers['X-PatternCopy-Options'] = base64_encode( wp_json_encode( $options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
        }
        $pending = $this->pending();
        try {
            $response = wp_remote_post( rtrim( $settings['endpoint'], '/' ) . '/v1/' . $operation, array(
                'headers' => $headers, 'body' => $body, 'timeout' => 110, 'redirection' => 0,
                'sslverify' => true, 'stream' => true, 'filename' => $pending,
                'limit_response_size' => 'stamp' === $operation ? self::MAX_OUTPUT + 1 : 262145,
                // Endpoint is explicitly chosen by the administrator, never from a download URL.
                'reject_unsafe_urls' => false, 'cookies' => array(),
            ) );
            unset( $body, $headers );
            if ( is_wp_error( $response ) ) {
                self::fail( 'service_unavailable' );
            }
            $status = wp_remote_retrieve_response_code( $response );
            if ( 200 !== $status ) {
                // Never show/log upstream messages, URLs, options, bodies or credentials.
                self::fail( 429 === $status ? 'service_quota_or_busy' : ( 300 <= $status && $status < 400 ? 'service_redirect_refused' : 'service_rejected_pdf' ) );
            }
            clearstatcache( true, $pending );
            $size = filesize( $pending );
            $type = strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $response, 'content-type' ) )[0] ) );
            if ( 'inspect' === $operation ) {
                if ( 'application/json' !== $type || $size > 262144 || $size < 2 ) {
                    self::fail( 'invalid_service_response' );
                }
                $data = json_decode( file_get_contents( $pending ), true );
                if ( is_array( $data ) && array_key_exists( 'ok', $data ) && true !== $data['ok'] ) {
                    self::fail( 'invalid_service_response' );
                }
                if ( is_array( $data ) && isset( $data['document'] ) && is_array( $data['document'] ) ) {
                    $data = $data['document'];
                }
                if ( ! is_array( $data ) || ! isset( $data['pages'], $data['layers'] ) || ! is_int( $data['pages'] ) || $data['pages'] < 1 || $data['pages'] > self::MAX_PAGES || ( ! is_array( $data['layers'] ) && ! is_int( $data['layers'] ) ) || ( is_int( $data['layers'] ) && $data['layers'] < 0 ) ) {
                    self::fail( 'invalid_service_response' );
                }
                return array( 'pages' => $data['pages'], 'layers' => is_array( $data['layers'] ) ? count( $data['layers'] ) : $data['layers'] );
            }
            if ( 'application/pdf' !== $type || $size < 8 || $size > self::MAX_OUTPUT ) {
                self::fail( 'invalid_service_response' );
            }
            $handle = fopen( $pending, 'rb' );
            $prefix = fread( $handle, 5 );
            fseek( $handle, max( 0, $size - 2048 ) );
            $tail = stream_get_contents( $handle, 2048 );
            fclose( $handle );
            if ( '%PDF-' !== $prefix || false === strpos( $tail, '%%EOF' ) ) {
                self::fail( 'invalid_service_response' );
            }
            $digest = wp_remote_retrieve_header( $response, 'x-patterncopy-sha256' );
            $actual = @hash_file( 'sha256', $pending );
            if ( ! is_string( $digest ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $digest ) || ! is_string( $actual ) || ! hash_equals( $digest, $actual ) ) {
                self::fail( 'invalid_service_response' );
            }
            $result = $pending;
            $pending = null;
            return $result;
        } finally {
            if ( $pending && is_file( $pending ) ) {
                @unlink( $pending );
            }
        }
    }

    /** Called only by capability/nonce-checked administrator actions. */
    public function preflight( $product ) {
        $fingerprint = self::fingerprint();
        self::private_dir();
        $results = array();
        foreach ( self::sources( $product ) as $id => $source ) {
            $results[ $id ] = array_merge( array( 'hash' => $source['hash'] ), $this->request( 'inspect', $source ) );
        }
        return array( 'fingerprint' => $fingerprint, 'checked' => time(), 'files' => $results );
    }

    public static function unicode_length( $text ) {
        if ( ! is_string( $text ) || 1 !== preg_match( '//u', $text ) ) {
            return -1;
        }
        return preg_match_all( '/./us', $text );
    }

    public static function template( $text ) {
        if ( ! is_string( $text ) || self::unicode_length( $text ) < 1 || self::unicode_length( $text ) > 160 || preg_match( '/[\x00-\x1f\x7f]/', $text ) ) {
            self::fail( 'invalid_stamp_text' );
        }
        preg_match_all( '/\{[^}]*\}/', $text, $matches );
        foreach ( $matches[0] as $placeholder ) {
            if ( ! in_array( $placeholder, array( '{name}', '{email}', '{order}', '{quantity}' ), true ) ) {
                self::fail( 'invalid_template_placeholder' );
            }
        }
        return $text;
    }

    public static function options( $product, $order ) {
        $template = self::template( $product->get_meta( '_patterncopy_text' ) ?: 'Licensed to {name} | Order #{order}' );
        $quantity = 0;
        foreach ( $order->get_items() as $item ) {
            if ( (int) $item->get_product_id() === (int) $product->get_id() ) {
                $quantity += (int) $item->get_quantity();
            }
        }
        if ( $quantity < 1 ) {
            self::fail( 'order_product_mismatch' );
        }
        $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $values = array( '{name}' => $name ?: $order->get_billing_email(), '{email}' => $order->get_billing_email(), '{order}' => (string) $order->get_order_number(), '{quantity}' => (string) $quantity );
        foreach ( $values as &$value ) {
            $value = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value ) ) );
        }
        unset( $value );
        $text = strtr( $template, $values );
        self::template( $text );
        return array( 'text' => $text, 'position' => 'bottom-left', 'font_size' => 9, 'margin' => 12 );
    }

    private function cache_hit( $path ) {
        $metadata = $path . '.json';
        if ( ! is_file( $path ) || is_link( $path ) || ! is_file( $metadata ) || is_link( $metadata ) || filesize( $metadata ) > 2048 ) {
            return false;
        }
        $data = json_decode( file_get_contents( $metadata ), true );
        $hash = @hash_file( 'sha256', $path );
        if ( ! is_array( $data ) || ! isset( $data['expires'], $data['sha256'], $data['bytes'] ) || ! is_int( $data['expires'] ) || $data['expires'] < time() || $data['bytes'] > self::MAX_OUTPUT || $data['bytes'] !== filesize( $path ) || ! is_string( $data['sha256'] ) || ! is_string( $hash ) || ! hash_equals( $data['sha256'], $hash ) ) {
            return false;
        }
        @touch( $path );
        return true;
    }

    /** Keep this lock through serving: cleanup cannot remove an in-flight cached copy. */
    public function stamp_for_download( $product, $order, $download, $original ) {
        $sources = self::sources( $product );
        $id = $download->get_download_id();
        if ( ! isset( $sources[ $id ] ) || self::local_source( $original )['path'] !== $sources[ $id ]['path'] ) {
            self::fail( 'source_changed' );
        }
        $checked = $product->get_meta( self::PREFLIGHT );
        if ( ! is_array( $checked ) || ! isset( $checked['fingerprint'], $checked['files'] ) || ! hash_equals( self::fingerprint(), (string) $checked['fingerprint'] ) || array_keys( $sources ) !== array_keys( $checked['files'] ) ) {
            self::fail( 'preflight_required' );
        }
        foreach ( $sources as $file_id => $source ) {
            if ( empty( $checked['files'][ $file_id ]['hash'] ) || ! hash_equals( $checked['files'][ $file_id ]['hash'], $source['hash'] ) ) {
                self::fail( 'preflight_required' );
            }
        }
        $options = self::options( $product, $order );
        $identity = array( $sources[ $id ]['hash'], (int) $order->get_id(), $order->get_billing_email(), (int) $download->get_user_id(), (int) $product->get_id(), (string) $id, $options, $checked['fingerprint'] );
        $key = hash_hmac( 'sha256', wp_json_encode( $identity ), wp_salt( 'auth' ) );
        $path = self::private_dir() . '/' . $key . '.pdf';
        $this->acquire();
        if ( $this->cache_hit( $path ) ) {
            return $path;
        }
        // A range/HEAD probe must never trigger a fresh billed personalization. A
        // full GET prepares the copy; later ranges may reuse that exact cached file.
        if ( isset( $_SERVER['HTTP_RANGE'] ) ) {
            self::fail( 'full_download_required' );
        }
        // Woo allows a resume request after the last download was consumed. Only reuse a
        // previously prepared copy in that case; never make a new billable API request.
        if ( '' !== $download->get_downloads_remaining() && (int) $download->get_downloads_remaining() <= 0 ) {
            self::fail( 'download_limit_reached' );
        }
        $this->cleanup_locked( self::MAX_OUTPUT );
        $available = @disk_free_space( self::private_dir() );
        if ( false !== $available && $available < self::MAX_OUTPUT + 2097152 ) {
            self::fail( 'private_cache_full' );
        }
        $pending = $this->request( 'stamp', $sources[ $id ], $options );
        try {
            $settings = self::settings();
            $metadata = array( 'expires' => time() + ( max( 1, min( 30, (int) $settings['cache_days'] ) ) * DAY_IN_SECONDS ), 'sha256' => hash_file( 'sha256', $pending ), 'bytes' => filesize( $pending ) );
            if ( ! @rename( $pending, $path ) || false === @file_put_contents( $path . '.json', wp_json_encode( $metadata ), LOCK_EX ) || ! @chmod( $path, 0600 ) || ! @chmod( $path . '.json', 0600 ) ) {
                @unlink( $path );
                @unlink( $path . '.json' );
                self::fail( 'private_cache_unavailable' );
            }
            return $path;
        } finally {
            if ( is_file( $pending ) ) {
                @unlink( $pending );
            }
        }
    }

    private function cleanup_locked( $reserve = 0, $purge = false ) {
        $dir = self::private_dir();
        $settings = self::settings();
        $limit = max( 32, min( 2048, (int) $settings['cache_mb'] ) ) * 1048576;
        $entries = array();
        $total = 0;
        foreach ( glob( $dir . '/*.pdf' ) ?: array() as $file ) {
            if ( ! preg_match( '/\A[a-f0-9]{64}\.pdf\z/D', basename( $file ) ) || is_link( $file ) ) {
                continue;
            }
            $meta = is_file( $file . '.json' ) ? json_decode( (string) @file_get_contents( $file . '.json', false, null, 0, 2048 ), true ) : null;
            if ( $purge || ! is_array( $meta ) || empty( $meta['expires'] ) || $meta['expires'] < time() ) {
                @unlink( $file );
                @unlink( $file . '.json' );
                continue;
            }
            $bytes = filesize( $file );
            $total += $bytes;
            $entries[] = array( 'path' => $file, 'bytes' => $bytes, 'access' => filemtime( $file ) );
        }
        usort( $entries, static fn( $a, $b ) => $a['access'] <=> $b['access'] );
        $remaining = count( $entries );
        foreach ( $entries as $entry ) {
            if ( $total + $reserve <= $limit && $remaining <= 512 ) {
                break;
            }
            @unlink( $entry['path'] );
            @unlink( $entry['path'] . '.json' );
            $total -= $entry['bytes'];
            --$remaining;
        }
        foreach ( glob( $dir . '/*.pdf.json' ) ?: array() as $file ) {
            if ( preg_match( '/\A[a-f0-9]{64}\.pdf\.json\z/D', basename( $file ) ) && ! is_file( substr( $file, 0, -5 ) ) ) {
                @unlink( $file );
            }
        }
        foreach ( glob( $dir . '/job-*.part' ) ?: array() as $file ) {
            if ( preg_match( '/\Ajob-[a-f0-9]{32}\.part\z/D', basename( $file ) ) && ( $purge || filemtime( $file ) < time() - HOUR_IN_SECONDS ) ) {
                @unlink( $file );
            }
        }
    }

    public function cleanup( $purge = false ) {
        $this->acquire();
        try {
            $this->cleanup_locked( 0, $purge );
        } finally {
            $this->release();
        }
    }
}
