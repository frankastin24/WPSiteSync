<?php
/**
 * REST: create export package for caller
 * Supports incremental if 'incremental' param passed (1)
 */
function tss_rest_export(WP_REST_Request $req) {
    // security: secret + user
    if ($req->get_param('secret') !== TSS_SECRET) return ['ok'=>false,'error'=>'Secret mismatch'];
    $username = $req->get_param('username');
    $password = $req->get_param('password');
    $user = wp_authenticate($username, md5($password)); // remember callers send md5(pass)
    if (is_wp_error($user)) return ['ok'=>false,'error'=>'Invalid credentials'];

    $incremental = $req->get_param('incremental') ? 1 : 0;
    $compression = get_option('tss_compression', 'deflate');

    $export = tss_create_export_package([
        'compression' => $compression,
        'incremental' => $incremental,
    ]);

    return $export;
}

// -------------------------
// EXPORT PACKAGE CREATION
// -------------------------
/**
 * Create export package (zip files) and return URLs (content_url) to them.
 * Accepts options:
 *   compression: 'deflate'|'store'
 *   incremental: 0|1 (if 1, will create partial zips with files changed since last manifest)
 */
function tss_create_export_package($opts = []) {
    $compression = ($opts['compression'] ?? get_option('tss_compression','deflate')) === 'store' ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE;
    $incremental = !empty($opts['incremental']);

    if (!file_exists(TSS_TEMP_DIR)) @mkdir(TSS_TEMP_DIR,0755,true);

    $manifest = tss_build_manifest($incremental);

    $manifest_file = TSS_MANIFEST . '/manifest_' . time() . '.json';
    file_put_contents($manifest_file, json_encode($manifest));
    $manifest_url = content_url('tss-temp/manifests/' . basename($manifest_file));

    // DB export to SQL file
    $sqlfile = TSS_TEMP_DIR . '/database_' . time() . '.sql';
    tss_export_database($sqlfile);

    // zip db
    $db_zip = TSS_TEMP_DIR . '/' . basename($sqlfile) . '.zip';
    $zdb = new ZipArchive;
    $zdb->open($db_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zdb->addFile($sqlfile, 'database.sql');
    // attempt compression mode
    if (method_exists($zdb,'setCompressionIndex')) {
        // noop — keep default; PHP's per-file compression control differs across versions
    }
    $zdb->close();

    // Prepare file zips — only changed files if incremental
    $uploads_zip = TSS_TEMP_DIR . '/uploads_' . time() . '.zip';
    tss_zip_folder_conditional(WP_CONTENT_DIR . '/uploads', $uploads_zip, $manifest['files'], $compression);

    $plugins_zip = TSS_TEMP_DIR . '/plugins_' . time() . '.zip';
    tss_zip_folder_conditional(WP_CONTENT_DIR . '/plugins', $plugins_zip, $manifest['files'], $compression);

    $themes_zip = TSS_TEMP_DIR . '/themes_' . time() . '.zip';
    tss_zip_folder_conditional(WP_CONTENT_DIR . '/themes', $themes_zip, $manifest['files'], $compression);

    // Return URLs (publicly accessible under content_url)
    // Ensure directories are under WP_CONTENT_DIR . '/tss-temp' to be served by content_url
    // We create them in TSS_TEMP_DIR. To serve with content_url(), TSS_TEMP_DIR should be inside wp-content and web-accessible.
    $db_url = content_url('tss-temp/' . basename($db_zip));
    $uploads_url = content_url('tss-temp/' . basename($uploads_zip));
    $plugins_url = content_url('tss-temp/' . basename($plugins_zip));
    $themes_url = content_url('tss-temp/' . basename($themes_zip));
    $manifest_url = content_url('tss-temp/manifests/' . basename($manifest_file));

    return [
        'ok' => true,
        'db' => $db_url,
        'uploads' => $uploads_url,
        'plugins' => $plugins_url,
        'themes' => $themes_url,
        'manifest' => $manifest_url
    ];
}

/**
 * Build manifest: map of files to mtimes + md5
 * If incremental is enabled, include only files changed since last manifest.
 * Manifest structure:
 * {
 *   "generated": 123456789,
 *   "base": "/var/www/wordpress",
 *   "files": {
 *       "wp-content/uploads/2025/.../file.jpg": [mtime, md5],
 *       ...
 *   }
 * }
 */
function tss_build_manifest($incremental = false) {
    // scan folders we care about
    $roots = [
        'uploads' => WP_CONTENT_DIR . '/uploads',
        'plugins' => WP_CONTENT_DIR . '/plugins',
        'themes'  => WP_CONTENT_DIR . '/themes'
    ];

    $last_manifest_path = glob(TSS_MANIFEST . '/manifest_*.json');
    $last_time = 0;
    if ($last_manifest_path) {
        // choose newest
        usort($last_manifest_path, function($a,$b){ return filemtime($b)-filemtime($a); });
        $last = json_decode(file_get_contents($last_manifest_path[0]), true);
        $last_time = $last['generated'] ?? 0;
    }

    $out = [
        'generated' => time(),
        'base' => WP_CONTENT_DIR,
        'files' => []
    ];

    foreach ($roots as $k=>$root) {
        if (!is_dir($root)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $relative = substr($f->getRealPath(), strlen(WP_CONTENT_DIR) + 1);
            $mtime = $f->getMTime();
            if ($incremental && $last_time && $mtime <= $last_time) continue; // skip unchanged by time
            // for safety compute md5 for files up to a reasonable size; avoid huge files hashing if needed
            $md5 = md5_file($f->getRealPath());
            $out['files'][$relative] = [$mtime, $md5];
        }
    }

    return $out;
}