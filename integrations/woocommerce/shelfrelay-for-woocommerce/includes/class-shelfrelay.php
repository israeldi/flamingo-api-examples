<?php
defined('ABSPATH') || exit;

final class ShelfRelay {
    private static $instance;
    private $db;
    private $jobs;
    private $items;
    private $states;

    public static function instance(): self { return self::$instance ?? (self::$instance = new self()); }
    private function __construct() {
        global $wpdb; $this->db = $wpdb;
        $this->jobs = $wpdb->prefix . 'shelfrelay_jobs'; $this->items = $wpdb->prefix . 'shelfrelay_items'; $this->states = $wpdb->prefix . 'shelfrelay_states';
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'privacy_content'));
        add_action('admin_post_shelfrelay', array($this, 'action'));
        add_action('shelfrelay_worker', array($this, 'worker'));
        add_action('shelfrelay_fetch', array($this, 'scheduled'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('woocommerce_product_meta_end', array($this, 'product_meta'));
        if (get_option('shelfrelay_db_version') !== SHELFRELAY_VERSION) { self::activate(); }
    }

    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php'; $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}shelfrelay_jobs (
            id varchar(36) NOT NULL,
            source_id varchar(36) NOT NULL,
            settings_hash varchar(64) NOT NULL,
            status varchar(24) NOT NULL,
            auto_apply tinyint NOT NULL DEFAULT '0',
            allow_existing tinyint NOT NULL DEFAULT '0',
            file_path text NOT NULL,
            record_cursor bigint NOT NULL DEFAULT '0',
            total bigint NOT NULL DEFAULT '0',
            policy longtext NOT NULL,
            service_usage longtext NULL,
            history_added tinyint NOT NULL DEFAULT '0',
            message text NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY source_id (source_id)
        ) $charset;");
        dbDelta("CREATE TABLE {$wpdb->prefix}shelfrelay_items (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            job_id varchar(36) NOT NULL,
            position bigint NOT NULL,
            status varchar(24) NOT NULL,
            xml longtext NOT NULL,
            result longtext NOT NULL,
            baseline_hash varchar(64) NOT NULL,
            record_key varchar(64) NOT NULL,
            isbn varchar(13) NOT NULL,
            product_id bigint NOT NULL DEFAULT '0',
            attempts int NOT NULL DEFAULT '0',
            origin varchar(16) NOT NULL DEFAULT 'feed',
            message text NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY job_position (job_id,position),
            KEY job_status (job_id,status)
        ) $charset;");
        dbDelta("CREATE TABLE {$wpdb->prefix}shelfrelay_states (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            source_id varchar(36) NOT NULL,
            record_key varchar(64) NOT NULL,
            record_reference varchar(191) NOT NULL,
            isbn varchar(13) NOT NULL,
            product_id bigint NOT NULL DEFAULT '0',
            xml longtext NOT NULL,
            source_hash varchar(64) NOT NULL,
            checked_on varchar(10) NOT NULL DEFAULT '',
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_record (source_id,record_key),
            UNIQUE KEY source_isbn (source_id,isbn)
        ) $charset;");
        update_option('shelfrelay_db_version', SHELFRELAY_VERSION, false);
        if (!get_option('shelfrelay_settings')) {
            add_option('shelfrelay_settings', array('source_id' => wp_generate_uuid4(), 'label' => 'Book supplier', 'api_url' => SHELFRELAY_API_BASE, 'api_key' => '',
                'source_url' => '', 'auth_type' => 'none', 'source_user' => '', 'source_secret' => '',
                'market_country' => 'US', 'currency' => get_option('woocommerce_currency', 'USD'), 'price_type' => get_option('woocommerce_prices_include_tax') === 'yes' ? '02' : '01',
                'supplier_type' => '', 'supplier_value' => '', 'merchant_owned_fields' => array(), 'schedule' => 'daily',
                'schedule_enabled' => false, 'publish_new' => false, 'consent' => false, 'approved' => false), '', false);
        }
        if (!wp_next_scheduled('shelfrelay_worker')) { wp_schedule_event(time() + 30, 'shelfrelay_minute', 'shelfrelay_worker'); }
    }

    public static function deactivate(): void { wp_clear_scheduled_hook('shelfrelay_worker'); wp_clear_scheduled_hook('shelfrelay_fetch'); }

    public static function private_dir(): string {
        $base = realpath(sys_get_temp_dir());
        // A SAPI filesystem path must stay exact; realpath validates it without destructive text sanitization.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Canonicalize the unslashed server document root; this is not a client-supplied HTTP header.
        $document_root = is_string($_SERVER['DOCUMENT_ROOT'] ?? null) ? wp_unslash($_SERVER['DOCUMENT_ROOT']) : '';
        $installation = realpath(ABSPATH); $served = $document_root !== '' ? realpath($document_root) : false;
        if (!$base || !$installation || ($document_root !== '' && !$served)) { throw new RuntimeException('A private system temporary directory outside the resolved WordPress and server web roots is required.'); }
        $web_roots = array_filter(array($installation, $served));
        foreach ($web_roots as $web) {
            if (str_starts_with(rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, rtrim($web, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) { throw new RuntimeException('A private system temporary directory outside the WordPress web root is required.'); }
        }
        $dir = $base . '/shelfrelay-' . substr(hash('sha256', ABSPATH . wp_salt('auth')), 0, 20);
        if (is_link($dir)) { throw new RuntimeException('The private ShelfRelay staging directory cannot be a symbolic link.'); }
        // Native creation sets 0700 atomically; WP_Filesystem_Direct::mkdir() creates with the default mode before chmod.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Supplier XML must never enter a directory created with broader permissions.
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) { throw new RuntimeException('Cannot create the private ShelfRelay staging directory.'); }
        $resolved = realpath($dir);
        if (is_link($dir) || !$resolved || $resolved !== $dir || !self::filesystem()->chmod($resolved, 0700)) {
            throw new RuntimeException('Cannot secure the private ShelfRelay staging directory.');
        }
        foreach ($web_roots as $web) {
            if (str_starts_with(rtrim($resolved, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, rtrim($web, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) { throw new RuntimeException('The ShelfRelay staging directory must remain outside the web root.'); }
        }
        clearstatcache(true, $resolved);
        if ((fileperms($resolved) & 0777) !== 0700) { throw new RuntimeException('The private ShelfRelay staging directory requires owner-only permissions.'); }
        return $resolved;
    }

    /** The worker needs the local POSIX filesystem; remote/interactive transports cannot protect private staging. */
    public static function filesystem(): WP_Filesystem_Direct {
        if (!class_exists('WP_Filesystem_Direct')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        }
        return new WP_Filesystem_Direct(null);
    }

    public static function secure_file(string $path): void {
        $dir = self::private_dir(); $resolved = realpath($path);
        if (is_link($path) || !$resolved || dirname($resolved) !== $dir || !self::filesystem()->chmod($resolved, 0600)) {
            wp_delete_file($path);
            throw new RuntimeException('Cannot secure the private ShelfRelay staging file.');
        }
        clearstatcache(true, $resolved);
        if ((fileperms($resolved) & 0777) !== 0600) {
            wp_delete_file($path);
            throw new RuntimeException('The private ShelfRelay staging file requires owner-only permissions.');
        }
    }

    public function settings(): array { return (array) get_option('shelfrelay_settings', array()); }
    private function policy(array $s): array {
        $p = array('market_country' => $s['market_country'], 'currency' => $s['currency'], 'price_type' => $s['price_type'], 'merchant_owned_fields' => $s['merchant_owned_fields'], 'as_of' => gmdate('Y-m-d'));
        if ($s['supplier_value'] !== '') { $p['supplier_id'] = array('type' => $s['supplier_type'], 'value' => $s['supplier_value']); }
        return $p;
    }
    private function settings_hash(array $s): string {
        unset($s['api_key'], $s['source_secret'], $s['approved'], $s['schedule_enabled'], $s['schedule'], $s['consent']);
        return hash('sha256', wp_json_encode($s));
    }
    private function policy_hash(array $policy): string { unset($policy['as_of']); return hash('sha256', wp_json_encode($policy)); }
    private static function seal(string $text): string {
        if (!function_exists('openssl_encrypt')) { throw new RuntimeException('The PHP OpenSSL extension is required to protect saved credentials.'); }
        $iv = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt($text, 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) { throw new RuntimeException('Credential encryption failed.'); }
        return base64_encode($iv . $tag . $encrypted);
    }
    private static function unseal(string $stored): string {
        if ($stored === '') { return ''; }
        $blob = base64_decode($stored, true);
        if ($blob === false || strlen($blob) < 28) { throw new RuntimeException('Saved credentials are unreadable. Enter them again.'); }
        $value = openssl_decrypt(substr($blob, 28), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($blob, 0, 12), substr($blob, 12, 16));
        if ($value === false) { throw new RuntimeException('WordPress encryption salts changed. Enter the credentials again.'); }
        return $value;
    }
    private function ready(array $s): void {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_product')) { throw new RuntimeException('Activate WooCommerce before using ShelfRelay.'); }
        if (empty($s['consent'])) { throw new RuntimeException('Review and enable the service data-transfer consent first.'); }
        ShelfRelay_Feed::url($s['api_url']);
        if (rtrim($s['api_url'], '/') !== SHELFRELAY_API_BASE) { throw new RuntimeException('Use the official ShelfRelay RapidAPI gateway shown in the connection settings.'); }
        if (self::unseal($s['api_key']) === '') { throw new RuntimeException('Save your RapidAPI application key first.'); }
        if ($s['currency'] !== get_woocommerce_currency()) { throw new RuntimeException('Policy currency must match the WooCommerce store currency.'); }
        if ($s['price_type'] !== (wc_prices_include_tax() ? '02' : '01')) { throw new RuntimeException('Choose the price convention matching WooCommerce tax settings.'); }
    }

    private function new_job(array $s, string $path, bool $auto): string {
        $active = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->jobs} WHERE status IN ('staging','normalizing','applying')"));
        if ($active >= 3) { wp_delete_file($path); throw new RuntimeException('The queue is busy. Let the current job finish before adding another feed.'); }
        $id = wp_generate_uuid4(); $destination = self::private_dir() . '/' . $id . '.xml';
        // Both paths are private; persist the queue only after the complete move and permission check succeed.
        if (!self::filesystem()->move($path, $destination, false)) { wp_delete_file($path); wp_delete_file($destination); throw new RuntimeException('Cannot stage the feed privately.'); }
        self::secure_file($destination); $now = gmdate('Y-m-d H:i:s');
        $ok = $this->db->insert($this->jobs, array('id' => $id, 'source_id' => $s['source_id'], 'settings_hash' => $this->settings_hash($s),
            'status' => 'staging', 'auto_apply' => $auto ? 1 : 0, 'allow_existing' => 0, 'file_path' => $destination,
            'record_cursor' => 0, 'total' => 0, 'policy' => wp_json_encode($this->policy($s)), 'message' => 'Reading the feed safely.', 'created_at' => $now, 'updated_at' => $now));
        if (!$ok) { wp_delete_file($destination); throw new RuntimeException('Could not persist the import queue.'); }
        return $id;
    }

    private function fetch(array $s): string {
        if (!$s['source_url']) { throw new RuntimeException('Save a source HTTPS feed URL first, or upload an XML file.'); }
        $headers = array('Accept' => 'application/xml, text/xml');
        $secret = self::unseal($s['source_secret']);
        if ($s['auth_type'] === 'basic') {
            if (!$s['source_user'] || !$secret) { throw new RuntimeException('Save the source username and password.'); }
            $headers['Authorization'] = 'Basic ' . base64_encode($s['source_user'] . ':' . $secret);
        } elseif ($s['auth_type'] === 'bearer') {
            if (!$secret) { throw new RuntimeException('Save the source bearer token.'); }
            $headers['Authorization'] = 'Bearer ' . $secret;
        }
        return ShelfRelay_Feed::download($s['source_url'], $headers, ShelfRelay_Feed::MAX_FILE);
    }

    public function scheduled(): void {
        $s = $this->settings();
        if (empty($s['schedule_enabled']) || empty($s['approved'])) { return; }
        if ($this->db->get_var("SELECT id FROM {$this->jobs} WHERE status IN ('staging','normalizing','applying','preview') LIMIT 1")) { return; }
        try { $this->ready($s); $this->new_job($s, $this->fetch($s), true); }
        catch (Throwable $e) { update_option('shelfrelay_last_error', array('at' => gmdate('c'), 'message' => $e->getMessage()), false); }
    }

    private function update_job(string $id, array $values): void {
        $current = $this->db->get_var($this->db->prepare("SELECT status FROM {$this->jobs} WHERE id=%s", $id));
        if ($current === 'cancelled' && ($values['status'] ?? '') !== 'cancelled') { return; }
        $values['updated_at'] = gmdate('Y-m-d H:i:s');
        // Compare-and-set prevents a worker from resurrecting a job cancelled in another request.
        $this->db->update($this->jobs, $values, array('id' => $id, 'status' => $current));
    }
    private function state(string $source, string $isbn): ?array {
        return $this->db->get_row($this->db->prepare("SELECT * FROM {$this->states} WHERE source_id=%s AND isbn=%s", $source, $isbn), ARRAY_A) ?: null;
    }
    private function incoming_identity(string $xml): array {
        $doc = new DOMDocument(); $previous = libxml_use_internal_errors(true);
        try {
            if (stripos($xml, '<!DOCTYPE') !== false || !$doc->loadXML($xml, LIBXML_NONET)) { throw new RuntimeException('Invalid product XML.'); }
            $xp = new DOMXPath($doc);
            $ref = trim((string) $xp->evaluate('string(//*[local-name()="Product" or local-name()="product"]/*[local-name()="RecordReference" or local-name()="a001"][1])'));
            $ids = $xp->query('//*[local-name()="Product" or local-name()="product"]/*[local-name()="ProductIdentifier" or local-name()="productidentifier"]');
            $isbn = '';
            foreach ($ids as $id) {
                $type = ''; $value = '';
                foreach ($id->childNodes as $child) {
                    if (in_array($child->localName, array('ProductIDType', 'b221'), true)) { $type = trim($child->textContent); }
                    if (in_array($child->localName, array('IDValue', 'b244'), true)) { $value = trim($child->textContent); }
                }
                if (in_array($type, array('03', '15'), true) && preg_match('/^97[89][0-9]{10}$/', $value)) {
                    if ($isbn && $isbn !== $value) { throw new RuntimeException('The product has ambiguous ISBN identifiers.'); } $isbn = $value;
                }
            }
            // This only finds local history. The service validates identity and every semantic value.
            return array('isbn13' => $isbn, 'record_reference' => $ref);
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }

    public function worker(): void {
        // An atomic option prevents concurrent cron/manual workers; its lease survives a killed request.
        $lease = time();
        if (!add_option('shelfrelay_worker_lock', $lease, '', false)) {
            $old = (int) get_option('shelfrelay_worker_lock');
            if ($old > time() - 300) { return; }
            $this->db->delete($this->db->options, array('option_name' => 'shelfrelay_worker_lock', 'option_value' => (string) $old));
            wp_cache_delete('shelfrelay_worker_lock', 'options');
            if (!add_option('shelfrelay_worker_lock', $lease, '', false)) { return; }
        }
        try {
            // Advance several bounded pages per visit so a quiet store does not need a minute per record batch.
            $deadline = microtime(true) + 15;
            for ($passes = 0; $passes < 20 && microtime(true) < $deadline; ++$passes) {
                $job = $this->db->get_row("SELECT * FROM {$this->jobs} WHERE status IN ('staging','normalizing','applying') ORDER BY created_at ASC LIMIT 1", ARRAY_A);
                if (!$job) { $this->cleanup(); return; }
                $s = $this->settings(); $this->ready($s);
                if ($job['source_id'] !== $s['source_id'] || $job['settings_hash'] !== $this->settings_hash($s)) {
                    $this->update_job($job['id'], array('status' => 'failed', 'message' => 'Settings changed after this job was created. Cancel it and create a new preview.')); continue;
                }
                $policy = json_decode($job['policy'], true);
                if ($job['status'] !== 'staging' && ($policy['as_of'] ?? '') !== gmdate('Y-m-d')) {
                    $this->refresh_date($job); continue;
                }
                try {
                    if ($job['status'] === 'staging') { $this->stage($job); }
                    elseif ($job['status'] === 'normalizing') { $this->normalize($job, $s); }
                    else { $this->apply_batch($job, $s); }
                } catch (Throwable $e) { $this->update_job($job['id'], array('status' => 'failed', 'message' => $e->getMessage())); }
            }
        } catch (Throwable $e) { update_option('shelfrelay_last_error', array('at' => gmdate('c'), 'message' => $e->getMessage()), false); }
        finally { delete_option('shelfrelay_worker_lock'); }
    }

    private function stage(array $job): void {
        $page = ShelfRelay_Feed::page($job['file_path'], (int) $job['record_cursor']);
        foreach ($page['records'] as $i => $xml) {
            $position = (int) $job['record_cursor'] + $i;
            $exists = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->items} WHERE job_id=%s AND position=%d", $job['id'], $position));
            if (!$exists && !$this->db->insert($this->items, array('job_id' => $job['id'], 'position' => $position, 'status' => 'pending', 'xml' => $xml,
                'result' => '', 'baseline_hash' => '', 'record_key' => '', 'isbn' => '', 'message' => 'Waiting for normalization.'))) { throw new RuntimeException('Could not persist feed records.'); }
        }
        $values = array('record_cursor' => $page['next'], 'total' => $page['next'], 'message' => 'Reading products: ' . $page['next']);
        if ($page['finished']) {
            $values['status'] = 'normalizing'; $values['message'] = 'Feed checked. Preparing the preview.';
            wp_delete_file($job['file_path']); $values['file_path'] = '';
        }
        $this->update_job($job['id'], $values);
    }

    private function normalize(array $job, array $s): void {
        $rows = $this->db->get_results($this->db->prepare("SELECT * FROM {$this->items} WHERE job_id=%s AND status='pending' ORDER BY position LIMIT 100", $job['id']), ARRAY_A);
        if (!$rows) {
            if (empty($job['history_added']) && $this->append_history($job, $s)) { return; }
            $bad = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->items} WHERE job_id=%s AND status='rejected'", $job['id']));
            $this->update_job($job['id'], array('status' => $job['auto_apply'] && !$bad ? 'applying' : 'preview',
                'message' => $bad ? 'Review the rejected records before applying the supported products.' : ($job['auto_apply'] ? 'Applying this scheduled update.' : 'Preview ready. Review and approve before store changes.'))); return;
        }
        $records = array(); $selected = array(); $baselines = array(); $bytes = 0; $new_group = null;
        $policy = json_decode($job['policy'], true);
        foreach ($rows as $row) {
            try {
                $identity = $this->incoming_identity($row['xml']);
                $state = $identity['isbn13'] ? $this->state($s['source_id'], $identity['isbn13']) : null;
                if (!$state && $identity['record_reference']) {
                    $state = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->states} WHERE source_id=%s AND record_key=%s", $s['source_id'], hash('sha256', $identity['record_reference'])), ARRAY_A) ?: null;
                }
                $is_new = !$state && $identity['isbn13'] && !wc_get_product_id_by_sku($identity['isbn13']);
                $seed_isbn = $identity['isbn13'] ?: ($state['isbn'] ?? '');
                $seed_ref = $identity['record_reference'] ?: ($state['record_reference'] ?? '');
                $seed_key = hash('sha256', $seed_ref !== '' ? $seed_ref : 'isbn:' . $seed_isbn);
                $this->db->update($this->items, array('isbn' => $seed_isbn, 'record_key' => $seed_key), array('id' => $row['id']));
                if ($new_group !== null && $is_new !== $new_group) { continue; }
                if ($new_group === null) {
                    $new_group = $is_new;
                    // Editorial ownership protects existing products, not required initial values.
                    if ($new_group) { $policy['merchant_owned_fields'] = array(); }
                    $bytes = ShelfRelay_API::empty_envelope_bytes($policy);
                }
                $record = array('xml' => $row['xml']); if ($state) { $record['previous_xml'] = $state['xml']; }
                $size = ShelfRelay_API::record_bytes($record) + ($records ? 1 : 0);
                if ($records && $bytes + $size > ShelfRelay_API::BATCH_TARGET_BYTES) { break; }
                if ($bytes + $size > ShelfRelay_API::REQUEST_BYTES) { throw new RuntimeException('The encoded policy and a product exceed the service’s 8 MiB request limit.'); }
                $records[] = $record; $selected[] = $row; $baselines[] = $state; $bytes += $size;
            } catch (Throwable $e) { $this->db->update($this->items, array('status' => 'rejected', 'message' => $e->getMessage()), array('id' => $row['id'])); }
        }
        if (!$records) { return; }
        $body = ShelfRelay_API::encode($records, $policy);
        $host = wp_parse_url($s['api_url'], PHP_URL_HOST);
        $response = ShelfRelay_Feed::request(rtrim($s['api_url'], '/') . '/v1/normalize', array('timeout' => 60, 'redirection' => 0, 'sslverify' => true,
            'reject_unsafe_urls' => true, 'limit_response_size' => ShelfRelay_API::RESPONSE_BYTES + 1, 'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json',
                'X-RapidAPI-Key' => self::unseal($s['api_key']), 'X-RapidAPI-Host' => $host), 'body' => $body), true);
        if (is_wp_error($response)) { throw new RuntimeException('The service could not be reached. Retry this job after checking its status.'); }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) { throw new RuntimeException('Normalization returned HTTP ' . esc_html((string) $code) . '. Check the key, subscription and hard usage allowance, then retry. No source state from this batch was advanced.'); }
        $data = ShelfRelay_API::decode(wp_remote_retrieve_body($response), array(
            'content-length' => wp_remote_retrieve_header($response, 'content-length'),
            'content-encoding' => wp_remote_retrieve_header($response, 'content-encoding'),
            'transfer-encoding' => wp_remote_retrieve_header($response, 'transfer-encoding')), count($selected));
        if (is_array($data['usage'] ?? null)) { $this->update_job($job['id'], array('service_usage' => wp_json_encode($data['usage']))); }
        delete_option('shelfrelay_last_error');
        foreach ($data['results'] as $index => $result) {
            $row = $selected[$index]; $state = $baselines[$index];
            $values = array('result' => wp_json_encode($result), 'baseline_hash' => $state['source_hash'] ?? '');
            try {
                if (!is_array($result) || ($result['index'] ?? -1) !== $index) { throw new RuntimeException('The service returned an out-of-order result.'); }
                ShelfRelay_Store::validate($result);
                $isbn = $result['identity']['isbn13']; $reference = $result['identity']['record_reference'] ?? '';
                if ($state && ($state['isbn'] !== $isbn || $state['record_reference'] !== $reference)) { throw new RuntimeException('The source changed an existing record identity. Review it separately.'); }
                $key = hash('sha256', $reference !== '' ? $reference : 'isbn:' . $isbn);
                $duplicate = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->items} WHERE job_id=%s AND id<>%d AND (isbn=%s OR record_key=%s) AND isbn<>'' LIMIT 1", $job['id'], $row['id'], $isbn, $key));
                if ($duplicate) {
                    $this->db->update($this->items, array('status' => 'rejected', 'message' => 'Duplicate ISBN or record reference in this feed; neither duplicate will be applied.'), array('id' => $duplicate));
                    throw new RuntimeException('Duplicate ISBN or record reference in one feed; split ordered changes into separate jobs.');
                }
                $product = ShelfRelay_Store::resolve($isbn, $s['source_id'], $state);
                $values += array('status' => 'preview', 'isbn' => $isbn, 'record_key' => $key, 'product_id' => $product ? $product->get_id() : 0,
                    'message' => $result['operation'] === 'withdraw' ? 'Will draft the linked product; never deletes it.' : ($product ? 'Will update the matching ISBN.' : 'Will create a physical-book product.'));
            } catch (Throwable $e) {
                $values += array('status' => 'rejected', 'message' => $e->getMessage());
            }
            if ($this->db->update($this->items, $values, array('id' => $row['id'])) === false) { throw new RuntimeException('Could not persist the normalization receipt.'); }
        }
    }

    private function append_history(array $job, array $s): bool {
        $retained = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->states} WHERE source_id=%s", $s['source_id']));
        $new = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(DISTINCT i.isbn) FROM {$this->items} i WHERE i.job_id=%s AND i.status='preview' AND i.isbn<>'' AND NOT EXISTS (SELECT 1 FROM {$this->states} s WHERE s.source_id=%s AND s.isbn=i.isbn)", $job['id'], $s['source_id']));
        if ($retained + $new > ShelfRelay_Feed::MAX_PRODUCTS) { throw new RuntimeException('This source would exceed 5,000 retained ISBN records. The job was stopped before store application.'); }
        $day = json_decode($job['policy'], true)['as_of'] ?? gmdate('Y-m-d');
        $policy_changed = get_option('shelfrelay_applied_policy_hash', '') !== $this->policy_hash(json_decode($job['policy'], true));
        $date_condition = $policy_changed ? '1=1' : $this->db->prepare('s.checked_on<%s', $day);
        $rows = $this->db->get_results($this->db->prepare("SELECT s.* FROM {$this->states} s WHERE s.source_id=%s AND ($date_condition) AND NOT EXISTS (SELECT 1 FROM {$this->items} i WHERE i.job_id=%s AND (i.isbn=s.isbn OR i.record_key=s.record_key)) ORDER BY s.id LIMIT 100", $s['source_id'], $job['id']), ARRAY_A);
        if (!$rows) { $this->update_job($job['id'], array('history_added' => 1)); return false; }
        $count = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->items} WHERE job_id=%s", $job['id']));
        if ($count + count($rows) > ShelfRelay_Feed::MAX_PRODUCTS) { throw new RuntimeException('The feed plus retained source history exceeds the supported 5,000-record catalog. No records in this job have been applied.'); }
        foreach ($rows as $state) {
            if (!$this->db->insert($this->items, array('job_id' => $job['id'], 'position' => $count++, 'status' => 'pending', 'xml' => $state['xml'], 'result' => '',
                'baseline_hash' => '', 'record_key' => $state['record_key'], 'isbn' => $state['isbn'], 'origin' => 'history', 'message' => 'Retained source date/policy check; consumes a normalization record.'))) { throw new RuntimeException('Could not queue the retained source date check.'); }
        }
        $this->update_job($job['id'], array('total' => $count, 'message' => 'Checking retained records absent from this feed for date-effective prices and status.'));
        return true;
    }

    private function apply_batch(array $job, array $s): void {
        $deadline = microtime(true) + 20;
        $rows = $this->db->get_results($this->db->prepare("SELECT * FROM {$this->items} WHERE job_id=%s AND status='preview' ORDER BY position LIMIT 100", $job['id']), ARRAY_A);
        if (!$rows) {
            $failed = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->items} WHERE job_id=%s AND status IN ('failed','rejected')", $job['id']));
            $this->update_job($job['id'], array('status' => 'completed', 'message' => $failed ? 'Completed with exceptions. Supported products were applied; review the remaining records.' : 'Complete. Source state and store changes are saved.'));
            if (!$failed) { update_option('shelfrelay_applied_policy_hash', $this->policy_hash(json_decode($job['policy'], true)), false); }
            $applied = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->items} WHERE job_id=%s AND status='applied'", $job['id']));
            if (!$job['auto_apply'] && $applied) { $s['approved'] = true; update_option('shelfrelay_settings', $s, false); }
            return;
        }
        foreach ($rows as $row) {
            if (microtime(true) > $deadline) { return; }
            if ($this->db->get_var($this->db->prepare("SELECT status FROM {$this->jobs} WHERE id=%s", $job['id'])) !== 'applying') { return; }
            try {
                $state = $this->state($s['source_id'], $row['isbn']);
                $result = json_decode($row['result'], true);
                if ($state && $state['source_hash'] === ($result['source_hash'] ?? '') && $state['xml'] === ($result['next_xml'] ?? '') && $state['product_id'] && wc_get_product((int) $state['product_id'])) {
                    $this->db->update($this->states, array('checked_on' => json_decode($job['policy'], true)['as_of']), array('id' => $state['id']));
                    $this->db->update($this->items, array('status' => 'applied', 'product_id' => $state['product_id'], 'message' => 'already applied; checkpoint recovered'), array('id' => $row['id'])); continue;
                }
                if (($state['source_hash'] ?? '') !== $row['baseline_hash']) { throw new RuntimeException('Source state changed after preview. Create a fresh preview; this stale record was not applied.'); }
                $done = ShelfRelay_Store::apply($result, $s, $state, !empty($job['allow_existing']));
                $values = array('source_id' => $s['source_id'], 'record_key' => $row['record_key'], 'record_reference' => $result['identity']['record_reference'] ?? '',
                    'isbn' => $row['isbn'], 'product_id' => $done['product_id'], 'xml' => $result['next_xml'], 'source_hash' => $result['source_hash'], 'checked_on' => json_decode($job['policy'], true)['as_of'], 'updated_at' => gmdate('Y-m-d H:i:s'));
                // If the process dies after Woo saves, replay resolves the same SKU and reuses downloaded covers.
                if ($state) { $ok = $this->db->update($this->states, $values, array('id' => $state['id'])); }
                else { $ok = $this->db->insert($this->states, $values); }
                if ($ok === false) { throw new RuntimeException('The product was saved but its checkpoint could not be saved. Retry to reconcile the same ISBN.'); }
                $this->db->update($this->items, array('status' => 'applied', 'product_id' => $done['product_id'], 'message' => $done['outcome']), array('id' => $row['id']));
            } catch (Throwable $e) { $this->db->update($this->items, array('status' => 'failed', 'attempts' => (int) $row['attempts'] + 1, 'message' => $e->getMessage()), array('id' => $row['id'])); }
        }
    }

    private function cleanup(): void {
        // Finished receipts need a human-readable outcome, not another copy of the entire catalog.
        // Keep complete source XML in the state table and in unapplied/retry records only.
        $archive = $this->db->get_results("SELECT i.id,i.result FROM {$this->items} i INNER JOIN {$this->jobs} j ON j.id=i.job_id WHERE j.status='completed' AND i.status='applied' AND i.xml<>'' ORDER BY i.id LIMIT 100", ARRAY_A);
        foreach ($archive as $item) {
            $result = json_decode($item['result'], true) ?: array(); unset($result['next_xml']); $result['receipt_abbreviated'] = true;
            foreach (array('fields', 'apply_fields') as $section) {
                foreach (($result['projection'][$section] ?? array()) as $field => $value) {
                    if (is_string($value)) { $result['projection'][$section][$field] = wp_html_excerpt(wp_strip_all_tags($value), 180, '…'); }
                    elseif (is_array($value)) { $result['projection'][$section][$field] = count($value) . ' supplied values'; }
                }
            }
            $this->db->update($this->items, array('xml' => '', 'result' => wp_json_encode($result)), array('id' => $item['id'], 'status' => 'applied'));
        }
        $expired = $this->db->get_results($this->db->prepare("SELECT id,file_path FROM {$this->jobs} WHERE created_at<%s AND status IN ('completed','cancelled','failed','preview') LIMIT 20", gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)), ARRAY_A);
        foreach ($expired as $job) {
            if ($job['file_path'] && str_starts_with($job['file_path'], self::private_dir() . '/')) { wp_delete_file($job['file_path']); }
            $this->db->delete($this->items, array('job_id' => $job['id'])); $this->db->delete($this->jobs, array('id' => $job['id']));
        }
    }

    private function refresh_date(array $job): void {
        $policy = json_decode($job['policy'], true); $policy['as_of'] = gmdate('Y-m-d');
        $this->db->update($this->items, array('status' => 'pending', 'result' => '', 'baseline_hash' => '', 'message' => 'Refreshing the effective-price date.'), array('job_id' => $job['id']));
        $this->update_job($job['id'], array('status' => 'normalizing', 'policy' => wp_json_encode($policy), 'message' => 'The UTC price date changed. Refreshing the preview before continuing.'));
    }

    public function menu(): void { add_submenu_page('woocommerce', 'ShelfRelay', 'ShelfRelay', 'manage_woocommerce', 'shelfrelay', array($this, 'page')); }
    public function privacy_content(): void {
        if (!function_exists('wp_add_privacy_policy_content')) { return; }
        $content = '<p>' . esc_html__('Suggested text: Our store uses ShelfRelay through RapidAPI to interpret authorized supplier book metadata. When enabled, product XML, prior source records needed for updates, and the selected price/market policy are transmitted to the service over HTTPS. This integration does not send orders, payment data or storefront passwords.', 'shelfrelay-for-woocommerce') . '</p>';
        $content .= '<p>' . esc_html__('Supplier credentials remain encrypted in our WordPress database. Source history stays here until deliberately removed. Import receipts are retained locally for seven days; completed applied receipts are abbreviated. Covers are fetched by this site from supplied public resource URLs and retained in the Media Library. No analytics or marketing tracking is added by this connector.', 'shelfrelay-for-woocommerce') . '</p>';
        $content .= '<p><a href="https://shelfrelay.flamingoapis.synology.me:8443/privacy">' . esc_html__('ShelfRelay privacy', 'shelfrelay-for-woocommerce') . '</a> · <a href="https://rapidapi.com/IsraelDiego/api/shelfrelay-onix">' . esc_html__('RapidAPI service listing', 'shelfrelay-for-woocommerce') . '</a></p>';
        wp_add_privacy_policy_content('ShelfRelay for WooCommerce', wp_kses_post($content));
    }
    public function assets(string $hook): void {
        if ($hook !== 'woocommerce_page_shelfrelay') { return; }
        wp_enqueue_style('shelfrelay-admin', plugins_url('assets/admin.css', SHELFRELAY_FILE), array(), SHELFRELAY_VERSION);
        wp_enqueue_script('shelfrelay-admin', plugins_url('assets/admin.js', SHELFRELAY_FILE), array(), SHELFRELAY_VERSION, true);
    }
    private function form_start(string $intent, string $job = ''): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
        wp_nonce_field('shelfrelay');
        echo '<input type="hidden" name="action" value="shelfrelay"><input type="hidden" name="intent" value="' . esc_attr($intent) . '">';
        if ($job !== '') { echo '<input type="hidden" name="job" value="' . esc_attr($job) . '">'; }
    }
    private function redirect(string $message, string $job = '', bool $error = false): void {
        set_transient('shelfrelay_notice_' . get_current_user_id(), array('message' => $message, 'error' => $error), 60);
        $url = admin_url('admin.php?page=shelfrelay'); if ($job) { $url = add_query_arg('job', $job, $url); }
        wp_safe_redirect($url); exit;
    }
    public function action(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die('You do not have permission to manage this store.'); }
        check_admin_referer('shelfrelay');
        $intent = sanitize_key(wp_unslash($_POST['intent'] ?? ''));
        if ($intent === 'tick') { $this->worker(); wp_send_json_success(); }
        $job_id = sanitize_text_field(wp_unslash($_POST['job'] ?? ''));
        try {
            $s = $this->settings();
            if ($intent === 'save') { $this->save_settings($s); $this->redirect('Settings saved. Create a new preview before enabling automatic updates.'); }
            if ($intent === 'upload' || $intent === 'fetch') {
                $this->ready($s);
                if ($intent === 'fetch') { $path = $this->fetch($s); }
                else {
                    // Keep PHP's temporary path exact; validate the upload origin and actual size below, never its supplied name/type.
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitizing an OS path can alter it; is_uploaded_file verifies PHP's upload provenance.
                    $file = $_FILES['feed'] ?? array();
                    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) { throw new RuntimeException('Choose a plain ONIX XML file within your WordPress upload limit.'); }
                    $size = filesize($file['tmp_name']);
                    if ($size === false || $size > ShelfRelay_Feed::MAX_FILE || $size < 1) { throw new RuntimeException('Choose a nonempty XML file up to 25 MiB.'); }
                    $path = self::private_dir() . '/upload-' . wp_generate_uuid4() . '.xml';
                    // wp_handle_upload defaults to public uploads. Keep PHP's upload-origin check and move only into our verified 0700 directory, without creating a Media Library entry or public URL.
                    // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- Private supplier XML must be staged outside public uploads; the route already checks capability, nonce, PHP upload origin and actual file size.
                    if (!move_uploaded_file($file['tmp_name'], $path)) { throw new RuntimeException('Could not stage the uploaded XML.'); }
                    self::secure_file($path);
                }
                $job_id = $this->new_job($s, $path, false);
                $this->redirect('Feed queued for preview. The store has not changed.', $job_id);
            }
            $job = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->jobs} WHERE id=%s", $job_id), ARRAY_A);
            if (!$job) { throw new RuntimeException('This job no longer exists.'); }
            if ($intent === 'approve') {
                $this->ready($s);
                if ($job['status'] !== 'preview' || $job['settings_hash'] !== $this->settings_hash($s)) { throw new RuntimeException('Create a current preview before applying.'); }
                if ((json_decode($job['policy'], true)['as_of'] ?? '') !== gmdate('Y-m-d')) {
                    $this->refresh_date($job); $this->redirect('The effective-price date changed. Review and approve the refreshed preview when it is ready.', $job_id);
                }
                if (empty($_POST['approve_existing'])) { throw new RuntimeException('Confirm the preview and existing-product field ownership before applying.'); }
                $this->update_job($job_id, array('status' => 'applying', 'allow_existing' => 1, 'message' => 'Preview approved. Applying the supported records.'));
                $this->redirect('Approved. Watch the receipt for completion and exceptions.', $job_id);
            }
            if ($intent === 'retry') {
                if (!in_array($job['status'], array('failed', 'completed'), true)) { throw new RuntimeException('Wait for the current job to stop before retrying.'); }
                if ($job['settings_hash'] !== $this->settings_hash($s)) { throw new RuntimeException('Settings changed; create a fresh preview instead.'); }
                if ($job['status'] === 'completed' && (json_decode($job['policy'], true)['as_of'] ?? '') !== gmdate('Y-m-d')) { throw new RuntimeException('This completed receipt belongs to an earlier price date. Create a fresh preview to retry with current source history.'); }
                $failed = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->items} WHERE job_id=%s AND status='failed'", $job_id));
                if ($failed) {
                    $this->db->update($this->items, array('status' => 'preview'), array('job_id' => $job_id, 'status' => 'failed'));
                    $next = 'applying';
                } else {
                    $pending = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->items} WHERE job_id=%s AND status='pending'", $job_id));
                    $next = $job['file_path'] ? 'staging' : ($pending ? 'normalizing' : 'preview');
                }
                $this->update_job($job_id, array('status' => $next, 'message' => 'Retry queued. Previously applied products remain checkpointed.'));
                $this->redirect('Retry queued.', $job_id);
            }
            if ($intent === 'cancel') {
                $this->update_job($job_id, array('status' => 'cancelled', 'message' => 'Cancelled. Already applied products are retained; unapplied records were stopped.'));
                if ($job['file_path'] && str_starts_with($job['file_path'], self::private_dir() . '/')) { wp_delete_file($job['file_path']); }
                $this->redirect('Job cancelled.', $job_id);
            }
            throw new RuntimeException('Unknown ShelfRelay action.');
        } catch (Throwable $e) { $this->redirect($e->getMessage(), $job_id, true); }
    }

    private function save_settings(array $old): void {
        // This helper is called only by action(); verify again so the request boundary stays explicit here too.
        check_admin_referer('shelfrelay');
        $s = $old;
        foreach (array('label', 'auth_type', 'source_user', 'market_country', 'currency', 'price_type', 'supplier_type', 'supplier_value', 'schedule') as $name) {
            $s[$name] = trim(sanitize_text_field(wp_unslash($_POST[$name] ?? '')));
        }
        $s['api_url'] = SHELFRELAY_API_BASE;
        $s['source_url'] = trim(esc_url_raw(wp_unslash($_POST['source_url'] ?? ''), array('https')));
        $s['market_country'] = strtoupper($s['market_country']); $s['currency'] = strtoupper($s['currency']);
        if (!preg_match('/^[A-Z]{2}$/D', $s['market_country']) || !preg_match('/^[A-Z]{3}$/D', $s['currency'])) { throw new RuntimeException('Use a two-letter market country and a three-letter currency code.'); }
        if (!in_array($s['price_type'], array('01', '02'), true) || !in_array($s['auth_type'], array('none', 'basic', 'bearer'), true) || !in_array($s['schedule'], array('hourly', 'twicedaily', 'daily', 'weekly'), true)) { throw new RuntimeException('Unsupported policy or schedule setting.'); }
        foreach (array('api_url', 'source_url') as $name) { if ($s[$name] !== '') { ShelfRelay_Feed::url($s[$name]); } }
        if (($s['supplier_type'] === '') !== ($s['supplier_value'] === '')) { throw new RuntimeException('Supplier identifier type and value must both be supplied, or both left blank.'); }
        if ($s['supplier_type'] !== '' && !preg_match('/^[0-9]{2}$/D', $s['supplier_type'])) { throw new RuntimeException('Supplier identifier type must be a two-digit ONIX code.'); }
        foreach (array('api_key', 'source_secret') as $key) {
            // Credentials are opaque: preserve their bytes after unslashing and reject invalid structure/control characters instead of destructive text sanitization.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Scalar and header-control validation below deliberately preserves valid credential characters.
            $raw = wp_unslash($_POST[$key] ?? '');
            if (!is_string($raw)) { throw new RuntimeException('Credentials must be text values.'); }
            $value = trim($raw);
            if (preg_match('/[\x00-\x1f\x7f]/', $value)) { throw new RuntimeException('Credentials cannot contain control characters.'); }
            if ($value !== '') { $s[$key] = self::seal($value); }
            if (!empty($_POST['clear_' . $key])) { $s[$key] = ''; }
        }
        $owned = array_map('sanitize_key', (array) wp_unslash($_POST['merchant_owned_fields'] ?? array()));
        $s['merchant_owned_fields'] = array_values(array_intersect(ShelfRelay_Store::FIELDS, $owned));
        $s['consent'] = !empty($_POST['consent']); $s['publish_new'] = !empty($_POST['publish_new']);
        $s['schedule_enabled'] = !empty($_POST['schedule_enabled']);
        if ($this->settings_hash($s) !== $this->settings_hash($old)) { $s['approved'] = false; }
        if ($s['schedule_enabled'] && (!$s['consent'] || !$s['source_url'])) { throw new RuntimeException('Scheduling requires service consent and a saved HTTPS source URL.'); }
        update_option('shelfrelay_settings', $s, false);
        wp_clear_scheduled_hook('shelfrelay_fetch');
        if ($s['schedule_enabled']) { wp_schedule_event(time() + 60, $s['schedule'], 'shelfrelay_fetch'); }
        // Routine worker scheduling is installed on activation and can recover after a scheduler reset.
        if (!wp_next_scheduled('shelfrelay_worker')) { wp_schedule_event(time() + 30, 'shelfrelay_minute', 'shelfrelay_worker'); }
    }

    private function text_input(array $s, string $key, string $label, string $hint = '', string $type = 'text'): void {
        echo '<label class="sr-field"><span>' . esc_html($label) . '</span><input name="' . esc_attr($key) . '" type="' . esc_attr($type) . '" value="' . ($type === 'password' ? '' : esc_attr($key === 'api_url' ? SHELFRELAY_API_BASE : ($s[$key] ?? ''))) . '" autocomplete="' . ($type === 'password' ? 'new-password' : 'off') . '"' . ($key === 'api_url' ? ' readonly' : '') . '>';
        if ($hint) { echo '<small>' . esc_html($hint) . '</small>'; } echo '</label>';
    }
    public function page(): void {
        if (!current_user_can('manage_woocommerce')) { return; }
        $s = $this->settings(); $notice = get_transient('shelfrelay_notice_' . get_current_user_id()); delete_transient('shelfrelay_notice_' . get_current_user_id());
        echo '<div class="wrap shelfrelay"><header class="sr-top"><div><p class="sr-eyebrow">SHELFRELAY / CATALOG CONNECTION</p><h1>Keep your bookshop in step.</h1><p>Preview your ONIX feed, choose what it owns, then let supported updates arrive on schedule.</p></div><span class="sr-pill">' . (empty($s['approved']) ? 'First preview needed' : 'Source approved') . '</span></header>';
        if ($notice) { echo '<div class="notice ' . ($notice['error'] ? 'notice-error' : 'notice-success') . '"><p>' . esc_html($notice['message']) . '</p></div>'; }
        if (!class_exists('WooCommerce')) { echo '<div class="notice notice-error"><p>Activate WooCommerce before previewing or applying a feed.</p></div>'; }
        $last = get_option('shelfrelay_last_error');
        if ($last) { echo '<div class="sr-alert"><strong>Last background check</strong><p>' . esc_html($last['message']) . ' <small>' . esc_html($last['at']) . '</small></p></div>'; }
        echo '<div class="sr-layout"><main><section class="sr-card"><div class="sr-card-head"><span class="sr-number">01</span><div><h2>Connect your source</h2><p>One source, one market, one physical product for each ISBN.</p></div></div>';
        $this->form_start('save');
        echo '<div class="sr-grid">';
        $this->text_input($s, 'label', 'Source name', 'A name you will recognize in import receipts.');
        $this->text_input($s, 'api_url', 'ShelfRelay service', 'Official RapidAPI gateway. Your key is sent only to this host.', 'url');
        $this->text_input($s, 'api_key', 'RapidAPI application key', empty($s['api_key']) ? 'Required. It is stored encrypted locally and never displayed.' : 'A key is saved. Leave blank to keep it.', 'password');
        $this->text_input($s, 'source_url', 'Supplier feed URL', 'Public HTTPS XML. Credentials stay on this WordPress site. Redirects are not followed.', 'url');
        echo '<label class="sr-field"><span>Supplier authentication</span><select name="auth_type">';
        foreach (array('none' => 'No authentication', 'basic' => 'Username and password', 'bearer' => 'Bearer token') as $key => $label) { echo '<option value="' . esc_attr($key) . '"' . selected($s['auth_type'], $key, false) . '>' . esc_html($label) . '</option>'; } echo '</select></label>';
        $this->text_input($s, 'source_user', 'Supplier username', 'Only used for username/password authentication.');
        $this->text_input($s, 'source_secret', 'Supplier password or bearer token', empty($s['source_secret']) ? 'Stored encrypted locally; never sent to ShelfRelay.' : 'A source credential is saved. Leave blank to keep it.', 'password');
        echo '</div><details class="sr-details"><summary>Remove a saved credential</summary><label><input type="checkbox" name="clear_api_key"> Remove saved RapidAPI key</label><br><label><input type="checkbox" name="clear_source_secret"> Remove saved supplier secret</label></details>';
        echo '<h3>Choose the price policy</h3><p>We select supplied prices. ShelfRelay does not convert currencies or calculate tax.</p><div class="sr-grid sr-grid-three">';
        $this->text_input($s, 'market_country', 'Market country', 'Two letters, such as US.');
        $this->text_input($s, 'currency', 'Currency', 'Must match store currency: ' . (function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'WooCommerce required') . '.');
        echo '<label class="sr-field"><span>ONIX price type</span><select name="price_type"><option value="01"' . selected($s['price_type'], '01', false) . '>01 · excluding tax</option><option value="02"' . selected($s['price_type'], '02', false) . '>02 · including tax</option></select><small>Must match WooCommerce’s entered-price tax setting.</small></label></div><details class="sr-details"><summary>Choose a specific supplier in a multi-supplier record</summary><div class="sr-grid">';
        $this->text_input($s, 'supplier_type', 'ONIX supplier ID type', 'Two-digit code, such as 06 for GLN. Leave blank unless needed.');
        $this->text_input($s, 'supplier_value', 'Supplier ID value', 'Use the exact identifier supplied in your feed.'); echo '</div></details>';
        echo '<h3>Keep editorial control</h3><p>Checked fields stay merchant-owned on existing products. New products receive their initial feed values.</p><div class="sr-ownership">';
        $labels = array('title' => 'Title', 'description' => 'Description', 'regular_price' => 'Price', 'stock_status' => 'Stock status', 'cover_url' => 'Cover', 'contributors' => 'Contributors', 'publisher' => 'Publisher', 'publication_date' => 'Publication date', 'languages' => 'Languages', 'subjects' => 'Supplied subject values');
        foreach ($labels as $field => $label) { echo '<label><input type="checkbox" name="merchant_owned_fields[]" value="' . esc_attr($field) . '"' . checked(in_array($field, $s['merchant_owned_fields'], true), true, false) . '> ' . esc_html($label) . '</label>'; }
        echo '</div><div class="sr-divider"></div><h3>Set the pace</h3><div class="sr-grid"><label class="sr-field"><span>Check the source</span><select name="schedule">';
        foreach (array('hourly' => 'Hourly', 'twicedaily' => 'Twice a day', 'daily' => 'Daily', 'weekly' => 'Weekly') as $key => $label) { echo '<option value="' . esc_attr($key) . '"' . selected($s['schedule'], $key, false) . '>' . esc_html($label) . '</option>'; }
        echo '</select><small>Uses WordPress cron. A host cron trigger is recommended for reliable timing on quiet stores.</small></label><div class="sr-checks"><label><input type="checkbox" name="schedule_enabled"' . checked(!empty($s['schedule_enabled']), true, false) . '> Enable automatic source checks after an approved import</label><label><input type="checkbox" name="publish_new"' . checked(!empty($s['publish_new']), true, false) . '> Publish new supported titles; otherwise create drafts</label></div></div>';
        echo '<div class="sr-consent"><label><input type="checkbox" name="consent"' . checked(!empty($s['consent']), true, false) . '> I am authorized to process this feed and send its product records and prior source state to ShelfRelay through RapidAPI.</label><p>Supplier credentials stay here. Product data, including supplied resource URLs, crosses the service and RapidAPI. The service performs normalization; this plugin retains the catalog history and applies the result. No storefront or supplier password is sent.</p><p><a href="https://shelfrelay.flamingoapis.synology.me:8443/terms" target="_blank" rel="noopener noreferrer">Service terms</a> · <a href="https://shelfrelay.flamingoapis.synology.me:8443/privacy" target="_blank" rel="noopener noreferrer">Privacy</a> · <a href="https://rapidapi.com/IsraelDiego/api/shelfrelay-onix" target="_blank" rel="noopener noreferrer">Subscription and limits</a></p></div>';
        submit_button(__('Save connection', 'shelfrelay-for-woocommerce'), 'primary', 'submit', false); echo '</form></section>';
        echo '<section class="sr-card"><div class="sr-card-head"><span class="sr-number">02</span><div><h2>Preview before the first update</h2><p>No product changes until you approve the preview. Up to 5,000 retained ISBN records; 25 MiB of plain XML per feed. Required date/policy checks also use your service allowance.</p></div></div><div class="sr-import-choices">';
        $this->form_start('fetch'); submit_button(__('Preview HTTPS feed', 'shelfrelay-for-woocommerce'), 'secondary', 'submit', false); echo '</form>';
        $this->form_start('upload'); echo '<label class="sr-file">' . esc_html__('Upload ONIX XML', 'shelfrelay-for-woocommerce') . ' <input type="file" name="feed" accept=".xml,.onx,application/xml,text/xml" required></label>'; submit_button(__('Preview file', 'shelfrelay-for-woocommerce'), 'secondary', 'submit', false); echo '</form></div></section>';
        $this->receipt(); echo '</main><aside><section class="sr-card sr-aside"><p class="sr-eyebrow">A CLEAR CONTRACT</p><h2>Your feed. Your store.</h2><ul><li>Physical books, one ISBN per product.</li><li>ONIX 3.0 and 3.1, reference or short tags.</li><li>Missing stock never becomes a made-up quantity.</li><li>A title absent from a delta is never deleted.</li><li>Record withdrawals draft linked products.</li><li>Covers are downloaded by your WordPress site.</li></ul><p>Multi-source reconciliation, grouped digital editions and custom mappings are outside this connector.</p></section><section class="sr-card sr-aside"><h2>Where to look next</h2><p>Start with a small representative feed. Review prices, availability and field ownership before enabling recurring updates.</p><p>Unchanged feed bytes are still checked: effective prices may change with the date.</p><p>Receipts are kept for seven days. Applied source history remains in your WordPress database for future block updates.</p></section></aside></div></div>';
    }

    private function receipt(): void {
        $jobs = $this->db->get_results("SELECT * FROM {$this->jobs} ORDER BY created_at DESC LIMIT 12", ARRAY_A);
        echo '<section class="sr-card" id="sr-receipts"><div class="sr-card-head"><span class="sr-number">03</span><div><h2>Import receipts</h2><p>Every supported, rejected and retried record has a visible outcome.</p></div></div>';
        if (!$jobs) { echo '<div class="sr-empty"><strong>Your first receipt will appear here.</strong><p>Save the connection, then preview a feed or upload a file.</p></div></section>'; return; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only receipt selection; the enclosing admin page checks manage_woocommerce and mutations use nonce-protected POSTs.
        $selected = sanitize_text_field(wp_unslash($_GET['job'] ?? $jobs[0]['id']));
        $job = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->jobs} WHERE id=%s", $selected), ARRAY_A) ?: $jobs[0];
        echo '<div class="sr-job-list">';
        foreach ($jobs as $entry) {
            echo '<a class="sr-job' . ($entry['id'] === $job['id'] ? ' is-selected' : '') . '" href="' . esc_url(add_query_arg(array('page' => 'shelfrelay', 'job' => $entry['id']), admin_url('admin.php'))) . '"><span>' . esc_html($entry['created_at']) . ' UTC</span><strong>' . esc_html(ucfirst($entry['status'])) . '</strong></a>';
        }
        echo '</div><div class="sr-receipt-head"><div><h3>' . esc_html(ucfirst($job['status'])) . ' · ' . esc_html(number_format_i18n((int) $job['total'])) . ' records</h3><p>' . esc_html($job['message']) . '</p><code>' . esc_html($job['id']) . '</code></div><span class="sr-pill">' . ($job['auto_apply'] ? 'Scheduled source' : 'Manual preview') . '</span></div>';
        $usage = json_decode($job['service_usage'] ?? '', true);
        if (is_array($usage)) {
            echo '<p class="sr-usage">Latest service receipt: ' . esc_html($usage['records_processed'] ?? '?') . ' records processed in that batch';
            if (isset($usage['calls_remaining'], $usage['records_remaining'])) { echo ' · ' . esc_html(number_format_i18n($usage['calls_remaining'])) . ' calls / ' . esc_html(number_format_i18n($usage['records_remaining'])) . ' records remaining at that response'; }
            echo '. Retained-source date checks also consume the service allowance.</p>';
        }
        $counts = $this->db->get_results($this->db->prepare("SELECT status,COUNT(*) AS n FROM {$this->items} WHERE job_id=%s GROUP BY status", $job['id']), ARRAY_A);
        echo '<div class="sr-stats">'; foreach ($counts as $count) { echo '<span><strong>' . esc_html(number_format_i18n($count['n'])) . '</strong> ' . esc_html($count['status']) . '</span>'; } echo '</div>';
        if (in_array($job['status'], array('staging', 'normalizing', 'applying'), true)) {
            echo '<div data-shelfrelay-active data-url="' . esc_url(admin_url('admin-post.php')) . '" data-nonce="' . esc_attr(wp_create_nonce('shelfrelay')) . '"><p class="sr-processing"><span></span> Working through the queue. This page refreshes after each batch.</p><p><button type="button" class="button sr-pause-view">Pause page updates</button> <a class="button" href="' . esc_url(add_query_arg(array('page' => 'shelfrelay', 'job' => $job['id']), admin_url('admin.php'))) . '">Refresh receipt</a></p></div>';
        }
        if ($job['status'] === 'preview') {
            $this->form_start('approve', $job['id']);
            echo '<div class="sr-consent"><label><input type="checkbox" name="approve_existing" required> I reviewed this preview and field ownership. Apply the supported records, including feed-owned fields on matching existing products.</label><p>Rejected records are skipped and remain in the receipt. Withdrawals draft linked products. Publishing new titles follows your saved connection setting.</p></div>';
            submit_button(__('Apply supported records', 'shelfrelay-for-woocommerce'), 'primary', 'submit', false); echo '</form>';
        }
        if (in_array($job['status'], array('failed', 'completed'), true)) { $this->form_start('retry', $job['id']); submit_button(__('Retry failed work', 'shelfrelay-for-woocommerce'), 'secondary', 'submit', false); echo '</form>'; }
        if (!in_array($job['status'], array('completed', 'cancelled'), true)) { $this->form_start('cancel', $job['id']); submit_button(__('Cancel remaining work', 'shelfrelay-for-woocommerce'), 'secondary', 'submit', false); echo '</form>'; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination of the capability-protected receipt, without a state change.
        $page = max(1, absint(wp_unslash($_GET['sr_page'] ?? 1))); $offset = ($page - 1) * 30;
        $rows = $this->db->get_results($this->db->prepare("SELECT * FROM {$this->items} WHERE job_id=%s ORDER BY position LIMIT 30 OFFSET %d", $job['id'], $offset), ARRAY_A);
        echo '<div class="sr-table-wrap"><table class="widefat sr-records"><thead><tr><th>Book / ISBN</th><th>Planned update</th><th>Result</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $result = json_decode($row['result'], true) ?: array(); $projection = $result['projection'] ?? array(); $fields = $projection['fields'] ?? array();
            echo '<tr><td><strong>' . esc_html($fields['title'] ?? ('Product ' . ((int) $row['position'] + 1))) . '</strong><br><code>' . esc_html($row['isbn'] ?: 'Identity not yet available') . '</code>';
            if ($row['product_id']) { echo '<br><a href="' . esc_url(get_edit_post_link((int) $row['product_id'])) . '">Open product</a>'; } echo '</td><td>';
            if (($row['origin'] ?? 'feed') === 'history') { echo '<small>Retained source · date check</small><br>'; }
            if ($result) {
                echo '<strong>' . esc_html(ucfirst($result['operation'] ?? 'Rejected')) . '</strong>';
                if (isset($fields['regular_price'])) { echo '<br>' . esc_html($s_currency = (json_decode($job['policy'], true)['currency'] ?? '')) . ' ' . esc_html($fields['regular_price']); }
                if (isset($fields['stock_status'])) { echo '<br>' . esc_html($fields['stock_status']); }
                echo '<details><summary>Fields and diagnostics</summary><dl>';
                if (!empty($result['receipt_abbreviated'])) { echo '<p>Completed receipt values are abbreviated. Open the product for its current metadata.</p>'; }
                foreach (array_keys($projection['apply_fields'] ?? array()) as $field) {
                    $value = $fields[$field] ?? null;
                    $display = $value === null ? 'Clear this field' : (is_array($value) ? wp_json_encode($value, JSON_UNESCAPED_UNICODE) : (is_bool($value) ? ($value ? 'Yes' : 'No') : wp_strip_all_tags((string) $value)));
                    echo '<dt>' . esc_html(str_replace('_', ' ', $field)) . '</dt><dd>' . esc_html(wp_html_excerpt($display, 600, '…')) . '</dd>';
                }
                echo '</dl>';
                foreach (($result['diagnostics'] ?? array()) as $diagnostic) { echo '<p><strong>' . esc_html($diagnostic['severity'] ?? 'Info') . ' · ' . esc_html($diagnostic['code'] ?? '') . '</strong><br>' . esc_html($diagnostic['message'] ?? '') . '</p>'; }
                echo '</details>';
            } else { echo 'Waiting'; }
            echo '</td><td><span class="sr-state sr-state-' . esc_attr($row['status']) . '">' . esc_html($row['status']) . '</span><p>' . esc_html($row['message']) . '</p></td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="3">The feed is being staged. Records will appear here shortly.</td></tr>'; }
        echo '</tbody></table></div><div class="sr-pagination">';
        if ($page > 1) { echo '<a class="button" href="' . esc_url(add_query_arg(array('page' => 'shelfrelay', 'job' => $job['id'], 'sr_page' => $page - 1), admin_url('admin.php'))) . '">Previous 30</a> '; }
        if ($offset + 30 < (int) $job['total']) { echo '<a class="button" href="' . esc_url(add_query_arg(array('page' => 'shelfrelay', 'job' => $job['id'], 'sr_page' => $page + 1), admin_url('admin.php'))) . '">Next 30</a>'; }
        echo '</div></section>';
    }

    public function product_meta(): void {
        global $product;
        if (!$product || !$product->get_meta('_shelfrelay_source')) { return; }
        $rows = array('ISBN' => $product->get_meta('_shelfrelay_isbn13'), 'Publisher' => $product->get_meta('_shelfrelay_publisher'), 'Publication date' => $product->get_meta('_shelfrelay_publication_date'));
        $contributors = (array) $product->get_meta('_shelfrelay_contributors'); $names = array();
        foreach ($contributors as $person) { if (is_array($person) && !empty($person['name'])) { $names[] = $person['name']; } }
        if ($names) { $rows['Contributors'] = implode(', ', $names); }
        $languages = $product->get_meta('_shelfrelay_languages'); if (is_array($languages) && $languages) { $rows['Languages'] = implode(', ', $languages); }
        foreach ($rows as $label => $value) { if (is_string($value) && $value !== '') { echo '<span class="shelfrelay-book-detail" style="display:block">' . esc_html($label) . ': <span>' . esc_html($value) . '</span></span>'; } }
    }
}
