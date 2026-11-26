<?php
require __DIR__ . '/bootstrap.php';
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(array('ready' => true, 'php' => PHP_VERSION, 'wordpress' => $wp_version)); return; }

function hook_signature(): array {
    global $wp_filter;
    $out = array();
    foreach (array('upload_dir', 'upload_mimes', 'wp_handle_upload_prefilter', 'wp_handle_sideload_prefilter', 'pre_move_uploaded_file', 'wp_handle_upload', 'wp_check_filetype_and_ext') as $tag) {
        $out[$tag] = array();
        if (isset($wp_filter[$tag])) {
            foreach ($wp_filter[$tag]->callbacks as $priority => $callbacks) {
                if ($callbacks) { $out[$tag][$priority] = array_keys($callbacks); }
            }
        }
    }
    return $out;
}

$case = $_POST['case'] ?? 'valid';
$file = $_FILES['feed'] ?? array();
$native_upload = isset($file['tmp_name']) && is_uploaded_file($file['tmp_name']);
$original_hash = $native_upload ? hash_file('sha256', $file['tmp_name']) : null;
$original_size = $native_upload ? filesize($file['tmp_name']) : null;
$dir = ShelfRelay::private_dir();
$before_files = glob($dir . '/*') ?: array();
$normal_upload_dir = wp_upload_dir(null, false)['path'];
$normal_mimes = get_allowed_mime_types();
$sentinel = tempnam(sys_get_temp_dir(), 'sr-unrelated-');
file_put_contents($sentinel, 'UNRELATED_FILE_MUST_SURVIVE');
$expected_sentinel = hash_file('sha256', $sentinel);
$created_public = array();
$late_filter = null;
$observation = array();

if ($case === 'forged_tmp') { $file['tmp_name'] = $sentinel; }
if ($case === 'fake_size') { $file['size'] = 1; }
if ($case === 'php_error') { $file['error'] = UPLOAD_ERR_PARTIAL; }
if ($case === 'prior_libxml_error') {
    libxml_use_internal_errors(true);
    $bad_reader = new XMLReader();
    $bad_reader->XML('<broken>');
    while ($bad_reader->read()) { }
    $bad_reader->close();
    $observation['preexisting_libxml_errors'] = count(libxml_get_errors());
}

add_filter('wp_handle_upload', static function ($result) use (&$observation, $normal_upload_dir, $normal_mimes) {
    $observation['post_hook_normal_directory'] = wp_upload_dir(null, false)['path'] === $normal_upload_dir;
    $observation['post_hook_normal_mimes'] = get_allowed_mime_types() === $normal_mimes;
    if (isset($result['file']) && is_file($result['file'])) {
        clearstatcache(true, $result['file']);
        $observation['post_hook_file_mode'] = fileperms($result['file']) & 0777;
    }
    return $result;
}, 1);

if ($case === 'prefilter_error') {
    add_filter('wp_handle_upload_prefilter', static function ($candidate) { $candidate['error'] = 'TEST_PREFILTER_REJECTION'; return $candidate; }, 10);
}
if ($case === 'throw_before_move') {
    add_filter('pre_move_uploaded_file', static function () { throw new RuntimeException('TEST_MOVE_EXCEPTION'); }, 10);
}
if ($case === 'short_circuit_move') {
    add_filter('pre_move_uploaded_file', static function () { return false; }, 10);
}
if ($case === 'reject_core_type') {
    add_filter('wp_check_filetype_and_ext', static function ($result) { $result['ext'] = false; $result['type'] = false; return $result; }, 10);
}
if ($case === 'redirect_directory') {
    $late_filter = static function ($uploads) use ($normal_upload_dir) { $uploads['path'] = $normal_upload_dir; return $uploads; };
    add_filter('wp_handle_upload_prefilter', static function ($candidate) use ($late_filter) {
        add_filter('upload_dir', $late_filter, PHP_INT_MAX);
        return $candidate;
    }, 10);
}
if ($case === 'foreign_result') {
    add_filter('wp_handle_upload', static function ($result) use ($sentinel) { $result['file'] = $sentinel; return $result; }, 10);
}
if ($case === 'nested_upload_before') {
    add_filter('wp_handle_upload_prefilter', static function ($candidate) use (&$observation) {
        static $inside = false;
        if (!$inside) {
            $inside = true;
            $second = $_FILES['other'];
            $observation['nested_result'] = wp_handle_upload($second, array('test_form' => false));
            $inside = false;
        }
        return $candidate;
    }, 10);
}
if ($case === 'nested_sideload_before') {
    add_filter('wp_handle_upload_prefilter', static function ($candidate) use (&$observation, $sentinel) {
        $second = array('tmp_name' => $sentinel, 'name' => 'unrelated.txt', 'type' => 'text/plain', 'size' => filesize($sentinel), 'error' => 0);
        $observation['nested_result'] = wp_handle_sideload($second, array('test_form' => false));
        return $candidate;
    }, 10);
}
if ($case === 'nested_upload_after') {
    add_filter('wp_handle_upload', static function ($result) use (&$observation, &$created_public) {
        static $inside = false;
        if (!$inside) {
            $inside = true;
            $second = $_FILES['other'];
            $nested = wp_handle_upload($second, array('test_form' => false));
            $observation['nested_result'] = $nested;
            if (isset($nested['file'])) { $created_public[] = $nested['file']; }
            $inside = false;
        }
        return $result;
    }, 10);
}

$before_hooks = hook_signature();
$result = array('case' => $case, 'native_upload' => $native_upload, 'original_size' => $original_size);
$path = null;
$original_document_root = $_SERVER['DOCUMENT_ROOT'];
if ($case === 'temp_inside_webroot') { $_SERVER['DOCUMENT_ROOT'] = sys_get_temp_dir(); }
try {
    $method = new ReflectionMethod('ShelfRelay', 'uploaded_feed');
    $path = $method->invoke(null, $file);
    clearstatcache(true, $path);
    $result += array(
        'accepted' => true,
        'basename' => basename($path),
        'same_bytes' => hash_file('sha256', $path) === $original_hash,
        'file_mode' => fileperms($path) & 0777,
        'dir_mode' => fileperms(dirname($path)) & 0777,
        'private_directory' => dirname($path) === $dir,
        'native_temporary_file_moved' => !file_exists($file['tmp_name']),
    );
} catch (Throwable $e) {
    $result += array('accepted' => false, 'error' => get_class($e) . ': ' . $e->getMessage());
} finally {
    $_SERVER['DOCUMENT_ROOT'] = $original_document_root;
    if ($late_filter) { remove_filter('upload_dir', $late_filter, PHP_INT_MAX); }
    $result['hooks_restored'] = hook_signature() === $before_hooks;
    $result['normal_directory_restored'] = wp_upload_dir(null, false)['path'] === $normal_upload_dir;
    $result['normal_mimes_restored'] = get_allowed_mime_types() === $normal_mimes;
    $result['unrelated_file_preserved'] = is_file($sentinel) && hash_file('sha256', $sentinel) === $expected_sentinel;
    $new_files = array_values(array_diff(glob($dir . '/*') ?: array(), $before_files));
    $result['private_files_remaining'] = count($new_files);
    $result['observations'] = $observation;
    if ($path && dirname($path) === $dir) { unlink($path); }
    foreach ($created_public as $created) { if (dirname($created) === $normal_upload_dir && is_file($created)) { unlink($created); } }
    if (file_exists($sentinel)) { unlink($sentinel); }
}
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
