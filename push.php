<?php 

/**
 * Push: create export on current site and ask target to import using URLs we host.
 * $opts = [url, username, password, compression, incremental]
 */
function tss_perform_push($opts) {
    $target = rtrim($opts['url'], "/");
    $username = $opts['username'];
    $password = $opts['password'];
    $compression = $opts['compression'];
    $incremental = $opts['incremental'];

    // 1) Create export package on THIS site
    $export = tss_create_export_package([
        'compression' => $compression,
        'incremental' => $incremental ? 1 : 0
    ]);

    // export will return URLs accessible via content_url(...)
    if (empty($export) || empty($export['ok'])) throw new Exception('Failed to create export on this site: ' . ($export['error'] ?? ''));

    // 2) Call target import endpoint with our URLs
    $resp = wp_remote_post($target . '/wp-json/tss/v1/import', [
        'timeout' => 300,
        'body' => [
            'secret' => TSS_SECRET,
            'username' => $username,
            'password' => md5($password),
            'db' => $export['db'] ?? '',
            'uploads' => $export['uploads'] ?? '',
            'plugins' => $export['plugins'] ?? '',
            'themes' => $export['themes'] ?? '',
            'manifest' => $export['manifest'] ?? '',
        ]
    ]);

    if (is_wp_error($resp)) throw new Exception('Could not contact target import endpoint: ' . $resp->get_error_message());

    $json = json_decode(wp_remote_retrieve_body($resp), true);
    if (!$json || empty($json['ok'])) throw new Exception('Target import failed: ' . ($json['error'] ?? 'unknown'));

    // finished
    return 'Push export requested and processed by target successfully.';
}