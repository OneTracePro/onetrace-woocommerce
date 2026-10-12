<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;

/**
 * Loyalty points in the Cart and Checkout blocks: the cart of the Store API carries extensions.onetrace.loyalty
 * (texts are formatted here, in the store language), the script in the order summary applies and removes points
 * through extensionCartUpdate — the blocks recalculate the totals themselves.
 */
final class LoyaltyBlocks
{
    public const NAMESPACE = 'onetrace';

    public static function register(): void
    {
        // The plugin may boot after WooCommerce Blocks are loaded.
        if (did_action('woocommerce_blocks_loaded')) {
            self::storeApi();
        } else {
            add_action('woocommerce_blocks_loaded', [self::class, 'storeApi']);
        }
        add_action('wp_enqueue_scripts', [self::class, 'script']);
    }

    public static function storeApi(): void
    {
        if (\function_exists('woocommerce_store_api_register_endpoint_data')) {
            woocommerce_store_api_register_endpoint_data([
                'endpoint' => CartSchema::IDENTIFIER,
                'namespace' => self::NAMESPACE,
                'data_callback' => static function (): array {
                    return ['loyalty' => self::state()];
                },
                'schema_callback' => static function (): array {
                    return ['loyalty' => ['description' => 'OneTrace loyalty points of the cart', 'type' => ['object', 'null'], 'context' => ['view', 'edit'], 'readonly' => true]];
                },
                'schema_type' => ARRAY_A,
            ]);
        }

        if (\function_exists('woocommerce_store_api_register_update_callback')) {
            woocommerce_store_api_register_update_callback(['namespace' => self::NAMESPACE, 'callback' => [self::class, 'update']]);
        }
    }

    /**
     * @param array<string, mixed> $data mode (apply | remove), points
     */
    public static function update(array $data): void
    {
        try {
            if (($data['mode'] ?? '') === 'remove') {
                Loyalty::remove();
            } else {
                Loyalty::apply(absint($data['points'] ?? 0));
            }
        } catch (\Throwable $error) {
            throw new RouteException('onetrace_loyalty', esc_html($error->getMessage()), 400);
        }
    }

    /**
     * The block state: what to show and the texts for it; null — nothing to show.
     *
     * @return array<string, mixed>|null
     */
    public static function state(): ?array
    {
        if (WC()->cart === null || WC()->cart->is_empty()) {
            return null;
        }

        $title = __('Bonus points', 'onetrace-for-woocommerce');

        if (Loyalty::profile() === null) {
            return ['title' => $title, 'link' => ['url' => wc_get_page_permalink('myaccount'), 'text' => __('Sign in to use your bonus points', 'onetrace-for-woocommerce')]];
        }

        $offer = Loyalty::offer();

        if ($offer === null) {
            return null;
        }

        /* translators: %s: number of points */
        $earn = Loyalty::earn($offer) > 0 ? sprintf(__('+%s points for this order', 'onetrace-for-woocommerce'), number_format_i18n(Loyalty::earn($offer))) : null;

        if (empty($offer['member'])) {
            return ['title' => $title, 'link' => ['url' => LoyaltyAccount::url(), 'text' => __('Join the loyalty program to collect points', 'onetrace-for-woocommerce')]];
        }

        $hold = Loyalty::hold();

        if ($hold !== null) {
            return [
                'title' => $title,
                /* translators: 1: number of points, 2: discount */
                'applied' => sprintf(__('%1$s points applied: −%2$s', 'onetrace-for-woocommerce'), number_format_i18n($hold['points']), self::money($hold['discount'])),
                'remove' => __('Remove', 'onetrace-for-woocommerce'),
                'earn' => $earn,
            ];
        }

        $balance = (int) ($offer['balance'] ?? 0);
        $max = (int) ($offer['max_points'] ?? 0);

        return [
            'title' => $title,
            /* translators: 1: number of points, 2: their value in money */
            'balance' => sprintf(__('You have %1$s points (%2$s).', 'onetrace-for-woocommerce'), number_format_i18n($balance), self::money($balance * (float) ($offer['point_value'] ?? 1))),
            'max' => $max,
            /* translators: 1: number of points, 2: discount */
            'hint' => $max > 0 ? sprintf(__('Up to %1$s points (−%2$s) for this cart.', 'onetrace-for-woocommerce'), number_format_i18n($max), self::money((float) ($offer['max_discount'] ?? 0))) : null,
            'field' => __('Points to use', 'onetrace-for-woocommerce'),
            'apply' => __('Use points', 'onetrace-for-woocommerce'),
            'earn' => $earn,
        ];
    }

    /** A price as plain text: the blocks print the texts as they are, without HTML entities. */
    private static function money(float $amount): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price($amount)), ENT_QUOTES, 'UTF-8');
    }

    public static function script(): void
    {
        if ((!is_cart() && !is_checkout()) || !\function_exists('has_block') || (!has_block('woocommerce/cart') && !has_block('woocommerce/checkout'))) {
            return;
        }

        wp_enqueue_script('onetrace-loyalty-blocks', plugins_url('assets/js/loyalty-blocks.js', ONETRACE_WC_FILE), ['wp-plugins', 'wp-element', 'wc-blocks-checkout'], ONETRACE_WC_VERSION, true);
    }
}
