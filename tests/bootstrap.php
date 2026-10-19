<?php

/**
 * Integration tests run inside the WordPress container of tests/env with WooCommerce and this plugin active
 * (tests/env/run.sh). Requests to the platform go to a recording transport.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('ABSPATH')) {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
    require getenv('WP_PATH') ?: '/var/www/html/wp-load.php';
}

// Order emails of WooCommerce are not sent from the test container.
add_filter('pre_wp_mail', '__return_false');

if (!class_exists('WooCommerce') || !class_exists(\OneTrace\WooCommerce\Plugin::class)) {
    fwrite(STDERR, "WooCommerce and the plugin must be active.\n");
    exit(1);
}

// A queue runner of the web server would send the queue in the middle of a test (tests/env/no-background-queue.php).
if (!(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) || apply_filters('action_scheduler_allow_async_request_runner', true)) {
    fwrite(STDERR, "WP-Cron and the async request runner of Action Scheduler must be off: tests/env/no-background-queue.php.\n");
    exit(1);
}
