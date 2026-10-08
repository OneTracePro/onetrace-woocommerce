<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

/**
 * WP-CLI: wp onetrace status | test | flush | sync-catalog.
 */
final class Cli
{
    /**
     * Shows the queue and the last error.
     *
     * @param list<string> $args
     */
    public function status(array $args): void
    {
        $stats = Queue::stats();
        \WP_CLI::line(sprintf('Queue: %d waiting, %d after an error.', $stats['waiting'], $stats['retrying']));

        if (($error = Queue::lastError()) !== null) {
            \WP_CLI::line('Last error: ' . $error);
        }

        if (\is_string($paused = get_option(Queue::PAUSED_OPTION))) {
            \WP_CLI::warning('Sending is paused: ' . $paused);
        }
    }

    /**
     * Checks both keys.
     *
     * @param list<string> $args
     */
    public function test(array $args): void
    {
        foreach (['write' => Settings::storefront(), 'secret' => Settings::server()] as $key => $configured) {
            if (!$configured) {
                \WP_CLI::warning(sprintf('The %s key is not set.', $key));

                continue;
            }

            try {
                $answer = Connection::client($key)->checkKey($key);
                \WP_CLI::success(sprintf('%s key: project "%s", permissions: %s.', ucfirst($key), (string) ($answer['project']['name'] ?? ''), implode(', ', $answer['scopes']) ?: '—'));
            } catch (\Throwable $error) {
                \WP_CLI::error(sprintf('%s key: %s', ucfirst($key), $error->getMessage()), false);
            }
        }
    }

    /**
     * Sends everything that is due now.
     *
     * @param list<string> $args
     */
    public function flush(array $args): void
    {
        delete_option(Queue::PAUSED_OPTION);
        $result = Queue::flush(PHP_INT_MAX);
        \WP_CLI::success(sprintf('Sent %d, to retry %d, dropped %d.', $result['sent'], $result['retried'], $result['dropped']));
    }

    /**
     * Uploads the whole catalog now (without Action Scheduler).
     *
     * @subcommand sync-catalog
     *
     * @param list<string> $args
     */
    public function syncCatalog(array $args): void
    {
        $page = 1;

        do {
            $ids = (array) wc_get_products(['status' => 'publish', 'limit' => Catalog::PAGE, 'page' => $page++, 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids']);

            foreach ($ids as $id) {
                Queue::product((int) $id);
            }

            $result = Queue::flush(PHP_INT_MAX);
            \WP_CLI::line(sprintf('Page %d: %d products, sent %d.', $page - 1, \count($ids), $result['sent']));
        } while (\count($ids) === Catalog::PAGE);

        update_option('onetrace_catalog_synced', time(), false);
        \WP_CLI::success('The catalog is uploaded.');
    }
}
