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
        array_unshift($links, sprintf('<a href="%s">%s</a>', esc_url(self::url()), esc_html__('Settings', 'onetrace-woocommerce')));

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
        $constant = __('Set in wp-config.php.', 'onetrace-woocommerce');
        $field = static function (string $key, array $field) use ($constant): array {
            $field += ['id' => Settings::OPTION . '[' . $key . ']', 'default' => Settings::DEFAULTS[$key] ?? ''];

            if (Settings::fromConstant($key)) {
                $field['custom_attributes'] = ['disabled' => 'disabled'];
                $field['desc'] = $constant;
            }

            return $field;
        };

        return [
            ['type' => 'title', 'id' => 'onetrace_connection', 'title' => __('Connection', 'onetrace-woocommerce'), 'desc' => __('Keys are in the "API keys" section of your OneTrace project.', 'onetrace-woocommerce')],
            $field('url', ['type' => 'url', 'title' => __('Platform address', 'onetrace-woocommerce'), 'placeholder' => 'https://cdp.onetrace.pro', 'desc_tip' => __('The address you open the platform at: the domain of your brand.', 'onetrace-woocommerce')]),
            $field('write_key', ['type' => 'text', 'title' => __('Write key', 'onetrace-woocommerce'), 'placeholder' => 'cdp_wk_…', 'desc_tip' => __('For the tracker on the storefront.', 'onetrace-woocommerce')]),
            $field('secret_key', ['type' => 'password', 'title' => __('Secret key', 'onetrace-woocommerce'), 'placeholder' => 'cdp_sk_…', 'desc_tip' => __('For orders, customers and the catalog; needs the products.write permission.', 'onetrace-woocommerce')]),
            ['type' => 'sectionend', 'id' => 'onetrace_connection'],

            ['type' => 'title', 'id' => 'onetrace_parts', 'title' => __('What to send', 'onetrace-woocommerce')],
            $field('tracker', ['type' => 'checkbox', 'title' => __('Tracker', 'onetrace-woocommerce'), 'desc' => __('Page and product views on the storefront', 'onetrace-woocommerce')]),
            $field('wait_for_consent', ['type' => 'checkbox', 'title' => __('Cookie consent', 'onetrace-woocommerce'), 'desc' => __('Load the tracker after the visitor agrees to statistics cookies (with a consent plugin that supports the WP Consent API)', 'onetrace-woocommerce')]),
            $field('orders', ['type' => 'checkbox', 'title' => __('Orders', 'onetrace-woocommerce'), 'desc' => __('Placed, paid, cancelled and refunded orders with the customer data', 'onetrace-woocommerce')]),
            $field('cart', ['type' => 'checkbox', 'title' => __('Cart', 'onetrace-woocommerce'), 'desc' => __('Adding to and removing from the cart, opening the checkout — for abandoned cart journeys', 'onetrace-woocommerce')]),
            $field('catalog', ['type' => 'checkbox', 'title' => __('Product catalog', 'onetrace-woocommerce'), 'desc' => __('Products and categories for recommendations and emails, updated when products change and daily', 'onetrace-woocommerce')]),
            $field('product_id', ['type' => 'select', 'title' => __('Product id', 'onetrace-woocommerce'), 'options' => ['id' => __('Post ID', 'onetrace-woocommerce'), 'sku' => __('SKU (the post ID for products without a SKU)', 'onetrace-woocommerce')], 'desc_tip' => __('Choose SKU if your OneTrace project already has the catalog and history under SKUs, e.g. after moving from another platform.', 'onetrace-woocommerce')]),
            ['type' => 'sectionend', 'id' => 'onetrace_parts'],

            ['type' => 'title', 'id' => 'onetrace_consent', 'title' => __('Newsletter consent', 'onetrace-woocommerce'), 'desc' => __('A checkbox subscribes the customer to marketing emails in OneTrace. Without it no consent is sent.', 'onetrace-woocommerce')],
            $field('consent_checkout', ['type' => 'checkbox', 'title' => __('Checkout', 'onetrace-woocommerce'), 'desc' => __('Checkbox at checkout (classic and block)', 'onetrace-woocommerce')]),
            $field('consent_registration', ['type' => 'checkbox', 'title' => __('Registration', 'onetrace-woocommerce'), 'desc' => __('Checkbox in the registration form', 'onetrace-woocommerce')]),
            $field('consent_account', ['type' => 'checkbox', 'title' => __('Account', 'onetrace-woocommerce'), 'desc' => __('Subscribe and unsubscribe on the account details page', 'onetrace-woocommerce')]),
            $field('consent_label', ['type' => 'text', 'title' => __('Checkbox text', 'onetrace-woocommerce'), 'placeholder' => __('Send me news and special offers by email', 'onetrace-woocommerce')]),
            $field('consent_topic', ['type' => 'text', 'title' => __('Subscription topic', 'onetrace-woocommerce'), 'placeholder' => 'news', 'desc_tip' => __('Empty — the whole email channel.', 'onetrace-woocommerce')]),
            ['type' => 'sectionend', 'id' => 'onetrace_consent'],

            ['type' => 'title', 'id' => 'onetrace_widgets', 'title' => __('Recommendations, search and Web Push', 'onetrace-woocommerce'), 'desc' => __('Widget ids are in the "Site → Widgets" section of the platform. Anywhere else use the [onetrace_widget id="…"] shortcode.', 'onetrace-woocommerce')],
            $field('widget_product', ['type' => 'text', 'title' => __('Widget on the product page', 'onetrace-woocommerce'), 'placeholder' => 'k3x9…']),
            $field('widget_cart', ['type' => 'text', 'title' => __('Widget in the cart', 'onetrace-woocommerce'), 'placeholder' => 'k3x9…']),
            $field('push', ['type' => 'checkbox', 'title' => __('Web Push', 'onetrace-woocommerce'), 'desc' => __('Serve /cdp-sw.js from the store root; put the [onetrace_push_button] shortcode where visitors subscribe', 'onetrace-woocommerce')]),
            $field('search', ['type' => 'checkbox', 'title' => __('Product search', 'onetrace-woocommerce'), 'desc' => __('Results of the store search and suggestions while typing come from OneTrace (the plan of the project must include product search); on an error — the usual WordPress search', 'onetrace-woocommerce')]),
            ['type' => 'sectionend', 'id' => 'onetrace_widgets'],
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
                $field['placeholder'] = (string) $field['value'] !== '' ? __('Saved — enter a new key to replace it', 'onetrace-woocommerce') : 'cdp_sk_…';
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
        Queue::schedule();

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
                $missing = $key === 'secret' && !\in_array('products.write', $answer['scopes'], true) && Settings::enabled('catalog');
                $results[] = [
                    $answer['type'] === $key && !$missing ? 'success' : 'warning',
                    sprintf(
                        $key === 'write'
                            /* translators: %s: project name */
                            ? __('Write key: project "%s".', 'onetrace-woocommerce')
                            /* translators: %s: project name */
                            : __('Secret key: project "%s".', 'onetrace-woocommerce'),
                        (string) ($answer['project']['name'] ?? '')
                    ) . ($missing ? ' ' . __('The key has no products.write permission: the catalog will not be uploaded.', 'onetrace-woocommerce') : ''),
                ];
            } catch (\Throwable $error) {
                $results[] = ['error', sprintf('%s: %s', $key === 'write' ? __('Write key', 'onetrace-woocommerce') : __('Secret key', 'onetrace-woocommerce'), $error->getMessage())];
            }
        }

        if ($results === []) {
            $results[] = ['error', __('Enter the platform address and the keys first.', 'onetrace-woocommerce')];
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
        set_transient('onetrace_notice_' . get_current_user_id(), [['success', __('The catalog upload has started; it runs in the background.', 'onetrace-woocommerce')]], 60);
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
                esc_html__('OneTrace: sending is paused because the platform rejected the key. Check the keys and save the settings.', 'onetrace-woocommerce'),
                esc_url(self::url()),
                esc_html__('Settings', 'onetrace-woocommerce'),
                esc_html($paused)
            );
        }
    }

    private static function status(): void
    {
        $stats = Queue::stats();
        $error = Queue::lastError();

        echo '<h2>' . esc_html__('Status', 'onetrace-woocommerce') . '</h2><table class="form-table"><tbody>';
        printf(
            '<tr><th>%s</th><td>%s</td></tr>',
            esc_html__('Queue', 'onetrace-woocommerce'),
            esc_html(sprintf(
                /* translators: 1: items waiting, 2: items waiting for a retry */
                __('%1$d waiting, %2$d of them after an error', 'onetrace-woocommerce'),
                $stats['waiting'],
                $stats['retrying']
            ))
        );

        if ($error !== null) {
            printf('<tr><th>%s</th><td><code>%s</code></td></tr>', esc_html__('Last error', 'onetrace-woocommerce'), esc_html($error));
        }

        echo '</tbody></table><p>';
        self::button('onetrace_test', __('Test connection', 'onetrace-woocommerce'));
        echo ' ';
        self::button('onetrace_sync', __('Upload the whole catalog', 'onetrace-woocommerce'));
        echo '</p>';
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
            wp_die(esc_html__('You are not allowed to do this.', 'onetrace-woocommerce'), 403);
        }
    }
}
