<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Client;
use OneTrace\Commerce\Messages;

/**
 * The library client and the message builder of this store.
 */
final class Connection
{
    /** @var array<string, Client> */
    private static $clients = [];

    /**
     * Client of the server part: the secret key only — server events carry consents, which the platform accepts
     * only with it. client('write') checks the write key of the storefront, client('search') — the product search.
     */
    public static function client(string $key = 'secret'): Client
    {
        if (!isset(self::$clients[$key])) {
            // search: the results page waits for the platform — a short timeout, then the usual WordPress search.
            $write = $key === 'write' || $key === 'search';
            $options = [
                $write ? 'write_key' : 'secret_key' => (string) Settings::get($write ? 'write_key' : 'secret_key') ?: null,
                'timeout' => $key === 'search' ? 3.0 : 10.0,
                'connect_timeout' => $key === 'search' ? 1.0 : 5.0,
                // The queue retries with long pauses; the client must not hold the request.
                'max_retries' => 0,
                'user_agent' => sprintf('onetrace-woocommerce/%s WooCommerce/%s', ONETRACE_WC_VERSION, \defined('WC_VERSION') ? WC_VERSION : '?'),
            ];

            /**
             * Options of the OneTrace client (see the onetrace-php library), e.g. a custom transport.
             *
             * @param array<string, mixed> $options
             */
            $options = apply_filters('onetrace_woocommerce_client_options', $options);
            self::$clients[$key] = new Client(Settings::url(), $options);
        }

        return self::$clients[$key];
    }

    public static function messages(): Messages
    {
        return new Messages('woocommerce', home_url('/'));
    }

    /** A new client after the settings change. */
    public static function reset(): void
    {
        self::$clients = [];
    }

    /** Value of the tracker cookie: links the request to the visitor's browser events. */
    public static function anonymousId(): ?string
    {
        $value = isset($_COOKIE['cdp_aid']) ? sanitize_text_field(wp_unslash((string) $_COOKIE['cdp_aid'])) : '';

        return $value !== '' && \strlen($value) <= 255 ? $value : null;
    }
}
