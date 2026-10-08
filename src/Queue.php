<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Commerce\Retry;

/**
 * Outgoing queue: messages and catalog changes wait in the table {prefix}onetrace_queue and are sent in batches by
 * Action Scheduler, so the store never waits for the platform and nothing is lost while it is unavailable.
 *
 * Kinds: "event" — a message for POST /batch; "product" — a product id to upload (read fresh when sent);
 * "delete" — a product id to remove from the catalog.
 */
final class Queue
{
    public const EVENT = 'event';

    public const PRODUCT = 'product';

    public const DELETE = 'delete';

    public const HOOK = 'onetrace_flush';

    public const GROUP = 'onetrace';

    /** Messages per request to the platform. */
    public const BATCH = 100;

    /** Products per request: building items reads products, so the batch is smaller. */
    public const PRODUCT_BATCH = 50;

    /** Error that stops sending until the settings are saved again (invalid key, missing permission). */
    public const PAUSED_OPTION = 'onetrace_paused';

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'onetrace_queue';
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta('CREATE TABLE ' . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            kind varchar(16) NOT NULL,
            item_key varchar(191) NOT NULL DEFAULT '',
            payload longtext NOT NULL,
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            available_at datetime NOT NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY kind_available (kind, available_at),
            KEY item_key (item_key)
        ) " . $wpdb->get_charset_collate() . ';');

        update_option('onetrace_db_version', ONETRACE_WC_VERSION, false);
    }

    /**
     * @param array<string, mixed>|null $message
     */
    public static function event(?array $message): void
    {
        if ($message !== null && Settings::server()) {
            self::push(self::EVENT, '', (string) wp_json_encode($message));
        }
    }

    /**
     * Product to upload; one waiting item per product.
     */
    public static function product(int $productId): void
    {
        if (Settings::server() && Settings::enabled('catalog')) {
            self::push(self::PRODUCT, 'p' . $productId, (string) $productId, true);
        }
    }

    /**
     * Product to delete from the catalog.
     */
    public static function delete(int $productId): void
    {
        if (Settings::server() && Settings::enabled('catalog')) {
            self::push(self::DELETE, 'p' . $productId, (string) $productId, true);
        }
    }

    private static function push(string $kind, string $key, string $payload, bool $unique = false): void
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');

        if ($unique) {
            // A newer change of the same product replaces the waiting one (upload ⇄ delete).
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE item_key = %s AND attempts = 0', $key));
        }

        $wpdb->insert(self::table(), ['kind' => $kind, 'item_key' => $key, 'payload' => $payload, 'available_at' => $now, 'created_at' => $now]);
        self::schedule();
    }

    /**
     * Sends soon in the background: one pending flush is enough.
     */
    public static function schedule(int $delay = 0): void
    {
        if (!\function_exists('as_schedule_single_action') || as_has_scheduled_action(self::HOOK, [], self::GROUP)) {
            return;
        }

        as_schedule_single_action(time() + $delay, self::HOOK, [], self::GROUP);
    }

    /**
     * Action Scheduler callback.
     */
    public static function run(): void
    {
        self::flush();
    }

    /**
     * Sends what is due. Called by Action Scheduler, WP-CLI (wp onetrace flush) and tests.
     *
     * @return array{sent: int, retried: int, dropped: int}
     */
    public static function flush(int $limit = 1000): array
    {
        $result = ['sent' => 0, 'retried' => 0, 'dropped' => 0];

        if (!Settings::server() || self::paused()) {
            return $result;
        }

        foreach ([self::EVENT, self::PRODUCT, self::DELETE] as $kind) {
            while ($limit > 0 && ($rows = self::due($kind, min($limit, $kind === self::PRODUCT ? self::PRODUCT_BATCH : self::BATCH))) !== []) {
                $limit -= \count($rows);
                $outcome = self::send($kind, $rows);

                foreach ($outcome as $key => $count) {
                    $result[$key] += $count;
                }

                if (self::paused()) {
                    return $result;
                }
            }
        }

        // Left for later: retries with a pause, or more than the limit.
        $next = self::nextAvailable();

        if ($next !== null) {
            self::schedule(max(0, $next - time()));
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    public static function stats(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results('SELECT kind, COUNT(*) AS waiting, SUM(attempts > 0) AS retrying FROM ' . self::table() . ' GROUP BY kind', ARRAY_A) ?: [];
        $stats = ['waiting' => 0, 'retrying' => 0];

        foreach ($rows as $row) {
            $stats['waiting'] += (int) $row['waiting'];
            $stats['retrying'] += (int) $row['retrying'];
        }

        return $stats;
    }

    /**
     * Sending stopped until the settings are saved (the platform rejected the key); a batch can set it mid-flush.
     *
     * @phpstan-impure
     */
    public static function paused(): bool
    {
        return \is_string(get_option(self::PAUSED_OPTION));
    }

    public static function lastError(): ?string
    {
        global $wpdb;

        $error = $wpdb->get_var('SELECT last_error FROM ' . self::table() . ' WHERE last_error IS NOT NULL ORDER BY id DESC LIMIT 1');

        return \is_string($error) ? $error : null;
    }

    /**
     * @return list<array{id: string, kind: string, payload: string, attempts: string}>
     */
    private static function due(string $kind, int $limit): array
    {
        global $wpdb;

        /** @var list<array{id: string, kind: string, payload: string, attempts: string}> */
        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, kind, payload, attempts FROM ' . self::table() . ' WHERE kind = %s AND available_at <= %s ORDER BY id LIMIT %d',
            $kind,
            gmdate('Y-m-d H:i:s'),
            $limit
        ), ARRAY_A) ?: [];
    }

    /**
     * @param list<array{id: string, kind: string, payload: string, attempts: string}> $rows
     *
     * @return array{sent: int, retried: int, dropped: int}
     */
    private static function send(string $kind, array $rows): array
    {
        try {
            if ($kind === self::EVENT) {
                Connection::client()->events()->batch(array_map(static function (array $row): array {
                    return (array) json_decode($row['payload'], true);
                }, $rows));
            } elseif ($kind === self::PRODUCT) {
                [$items, $categories, $missing] = Catalog::items(array_map('intval', array_column($rows, 'payload')));

                if ($items !== []) {
                    Connection::client()->products()->upsert($items, $categories);
                }

                if ($missing !== []) {
                    Connection::client()->products()->delete($missing);
                }
            } else {
                Connection::client()->products()->delete(array_column($rows, 'payload'));
            }
        } catch (\Throwable $error) {
            return self::failed($kind, $rows, $error);
        }

        self::remove(array_column($rows, 'id'));

        return ['sent' => \count($rows), 'retried' => 0, 'dropped' => 0];
    }

    /**
     * @param list<array{id: string, kind: string, payload: string, attempts: string}> $rows
     *
     * @return array{sent: int, retried: int, dropped: int}
     */
    private static function failed(string $kind, array $rows, \Throwable $error): array
    {
        global $wpdb;

        // Errors outside the platform's answers (database, a bug while building items) are retried, not dropped.
        $decision = $error instanceof \OneTrace\Exception\OneTraceException ? Retry::decide($error) : Retry::RETRY;
        $message = mb_substr(\get_class($error) . ': ' . $error->getMessage(), 0, 1000);

        if ($decision === Retry::SETTINGS) {
            update_option(self::PAUSED_OPTION, $message, false);
            self::log($message);

            return ['sent' => 0, 'retried' => 0, 'dropped' => 0];
        }

        // Invalid data in a batch: send the messages one by one to drop only the broken ones.
        if ($decision === Retry::DROP && \count($rows) > 1) {
            $result = ['sent' => 0, 'retried' => 0, 'dropped' => 0];

            foreach ($rows as $row) {
                foreach (self::send($kind, [$row]) as $key => $count) {
                    $result[$key] += $count;
                }
            }

            return $result;
        }

        if ($decision === Retry::DROP) {
            self::remove(array_column($rows, 'id'));
            self::log(sprintf('Dropped %s %s: %s', $kind, $rows[0]['payload'], $message));

            return ['sent' => 0, 'retried' => 0, 'dropped' => \count($rows)];
        }

        $retried = 0;
        $dropped = [];

        foreach ($rows as $row) {
            $attempt = (int) $row['attempts'] + 1;
            $delay = Retry::delay($attempt);

            if ($delay === null) {
                $dropped[] = $row['id'];

                continue;
            }

            $wpdb->update(self::table(), ['attempts' => $attempt, 'available_at' => gmdate('Y-m-d H:i:s', time() + $delay), 'last_error' => $message], ['id' => $row['id']]);
            ++$retried;
        }

        if ($dropped !== []) {
            self::remove($dropped);
            self::log(sprintf('Dropped %d %s item(s) after all retries: %s', \count($dropped), $kind, $message));
        }

        return ['sent' => 0, 'retried' => $retried, 'dropped' => \count($dropped)];
    }

    /**
     * @param list<string> $ids
     */
    private static function remove(array $ids): void
    {
        global $wpdb;

        if ($ids !== []) {
            $wpdb->query('DELETE FROM ' . self::table() . ' WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
        }
    }

    private static function nextAvailable(): ?int
    {
        global $wpdb;

        $next = $wpdb->get_var('SELECT MIN(available_at) FROM ' . self::table());

        return \is_string($next) ? (int) strtotime($next . ' UTC') : null;
    }

    private static function log(string $message): void
    {
        // A broken log (unwritable directory, filesystem over FTP) must not stop the queue.
        try {
            if (\function_exists('wc_get_logger')) {
                wc_get_logger()->warning($message, ['source' => 'onetrace']);
            }
        } catch (\Throwable $error) {
            error_log('OneTrace: ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }
}
