<?php
defined('ABSPATH') || exit;

/** Byte limits refer to encoded request JSON and decoded response JSON, not XML character counts. */
final class ShelfRelay_API {
    const REQUEST_BYTES = 8388608;
    const RESPONSE_BYTES = 33554432;
    const BATCH_TARGET_BYTES = 6000000;

    public static function encode(array $records, array $policy): string {
        $body = wp_json_encode(array('records' => $records, 'policy' => $policy));
        if (!is_string($body)) { throw new RuntimeException('The request could not be encoded as valid JSON. No records were sent.'); }
        if (strlen($body) > self::REQUEST_BYTES) { throw new RuntimeException('The encoded product and policy request exceeds the service’s 8 MiB JSON limit. No records were sent.'); }
        return $body;
    }

    public static function empty_envelope_bytes(array $policy): int { return strlen(self::encode(array(), $policy)); }
    public static function record_bytes(array $record): int {
        $encoded = wp_json_encode($record);
        if (!is_string($encoded)) { throw new RuntimeException('A product could not be encoded as valid JSON.'); }
        return strlen($encoded);
    }

    /** Decode completely before any record result/checkpoint from this response is persisted. */
    public static function decode(string $body, array $headers, int $expected): array {
        $bytes = strlen($body);
        if ($bytes > self::RESPONSE_BYTES) {
            throw new RuntimeException('The normalization response exceeds the service’s 32 MiB JSON limit. No batch records were applied or checkpointed. Retry after checking the service response.');
        }
        $length = $headers['content-length'] ?? '';
        $encoding = $headers['content-encoding'] ?? '';
        $transfer = $headers['transfer-encoding'] ?? '';
        $length = is_string($length) || is_int($length) ? trim((string) $length) : '';
        $encoding = is_string($encoding) ? strtolower(trim($encoding)) : 'unknown';
        $transfer = is_string($transfer) ? strtolower(trim($transfer)) : 'unknown';
        // WordPress may already have decoded gzip. Its compressed Content-Length is not JSON length.
        if (($encoding === '' || $encoding === 'identity') && ($transfer === '' || $transfer === 'identity') && preg_match('/^[0-9]+$/D', $length)) {
            if ((float) $length > self::RESPONSE_BYTES) {
                throw new RuntimeException('The service announced a normalization response larger than 32 MiB. No batch records were applied or checkpointed.');
            }
            if ((int) $length !== $bytes) {
                throw new RuntimeException('The normalization response was truncated or its length was inconsistent. No batch records were applied or checkpointed. Retry the saved job.');
            }
        }
        try { $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) {
            throw new RuntimeException('The normalization response was truncated or was not valid JSON. No batch records were applied or checkpointed. Retry the saved job.');
        }
        if (!is_array($data) || !is_array($data['results'] ?? null) || count($data['results']) !== $expected) {
            throw new RuntimeException('The normalization response did not contain every requested record. No batch records were applied or checkpointed. Retry the saved job.');
        }
        return $data;
    }
}
