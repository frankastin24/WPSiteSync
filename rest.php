<?php
// -------------------------
// EXPORT & IMPORT REST ENDPOINTS (for both sides)
// -------------------------
add_action('rest_api_init', function() {
    register_rest_route('tss/v1', '/export', [
        'methods' => 'POST',
        'callback' => 'tss_rest_export',
        'permission_callback' => '__return_true'
    ]);
    register_rest_route('tss/v1', '/import', [
        'methods' => 'POST',
        'callback' => 'tss_rest_import',
        'permission_callback' => '__return_true'
    ]);
});