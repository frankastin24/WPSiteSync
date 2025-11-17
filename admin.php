<?php

// -------------------------
// ADMIN UI
// -------------------------



function tss_admin_page() {
    if (!current_user_can('manage_options')) return;

    // load saved options (keeps ephemeral choices out of DB; adjust if you want persistent settings)
    $saved_compression = get_option('tss_compression', 'deflate'); // 'deflate' or 'store'
    $saved_incremental  = get_option('tss_incremental', '1');

    $message = '';

    if (isset($_POST['tss_action'])) {
        // nonce check could be added in production
        $action = sanitize_text_field($_POST['tss_action']);
        $direction = sanitize_text_field($_POST['direction']); // 'pull' or 'push'
        $target_url = esc_url_raw($_POST['target_url']);
        $target_user = sanitize_text_field($_POST['target_username']);
        $target_pass = sanitize_text_field($_POST['target_password']);
        $compression = in_array($_POST['compression'] ?? '', ['store','deflate']) ? $_POST['compression'] : 'deflate';
        $incremental = !empty($_POST['incremental']) ? 1 : 0;
        update_option('tss_compression', $compression);
        update_option('tss_incremental', $incremental);

        // store for use
        $GLOBALS['tss_admin_target'] = [
            'url' => $target_url,
            'username' => $target_user,
            'password' => $target_pass,
            'compression' => $compression,
            'incremental' => $incremental,
        ];

        try {
            if ($direction === 'pull') {
                // live pulls from production (target_url is production)
                $message = tss_perform_pull($GLOBALS['tss_admin_target']);
            } else {
                // push: live (this site) pushes to target (target_url)
                $message = tss_perform_push($GLOBALS['tss_admin_target']);
            }
        } catch (Exception $e) {
            $message = "Exception: " . $e->getMessage();
        }
    }

    ?>

    <div class="wrap">
        <h1>Two-Site Sync</h1>

        <?php if (!empty($message)): ?>
            <div class="notice notice-success"><p><strong>Result:</strong><br><?php echo esc_html($message); ?></p></div>
        <?php endif; ?>

        <form method="post">
            <p><strong>Target Site (the site you want to contact)</strong></p>

            <label>Target Site URL</label><br>
            <input type="text" name="target_url" value="" style="width:420px" required><br><br>

            <label>Target Admin Username</label><br>
            <input type="text" name="target_username" required><br><br>

            <label>Target Admin Password</label><br>
            <input type="password" name="target_password" required><br><br>

            <label>Sync Direction</label><br>
            <select name="direction">
                <option value="pull">Pull (this site pulls FROM target)</option>
                <option value="push">Push (this site exports and asks target to import)</option>
            </select><br><br>

            <label>Incremental file sync?</label>
            <input type="checkbox" name="incremental" value="1" <?php checked(get_option('tss_incremental', '1'), '1'); ?> /> Only changed files (since last sync manifest).<br><br>

            <label>Compression</label><br>
            <select name="compression">
                <option value="deflate" <?php selected($saved_compression,'deflate'); ?>>Deflate (compressed)</option>
                <option value="store" <?php selected($saved_compression,'store'); ?>>Store (no compression)</option>
            </select><br><br>

            <p><em>Note:</em> This will create backups on the current site before overwriting anything. Exports and imports are done with pure PHP (no shell).</p>

            <input type="submit" name="tss_action" class="button button-primary" value="Run Sync">
        </form>
    </div>

    <?php
}