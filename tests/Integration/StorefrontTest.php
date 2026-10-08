<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\WooCommerce\Cart;
use OneTrace\WooCommerce\Customers;
use OneTrace\WooCommerce\Storefront;
use OneTrace\WooCommerce\Tests\TestCase;

final class StorefrontTest extends TestCase
{
    public function testPrintsTheTrackerWithTheWriteKeyAndThePageLanguage(): void
    {
        ob_start();
        Storefront::tracker();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('"https:\/\/cdp.example.com\/tracker\/cdp.js"', $html);
        self::assertStringContainsString('cdp.init("cdp_wk_test", {"host":"https://cdp.example.com","language":"page"})', $html);
        self::assertStringContainsString('"setLanguage"', $html);
        self::assertStringNotContainsString('cdp_sk_test', $html);
    }

    public function testSendsCartEventsOnlyForVisitorsTheProfileCanBeLinkedTo(): void
    {
        $product = $this->product();

        Cart::added('key', $product->get_id(), 1);
        $_COOKIE['cdp_aid'] = 'browser-1';
        Cart::added('key', $product->get_id(), 3);
        $this->flush();

        $added = $this->platform->events('add_to_cart');

        self::assertCount(1, $added);
        self::assertSame(['anonymousId' => 'browser-1'], array_intersect_key($added[0], ['anonymousId' => 1, 'userId' => 1]));
        self::assertSame(3, $added[0]['properties']['quantity']);
        self::assertContract($added[0]);
    }

    public function testRendersWidgetsWithTheProductsOfThePage(): void
    {
        self::assertSame('<div class="onetrace-widget" data-cdp-widget="k3x9" data-item="17"></div>', Storefront::widgetShortcode(['id' => 'k3x9', 'item' => '17']));
        self::assertSame('', Storefront::widgetShortcode(['id' => '"><script>']));
    }

    public function testRegistersTheConsentCheckboxOfTheCheckoutBlock(): void
    {
        $fields = \Automattic\WooCommerce\Blocks\Package::container()->get(\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class);

        self::assertArrayHasKey(Customers::CHECKOUT_FIELD, $fields->get_additional_fields());
    }
}
