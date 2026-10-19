<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

/**
 * WooCommerce → Settings → OneTrace: keys, parts of the integration, the connection check, the queue state and
 * the full catalog upload.
 */
final class Admin
{
    public const TAB = 'onetrace';

    public static function register(): void
    {
        add_filter('woocommerce_settings_tabs_array', [self::class, 'tab'], 70);
        add_action('woocommerce_settings_' . self::TAB, [self::class, 'render']);
        add_action('woocommerce_update_options_' . self::TAB, [self::class, 'save']);
        add_action('admin_post_onetrace_test', [self::class, 'test']);
        add_action('admin_post_onetrace_sync', [self::class, 'sync']);
        add_action('admin_notices', [self::class, 'notices']);
        add_filter('plugin_action_links_' . plugin_basename(ONETRACE_WC_FILE), [self::class, 'links']);
    }

    /**
     * @param array<string, string> $tabs
     *
     * @return array<string, string>
     */
    public static function tab(array $tabs): array
    {
        $tabs[self::TAB] = 'OneTrace';

        return $tabs;
    }

    /**
     * @param array<int|string, string> $links
     *
     * @return array<int|string, string>
     */
    public static function links(array $links): array
    {
        array_unshift($links, sprintf('<a href="%s">%s</a>', esc_url(self::url()), esc_html__('Settings', 'onetrace-for-woocommerce')));

        return $links;
    }

    public static function url(): string
    {
        return admin_url('admin.php?page=wc-settings&tab=' . self::TAB);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fields(): array
    {
        $constant = __('Set in wp-config.php.', 'onetrace-for-woocommerce');
        $field = static function (string $key, array $field) use ($constant): array {
            $field += ['id' => Settings::OPTION . '[' . $key . ']', 'default' => Settings::DEFAULTS[$key] ?? ''];

            if (Settings::fromConstant($key)) {
                $field['custom_attributes'] = ['disabled' => 'disabled'];
                $field['desc'] = $constant;
            }

            return $field;
        };

        return [
            ['type' => 'title', 'id' => 'onetrace_connection', 'title' => __('Connection', 'onetrace-for-woocommerce'), 'desc' => __('Keys are in the "API keys" section of your OneTrace project.', 'onetrace-for-woocommerce')],
            $field('url', ['type' => 'url', 'title' => __('Platform address', 'onetrace-for-woocommerce'), 'placeholder' => 'https://cdp.onetrace.pro', 'desc_tip' => __('The address you open the platform at: the domain of your brand.', 'onetrace-for-woocommerce')]),
            $field('write_key', ['type' => 'text', 'title' => __('Write key', 'onetrace-for-woocommerce'), 'placeholder' => 'cdp_wk_…', 'desc_tip' => __('For the tracker on the storefront.', 'onetrace-for-woocommerce')]),
            $field('secret_key', ['type' => 'password', 'title' => __('Secret key', 'onetrace-for-woocommerce'), 'placeholder' => 'cdp_sk_…', 'desc_tip' => __('For orders, customers and the catalog; needs the products.write permission.', 'onetrace-for-woocommerce')]),
            ['type' => 'sectionend', 'id' => 'onetrace_connection'],

            ['type' => 'title', 'id' => 'onetrace_parts', 'title' => __('What to send', 'onetrace-for-woocommerce')],
            $field('tracker', ['type' => 'checkbox', 'title' => __('Tracker', 'onetrace-for-woocommerce'), 'desc' => __('Page and product views on the storefront', 'onetrace-for-woocommerce')]),
            $field('wait_for_consent', ['type' => 'checkbox', 'title' => __('Cookie consent', 'onetrace-for-woocommerce'), 'desc' => __('Load the tracker after the visitor agrees to statistics cookies (with a consent plugin that supports the WP Consent API)', 'onetrace-for-woocommerce')]),
            $field('orders', ['type' => 'checkbox', 'title' => __('Orders', 'onetrace-for-woocommerce'), 'desc' => __('Placed, paid, shipped, delivered, cancelled and refunded orders with the customer data', 'onetrace-for-woocommerce')]),
            $field('cart', ['type' => 'checkbox', 'title' => __('Cart', 'onetrace-for-woocommerce'), 'desc' => __('Adding to and removing from the cart, opening the checkout — for abandoned cart journeys', 'onetrace-for-woocommerce')]),
            $field('wishlist', ['type' => 'checkbox', 'title' => __('Wishlist', 'onetrace-for-woocommerce'), 'desc' => __('Adding to and removing from the wishlist of YITH WooCommerce Wishlist or TI WooCommerce Wishlist — for wishlist journeys', 'onetrace-for-woocommerce')]),
            $field('catalog', ['type' => 'checkbox', 'title' => __('Product catalog', 'onetrace-for-woocommerce'), 'desc' => __('Products, categories and stock for recommendations, emails and catalog journeys, updated when products or their stock change and daily', 'onetrace-for-woocommerce')]),
            $field('product_id', ['type' => 'select', 'title' => __('Product id', 'onetrace-for-woocommerce'), 'options' => ['id' => __('Post ID', 'onetrace-for-woocommerce'), 'sku' => __('SKU (the post ID for products without a SKU)', 'onetrace-for-woocommerce')], 'desc_tip' => __('Choose SKU if your OneTrace project already has the catalog and history under SKUs, e.g. after moving from another platform.', 'onetrace-for-woocommerce')]),
            ['type' => 'sectionend', 'id' => 'onetrace_parts'],

            ['type' => 'title', 'id' => 'onetrace_consent', 'title' => __('Newsletter consent', 'onetrace-for-woocommerce'), 'desc' => __('A checkbox subscribes the customer to marketing emails in OneTrace. Without it no consent is sent.', 'onetrace-for-woocommerce')],
            $field('consent_checkout', ['type' => 'checkbox', 'title' => __('Checkout', 'onetrace-for-woocommerce'), 'desc' => __('Checkbox at checkout (classic and block)', 'onetrace-for-woocommerce')]),
            $field('consent_registration', ['type' => 'checkbox', 'title' => __('Registration', 'onetrace-for-woocommerce'), 'desc' => __('Checkbox in the registration form', 'onetrace-for-woocommerce')]),
            $field('consent_account', ['type' => 'checkbox', 'title' => __('Account', 'onetrace-for-woocommerce'), 'desc' => __('Subscribe and unsubscribe on the account details page', 'onetrace-for-woocommerce')]),
            $field('consent_label', ['type' => 'text', 'title' => __('Checkbox text', 'onetrace-for-woocommerce'), 'placeholder' => __('Send me news and special offers by email', 'onetrace-for-woocommerce')]),
            $field('consent_topic', ['type' => 'text', 'title' => __('Subscription topic', 'onetrace-for-woocommerce'), 'placeholder' => 'news', 'desc_tip' => __('Empty — the whole email channel.', 'onetrace-for-woocommerce')]),
            ['type' => 'sectionend', 'id' => 'onetrace_consent'],

            ['type' => 'title', 'id' => 'onetrace_widgets', 'title' => __('Recommendations, search and Web Push', 'onetrace-for-woocommerce'), 'desc' => __('Widget ids are in the "Site → Widgets" section of the platform. Anywhere else use the [onetrace_widget id="…"] shortcode.', 'onetrace-for-woocommerce')],
            $field('widget_product', ['type' => 'text', 'title' => __('Widget on the product page', 'onetrace-for-woocommerce'), 'placeholder' => 'k3x9…']),
            $field('widget_cart', ['type' => 'text', 'title' => __('Widget in the cart', 'onetrace-for-woocommerce'), 'placeholder' => 'k3x9…']),
            $field('push', ['type' => 'checkbox', 'title' => __('Web Push', 'onetrace-for-woocommerce'), 'desc' => __('Serve /cdp-sw.js from the store root; put the [onetrace_push_button] shortcode where visitors subscribe', 'onetrace-for-woocommerce')]),
            $field('search', ['type' => 'checkbox', 'title' => __('Product search', 'onetrace-for-woocommerce'), 'desc' => __('Results of the store search and suggestions while typing come from OneTrace (the plan of the project must include product search); on an error — the usual WordPress search', 'onetrace-for-woocommerce')]),
            $field('notify', ['type' => 'checkbox', 'title' => __('Notify when available', 'onetrace-for-woocommerce'), 'desc' => __('Show the “Notify when available” form on the page of a product that is out of stock', 'onetrace-for-woocommerce'), 'desc_tip' => __('The form itself is set up in OneTrace → Site → Forms (kind “Notify when available”); without an active form of this kind nothing is shown.', 'onetrace-for-woocommerce')]),
            ['type' => 'sectionend', 'id' => 'onetrace_widgets'],

            ['type' => 'title', 'id' => 'onetrace_loyalty', 'title' => __('Loyalty program', 'onetrace-for-woocommerce'), 'desc' => __('The program is set up in the "Loyalty" section of the platform. Signed-in customers pay for part of the order with points in the cart and at checkout and see their points in My account → Bonus points.', 'onetrace-for-woocommerce')],
            $field('loyalty', ['type' => 'checkbox', 'title' => __('Bonus points', 'onetrace-for-woocommerce'), 'desc' => __('Show the balance and use points in the cart and at checkout (the secret key needs the loyalty.read and loyalty.write permissions)', 'onetrace-for-woocommerce')]),
            $field('referral_field', ['type' => 'checkbox', 'title' => __('Referrals', 'onetrace-for-woocommerce'), 'desc' => __('The "Friend\'s code" field in the registration form. Without it the code of a referral link (?ref=) still counts: the tracker keeps it in a cookie.', 'onetrace-for-woocommerce')]),
            ['type' => 'sectionend', 'id' => 'onetrace_loyalty'],

            ['type' => 'title', 'id' => 'onetrace_promo', 'title' => __('Promo codes', 'onetrace-for-woocommerce'), 'desc' => __('Unique codes of OneTrace promo code pools become WooCommerce coupons every 5 minutes: with the discount, restrictions and expiry of a template coupon, one use each. Create the template coupon in Marketing → Coupons and bind it to the pool key from the "Promo codes" section of the platform.', 'onetrace-for-woocommerce')],
            $field('promo_pools', ['type' => 'textarea', 'title' => __('Pools and template coupons', 'onetrace-for-woocommerce'), 'placeholder' => 'welcome = WELCOME-TEMPLATE', 'css' => 'min-height:5em;font-family:monospace', 'desc' => __('One per line: pool key = template coupon code. The secret key needs the promo.read permission.', 'onetrace-for-woocommerce')]),
            ['type' => 'sectionend', 'id' => 'onetrace_promo'],
        ];
    }

    public static function render(): void
    {
        $values = get_option(Settings::OPTION, []);
        $fields = array_map(static function (array $field) use ($values): array {
            if (preg_match('/\[(\w+)\]$/', (string) ($field['id'] ?? ''), $match) === 1) {
                $field['value'] = Settings::fromConstant($match[1]) ? (string) Settings::get($match[1]) : ($values[$match[1]] ?? $field['default']);
            }

            // The secret key is never printed back: an empty field keeps the stored one.
            if (($field['id'] ?? '') === Settings::OPTION . '[secret_key]') {
                $field['placeholder'] = (string) $field['value'] !== '' ? __('Saved — enter a new key to replace it', 'onetrace-for-woocommerce') : 'cdp_sk_…';
                $field['value'] = '';
            }

            return $field;
        }, self::fields());

        woocommerce_admin_fields($fields);
        self::status();
    }

    public static function save(): void
    {
        $previous = get_option(Settings::OPTION, []);
        woocommerce_update_options(self::fields());
        $settings = get_option(Settings::OPTION, []);

        // An empty password field keeps the stored secret key.
        if (\is_array($settings) && \is_array($previous) && ($settings['secret_key'] ?? '') === '' && ($previous['secret_key'] ?? '') !== '') {
            $settings['secret_key'] = $previous['secret_key'];
            update_option(Settings::OPTION, $settings);
        }

        delete_option(Queue::PAUSED_OPTION);
        Connection::reset();
        StoreEmails::forget();
        Queue::schedule();
        PromoSync::schedule();

        if (Settings::server() && Settings::enabled('catalog') && !get_option('onetrace_catalog_synced')) {
            update_option('onetrace_catalog_synced', time(), false);
            Catalog::syncAll();
        }
    }

    public static function test(): void
    {
        check_admin_referer('onetrace_test');
        self::authorize();

        $results = [];

        foreach (['write' => Settings::storefront(), 'secret' => Settings::server()] as $key => $configured) {
            if (!$configured) {
                continue;
            }

            try {
                $answer = Connection::client($key)->checkKey($key);

                if ($key === 'secret') {
                    set_transient(StoreEmails::PROJECT_TRANSIENT, (string) ($answer['project']['uid'] ?? ''), DAY_IN_SECONDS);
                }

                $missing = $key === 'secret' && !\in_array('products.write', $answer['scopes'], true) && Settings::enabled('catalog');
                $loyalty = $key === 'secret' && Settings::enabled('loyalty') && array_diff(['loyalty.read', 'loyalty.write'], $answer['scopes']) !== [];
                $promo = $key === 'secret' && PromoSync::pools() !== [] && !\in_array('promo.read', $answer['scopes'], true);
                $results[] = [
                    $answer['type'] === $key && !$missing && !$loyalty && !$promo ? 'success' : 'warning',
                    sprintf(
                        $key === 'write'
                            /* translators: %s: project name */
                            ? __('Write key: project "%s".', 'onetrace-for-woocommerce')
                            /* translators: %s: project name */
                            : __('Secret key: project "%s".', 'onetrace-for-woocommerce'),
                        (string) ($answer['project']['name'] ?? '')
                    ) . ($missing ? ' ' . __('The key has no products.write permission: the catalog will not be uploaded.', 'onetrace-for-woocommerce') : '')
                    . ($loyalty ? ' ' . __('The key has no loyalty.read and loyalty.write permissions: bonus points will not work.', 'onetrace-for-woocommerce') : '')
                    . ($promo ? ' ' . __('The key has no promo.read permission: promo codes will not become coupons.', 'onetrace-for-woocommerce') : ''),
                ];
            } catch (\Throwable $error) {
                $results[] = ['error', sprintf('%s: %s', $key === 'write' ? __('Write key', 'onetrace-for-woocommerce') : __('Secret key', 'onetrace-for-woocommerce'), $error->getMessage())];
            }
        }

        if ($results === []) {
            $results[] = ['error', __('Enter the platform address and the keys first.', 'onetrace-for-woocommerce')];
        } elseif (!\in_array('error', array_column($results, 0), true)) {
            delete_option(Queue::PAUSED_OPTION);
            Queue::schedule();
        }

        set_transient('onetrace_notice_' . get_current_user_id(), $results, 60);
        wp_safe_redirect(self::url());
        exit;
    }

    public static function sync(): void
    {
        check_admin_referer('onetrace_sync');
        self::authorize();
        Catalog::syncAll();
        update_option('onetrace_catalog_synced', time(), false);
        set_transient('onetrace_notice_' . get_current_user_id(), [['success', __('The catalog upload has started; it runs in the background.', 'onetrace-for-woocommerce')]], 60);
        wp_safe_redirect(self::url());
        exit;
    }

    public static function notices(): void
    {
        $results = get_transient('onetrace_notice_' . get_current_user_id());

        if (\is_array($results)) {
            delete_transient('onetrace_notice_' . get_current_user_id());

            foreach ($results as [$type, $text]) {
                printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($text));
            }
        }

        $paused = get_option(Queue::PAUSED_OPTION);

        if (\is_string($paused) && current_user_can('manage_woocommerce')) {
            printf(
                '<div class="notice notice-error"><p>%s <a href="%s">%s</a></p><p><code>%s</code></p></div>',
                esc_html__('OneTrace: sending is paused because the platform rejected the key. Check the keys and save the settings.', 'onetrace-for-woocommerce'),
                esc_url(self::url()),
                esc_html__('Settings', 'onetrace-for-woocommerce'),
                esc_html($paused)
            );
        }
    }

    private static function status(): void
    {
        $stats = Queue::stats();
        $error = Queue::lastError();

        echo '<h2>' . esc_html__('Status', 'onetrace-for-woocommerce') . '</h2><table class="form-table"><tbody>';
        printf(
            '<tr><th>%s</th><td>%s</td></tr>',
            esc_html__('Queue', 'onetrace-for-woocommerce'),
            esc_html(sprintf(
                /* translators: 1: items waiting, 2: items waiting for a retry */
                __('%1$d waiting, %2$d of them after an error', 'onetrace-for-woocommerce'),
                $stats['waiting'],
                $stats['retrying']
            ))
        );

        if ($error !== null) {
            printf('<tr><th>%s</th><td><code>%s</code></td></tr>', esc_html__('Last error', 'onetrace-for-woocommerce'), esc_html($error));
        }

        echo '</tbody></table><p>';
        self::button('onetrace_test', __('Test connection', 'onetrace-for-woocommerce'));
        echo ' ';
        self::button('onetrace_sync', __('Upload the whole catalog', 'onetrace-for-woocommerce'));
        echo '</p>';
        self::storeEmails();
    }

    /**
     * Read-only state of the store emails: they are set up in the "Store emails" section of the platform.
     */
    private static function storeEmails(): void
    {
        echo '<h2>' . esc_html__('Store emails', 'onetrace-for-woocommerce') . '</h2><p>'
            . esc_html__('Emails of WooCommerce, WordPress and other plugins can go out through the email integration of your OneTrace project, with delivery statuses and history in the customer profile; order emails — from OneTrace templates in the customer\'s language. This is set up in the "Store emails" section of the platform; the secret key needs the "Send messages manually" permission (messages.send). An email OneTrace does not accept is sent by the store itself.', 'onetrace-for-woocommerce')
            . '</p><table class="form-table"><tbody>';

        $row = static function (string $title, string $html): void {
            printf('<tr><th>%s</th><td>%s</td></tr>', esc_html($title), $html); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the callers.
        };
        $settings = StoreEmails::settings();

        if (!Settings::server()) {
            $row(__('State', 'onetrace-for-woocommerce'), esc_html__('Enter the platform address and the secret key.', 'onetrace-for-woocommerce'));
        } elseif ($settings === null) {
            $error = StoreEmails::error();
            $row(__('State', 'onetrace-for-woocommerce'), esc_html__('Not available — the store sends its emails itself.', 'onetrace-for-woocommerce') . ($error !== null ? ' <code>' . esc_html($error) . '</code>' : ''));
        } else {
            $labels = [
                'order_placed' => __('Order placed', 'onetrace-for-woocommerce'),
                'order_paid' => __('Order paid', 'onetrace-for-woocommerce'),
                'order_shipped' => __('Order shipped', 'onetrace-for-woocommerce'),
                'order_delivered' => __('Order delivered', 'onetrace-for-woocommerce'),
                'order_cancelled' => __('Order cancelled', 'onetrace-for-woocommerce'),
                'order_refunded' => __('Order refunded', 'onetrace-for-woocommerce'),
            ];
            $templates = array_values(array_intersect_key($labels, array_filter($settings['templates'])));

            $row(__('All store emails', 'onetrace-for-woocommerce'), esc_html($settings['relay']
                ? __('Through OneTrace: emails without a OneTrace template go out as the store makes them', 'onetrace-for-woocommerce')
                : __('Sent by the store', 'onetrace-for-woocommerce')));
            $row(__('Order emails from OneTrace templates', 'onetrace-for-woocommerce'), esc_html($templates !== [] ? implode(', ', $templates) : __('None', 'onetrace-for-woocommerce')));
        }

        $project = self::project();
        $row(__('Settings', 'onetrace-for-woocommerce'), $project !== ''
            ? sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url(Settings::url() . '/p/' . rawurlencode($project) . '/store-emails'), esc_html__('Store emails in OneTrace', 'onetrace-for-woocommerce'))
            : esc_html__('OneTrace → Store emails', 'onetrace-for-woocommerce'));
        echo '</tbody></table>';
    }

    /**
     * Uid of the project of the secret key, for the link to its sections; cached for a day.
     */
    private static function project(): string
    {
        if (!Settings::server()) {
            return '';
        }

        $cached = get_transient(StoreEmails::PROJECT_TRANSIENT);

        if (\is_string($cached)) {
            return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $cached) === 1 ? $cached : '';
        }

        try {
            $answer = Connection::client('emails')->checkKey('secret');
            $project = (string) ($answer['project']['uid'] ?? '');
        } catch (\Throwable $error) {
            set_transient(StoreEmails::PROJECT_TRANSIENT, '', 10 * MINUTE_IN_SECONDS);

            return '';
        }

        set_transient(StoreEmails::PROJECT_TRANSIENT, $project, DAY_IN_SECONDS);

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $project) === 1 ? $project : '';
    }

    /**
     * A button outside the settings form (forms cannot be nested): a link through admin-post.php with a nonce.
     */
    private static function button(string $action, string $label): void
    {
        printf('<a class="button" href="%s">%s</a>', esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . $action), $action)), esc_html($label));
    }

    private static function authorize(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to do this.', 'onetrace-for-woocommerce'), 403);
        }
    }
}
