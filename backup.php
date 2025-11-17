<?php 

// -------------------------
// BACKUP
// -------------------------
/**
 * Backup current site's DB and files (uploads, plugins, themes) to TSS_BACKUP_DIR with timestamped name.
 */
function tss_backup_current_site() {
    if (!file_exists(TSS_BACKUP_DIR)) @mkdir(TSS_BACKUP_DIR,0755,true);
    $ts = time();
    $label = 'backup_' . $ts;
    $dir = TSS_BACKUP_DIR . '/' . $label;
    @mkdir($dir,0755,true);

    // 1) DB
    $sqlfile = $dir . '/database.sql';
    tss_export_database($sqlfile);

    // zip DB
    $zipdb = $dir . '/database.zip';
    $z = new ZipArchive;
    $z->open($zipdb, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFile($sqlfile, 'database.sql');
    $z->close();

    // 2) Files: uploads, plugins, themes
    $folders = [
        'uploads' => WP_CONTENT_DIR . '/uploads',
        'plugins' => WP_CONTENT_DIR . '/plugins',
        'themes'  => WP_CONTENT_DIR . '/themes'
    ];
    foreach ($folders as $name=>$path) {
        $outzip = $dir . '/' . $name . '.zip';
        tss_zip_folder_conditional($path, $outzip, [], ZipArchive::CM_DEFLATE);
    }

    // optional: log backup path
    file_put_contents(TSS_BACKUP_DIR . '/last_backup.txt', $dir . PHP_EOL);
    return $dir;
}