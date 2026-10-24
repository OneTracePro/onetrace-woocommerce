<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\WooCommerce\Cart;
use OneTrace\WooCommerce\Customers;
use OneTrace\WooCommerce\Settings;
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

    public function testShowsTheConsentCheckboxOfTheClassicCheckoutOutsideTheRedrawnOrderReview(): void
    {
        ob_start();
        do_action('woocommerce_after_checkout_billing_form', WC()->checkout());
        $billing = (string) ob_get_clean();
        ob_start();
        do_action('woocommerce_review_order_before_submit');
        $review = (string) ob_get_clean();

        self::assertStringContainsString('name="onetrace_marketing_consent"', $billing);
        self::assertStringNotContainsString('onetrace_marketing_consent', $review);
    }

    public function testRegistersTheConsentCheckboxOfTheCheckoutBlock(): void
    {
        $fields = \Automattic\WooCommerce\Blocks\Package::container()->get(\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class);

        self::assertArrayHasKey(Customers::CHECKOUT_FIELD, $fields->get_additional_fields());
    }

    public function testMarksThePlaceOfTheNotifyFormOnThePageOfAProductOutOfStockOnce(): void
    {
        global $product;

        $product = $this->product(['stock_status' => 'outofstock']);
        ob_start();
        Storefront::notifyForm();
        $html = (string) ob_get_clean();

        self::assertSame('<div class="onetrace-notify" data-cdp-notify="' . $product->get_id() . '"></div>', $html);
        self::assertSame('<form></form>', Storefront::notifyBlock('<form></form>'), 'The block of the same product adds nothing again.');
        self::assertSame('', Storefront::notify($this->product()), 'In stock.');
        self::assertSame('', Storefront::notify($this->product(['stock_status' => 'onbackorder'])), 'On backorder.');

        $GLOBALS['post'] = get_post($this->product(['stock_status' => 'outofstock'])->get_id());
        self::assertStringContainsString('data-cdp-notify="' . $GLOBALS['post']->ID . '"', (string) Storefront::notifyBlock('<form></form>'), 'Block themes: after the add to cart block.');
        unset($GLOBALS['post'], $product);
    }

    public function testAVariableProductNeedsAVariationInStockAndTheIdIsEscaped(): void
    {
        update_option(Settings::OPTION, ['product_id' => 'sku'] + (array) get_option(Settings::OPTION));
        $product = new \WC_Product_Variable();
        $product->set_props(['name' => 'Runner', 'status' => 'publish', 'sku' => 'R"<' . wp_rand()]);
        $product->save();
        $this->remember($product->get_id());

        foreach (['outofstock', 'instock'] as $status) {
            $variation = new \WC_Product_Variation();
            $variation->set_props(['parent_id' => $product->get_id(), 'regular_price' => '59.00', 'stock_status' => $status]);
            $variation->save();
        }

        self::assertSame('', Storefront::notify(wc_get_product($product->get_id())));

        $variation->set_stock_status('outofstock');
        $variation->save();

        $html = Storefront::notify(wc_get_product($product->get_id()));

        self::assertSame('<div class="onetrace-notify" data-cdp-notify="' . esc_attr($product->get_sku()) . '"></div>', $html);
        self::assertStringContainsString('data-cdp-notify="R&quot;&lt;', $html);
    }

    public function testPlacesTheAskAiBlockOncePerProductWithTheProductIdOfTheEvents(): void
    {
        global $product;

        $product = $this->product();
        ob_start();
        Storefront::askForm();
        $html = (string) ob_get_clean();

        self::assertSame('<div class="onetrace-ask" data-cdp-ask data-product-id="' . $product->get_id() . '"></div>', $html);
        self::assertSame('<form></form>', Storefront::askBlock('<form></form>'), 'The block of the same product adds nothing again.');
        self::assertSame('', Storefront::askShortcode([]), 'Nor the shortcode.');

        $other = $this->product();
        self::assertSame('<div class="onetrace-ask" data-cdp-ask data-product-id="' . $other->get_id() . '"></div>', Storefront::askShortcode(['product' => (string) $other->get_id()]));
        self::assertSame('', Storefront::askShortcode(['product' => '999999999']), 'No such product.');

        $GLOBALS['post'] = get_post($this->product()->get_id());
        self::assertStringContainsString('data-cdp-ask data-product-id="' . $GLOBALS['post']->ID . '"', (string) Storefront::askBlock('<form></form>'), 'Block themes: after the add to cart block.');
        unset($GLOBALS['post'], $GLOBALS['product']);
    }

    public function testTheAskAiBlockOfAVariationIsThePlaceOfItsParentUnderItsSku(): void
    {
        update_option(Settings::OPTION, ['product_id' => 'sku'] + (array) get_option(Settings::OPTION));
        $parent = new \WC_Product_Variable();
        $parent->set_props(['name' => 'Runner', 'status' => 'publish', 'sku' => 'R"<' . wp_rand()]);
        $parent->save();
        $this->remember($parent->get_id());
        $variation = new \WC_Product_Variation();
        $variation->set_props(['parent_id' => $parent->get_id(), 'regular_price' => '59.00', 'sku' => 'V-' . wp_rand()]);
        $variation->save();

        $html = Storefront::ask(wc_get_product($variation->get_id()));

        self::assertSame('<div class="onetrace-ask" data-cdp-ask data-product-id="' . esc_attr($parent->get_sku()) . '"></div>', $html);
        self::assertSame('', Storefront::ask(wc_get_product($parent->get_id())), 'The parent is already on the page.');
    }

    public function testTheAskAiSettingTurnsTheBlockOffButNotTheShortcode(): void
    {
        $callback = [Storefront::class, 'askForm'];
        remove_action('woocommerce_single_product_summary', $callback, 36);

        update_option(Settings::OPTION, ['ask_ai' => 'no'] + (array) get_option(Settings::OPTION));
        Storefront::register();
        self::assertFalse(has_action('woocommerce_single_product_summary', $callback));
        self::assertTrue(shortcode_exists('onetrace_ask'));

        update_option(Settings::OPTION, ['ask_ai' => 'yes'] + (array) get_option(Settings::OPTION));
        Storefront::register();
        self::assertSame(36, has_action('woocommerce_single_product_summary', $callback), 'After the add to cart form and the notify form.');
        self::assertSame(11, has_filter('render_block_woocommerce/add-to-cart-with-options', [Storefront::class, 'askBlock']));
    }

    public function testTheNotifySettingTurnsTheFormOff(): void
    {
        $callback = [Storefront::class, 'notifyForm'];
        remove_action('woocommerce_single_product_summary', $callback, 35);

        update_option(Settings::OPTION, ['notify' => 'no'] + (array) get_option(Settings::OPTION));
        Storefront::register();
        self::assertFalse(has_action('woocommerce_single_product_summary', $callback));

        update_option(Settings::OPTION, ['notify' => 'yes'] + (array) get_option(Settings::OPTION));
        Storefront::register();
        self::assertSame(35, has_action('woocommerce_single_product_summary', $callback));
        self::assertSame(10, has_filter('render_block_woocommerce/add-to-cart-form', [Storefront::class, 'notifyBlock']));
    }
}
