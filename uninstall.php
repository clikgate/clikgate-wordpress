<?php
/**
 * Deleting the plugin removes everything it stored: the encrypted plugin key, cached settings, queue, log,
 * device sign-in transients and the click IDs kept with records. Revoke the key in Clikgate as well
 * (Settings › API keys) or delete the WordPress connection there.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

foreach (['clikgate_connection', 'clikgate_remote', 'clikgate_options', 'clikgate_queue', 'clikgate_sent', 'clikgate_log', 'clikgate_app_banners', 'clikgate_wc_settings'] as $clikgate_option) {
    delete_option($clikgate_option);
}
wp_clear_scheduled_hook('clikgate_cron');
wp_clear_scheduled_hook('clikgate_flush');

global $wpdb;
// Transients: clikgate_device_{user}, clikgate_click_{hash}, clikgate_flush_lock
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on uninstall
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like('_transient_clikgate_') . '%',
    $wpdb->esc_like('_transient_timeout_clikgate_') . '%'
));
