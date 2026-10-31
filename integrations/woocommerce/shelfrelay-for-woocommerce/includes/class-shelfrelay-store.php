<?php
defined('ABSPATH') || exit;

/** Apply a validated service projection using WooCommerce's public product API. */
final class ShelfRelay_Store {
    const FIELDS = array('title', 'description', 'regular_price', 'stock_status', 'contributors', 'publisher',
        'publication_date', 'languages', 'subjects', 'cover_url', 'product_form', 'availability_code', 'publishing_status', 'price_includes_tax');
    const CLEARABLE = array('description', 'contributors', 'publisher', 'publication_date', 'languages', 'subjects', 'cover_url');

    public static function validate(array $result): array {
        if (($result['status'] ?? '') !== 'ok' || !in_array($result['operation'] ?? '', array('upsert', 'withdraw', 'unchanged'), true)) {
            throw new RuntimeException('The service rejected this product. Review its diagnostics.');
        }
        $isbn = $result['identity']['isbn13'] ?? '';
        if (!is_string($result['identity']['record_reference'] ?? '') || strlen($result['identity']['record_reference'] ?? '') > 191) { throw new RuntimeException('The source record reference exceeds the supported 191-byte identity limit.'); }
        if (!is_string($isbn) || !preg_match('/^97[89][0-9]{10}$/', $isbn)) { throw new RuntimeException('The service returned an invalid ISBN-13 identity.'); }
        $sum = 0; for ($i = 0; $i < 12; ++$i) { $sum += (int) $isbn[$i] * ($i % 2 ? 3 : 1); }
        if ((10 - $sum % 10) % 10 !== (int) $isbn[12]) { throw new RuntimeException('The service returned an invalid ISBN checksum.'); }
        if (!is_string($result['next_xml'] ?? null) || strlen($result['next_xml']) > 262144 || !preg_match('/^[a-f0-9]{64}$/', $result['source_hash'] ?? '')) {
            throw new RuntimeException('The service result has invalid source state.');
        }
        $projection = $result['projection'] ?? null;
        if (!is_array($projection) || !is_array($projection['fields'] ?? null) || !is_array($projection['field_states'] ?? null) || !is_array($projection['apply_fields'] ?? null)) {
            throw new RuntimeException('The service result has no complete mutation contract.');
        }
        $fields = $projection['fields']; $states = $projection['field_states'];
        foreach ($projection['apply_fields'] as $field => $apply_value) {
            if (!is_string($field) || !in_array($field, self::FIELDS, true) || !array_key_exists($field, $fields)) {
                throw new RuntimeException('The service requested an unsupported or missing field.');
            }
            if ($apply_value !== $fields[$field]) { throw new RuntimeException('The service mutation value disagrees with its projected field.'); }
            $state = $states[$field] ?? '';
            if ($state === 'clear') {
                if ($fields[$field] !== null || !in_array($field, self::CLEARABLE, true)) { throw new RuntimeException('An unsafe field-clear instruction was rejected.'); }
                continue;
            }
            if ($state !== 'value' || $fields[$field] === null) { throw new RuntimeException('An unknown/absent field cannot be applied.'); }
            $value = $fields[$field];
            if ($field === 'regular_price' && (!is_string($value) || !preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $value))) { throw new RuntimeException('Price must be an explicit non-negative decimal string.'); }
            if ($field === 'stock_status' && !in_array($value, array('instock', 'outofstock', 'onbackorder'), true)) { throw new RuntimeException('Unsupported stock status.'); }
            if ($field === 'price_includes_tax' && !is_bool($value)) { throw new RuntimeException('Invalid tax convention.'); }
            if (in_array($field, array('languages', 'contributors', 'subjects'), true)) {
                if (!is_array($value) || count($value) > 1000) { throw new RuntimeException('Invalid repeated metadata.'); }
                if ($field === 'languages') {
                    foreach ($value as $entry) { if (!is_string($entry)) { throw new RuntimeException('Invalid language metadata.'); } }
                } else {
                    foreach ($value as $entry) { if (!is_array($entry)) { throw new RuntimeException('Invalid contributor/subject metadata.'); }
                        foreach ($entry as $text) { if (!is_string($text) && $text !== null) { throw new RuntimeException('Invalid contributor/subject value.'); } }
                    }
                }
            } elseif (!in_array($field, array('regular_price', 'stock_status', 'price_includes_tax'), true) && (!is_string($value) || strlen($value) > 100000)) {
                throw new RuntimeException('Invalid text metadata.');
            }
        }
        return $result;
    }

    public static function resolve(string $isbn, string $source, ?array $state): ?WC_Product {
        $id = $state ? (int) $state['product_id'] : 0;
        if (!$id) { $id = (int) wc_get_product_id_by_sku($isbn); }
        if (!$id) { return null; }
        $product = wc_get_product($id);
        if (!$product || !$product->is_type('simple')) { throw new RuntimeException('The matching SKU must be a standard simple WooCommerce product.'); }
        if ($product->get_sku() !== $isbn) { throw new RuntimeException('The previously linked product SKU changed. Relink this ISBN before importing.'); }
        $owner = (string) $product->get_meta('_shelfrelay_source');
        if ($owner && $owner !== $source) { throw new RuntimeException('This SKU is already managed by a different ShelfRelay source.'); }
        return $product;
    }

    public static function apply(array $result, array $settings, ?array $state, bool $allow_existing): array {
        self::validate($result);
        $isbn = $result['identity']['isbn13'];
        $product = self::resolve($isbn, $settings['source_id'], $state);
        if ($product && !$state && !$allow_existing && $product->get_meta('_shelfrelay_source') !== $settings['source_id']) { throw new RuntimeException('Existing-product adoption needs the initial preview approval.'); }
        if ($result['operation'] === 'withdraw') {
            if ($product) {
                $product->set_status('draft');
                $product->update_meta_data('_shelfrelay_withdrawn', gmdate('c'));
                $product->update_meta_data('_shelfrelay_source', $settings['source_id']);
                $product->save();
            }
            return array('product_id' => $product ? $product->get_id() : 0, 'outcome' => $product ? 'withdrawn' : 'withdrawal recorded');
        }
        if ($result['operation'] === 'unchanged' && $state && $product) {
            return array('product_id' => $product->get_id(), 'outcome' => 'unchanged');
        }
        $new = !$product;
        $product = $product ?: new WC_Product_Simple();
        if ($new) { $product->set_sku($isbn); $product->set_status(!empty($settings['publish_new']) ? 'publish' : 'draft'); }
        $projection = $result['projection'];
        $fields = $projection['fields'];
        $apply = array_values(array_diff(array_keys($projection['apply_fields']), $new ? array() : ($settings['merchant_owned_fields'] ?? array())));
        if ($new && !in_array('title', $apply, true)) { throw new RuntimeException('A new product requires a feed-owned title.'); }
        if (in_array('price_includes_tax', $apply, true) && $fields['price_includes_tax'] !== wc_prices_include_tax()) {
            throw new RuntimeException('The feed price convention does not match WooCommerce tax settings.');
        }
        if (in_array('regular_price', $apply, true)) {
            $expected = wc_prices_include_tax() ? '02' : '01';
            if ($settings['price_type'] !== $expected) { throw new RuntimeException('Price type must match WooCommerce prices-entered-with-tax setting.'); }
        }
        // Fetch media before saving any product fields. A failed download must not advance source state.
        $attachment = null;
        if (in_array('cover_url', $apply, true) && $fields['cover_url'] !== null && $fields['cover_url'] !== '') {
            $cover = $fields['cover_url']; ShelfRelay_Feed::url($cover);
            $old = (string) $product->get_meta('_shelfrelay_cover_url');
            if ($cover !== $old || !$product->get_image_id()) { $attachment = self::cover($cover, $isbn, $settings['source_id']); }
        }
        foreach ($apply as $field) {
            $value = $fields[$field];
            switch ($field) {
                case 'title':
                    if (trim($value) === '') { throw new RuntimeException('An empty product title is not supported.'); }
                    $product->set_name(wp_strip_all_tags($value)); break;
                case 'description': $product->set_description($value === null ? '' : wp_kses_post($value)); break;
                case 'regular_price': $product->set_regular_price($value); break;
                case 'stock_status':
                    // Stock counts are never manufactured. Existing numeric stock remains merchant-controlled.
                    if ($product->get_manage_stock()) { throw new RuntimeException('This product manages numeric stock. Mark stock_status merchant-owned or disable numeric stock management before applying feed availability.'); }
                    $product->set_stock_status($value);
                    $product->set_backorders($value === 'onbackorder' ? 'notify' : 'no'); break;
                case 'cover_url':
                    if ($value === null || $value === '') { $product->set_image_id(0); $product->delete_meta_data('_shelfrelay_cover_url'); }
                    else { if ($attachment !== null) { $product->set_image_id($attachment); } $product->update_meta_data('_shelfrelay_cover_url', $value); }
                    break;
                default:
                    if ($value === null) { $product->delete_meta_data('_shelfrelay_' . $field); }
                    else { $product->update_meta_data('_shelfrelay_' . $field, $value); }
            }
        }
        $product->update_meta_data('_shelfrelay_source', $settings['source_id']);
        $product->update_meta_data('_shelfrelay_isbn13', $isbn);
        $product->update_meta_data('_shelfrelay_record_reference', $result['identity']['record_reference'] ?? '');
        $product->delete_meta_data('_shelfrelay_withdrawn');
        $product->save();
        return array('product_id' => $product->get_id(), 'outcome' => $new ? 'created' : 'updated');
    }

    private static function cover(string $url, string $isbn, string $source): int {
        $existing = get_posts(array('post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => 1,
            'meta_query' => array(array('key' => '_shelfrelay_original_url', 'value' => $url), array('key' => '_shelfrelay_source', 'value' => $source))));
        if ($existing && is_file(get_attached_file($existing[0]))) { return (int) $existing[0]; }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $path = ShelfRelay_Feed::download($url, array(), 8388608);
        try {
            $mime = wp_get_image_mime($path);
            $ext = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif');
            if (!isset($ext[$mime])) { throw new RuntimeException('The cover must be a supported JPEG, PNG, WebP or GIF image.'); }
            $size = @getimagesize($path);
            if (!$size || $size[0] * $size[1] > 24000000) { throw new RuntimeException('The cover exceeds the 24-megapixel image limit.'); }
            $id = media_handle_sideload(array('name' => $isbn . '.' . $ext[$mime], 'tmp_name' => $path), 0, 'Book cover ' . $isbn);
            if (is_wp_error($id)) { throw new RuntimeException('WordPress could not save this cover.'); }
            update_post_meta($id, '_shelfrelay_original_url', $url); update_post_meta($id, '_shelfrelay_source', $source);
            return (int) $id;
        } finally { if (is_file($path)) { wp_delete_file($path); } }
    }
}
