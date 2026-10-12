<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

/**
 * Loyalty points in the classic cart and checkout: a row of the totals table with the balance, the field for the
 * points and the points for the order; applying and removing — AJAX, then WooCommerce recalculates the totals.
 */
final class LoyaltyCart
{
    private const ACTION = 'onetrace_loyalty';

    public static function register(): void
    {
        add_action('woocommerce_cart_totals_before_order_total', [self::class, 'row']);
        add_action('woocommerce_review_order_before_order_total', [self::class, 'row']);
        add_action('wp_enqueue_scripts', [self::class, 'script']);
        add_action('wp_ajax_' . self::ACTION, [self::class, 'ajax']);
    }

    public static function script(): void
    {
        if (!is_cart() && !is_checkout()) {
            return;
        }

        wp_enqueue_script('onetrace-loyalty', plugins_url('assets/js/loyalty.js', ONETRACE_WC_FILE), ['jquery'], ONETRACE_WC_VERSION, true);
        wp_localize_script('onetrace-loyalty', 'onetraceLoyalty', [
            'url' => admin_url('admin-ajax.php'),
            'action' => self::ACTION,
            'nonce' => wp_create_nonce(self::ACTION),
            'error' => __('Could not apply the points. Please try again.', 'onetrace-for-woocommerce'),
        ]);
    }

    /**
     * The totals row: for a guest — a hint to sign in, for a customer — the balance and the points field or the
     * applied points.
     */
    public static function row(): void
    {
        $content = self::content();

        if ($content === '') {
            return;
        }

        printf(
            '<tr class="onetrace-loyalty"><th>%s</th><td data-title="%s">%s</td></tr>',
            esc_html__('Bonus points', 'onetrace-for-woocommerce'),
            esc_attr__('Bonus points', 'onetrace-for-woocommerce'),
            wp_kses_post($content)
        );
    }

    public static function content(): string
    {
        if (WC()->cart === null || WC()->cart->is_empty()) {
            return '';
        }

        if (Loyalty::profile() === null) {
            return sprintf(
                '<a href="%s">%s</a>',
                esc_url(wc_get_page_permalink('myaccount')),
                esc_html__('Sign in to use your bonus points', 'onetrace-for-woocommerce')
            );
        }

        $offer = Loyalty::offer();

        if ($offer === null) {
            return '';
        }

        $hold = Loyalty::hold();
        $earn = Loyalty::earn($offer) > 0
            /* translators: %s: number of points */
            ? '<small class="onetrace-loyalty__earn">' . esc_html(sprintf(__('+%s points for this order', 'onetrace-for-woocommerce'), number_format_i18n(Loyalty::earn($offer)))) . '</small>'
            : '';

        if (empty($offer['member'])) {
            return sprintf('<a href="%s">%s</a>', esc_url(LoyaltyAccount::url()), esc_html__('Join the loyalty program to collect points', 'onetrace-for-woocommerce'));
        }

        if ($hold !== null) {
            return sprintf(
                '<span class="onetrace-loyalty__applied">%s</span> <a href="#" class="onetrace-loyalty__remove">%s</a><br>%s',
                /* translators: 1: number of points, 2: discount */
                esc_html(sprintf(__('%1$s points applied: −%2$s', 'onetrace-for-woocommerce'), number_format_i18n($hold['points']), wp_strip_all_tags(wc_price($hold['discount'])))),
                esc_html__('Remove', 'onetrace-for-woocommerce'),
                $earn
            );
        }

        $balance = (int) ($offer['balance'] ?? 0);
        $max = (int) ($offer['max_points'] ?? 0);
        /* translators: 1: number of points, 2: their value in money */
        $text = esc_html(sprintf(__('You have %1$s points (%2$s).', 'onetrace-for-woocommerce'), number_format_i18n($balance), wp_strip_all_tags(wc_price($balance * (float) ($offer['point_value'] ?? 1)))));

        if ($max <= 0) {
            return $text . '<br>' . $earn;
        }

        return sprintf(
            '%1$s<br><span class="onetrace-loyalty__form"><input type="number" class="input-text onetrace-loyalty__points" min="1" max="%2$d" step="1" value="%2$d" aria-label="%3$s" style="width:7em"> <button type="button" class="button onetrace-loyalty__apply">%4$s</button></span><br><small>%5$s</small><br>%6$s',
            $text,
            $max,
            esc_attr__('Points to use', 'onetrace-for-woocommerce'),
            esc_html__('Use points', 'onetrace-for-woocommerce'),
            /* translators: 1: number of points, 2: discount */
            esc_html(sprintf(__('Up to %1$s points (−%2$s) for this cart.', 'onetrace-for-woocommerce'), number_format_i18n($max), wp_strip_all_tags(wc_price((float) ($offer['max_discount'] ?? 0))))),
            $earn
        );
    }

    public static function ajax(): void
    {
        check_ajax_referer(self::ACTION, 'nonce');
        $mode = isset($_POST['mode']) ? sanitize_key((string) wp_unslash($_POST['mode'])) : '';

        try {
            if ($mode === 'remove') {
                Loyalty::remove();
            } else {
                Loyalty::apply(isset($_POST['points']) ? absint(wp_unslash($_POST['points'])) : 0);
            }
        } catch (\Throwable $error) {
            wp_send_json_error(['message' => $error->getMessage()], 422);
        }

        wp_send_json_success();
    }
}
