<?php
defined('ABSPATH') || exit;

/** Local, bounded transport/chunking. Semantic ONIX interpretation belongs to the service. */
final class ShelfRelay_Feed {
    const MAX_FILE = 26214400;
    const MAX_RECORD = 262144;
    const MAX_PRODUCTS = 5000;

    public static function url(string $url): string {
        $parts = wp_parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException('Use a public HTTPS URL without embedded credentials or a fragment.');
        }
        if (!in_array($parts['port'] ?? 443, array(443, 8443), true)) { throw new RuntimeException('Only HTTPS ports 443 and 8443 are supported.'); }
        if (!self::with_port($url, static function () use ($url) { return wp_http_validate_url($url); })) {
            throw new RuntimeException('The URL must resolve to a public HTTPS destination.');
        }
        return $url;
    }

    private static function with_port(string $url, callable $work) {
        $filter = static function ($ports, $host, $checked) use ($url) {
            if ($checked === $url && wp_parse_url($url, PHP_URL_SCHEME) === 'https' && wp_parse_url($url, PHP_URL_PORT) === 8443) { $ports[] = 8443; }
            return array_unique($ports);
        };
        add_filter('http_allowed_safe_ports', $filter, 10, 3);
        try { return $work(); } finally { remove_filter('http_allowed_safe_ports', $filter, 10); }
    }

    public static function request(string $url, array $args, bool $post = false) {
        self::url($url);
        return self::with_port($url, static function () use ($url, $args, $post) {
            return $post ? wp_safe_remote_post($url, $args) : wp_safe_remote_get($url, $args);
        });
    }

    public static function download(string $url, array $headers, int $limit): string {
        self::url($url);
        if (!function_exists('wp_tempnam')) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
        $path = wp_tempnam('shelfrelay-feed', trailingslashit(ShelfRelay::private_dir()));
        if (!$path) { throw new RuntimeException('WordPress could not allocate a temporary file.'); }
        ShelfRelay::secure_file($path);
        try {
            // Redirects are deliberately disabled: supplier Authorization must never follow a different origin.
            $response = self::request($url, array('timeout' => 45, 'redirection' => 0, 'headers' => $headers,
                'stream' => true, 'filename' => $path, 'limit_response_size' => $limit + 1,
                'reject_unsafe_urls' => true, 'sslverify' => true));
            if (is_wp_error($response)) { throw new RuntimeException('HTTPS fetch failed: ' . $response->get_error_message()); }
            if (wp_remote_retrieve_response_code($response) !== 200) {
                throw new RuntimeException('The source returned HTTP ' . wp_remote_retrieve_response_code($response) . '. Redirects are not followed; use its final HTTPS address.');
            }
            clearstatcache(true, $path);
            if (!is_file($path) || filesize($path) < 1 || filesize($path) > $limit) {
                throw new RuntimeException('The downloaded file is empty or exceeds the supported size limit.');
            }
            return $path;
        } catch (Throwable $e) { wp_delete_file($path); throw $e; }
    }

    /** Returns a restartable page; no DOM of the whole feed or contact-bearing Header is retained. */
    public static function page(string $path, int $offset, int $count = 100): array {
        if (!class_exists('XMLReader')) { throw new RuntimeException('The PHP XMLReader extension is required.'); }
        if (!is_file($path) || filesize($path) > self::MAX_FILE) { throw new RuntimeException('Feed file is missing or exceeds 25 MiB.'); }
        $previous = libxml_use_internal_errors(true);
        $reader = new XMLReader();
        $root = ''; $close = ''; $root_depth = -1; $seen = 0; $records = array(); $bytes = 0; $finished = true;
        $header_name = ''; $defaults = ''; $root_prefix = '';
        try {
            if (!$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) { throw new RuntimeException('Cannot open XML feed.'); }
            $reader->setParserProperty(XMLReader::LOADDTD, false);
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE || $reader->nodeType === XMLReader::ENTITY_REF) {
                    throw new RuntimeException('DTD and entity declarations/references are not supported. Export plain ONIX XML.');
                }
                if ($reader->nodeType !== XMLReader::ELEMENT) { continue; }
                if ($root_depth === -1) {
                    if (!in_array($reader->localName, array('ONIXMessage', 'ONIXmessage'), true)) {
                        throw new RuntimeException('The file must contain an ONIXMessage root with release 3.0 or 3.1.');
                    }
                    $release = $reader->getAttribute('release');
                    if (!in_array($release, array('3.0', '3.1'), true)) { throw new RuntimeException('Only explicit ONIX release 3.0 and 3.1 are supported.'); }
                    $root = '<' . $reader->name; $close = '</' . $reader->name . '>'; $root_depth = $reader->depth; $root_prefix = $reader->prefix !== '' ? $reader->prefix . ':' : '';
                    if ($reader->moveToFirstAttribute()) {
                        do { $root .= ' ' . $reader->name . '="' . htmlspecialchars($reader->value, ENT_QUOTES | ENT_XML1, 'UTF-8') . '"'; } while ($reader->moveToNextAttribute());
                        $reader->moveToElement();
                    }
                    $root .= '>';
                    continue;
                }
                if ($reader->depth === $root_depth + 1 && in_array($reader->localName, array('Header', 'header'), true)) { $header_name = $root_prefix . $reader->localName; continue; }
                if ($header_name && $reader->depth === $root_depth + 2 && in_array($reader->localName, array('DefaultCurrencyCode', 'm186', 'DefaultPriceType', 'x310'), true)) {
                    $name = $root_prefix . $reader->localName; $value = trim($reader->readString());
                    if (strlen($value) > 16) { throw new RuntimeException('Invalid ONIX Header default.'); }
                    $defaults .= '<' . $name . '>' . htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</' . $name . '>'; continue;
                }
                if ($reader->depth !== $root_depth + 1 || !in_array($reader->localName, array('Product', 'product'), true)) { continue; }
                ++$seen;
                if ($seen > self::MAX_PRODUCTS) { throw new RuntimeException('This connector supports up to 5,000 products in one feed. No products from this job have been applied.'); }
                if ($seen <= $offset) { continue; }
                $safe_header = $defaults !== '' ? '<' . $header_name . '>' . $defaults . '</' . $header_name . '>' : '';
                $xml = $root . $safe_header . $reader->readOuterXml() . $close;
                if (strlen($xml) > self::MAX_RECORD) { throw new RuntimeException('A product exceeds the 256 KiB record limit.'); }
                if (count($records) >= $count || ($records && $bytes + strlen($xml) > 2097152)) { $finished = false; break; }
                $records[] = $xml; $bytes += strlen($xml);
            }
            if ($finished && libxml_get_errors()) { throw new RuntimeException('The feed is not well-formed XML. Correct the source and create a new preview.'); }
            if ($root_depth === -1 || ($finished && $seen === 0)) { throw new RuntimeException('No ONIX products were found.'); }
            return array('records' => $records, 'next' => $offset + count($records), 'finished' => $finished);
        } finally {
            $reader->close(); libxml_clear_errors(); libxml_use_internal_errors($previous);
        }
    }
}
