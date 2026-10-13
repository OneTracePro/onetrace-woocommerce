<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce;

use OneTrace\Exception\AuthenticationException;
use OneTrace\Exception\NotFoundException;

/**
 * Promo codes of OneTrace pools as WooCommerce coupons: a pool is bound to a template coupon of the store (setting
 * "promo_pools": "pool_key = TEMPLATE-CODE" per line). Every 5 minutes new and changed codes of the pool come by the
 * cursor of the platform and become coupons with the discount, restrictions and limits of the template, one use each
 * and the expiry of the code. The platform counts the use from the coupon of the order.
 */
final class PromoSync
{
    public const HOOK = 'onetrace_promo_sync';

    private const CURSORS = 'onetrace_promo_cursors';

    /** Coupons created per pool and run; the rest come with the next run. */
    private const BATCH = 1000;

    private const PAGE = 250;

    /** Meta of a coupon created from a pool: the pool key. */
    public const META = '_onetrace_pool';

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'run']);
    }

    /**
     * Pools bound to template coupons: pool key → template coupon code.
     *
     * @return array<string, string>
     */
    public static function pools(): array
    {
        $pools = [];

        foreach (preg_split('/\R/', (string) Settings::get('promo_pools')) ?: [] as $line) {
            if (preg_match('/^\s*([a-z0-9_]{1,64})\s*=\s*(\S.*?)\s*$/', $line, $match) === 1) {
                $pools[$match[1]] = $match[2];
            }
        }

        return $pools;
    }

    public static function schedule(): void
    {
        if (!\function_exists('as_has_scheduled_action')) {
            return;
        }

        $scheduled = as_has_scheduled_action(self::HOOK, null, Queue::GROUP);

        if (Settings::server() && self::pools() !== [] && !$scheduled) {
            as_schedule_recurring_action(time() + 60, 300, self::HOOK, [], Queue::GROUP);
        } elseif ((!Settings::server() || self::pools() === []) && $scheduled) {
            as_unschedule_all_actions(self::HOOK, [], Queue::GROUP);
        }
    }

    public static function run(): void
    {
        self::synchronize();
    }

    /**
     * @return array<string, int> pool key → coupons created or updated
     */
    public static function synchronize(): array
    {
        if (!Settings::server() || get_option(Queue::PAUSED_OPTION)) {
            return [];
        }

        $cursors = get_option(self::CURSORS, []);
        $cursors = \is_array($cursors) ? $cursors : [];
        $done = [];

        foreach (self::pools() as $pool => $template) {
            $templateId = wc_get_coupon_id_by_code($template);

            if ($templateId <= 0) {
                Queue::log(sprintf('promo %s: no template coupon %s', $pool, $template));

                continue;
            }

            try {
                $coupon = new \WC_Coupon($templateId);
                [$done[$pool], $cursors[$pool]] = self::sync($pool, $coupon, isset($cursors[$pool]) ? (string) $cursors[$pool] : null);
            } catch (AuthenticationException $error) {
                Queue::log('promo: ' . $error->getMessage());

                break;
            } catch (NotFoundException $error) {
                Queue::log(sprintf('promo %s: no such pool', $pool));
            } catch (\Throwable $error) {
                Queue::log(sprintf('promo %s: %s', $pool, $error->getMessage()));
            }

            update_option(self::CURSORS, $cursors, false);
        }

        return $done;
    }

    /**
     * @return array{0: int, 1: string|null} coupons changed and the cursor to continue from
     */
    private static function sync(string $pool, \WC_Coupon $template, ?string $cursor): array
    {
        $changed = 0;

        do {
            $page = Connection::client()->promo()->codes($pool, $cursor, self::PAGE);
            $codes = isset($page['data']) && \is_array($page['data']) ? $page['data'] : [];

            foreach ($codes as $code) {
                if (\is_array($code) && \is_string($code['code'] ?? null) && ($code['status'] ?? '') !== 'shared') {
                    $changed += self::coupon($pool, $template, $code) ? 1 : 0;
                }
            }

            $cursor = isset($page['next']) && \is_string($page['next']) ? $page['next'] : $cursor;
        } while (\count($codes) >= self::PAGE && $changed < self::BATCH);

        return [$changed, $cursor];
    }

    /**
     * Creates the coupon of a code from the template or updates its expiry.
     *
     * @param array<mixed> $code
     */
    private static function coupon(string $pool, \WC_Coupon $template, array $code): bool
    {
        $value = (string) $code['code'];
        $expires = \is_string($code['expires_at'] ?? null) ? strtotime($code['expires_at']) : false;
        $existing = wc_get_coupon_id_by_code($value);

        if ($existing > 0) {
            $coupon = new \WC_Coupon($existing);

            if ($coupon->get_meta(self::META) !== $pool) {
                // A coupon of the store with the same code is left as it is.
                return false;
            }

            $coupon->set_date_expires($expires !== false ? $expires : self::expiry($template));
            $coupon->save();

            return true;
        }

        $coupon = new \WC_Coupon();
        $coupon->set_props([
            'code' => $value,
            'description' => sprintf('OneTrace: %s', $pool),
            'discount_type' => $template->get_discount_type(),
            'amount' => $template->get_amount(),
            'free_shipping' => $template->get_free_shipping(),
            'individual_use' => $template->get_individual_use(),
            'exclude_sale_items' => $template->get_exclude_sale_items(),
            'product_ids' => $template->get_product_ids(),
            'excluded_product_ids' => $template->get_excluded_product_ids(),
            'product_categories' => $template->get_product_categories(),
            'excluded_product_categories' => $template->get_excluded_product_categories(),
            'minimum_amount' => $template->get_minimum_amount(),
            'maximum_amount' => $template->get_maximum_amount(),
            'limit_usage_to_x_items' => $template->get_limit_usage_to_x_items(),
            'usage_limit' => 1,
            'usage_limit_per_user' => 1,
            'date_expires' => $expires !== false ? $expires : self::expiry($template),
        ]);
        $coupon->update_meta_data(self::META, $pool);
        $coupon->save();

        return $coupon->get_id() > 0;
    }

    private static function expiry(\WC_Coupon $template): ?int
    {
        $date = $template->get_date_expires();

        return $date !== null ? $date->getTimestamp() : null;
    }
}
