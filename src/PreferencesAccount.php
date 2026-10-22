<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Exception\NotFoundException;
use OneTrace\Identity;

/**
 * My account → Newsletters: the preference center of OneTrace inside the store — subscription topics by channel,
 * how often marketing messages come, a pause and "Unsubscribe from everything". Transactional messages (orders,
 * passwords) keep coming whatever the customer chooses here.
 */
final class PreferencesAccount
{
    public const ENDPOINT = 'newsletters';

    public const PAUSE_DAYS = [30, 90];

    private const SAVE = 'onetrace_preferences';

    /** Rewrite rules of the endpoint are flushed once per plugin version. */
    private const REWRITE_OPTION = 'onetrace_preferences_rewrite';

    public static function register(): void
    {
        if (!self::enabled()) {
            return;
        }

        add_action('init', [self::class, 'endpoint']);
        add_filter('woocommerce_get_query_vars', [self::class, 'queryVars']);
        add_filter('woocommerce_account_menu_items', [self::class, 'menu']);
        add_filter('woocommerce_endpoint_' . self::ENDPOINT . '_title', [self::class, 'title']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [self::class, 'render']);
        add_action('template_redirect', [self::class, 'handle']);
    }

    public static function enabled(): bool
    {
        return Settings::enabled('preferences') && Settings::server();
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
        return __('Newsletters', 'onetrace-for-woocommerce');
    }

    public static function render(): void
    {
        $state = self::state();

        if ($state === null) {
            echo '<p>' . esc_html__('Newsletter settings are not available right now. Please try again later.', 'onetrace-for-woocommerce') . '</p>';

            return;
        }

        $channels = \is_array($state['channels'] ?? null) ? $state['channels'] : [];
        $topics = \is_array($state['topics'] ?? null) ? $state['topics'] : [];

        if ($channels === [] || $topics === []) {
            echo '<p>' . esc_html__('We do not send you newsletters yet.', 'onetrace-for-woocommerce') . '</p>';

            return;
        }

        echo '<form method="post" class="onetrace-preferences">';
        wp_nonce_field(self::SAVE);
        printf('<input type="hidden" name="%s" value="1">', esc_attr(self::SAVE));
        printf('<p>%s</p>', esc_html__('Choose what we send you and where. Messages about your orders and your account come anyway.', 'onetrace-for-woocommerce'));
        echo '<table class="woocommerce-table shop_table onetrace-preferences-topics"><thead><tr><th></th>';

        foreach ($channels as $channel) {
            printf('<th style="text-align:center">%s</th>', esc_html(self::capitalize((string) ($channel['name'] ?? $channel['key'] ?? ''))));
        }

        echo '</tr></thead><tbody>';

        foreach ($topics as $topic) {
            $description = trim((string) ($topic['description'] ?? ''));
            printf('<tr><td>%s%s</td>', esc_html((string) ($topic['name'] ?? $topic['key'] ?? '')), $description !== '' ? '<br><small>' . esc_html($description) . '</small>' : '');

            foreach ($channels as $channel) {
                $key = (string) ($channel['key'] ?? '');
                $subscribed = $topic['channels'][$key] ?? null;

                if ($subscribed === null) {
                    echo '<td style="text-align:center">—</td>';
                    continue;
                }

                printf(
                    '<td style="text-align:center"><input type="checkbox" name="topics[%s][%s]" value="1" aria-label="%s"%s%s></td>',
                    esc_attr((string) $topic['key']),
                    esc_attr($key),
                    esc_attr((string) ($topic['name'] ?? '') . ' · ' . (string) ($channel['name'] ?? $key)),
                    checked((bool) $subscribed, true, false),
                    disabled(!empty($channel['blocked']), true, false)
                );
            }

            echo '</tr>';
        }

        echo '</tbody></table>';

        if (\in_array(true, array_map(static function ($channel): bool {
            return !empty($channel['blocked']);
        }, $channels), true)) {
            printf('<p><small>%s</small></p>', esc_html__('Some channels cannot be changed: the address does not receive messages.', 'onetrace-for-woocommerce'));
        }

        $frequency = (string) ($state['frequency'] ?? '');
        printf('<p class="form-row"><label for="onetrace-frequency">%s</label><select name="frequency" id="onetrace-frequency">', esc_html__('How often', 'onetrace-for-woocommerce'));

        foreach (['default' => __('As they come', 'onetrace-for-woocommerce'), 'weekly' => __('At most once a week', 'onetrace-for-woocommerce'), 'monthly' => __('At most once a month', 'onetrace-for-woocommerce')] as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($frequency === '' ? 'default' : $frequency, $value, false), esc_html($label));
        }

        echo '</select></p>';

        $paused = \is_string($state['paused_until'] ?? null) ? strtotime($state['paused_until']) : false;
        printf('<p class="form-row"><label for="onetrace-pause">%s</label><select name="pause" id="onetrace-pause">', esc_html__('Pause', 'onetrace-for-woocommerce'));
        $pauses = $paused !== false
            /* translators: %s: date */
            ? ['keep' => sprintf(__('Paused until %s', 'onetrace-for-woocommerce'), date_i18n(wc_date_format(), $paused)), 'none' => __('Resume now', 'onetrace-for-woocommerce')]
            : ['none' => __('No pause', 'onetrace-for-woocommerce')];

        foreach (self::PAUSE_DAYS as $days) {
            /* translators: %d: number of days */
            $pauses[(string) $days] = sprintf(_n('Pause for %d day', 'Pause for %d days', $days, 'onetrace-for-woocommerce'), $days);
        }

        foreach ($pauses as $value => $label) {
            printf('<option value="%s">%s</option>', esc_attr((string) $value), esc_html($label));
        }

        echo '</select></p>';
        printf(
            '<p><button type="submit" class="button" name="onetrace_action" value="save">%s</button> <button type="submit" class="button" name="onetrace_action" value="all">%s</button></p></form>',
            esc_html__('Save', 'onetrace-for-woocommerce'),
            esc_html__('Unsubscribe from everything', 'onetrace-for-woocommerce')
        );
    }

    /**
     * The form of the tab: save the choice and come back with a notice.
     */
    public static function handle(): void
    {
        if (!isset($_POST[self::SAVE]) || !is_account_page() || !is_user_logged_in()) {
            return;
        }

        check_admin_referer(self::SAVE);
        $action = isset($_POST['onetrace_action']) && $_POST['onetrace_action'] === 'all' ? 'all' : 'save';
        $topics = isset($_POST['topics']) && \is_array($_POST['topics']) ? (array) map_deep(wp_unslash($_POST['topics']), 'sanitize_key') : [];
        $frequency = isset($_POST['frequency']) ? sanitize_key(wp_unslash($_POST['frequency'])) : 'default';
        $pause = isset($_POST['pause']) ? sanitize_key(wp_unslash($_POST['pause'])) : 'keep';

        if (self::save($action, $topics, $frequency, $pause)) {
            wc_add_notice($action === 'all' ? __('You are unsubscribed from all newsletters.', 'onetrace-for-woocommerce') : __('Your newsletter settings are saved.', 'onetrace-for-woocommerce'));
        } else {
            wc_add_notice(__('Could not save the settings. Please try again later.', 'onetrace-for-woocommerce'), 'error');
        }

        wp_safe_redirect(self::url());
        exit;
    }

    /**
     * Sends the choice to OneTrace: every topic of the form in every channel that can change (an unticked box —
     * unsubscribed), the frequency and the pause; "all" unsubscribes from every marketing message.
     *
     * @param 'save'|'all' $action
     * @param array<mixed> $topics topic → channel → "1" for ticked boxes
     */
    public static function save(string $action, array $topics, string $frequency, string $pause): bool
    {
        $profile = self::profile();
        $state = self::state();

        if ($profile === null || $state === null) {
            return false;
        }

        if ($action === 'all') {
            $changes = ['unsubscribe_all' => true];
        } else {
            $blocked = [];

            foreach ((array) ($state['channels'] ?? []) as $channel) {
                if (!empty($channel['blocked'])) {
                    $blocked[] = (string) $channel['key'];
                }
            }

            $choices = [];

            foreach ((array) ($state['topics'] ?? []) as $topic) {
                foreach ((array) ($topic['channels'] ?? []) as $channel => $subscribed) {
                    if ($subscribed !== null && !\in_array((string) $channel, $blocked, true)) {
                        $choices[(string) $topic['key']][(string) $channel] = !empty($topics[$topic['key']][$channel]);
                    }
                }
            }

            $changes = ['topics' => $choices, 'frequency' => \in_array($frequency, ['weekly', 'monthly'], true) ? $frequency : null];

            if ($pause === 'none') {
                $changes['paused_until'] = null;
            } elseif (\in_array((int) $pause, self::PAUSE_DAYS, true)) {
                $changes['paused_until'] = new \DateTimeImmutable('+' . (int) $pause . ' days');
            }
        }

        $changes['language'] = self::language();

        try {
            Connection::client()->profiles()->updatePreferences($profile['type'], $profile['value'], $changes);
        } catch (\Throwable $error) {
            return false;
        }

        return true;
    }

    /**
     * Preferences of the signed-in customer in the store language; a customer the platform does not know yet has
     * nothing to choose; null — a guest or the platform did not answer.
     *
     * @return array<string, mixed>|null
     */
    private static function state(): ?array
    {
        $profile = self::profile();

        if ($profile === null) {
            return null;
        }

        try {
            return Connection::client()->profiles()->preferences($profile['type'], $profile['value'], self::language());
        } catch (NotFoundException $error) {
            return ['channels' => [], 'topics' => []];
        } catch (\Throwable $error) {
            return null;
        }
    }

    /**
     * @return array{type: string, value: string}|null
     */
    private static function profile(): ?array
    {
        $userId = get_current_user_id();

        return $userId > 0 ? Identity::userId((string) $userId) : null;
    }

    private static function language(): string
    {
        return str_replace('_', '-', determine_locale());
    }

    private static function capitalize(string $text): string
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1) : ucfirst($text);
    }
}
