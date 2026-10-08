<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

/**
 * Plugin settings: the option "onetrace_settings", the keys may also come from wp-config.php constants
 * ONETRACE_URL, ONETRACE_WRITE_KEY and ONETRACE_SECRET_KEY (then they are not stored in the database).
 */
final class Settings
{
    public const OPTION = 'onetrace_settings';

    /** @var array<string, mixed> */
    public const DEFAULTS = [
        'url' => '',
        'write_key' => '',
        'secret_key' => '',
        'tracker' => 'yes',
        'wait_for_consent' => 'yes',
        'orders' => 'yes',
        'cart' => 'yes',
        'catalog' => 'yes',
        'consent_checkout' => 'yes',
        'consent_registration' => 'yes',
        'consent_account' => 'yes',
        'consent_label' => '',
        'consent_topic' => '',
        'widget_product' => '',
        'widget_cart' => '',
        'push' => 'no',
    ];

    /**
     * @return mixed
     */
    public static function get(string $key)
    {
        $constant = ['url' => 'ONETRACE_URL', 'write_key' => 'ONETRACE_WRITE_KEY', 'secret_key' => 'ONETRACE_SECRET_KEY'][$key] ?? null;

        if ($constant !== null && \defined($constant) && \is_string(\constant($constant)) && \constant($constant) !== '') {
            return \constant($constant);
        }

        $settings = get_option(self::OPTION, []);

        return \is_array($settings) && \array_key_exists($key, $settings) ? $settings[$key] : (self::DEFAULTS[$key] ?? null);
    }

    public static function enabled(string $key): bool
    {
        return self::get($key) === 'yes';
    }

    public static function fromConstant(string $key): bool
    {
        $constant = ['url' => 'ONETRACE_URL', 'write_key' => 'ONETRACE_WRITE_KEY', 'secret_key' => 'ONETRACE_SECRET_KEY'][$key] ?? null;

        return $constant !== null && \defined($constant);
    }

    /** Platform address without the trailing slash, e.g. https://cdp.onetrace.pro. */
    public static function url(): string
    {
        return rtrim(trim((string) self::get('url')), '/');
    }

    /** The server part works: the address and the secret key are set. */
    public static function server(): bool
    {
        return self::url() !== '' && (string) self::get('secret_key') !== '';
    }

    /** The storefront part works: the address and the write key are set. */
    public static function storefront(): bool
    {
        return self::url() !== '' && (string) self::get('write_key') !== '';
    }

    public static function consentLabel(): string
    {
        $label = trim((string) self::get('consent_label'));

        return $label !== '' ? $label : __('Send me news and special offers by email', 'onetrace-woocommerce');
    }

    /** Subscription topic of the consent checkboxes; null — the whole email channel. */
    public static function consentTopic(): ?string
    {
        $topic = trim((string) self::get('consent_topic'));

        return preg_match('/^[a-z0-9_-]{1,64}$/', $topic) === 1 ? $topic : null;
    }
}
