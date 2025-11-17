<?php
/**
 * Plugin Name: WP Site Sync
 * Description: Sync production <-> live with pure-PHP DB export/import, incremental file sync, backups, compression control, and push/pull directions.
 * Version: 4.0
 * Author: Robot
 */

/**
 * WARNING
 * This plugin can overwrite databases and files. Use with extreme care and always test on staging first.
 */

// -------------------------
// CONFIG
// -------------------------
defined('ABSPATH') or exit;

if (!defined('TSS_SECRET')) define('TSS_SECRET', 'CHANGE_THIS_SECRET');

if (!defined('TSS_TEMP_DIR')) define('TSS_TEMP_DIR', WP_CONTENT_DIR . '/tss-temp');
if (!defined('TSS_BACKUP_DIR')) define('TSS_BACKUP_DIR', TSS_TEMP_DIR . '/backups');
if (!defined('TSS_MANIFEST')) define('TSS_MANIFEST', TSS_TEMP_DIR . '/manifests');

register_activation_hook(__FILE__, function() {
    foreach ([TSS_TEMP_DIR, TSS_BACKUP_DIR, TSS_MANIFEST] as $d) {
        if (!file_exists($d)) @mkdir($d, 0755, true);
    }
});

require './admin.php';
require './backup.php';
require './db.php';
require './export.php';
require './file-helpers.php';
require './import.php';
require './pull.php';
require './push.php';
require './rest.php';




