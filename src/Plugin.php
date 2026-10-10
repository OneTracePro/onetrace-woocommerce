<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

/**
 * Wires the parts of the plugin into WordPress and WooCommerce.
 */
final class Plugin
{
    /** @var self|null */
    private static $instance;

    public static function instance(): self
    {
        return self::$instance ?? self::$instance = new self();
    }

    public function boot(): void
    {
        load_plugin_textdomain('onetrace-for-woocommerce', false, \dirname(plugin_basename(ONETRACE_WC_FILE)) . '/languages');

        if (get_option('onetrace_db_version') !== ONETRACE_WC_VERSION) {
            Queue::install();
        }

        add_action(Queue::HOOK, [Queue::class, 'run']);
        add_action('onetrace_flush_recurring', [Queue::class, 'run']);
        add_action('onetrace_daily_catalog', [Catalog::class, 'syncAll']);
        add_action('init', [self::class, 'schedule']);

        Customers::register();
        Orders::register();
        Cart::register();
        Catalog::register();
        Storefront::register();
        Search::register();

        if (is_admin()) {
            Admin::register();
        }

        if (\defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('onetrace', Cli::class);
        }
    }

    /**
     * A safety flush every five minutes and the daily catalog upload.
     */
    public static function schedule(): void
    {
        if (!\function_exists('as_has_scheduled_action') || !Settings::server()) {
            return;
        }

        if (!as_has_scheduled_action('onetrace_flush_recurring', null, Queue::GROUP)) {
            as_schedule_recurring_action(time() + 300, 300, 'onetrace_flush_recurring', [], Queue::GROUP);
        }

        if (Settings::enabled('catalog') && !as_has_scheduled_action('onetrace_daily_catalog', null, Queue::GROUP)) {
            as_schedule_recurring_action(time() + DAY_IN_SECONDS, DAY_IN_SECONDS, 'onetrace_daily_catalog', [], Queue::GROUP);
        }
    }

    public static function deactivate(): void
    {
        if (\function_exists('as_unschedule_all_actions')) {
            foreach ([Queue::HOOK, 'onetrace_flush_recurring', 'onetrace_daily_catalog', Catalog::SYNC_HOOK] as $hook) {
                as_unschedule_all_actions($hook, [], Queue::GROUP);
            }
        }
    }
}
