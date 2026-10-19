<?php

/**
 * Must-use plugin of the test site (copied by run.sh): Action Scheduler runs nothing in the background.
 *
 * The tests own the queue and send it with Queue::flush() to the recording transport. A background runner in the
 * web server (WP-Cron, or the async request chain of admin-ajax that WooCommerce's pending actions keep going)
 * ran onetrace_flush during the run: it sent the waiting messages to the real https://cdp.example.com, could not
 * resolve it and left them for a retry minutes later, so a test randomly found no event or product to check.
 */
if (!defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', true);
}

add_filter('action_scheduler_allow_async_request_runner', '__return_false');
