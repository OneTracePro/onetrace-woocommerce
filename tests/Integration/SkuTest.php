<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\WooCommerce\Settings;
use OneTrace\WooCommerce\Storefront;
use OneTrace\WooCommerce\Tests\TestCase;

/**
 * "Product id: SKU" — the catalog and the history of the project live under SKUs.
 */
final class SkuTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        update_option(Settings::OPTION, ['product_id' => 'sku'] + (array) get_option(Settings::OPTION));
    }

    public function testSendsSkusInOrdersTheCatalogAndDeletions(): void
    {
        $sku = 'WOM-' . wp_rand(10000, 99999);
        $product = $this->product(['sku' => $sku]);
        $order = $this->order($product, 1);
        do_action('woocommerce_checkout_order_processed', $order->get_id(), [], $order);
        $this->flush();

        $upload = array_values(array_filter($this->platform->requests, static function (array $request): bool {
            return $request['method'] === 'POST' && $request['path'] === '/api/v1/products';
        }));

        self::assertSame($sku, $this->platform->events('order_completed')[0]['properties']['products'][0]['product_id']);
        self::assertSame($sku, $upload[0]['body']['items'][0]['id']);

        wp_trash_post($product->get_id());
        $this->flush();

        self::assertSame(['DELETE', ['ids' => [$sku]]], [end($this->platform->requests)['method'], end($this->platform->requests)['body']]);
    }

    public function testFallsBackToThePostIdWithoutASkuAndShowsSkusOnTheStorefront(): void
    {
        $without = $this->product();
        $with = $this->product(['sku' => 'SHO-' . wp_rand(10000, 99999)]);

        self::assertSame((string) $without->get_id(), \OneTrace\WooCommerce\Products::id($without));
        self::assertSame('<div class="onetrace-widget" data-cdp-widget="k3x9" data-item="' . $with->get_sku() . '"></div>', Storefront::widgetShortcode(['id' => 'k3x9', 'item' => \OneTrace\WooCommerce\Products::id($with)]));
    }
}
