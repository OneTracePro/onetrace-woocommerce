<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Exception\NotFoundException;

/**
 * My account → Bonus points: the balance, points waiting for the return period, the tier, points expiring soon and
 * the latest operations; in a program with explicit enrollment — the "Join" button.
 */
final class LoyaltyAccount
{
    public const ENDPOINT = 'bonus-points';

    private const JOIN = 'onetrace_loyalty_join';

    /** Rewrite rules of the endpoint are flushed once per plugin version. */
    private const REWRITE_OPTION = 'onetrace_loyalty_rewrite';

    public static function register(): void
    {
        add_action('init', [self::class, 'endpoint']);
        add_filter('woocommerce_get_query_vars', [self::class, 'queryVars']);
        add_filter('woocommerce_account_menu_items', [self::class, 'menu']);
        add_filter('woocommerce_endpoint_' . self::ENDPOINT . '_title', [self::class, 'title']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [self::class, 'render']);
        add_action('template_redirect', [self::class, 'join']);
    }

    public static function url(): string
    {
        return wc_get_account_endpoint_url(self::ENDPOINT);
    }

    public static function endpoint(): void
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);

        if (get_option(self::REWRITE_OPTION) !== ONETRACE_WC_VERSION) {
            update_option(self::REWRITE_OPTION, ONETRACE_WC_VERSION, true);
            flush_rewrite_rules(false);
        }
    }

    /**
     * @param array<string, string> $vars
     * @return array<string, string>
     */
    public static function queryVars(array $vars): array
    {
        $vars[self::ENDPOINT] = self::ENDPOINT;

        return $vars;
    }

    /**
     * @param array<string, string> $items
     * @return array<string, string>
     */
    public static function menu(array $items): array
    {
        $logout = isset($items['customer-logout']) ? ['customer-logout' => $items['customer-logout']] : [];
        unset($items['customer-logout']);

        return $items + [self::ENDPOINT => self::title()] + $logout;
    }

    public static function title(): string
    {
        return __('Bonus points', 'onetrace-for-woocommerce');
    }

    public static function render(): void
    {
        $profile = Loyalty::profile();

        if ($profile === null) {
            return;
        }

        try {
            $account = Connection::client()->loyalty()->account($profile['type'], $profile['value']);
            $operations = !empty($account['member']) ? Connection::client()->loyalty()->transactions($profile['type'], $profile['value'], ['limit' => 10])->getData() : [];
        } catch (NotFoundException $error) {
            // The platform knows nothing about the customer yet — not a member.
            $account = ['member' => false];
            $operations = [];
        } catch (\Throwable $error) {
            echo '<p>' . esc_html__('Bonus points are not available right now. Please try again later.', 'onetrace-for-woocommerce') . '</p>';

            return;
        }

        if (empty($account['member'])) {
            printf('<p>%s</p><form method="post"><input type="hidden" name="%s" value="1">', esc_html__('Join the loyalty program: collect points for orders and pay for part of your next orders with them.', 'onetrace-for-woocommerce'), esc_attr(self::JOIN));
            wp_nonce_field(self::JOIN);
            printf('<button type="submit" class="button">%s</button></form>', esc_html__('Join the program', 'onetrace-for-woocommerce'));

            return;
        }

        $rows = [
            __('Available', 'onetrace-for-woocommerce') => number_format_i18n((int) $account['balance']),
            __('Pending (available after the return period)', 'onetrace-for-woocommerce') => number_format_i18n((int) $account['pending']),
        ];

        if (!empty($account['tier'])) {
            $rows[__('Tier', 'onetrace-for-woocommerce')] = (string) $account['tier'];
        }

        if ((int) ($account['expiring'] ?? 0) > 0 && !empty($account['expires_at'])) {
            /* translators: 1: number of points, 2: date */
            $rows[__('Expiring soon', 'onetrace-for-woocommerce')] = sprintf(__('%1$s points on %2$s', 'onetrace-for-woocommerce'), number_format_i18n((int) $account['expiring']), date_i18n(wc_date_format(), (int) strtotime((string) $account['expires_at'])));
        }

        echo '<table class="woocommerce-table shop_table onetrace-loyalty-account"><tbody>';

        foreach ($rows as $label => $value) {
            printf('<tr><th>%s</th><td>%s</td></tr>', esc_html($label), esc_html($value));
        }

        echo '</tbody></table>';

        if ($operations === []) {
            return;
        }

        printf('<h3>%s</h3><table class="woocommerce-table shop_table onetrace-loyalty-history"><thead><tr><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>', esc_html__('History', 'onetrace-for-woocommerce'), esc_html__('Date', 'onetrace-for-woocommerce'), esc_html__('Operation', 'onetrace-for-woocommerce'), esc_html__('Points', 'onetrace-for-woocommerce'));

        foreach ($operations as $operation) {
            $points = (int) ($operation['points'] ?? 0);
            // The reference of a hold is the cart of the store — not useful to the customer.
            $reference = (string) ($operation['reference'] ?? '');
            $details = array_filter([(string) ($operation['reason'] ?? ''), (string) ($operation['rule'] ?? ''), strpos($reference, 'wc:') === 0 ? '' : $reference]);
            printf(
                '<tr><td>%s</td><td>%s%s</td><td>%s</td></tr>',
                esc_html(date_i18n(wc_date_format(), (int) strtotime((string) ($operation['created_at'] ?? '')))),
                esc_html(self::operation((string) ($operation['type'] ?? ''))),
                $details !== [] ? '<br><small>' . esc_html(implode(' · ', $details)) . '</small>' : '',
                esc_html(($points > 0 ? '+' : '') . number_format_i18n($points))
            );
        }

        echo '</tbody></table>';
    }

    /**
     * The "Join the program" form.
     */
    public static function join(): void
    {
        if (!isset($_POST[self::JOIN]) || !is_account_page() || !is_user_logged_in()) {
            return;
        }

        check_admin_referer(self::JOIN);
        $profile = Loyalty::profile();

        try {
            if ($profile !== null) {
                Connection::client()->loyalty()->join($profile);
                wc_add_notice(__('Welcome to the loyalty program!', 'onetrace-for-woocommerce'));
            }
        } catch (\Throwable $error) {
            wc_add_notice(__('Could not join the program. Please try again later.', 'onetrace-for-woocommerce'), 'error');
        }

        wp_safe_redirect(self::url());
        exit;
    }

    private static function operation(string $type): string
    {
        $labels = [
            'earn' => __('Earned', 'onetrace-for-woocommerce'),
            'cancel' => __('Earning cancelled', 'onetrace-for-woocommerce'),
            'expire' => __('Expired', 'onetrace-for-woocommerce'),
            'adjust' => __('Adjustment', 'onetrace-for-woocommerce'),
            'redeem' => __('Used in an order', 'onetrace-for-woocommerce'),
            'refund' => __('Returned', 'onetrace-for-woocommerce'),
        ];

        return $labels[$type] ?? $type;
    }
}
