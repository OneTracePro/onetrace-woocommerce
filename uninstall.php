<?php
/**
 * Removes the plugin data: settings, the queue table and scheduled actions. Data already sent to OneTrace stays
 * in the project; delete it there.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

foreach (['onetrace_settings', 'onetrace_paused', 'onetrace_db_version', 'onetrace_catalog_synced', 'onetrace_loyalty_rewrite'] as $option) {
    delete_option($option);
}

foreach (['onetrace_email_settings', 'onetrace_project_uid'] as $onetrace_transient) {
    delete_transient($onetrace_transient);
}

$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'onetrace_queue'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

if (function_exists('as_unschedule_all_actions')) {
    foreach (['onetrace_flush', 'onetrace_flush_recurring', 'onetrace_daily_catalog', 'onetrace_sync_catalog', 'onetrace_loyalty_confirm', 'onetrace_order_email'] as $hook) {
        as_unschedule_all_actions($hook, [], 'onetrace');
    }
}
