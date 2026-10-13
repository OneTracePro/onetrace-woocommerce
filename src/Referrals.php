<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Exception\NotFoundException;
use OneTrace\Exception\ValidationException;
use OneTrace\Identity;

/**
 * Referral program of OneTrace: on registration the friend's code — from the "Friend's code" field (setting
 * "referral_field") or from the cdp_ref cookie of the tracker (a ?ref= link) — goes to the platform, which gives the
 * friend the welcome reward. The call waits for the identify of the new customer: the platform may not know the
 * profile yet (404 — repeated).
 */
final class Referrals
{
    public const HOOK = 'onetrace_referral';

    public const FIELD = 'onetrace_referral_code';

    private const COOKIE = 'cdp_ref';

    private const ATTEMPTS = 5;

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'send'], 10, 3);

        if (!Loyalty::enabled()) {
            return;
        }

        add_action('woocommerce_created_customer', [self::class, 'created'], 30);

        if (Settings::enabled('referral_field')) {
            add_action('woocommerce_register_form', [self::class, 'field'], 15);
        }
    }

    public static function field(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the registration form is verified by WooCommerce.
        $value = isset($_POST[self::FIELD]) ? sanitize_text_field(wp_unslash((string) $_POST[self::FIELD])) : (self::cookie() ?? '');

        printf(
            '<p class="form-row form-row-wide"><label for="%1$s">%2$s <span class="optional">%3$s</span></label><input type="text" class="input-text" name="%1$s" id="%1$s" value="%4$s" maxlength="16" autocomplete="off"></p>',
            esc_attr(self::FIELD),
            esc_html__("Friend's code", 'onetrace-for-woocommerce'),
            esc_html__('(optional)', 'onetrace-for-woocommerce'),
            esc_attr($value)
        );
    }

    /**
     * @param int|string $userId
     */
    public static function created($userId): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the registration form is verified by WooCommerce.
        $typed = Settings::enabled('referral_field') && isset($_POST[self::FIELD]) ? sanitize_text_field(wp_unslash((string) $_POST[self::FIELD])) : '';
        $code = self::normalize($typed) ?? self::cookie();

        if ($code !== null && \function_exists('as_schedule_single_action')) {
            // After the identify of the new customer leaves the queue.
            as_schedule_single_action(time() + 30, self::HOOK, [(string) $userId, $code, 1], Queue::GROUP);
        }
    }

    /**
     * @param int|string $userId
     * @param int|string $attempt
     */
    public static function send($userId, string $code, $attempt = 1): void
    {
        if (!Settings::server()) {
            return;
        }

        try {
            Connection::client()->loyalty()->refer(Identity::userId((string) $userId), $code, 'wc-referral-' . $userId . '-' . $attempt);
        } catch (ValidationException $error) {
            // Own code, an existing customer, referrals off — nothing to repeat.
            Queue::log(sprintf('referral %s: %s', $userId, $error->getMessage()));
        } catch (NotFoundException $error) {
            self::retry($userId, $code, (int) $attempt);
        } catch (\Throwable $error) {
            Queue::log(sprintf('referral %s: %s', $userId, $error->getMessage()));
            self::retry($userId, $code, (int) $attempt);
        }
    }

    /**
     * @param int|string $userId
     */
    private static function retry($userId, string $code, int $attempt): void
    {
        if ($attempt < self::ATTEMPTS && \function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + 60 * $attempt, self::HOOK, [(string) $userId, $code, $attempt + 1], Queue::GROUP);
        }
    }

    private static function cookie(): ?string
    {
        return isset($_COOKIE[self::COOKIE]) ? self::normalize(sanitize_text_field(wp_unslash((string) $_COOKIE[self::COOKIE]))) : null;
    }

    private static function normalize(string $code): ?string
    {
        $code = strtoupper(trim($code));

        return preg_match('/^[A-Z0-9]{4,16}$/', $code) === 1 ? $code : null;
    }
}
