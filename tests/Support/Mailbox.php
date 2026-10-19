<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Support;

/**
 * Emails WordPress would send itself (pre_wp_mail, last): recorded, never sent from the test container.
 */
final class Mailbox
{
    /** @var list<array<string, mixed>> arguments of wp_mail() */
    public static $sent = [];

    /**
     * @param mixed $return
     * @param mixed $atts
     *
     * @return mixed
     */
    public static function capture($return, $atts)
    {
        if ($return === null && \is_array($atts)) {
            self::$sent[] = $atts;
        }

        return $return ?? false;
    }

    /**
     * Native emails to the address.
     *
     * @return list<array<string, mixed>>
     */
    public static function to(string $address): array
    {
        return array_values(array_filter(self::$sent, static function (array $atts) use ($address): bool {
            $to = \is_array($atts['to']) ? implode(',', $atts['to']) : (string) $atts['to'];

            return stripos($to, $address) !== false;
        }));
    }
}
