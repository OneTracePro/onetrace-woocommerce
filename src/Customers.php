<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Commerce\Customer;
use OneTrace\Commerce\Messages;

/**
 * Customers and their consents: identify on registration, login, profile and address changes, and the newsletter
 * checkboxes of the registration form, the classic and block checkout and the account details page.
 */
final class Customers
{
    /** Id of the block checkout field; WooCommerce stores its value as the order meta "_wc_other/{id}". */
    public const CHECKOUT_FIELD = 'onetrace/marketing-consent';

    /** Order meta of the classic checkout checkbox. */
    public const ORDER_META = '_onetrace_marketing_consent';

    /** User meta: the customer subscribed with one of the checkboxes (shown checked on the account page). */
    public const USER_META = 'onetrace_subscribed';

    public static function register(): void
    {
        add_action('woocommerce_created_customer', [self::class, 'created'], 20);
        add_action('wp_login', [self::class, 'loggedIn'], 20, 2);
        add_action('profile_update', [self::class, 'updated'], 20);
        add_action('woocommerce_customer_save_address', [self::class, 'updated'], 20);

        if (Settings::enabled('consent_registration')) {
            add_action('woocommerce_register_form', [self::class, 'registrationCheckbox']);
        }

        if (Settings::enabled('consent_account')) {
            add_action('woocommerce_edit_account_form', [self::class, 'accountCheckbox']);
            add_action('woocommerce_save_account_details', [self::class, 'accountSaved'], 20);
        }

        if (Settings::enabled('consent_checkout')) {
            add_action('woocommerce_review_order_before_submit', [self::class, 'classicCheckbox']);
            add_action('woocommerce_checkout_create_order', [self::class, 'classicSaved']);
            add_action('woocommerce_init', [self::class, 'blockField']);
        }
    }

    /**
     * Customer of a user account with the store's billing data.
     */
    public static function fromUser(int $userId): Customer
    {
        $wc = new \WC_Customer($userId);
        $user = get_userdata($userId);

        return (new Customer((string) $userId, Connection::anonymousId()))
            ->email($wc->get_billing_email() ?: ($user ? $user->user_email : null))
            ->phone($wc->get_billing_phone())
            ->name($wc->get_billing_first_name() ?: $wc->get_first_name(), $wc->get_billing_last_name() ?: $wc->get_last_name())
            ->city($wc->get_billing_city())
            ->country($wc->get_billing_country())
            ->language(determine_locale());
    }

    /**
     * Customer of an order: the account if there is one, otherwise a guest found by email and phone.
     */
    public static function fromOrder(\WC_Order $order): Customer
    {
        $userId = $order->get_customer_id();
        $anonymousId = $order->get_meta('_onetrace_anonymous_id');

        return (new Customer($userId ? (string) $userId : null, \is_string($anonymousId) && $anonymousId !== '' ? $anonymousId : null))
            ->email($order->get_billing_email())
            ->phone($order->get_billing_phone())
            ->name($order->get_billing_first_name(), $order->get_billing_last_name())
            ->city($order->get_billing_city())
            ->country($order->get_billing_country());
    }

    /**
     * The newsletter checkbox of the order, if the customer ticked it (classic or block checkout).
     *
     * @return list<array{channel: string, status: string, topic?: string}>
     */
    public static function orderConsents(\WC_Order $order): array
    {
        $ticked = \in_array($order->get_meta(self::ORDER_META), ['1', 'yes', true, 1], true)
            || \in_array($order->get_meta('_wc_other/' . self::CHECKOUT_FIELD), ['1', 'yes', true, 1], true);

        return $ticked ? [Messages::subscribed('email', Settings::consentTopic())] : [];
    }

    /**
     * @param int|string $userId
     */
    public static function created($userId): void
    {
        $consents = [];

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the registration form is verified by WooCommerce.
        if (Settings::enabled('consent_registration') && !empty($_POST['onetrace_marketing_consent'])) {
            $consents[] = Messages::subscribed('email', Settings::consentTopic());
            update_user_meta((int) $userId, self::USER_META, 'yes');
        }

        Queue::event(Connection::messages()->identify(self::fromUser((int) $userId), $consents));
    }

    /**
     * @param string $login
     * @param mixed $user
     */
    public static function loggedIn($login, $user): void
    {
        if ($user instanceof \WP_User && self::isCustomer($user)) {
            Queue::event(Connection::messages()->identify(self::fromUser($user->ID)));
        }
    }

    /**
     * @param int|string $userId
     */
    public static function updated($userId): void
    {
        $user = get_userdata((int) $userId);

        if ($user instanceof \WP_User && self::isCustomer($user) && !did_action('woocommerce_created_customer')) {
            Queue::event(Connection::messages()->identify(self::fromUser($user->ID)));
        }
    }

    public static function registrationCheckbox(): void
    {
        self::checkbox('onetrace_marketing_consent', false);
    }

    public static function accountCheckbox(): void
    {
        self::checkbox('onetrace_marketing_consent', get_user_meta(get_current_user_id(), self::USER_META, true) === 'yes');
        echo '<input type="hidden" name="onetrace_marketing_consent_shown" value="1">';
    }

    /**
     * Subscribing and unsubscribing on the account details page.
     *
     * @param int|string $userId
     */
    public static function accountSaved($userId): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the account form is verified by WooCommerce.
        if (empty($_POST['onetrace_marketing_consent_shown'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $subscribed = !empty($_POST['onetrace_marketing_consent']);
        $was = get_user_meta((int) $userId, self::USER_META, true) === 'yes';

        if ($subscribed === $was) {
            return;
        }

        update_user_meta((int) $userId, self::USER_META, $subscribed ? 'yes' : 'no');
        $consent = $subscribed ? Messages::subscribed('email', Settings::consentTopic()) : Messages::unsubscribed('email', Settings::consentTopic());
        Queue::event(Connection::messages()->identify(self::fromUser((int) $userId), [$consent]));
    }

    public static function classicCheckbox(): void
    {
        self::checkbox('onetrace_marketing_consent', false);
    }

    public static function classicSaved(\WC_Order $order): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the checkout is verified by WooCommerce.
        if (!empty($_POST['onetrace_marketing_consent'])) {
            $order->update_meta_data(self::ORDER_META, 'yes');
        }
    }

    /**
     * The checkbox of the Checkout block (additional checkout fields API, WooCommerce 8.9+).
     */
    public static function blockField(): void
    {
        if (!\function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }

        woocommerce_register_additional_checkout_field([
            'id' => self::CHECKOUT_FIELD,
            'label' => Settings::consentLabel(),
            'location' => 'order',
            'type' => 'checkbox',
            'required' => false,
        ]);
    }

    private static function checkbox(string $name, bool $checked): void
    {
        printf(
            '<p class="form-row onetrace-consent"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="%1$s" value="1"%2$s> <span>%3$s</span></label></p>',
            esc_attr($name),
            $checked ? ' checked' : '',
            esc_html(Settings::consentLabel())
        );
    }

    private static function isCustomer(\WP_User $user): bool
    {
        return !user_can($user, 'manage_woocommerce');
    }
}
