<?php
/**
 * Pull: live site downloads export from production and restores locally.
 * $opts = [url, username, password, compression, incremental]
 */
function tss_perform_pull($opts) {
    $url = rtrim($opts['url'], "/");
    $username = $opts['username'];
    $password = $opts['password'];
    $compression = $opts['compression'];
    $incremental = $opts['incremental'];

    // 1) Request export manifest/URLs from production
    $resp = wp_remote_post($url . '/wp-json/tss/v1/export', [
        'timeout' => 300,
        'body' => [
            'secret' => TSS_SECRET,
            'username' => $username,
            'password' => $password,
            'incremental' => $incremental ? 1 : 0
        ]
    ]);

    if (is_wp_error($resp)) throw new Exception('Could not contact target: ' . $resp->get_error_message());

    $json = json_decode(wp_remote_retrieve_body($resp), true);
    if (!$json || empty($json['ok'])) throw new Exception('Target export failed: ' . ($json['error'] ?? 'unknown'));

    // 2) Backup current site (DB + files)
    tss_backup_current_site();

    // 3) Download zips (db/uploads/plugins/themes) - may be partial zips for incremental
    $downloaded = [];
    foreach (['db','uploads','plugins','themes','manifest'] as $k) {
        if (!empty($json[$k])) {
            $downloaded[$k] = tss_fetch_remote_file($json[$k]);
            if (!$downloaded[$k]) throw new Exception("Failed to download $k from target.");
        }
    }

    // 4) If there is a manifest, store it as last manifest
    if (!empty($downloaded['manifest'])) {
        copy($downloaded['manifest'], TSS_MANIFEST . '/last_manifest.json');
    }

    // 5) Extract files to appropriate locations
    if (!empty($downloaded['uploads'])) tss_extract_zip_to($downloaded['uploads'], WP_CONTENT_DIR . '/uploads');
    if (!empty($downloaded['plugins'])) tss_extract_zip_to($downloaded['plugins'], WP_CONTENT_DIR . '/plugins');
    if (!empty($downloaded['themes']))  tss_extract_zip_to($downloaded['themes'], WP_CONTENT_DIR . '/themes');

    // 6) Import DB (pure PHP, transactional)
    if (!empty($downloaded['db'])) {
        // db zip contains database.sql
        $tmp_db_dir = TSS_TEMP_DIR . '/import_db_' . time();
        @mkdir($tmp_db_dir,0755,true);
        tss_extract_zip_to($downloaded['db'], $tmp_db_dir);
        $sqlfile = $tmp_db_dir . '/database.sql';
        if (!file_exists($sqlfile)) throw new Exception('database.sql missing in downloaded DB zip.');
        tss_import_database_transactional($sqlfile);
    }

    // 7) update last-sync timestamp and manifest
    update_option('tss_last_sync', time());

    return 'Pull sync completed successfully.';
}