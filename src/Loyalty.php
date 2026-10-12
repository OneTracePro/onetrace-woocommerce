<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Exception\ApiException;
use OneTrace\Identity;

/**
 * Loyalty points of the OneTrace program at checkout: the offer for the cart (balance, how many points the cart can
 * take, points for the order), a hold of the chosen points for 30 minutes, the discount as a negative cart fee and the
 * confirmation with the order number when the order is placed. Only signed-in customers redeem points: the platform
 * finds them by user_id, like the order events. Refunds and cancellations return the points on the platform side
 * (order_refunded / order_cancelled events).
 */
final class Loyalty
{
    /** WooCommerce session: the hold of this cart. */
    private const SESSION_KEY = 'onetrace_loyalty';

    /** The offer for the same customer and cart amount is reused for a minute. */
    private const OFFER_TTL = 60;

    /** Order meta: the redemption id and the redeemed points. */
    public const META_REDEMPTION = '_onetrace_loyalty_redemption';

    public const META_POINTS = '_onetrace_loyalty_points';

    /** Confirmation retries of an order whose confirmation failed at checkout (the hold lives 30 minutes). */
    public const CONFIRM_HOOK = 'onetrace_loyalty_confirm';

    private const CONFIRM_ATTEMPTS = 5;

    /** @var bool the hold was already refreshed in this request */
    private static $refreshed = false;

    public static function register(): void
    {
        if (!self::enabled()) {
            return;
        }

        add_action('woocommerce_cart_calculate_fees', [self::class, 'fee'], 20);
        add_action('woocommerce_checkout_create_order', [self::class, 'attach'], 20);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [self::class, 'attach'], 20);
        add_action('woocommerce_checkout_order_processed', [self::class, 'classicPlaced'], 30, 3);
        add_action('woocommerce_store_api_checkout_order_processed', [self::class, 'placed'], 30);
        add_action(self::CONFIRM_HOOK, [self::class, 'retry'], 10, 2);
        add_action('wp_logout', [self::class, 'forget']);

        LoyaltyCart::register();
        LoyaltyBlocks::register();
        LoyaltyAccount::register();
    }

    public static function enabled(): bool
    {
        return Settings::enabled('loyalty') && Settings::server();
    }

    /**
     * The profile of the signed-in customer; null — a guest (points are not redeemed).
     *
     * @return array{type: string, value: string}|null
     */
    public static function profile(): ?array
    {
        $userId = get_current_user_id();

        return $userId > 0 ? Identity::userId((string) $userId) : null;
    }

    /**
     * Amount the points may pay: goods after coupons with taxes, without shipping and other fees.
     */
    public static function cartAmount(): float
    {
        if (WC()->cart === null) {
            return 0.0;
        }

        $cart = WC()->cart;

        return max(0.0, round((float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax() - (float) $cart->get_discount_total() - (float) $cart->get_discount_tax(), 2));
    }

    /**
     * Offer for the current cart: member, balance, max_points, max_discount, earn_points, point_value, currency, tier;
     * null — a guest, an empty cart or the platform did not answer (the block is not shown).
     *
     * @return array<string, mixed>|null
     */
    public static function offer(): ?array
    {
        $profile = self::profile();
        $amount = self::cartAmount();

        if ($profile === null || $amount <= 0 || WC()->session === null) {
            return null;
        }

        $key = self::offerKey($profile['value'], $amount);
        $cached = get_transient($key);

        if (\is_array($cached)) {
            return $cached;
        }

        try {
            $offer = Connection::client()->loyalty()->calculate($profile, $amount);
        } catch (\Throwable $error) {
            return null;
        }

        set_transient($key, $offer, self::OFFER_TTL);

        return $offer;
    }

    /**
     * The hold of this cart: id, points, discount, amount (cart amount at the hold), user, held_until.
     *
     * @return array{id: string, points: int, discount: float, amount: float, user: int, held_until: int}|null
     */
    public static function hold(): ?array
    {
        $hold = WC()->session !== null ? WC()->session->get(self::SESSION_KEY) : null;

        if (!\is_array($hold) || (int) ($hold['user'] ?? 0) !== get_current_user_id() || !isset($hold['id'])) {
            return null;
        }

        return [
            'id' => (string) $hold['id'],
            'points' => (int) ($hold['points'] ?? 0),
            'discount' => (float) ($hold['discount'] ?? 0),
            'amount' => (float) ($hold['amount'] ?? 0),
            'user' => (int) $hold['user'],
            'held_until' => (int) ($hold['held_until'] ?? 0),
        ];
    }

    /**
     * Holds the points for the cart (a new hold of the same cart replaces the previous one on the platform).
     *
     * @throws \Throwable the platform refused: more than the balance or the allowed share of the cart
     */
    public static function apply(int $points): void
    {
        $profile = self::profile();

        if ($profile === null || $points <= 0 || WC()->session === null) {
            throw new \InvalidArgumentException(esc_html__('Sign in to use your points.', 'onetrace-for-woocommerce'));
        }

        $amount = self::cartAmount();
        $redemption = Connection::client()->loyalty()->hold($profile, $points, self::cartId(), $amount);
        delete_transient(self::offerKey($profile['value'], $amount));
        WC()->session->set(self::SESSION_KEY, [
            'id' => (string) $redemption['id'],
            'points' => (int) $redemption['points'],
            'discount' => (float) $redemption['discount'],
            'amount' => $amount,
            'user' => get_current_user_id(),
            'held_until' => (int) strtotime((string) $redemption['held_until']),
        ]);
    }

    /**
     * Removes the points from the cart and returns them to the balance.
     */
    public static function remove(): void
    {
        $hold = self::hold();
        self::forget();
        $profile = self::profile();

        if ($profile !== null) {
            delete_transient(self::offerKey($profile['value'], self::cartAmount()));
        }

        if ($hold !== null) {
            try {
                Connection::client()->loyalty()->cancel($hold['id']);
            } catch (\Throwable $error) {
                // The hold expires by itself in 30 minutes.
            }
        }
    }

    public static function forget(): void
    {
        if (WC()->session !== null) {
            WC()->session->set(self::SESSION_KEY, null);
        }
    }

    /**
     * The discount of the held points. When the cart changed or the hold is about to expire, the points are held
     * again (at most the new allowed share); if the platform refuses, the discount is removed.
     */
    public static function fee(\WC_Cart $cart): void
    {
        $hold = self::hold();

        if ($hold === null) {
            return;
        }

        $amount = self::cartAmount();
        $stale = abs($amount - $hold['amount']) >= 0.01 || $hold['held_until'] - time() < 300;

        if ($stale && !self::$refreshed) {
            self::$refreshed = true;
            $hold = self::refresh($hold);

            if ($hold === null) {
                return;
            }
        }

        $discount = min($hold['discount'], $amount);

        if ($discount > 0) {
            $cart->add_fee(self::label($hold['points']), -$discount, false);
        }
    }

    public static function label(int $points): string
    {
        /* translators: %s: number of points */
        return sprintf(_n('Bonus points (%s)', 'Bonus points (%s)', $points, 'onetrace-for-woocommerce'), number_format_i18n($points));
    }

    /**
     * Saves the hold with the order: the order total already has the discount.
     */
    public static function attach(\WC_Order $order): void
    {
        $hold = self::hold();

        if ($hold !== null) {
            $order->update_meta_data(self::META_REDEMPTION, $hold['id']);
            $order->update_meta_data(self::META_POINTS, (string) $hold['points']);
        }
    }

    /**
     * @param int|string $orderId
     * @param array<string, mixed> $data
     * @param \WC_Order|null $order
     */
    public static function classicPlaced($orderId, $data = [], $order = null): void
    {
        $order = $order instanceof \WC_Order ? $order : wc_get_order((int) $orderId);

        if ($order instanceof \WC_Order) {
            self::placed($order);
        }
    }

    /**
     * Confirms the redemption with the order number; on an error — again in a minute (the hold lives 30 minutes).
     */
    public static function placed(\WC_Order $order): void
    {
        $id = (string) $order->get_meta(self::META_REDEMPTION);

        if ($id === '') {
            return;
        }

        self::forget();

        if (!self::confirm($order, $id)) {
            self::schedule($order->get_id(), 1);
        }
    }

    /**
     * @param int|string $orderId
     * @param int|string $attempt
     */
    public static function retry($orderId, $attempt): void
    {
        $order = wc_get_order((int) $orderId);
        $id = $order instanceof \WC_Order ? (string) $order->get_meta(self::META_REDEMPTION) : '';

        if ($id !== '' && !self::confirm($order, $id) && (int) $attempt < self::CONFIRM_ATTEMPTS) {
            self::schedule((int) $orderId, (int) $attempt + 1);
        }
    }

    private static function confirm(\WC_Order $order, string $id): bool
    {
        try {
            Connection::client()->loyalty()->confirm($id, (string) $order->get_order_number());
            $order->update_meta_data('_onetrace_loyalty_confirmed', (string) time());
            $order->save_meta_data();

            return true;
        } catch (\Throwable $error) {
            // 4xx (the hold expired or was confirmed by another order) — retrying will not help.
            return $error instanceof ApiException && $error->getCode() < 500 && $error->getCode() !== 429;
        }
    }

    private static function schedule(int $orderId, int $attempt): void
    {
        if (\function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + 60 * $attempt, self::CONFIRM_HOOK, [$orderId, $attempt], Queue::GROUP);
        }
    }

    /**
     * @param array{id: string, points: int, discount: float, amount: float, user: int, held_until: int} $hold
     * @return array{id: string, points: int, discount: float, amount: float, user: int, held_until: int}|null
     */
    private static function refresh(array $hold): ?array
    {
        $offer = self::offer();
        $points = min($hold['points'], (int) ($offer['max_points'] ?? 0));

        try {
            if ($points <= 0) {
                throw new \RuntimeException('nothing to hold');
            }

            self::apply($points);
        } catch (\Throwable $error) {
            self::remove();

            return null;
        }

        return self::hold();
    }

    /**
     * Points for the order: the offer counts them for the cart paid with the most points; with fewer points held
     * the paid amount is larger — the points grow in proportion.
     *
     * @param array<string, mixed> $offer
     */
    public static function earn(array $offer): int
    {
        $earn = (int) ($offer['earn_points'] ?? 0);
        $hold = self::hold();
        $amount = self::cartAmount();
        $paidAtMax = $amount - (float) ($offer['max_discount'] ?? 0);

        if ($hold === null || $earn <= 0 || $paidAtMax <= 0) {
            return $earn;
        }

        return (int) floor($earn * ($amount - min($hold['discount'], $amount)) / $paidAtMax);
    }

    private static function offerKey(string $user, float $amount): string
    {
        return 'onetrace_offer_' . md5($user . '|' . $amount);
    }

    /** One hold per cart: the WooCommerce session of the customer. */
    private static function cartId(): string
    {
        return 'wc:' . (WC()->session !== null ? (string) WC()->session->get_customer_id() : (string) get_current_user_id());
    }
}
