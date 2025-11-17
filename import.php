<?php 
/**
 * REST: import package from URLs provided
 * Body should contain secret, username (MD5), password (MD5), and URLs to db/uploads/plugins/themes (content_url paths)
 */
function tss_rest_import(WP_REST_Request $req) {
    if ($req->get_param('secret') !== TSS_SECRET) return ['ok'=>false,'error'=>'Secret mismatch'];
    $username = $req->get_param('username');
    $password = $req->get_param('password');
    $user = wp_authenticate($username, md5($password));
    if (is_wp_error($user)) return ['ok'=>false,'error'=>'Invalid credentials'];

    // backup current site before import
    tss_backup_current_site();

    $db_url = $req->get_param('db');
    $uploads_url = $req->get_param('uploads');
    $plugins_url = $req->get_param('plugins');
    $themes_url = $req->get_param('themes');
    $manifest_url = $req->get_param('manifest');

    $downloaded = [];
    foreach (['db'=>$db_url,'uploads'=>$uploads_url,'plugins'=>$plugins_url,'themes'=>$themes_url,'manifest'=>$manifest_url] as $k=>$u) {
        if (!empty($u)) {
            $downloaded[$k] = tss_fetch_remote_file($u);
            if (!$downloaded[$k]) return ['ok'=>false,'error'=>"Failed to download $k"];
        }
    }

    // apply files
    if (!empty($downloaded['uploads'])) tss_extract_zip_to($downloaded['uploads'], WP_CONTENT_DIR . '/uploads');
    if (!empty($downloaded['plugins'])) tss_extract_zip_to($downloaded['plugins'], WP_CONTENT_DIR . '/plugins');
    if (!empty($downloaded['themes']))  tss_extract_zip_to($downloaded['themes'], WP_CONTENT_DIR . '/themes');

    // import db if present
    if (!empty($downloaded['db'])) {
        $tmp_db_dir = TSS_TEMP_DIR . '/import_db_' . time();
        @mkdir($tmp_db_dir,0755,true);
        tss_extract_zip_to($downloaded['db'], $tmp_db_dir);
        $sqlfile = $tmp_db_dir . '/database.sql';
        if (!file_exists($sqlfile)) return ['ok'=>false,'error'=>'database.sql missing in downloaded DB zip'];
        try {
            tss_import_database_transactional($sqlfile);
        } catch (Exception $e) {
            return ['ok'=>false,'error'=>'DB import failed: ' . $e->getMessage()];
        }
    }

    // store manifest if present
    if (!empty($downloaded['manifest'])) {
        copy($downloaded['manifest'], TSS_MANIFEST . '/last_manifest.json');
    }

    return ['ok'=>true];
}