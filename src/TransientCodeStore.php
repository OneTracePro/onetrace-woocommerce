<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\StoreMcp\CodeStore;

/**
 * Confirmation codes of the store MCP server in WordPress transients (object cache when there is one).
 */
final class TransientCodeStore implements CodeStore
{
    public function get(string $key): ?array
    {
        $value = get_transient($key);

        return \is_array($value) ? $value : null;
    }

    public function put(string $key, array $value, int $ttl): void
    {
        set_transient($key, $value, $ttl);
    }

    public function delete(string $key): void
    {
        delete_transient($key);
    }
}
