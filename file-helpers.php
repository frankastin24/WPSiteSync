<?php
// -------------------------
// FILE UTILITIES
// -------------------------
/**
 * Zip folder but only add files that exist in $allowedMap (array of relative paths => [mtime,md5])
 * If $allowedMap is empty -> add all files
 * $compressionMode: ZipArchive::CM_STORE or ZipArchive::CM_DEFLATE
 */
function tss_zip_folder_conditional($folder, $zipPath, $allowedMap = [], $compressionMode = ZipArchive::CM_DEFLATE) {
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $folder = rtrim($folder, '/');
    $len = strlen(dirname($folder)) + 1;

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $full = $f->getRealPath();
        $relative = substr($full, strlen(WP_CONTENT_DIR) + 1); // relative to wp-content
        // If allowedMap provided and file not present, skip
        if (!empty($allowedMap) && !isset($allowedMap[$relative])) continue;
        $localpath = substr($full, $len); // path inside zip
        $zip->addFile($full, $localpath);
        // set compression per-file when supported (PHP versions vary)
        if (defined('ZipArchive::CM_DEFLATE') && method_exists($zip,'setCompressionName')) {
            $zip->setCompressionName($localpath, $compressionMode);
        }
    }

    $zip->close();
}

/**
 * Extract zip to destination (overwrites)
 */
function tss_extract_zip_to($zipfile, $dest) {
    $z = new ZipArchive;
    if ($z->open($zipfile) === true) {
        // create dest if missing
        if (!file_exists($dest)) @mkdir($dest,0755,true);
        $z->extractTo($dest);
        $z->close();
        return true;
    }
    return false;
}

/**
 * Fetch remote file (http) and save locally under TSS_TEMP_DIR.
 * Accepts both full URLs (http/https) and content_url paths.
 * Returns local path or false.
 */
function tss_fetch_remote_file($url) {
    $url = trim($url);
    if (empty($url)) return false;

    $resp = wp_remote_get($url, ['timeout'=>300, 'stream'=>true, 'filename' => TSS_TEMP_DIR . '/' . basename($url)]);
    if (is_wp_error($resp)) return false;
    $code = wp_remote_retrieve_response_code($resp);
    if ($code != 200) return false;

    // If wp_remote_get with 'filename' saved file, it returns the body as empty but file created
    $local = TSS_TEMP_DIR . '/' . basename($url);
    if (!file_exists($local)) {
        // fallback: try to fetch file contents
        $body = wp_remote_retrieve_body($resp);
        if (empty($body)) return false;
        file_put_contents($local, $body);
    }
    return $local;
}


/**
 * Helper: extract a zip to a destination but before doing so, remove existing files that will be replaced.
 * (For simplicity we overwrite files by extraction; removing may be dangerous - backups are created earlier.)
 */


function tss_safe_extract_overwrite($zipfile, $dest) {
    return tss_extract_zip_to($zipfile,$dest);
}