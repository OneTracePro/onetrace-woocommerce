<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\WooCommerce\CartLinks;
use OneTrace\WooCommerce\Settings;
use OneTrace\WooCommerce\Tests\TestCase;

/**
 * Cart links of the OneTrace AI assistants: ?onetrace_cart=ID:QTY,… fills the cart and redirects to it with the
 * other parameters of the link.
 */
final class CartLinksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wc_load_cart();
        WC()->cart->empty_cart();
        wc_clear_notices();
    }

    protected function tearDown(): void
    {
        WC()->cart->empty_cart();
        wc_clear_notices();
        parent::tearDown();
    }

    /**
     * A variable product with sizes 40 and 41 and a variation of size 40; $any — a second variation for any size.
     *
     * @return array{0: \WC_Product_Variable, 1: \WC_Product_Variation, 2: \WC_Product_Variation}
     */
    private function variable(array $variation = []): array
    {
        $attribute = new \WC_Product_Attribute();
        $attribute->set_name('Size');
        $attribute->set_options(['40', '41']);
        $attribute->set_variation(true);
        $variable = new \WC_Product_Variable();
        $variable->set_props(['name' => 'Runner', 'status' => 'publish', 'attributes' => [$attribute]]);
        $variable->save();
        $this->remember($variable->get_id());

        $size = new \WC_Product_Variation();
        $size->set_props($variation + ['parent_id' => $variable->get_id(), 'regular_price' => '59.00', 'attributes' => ['size' => '40']]);
        $size->save();
        $this->remember($size->get_id());

        $any = new \WC_Product_Variation();
        $any->set_props(['parent_id' => $variable->get_id(), 'regular_price' => '59.00', 'attributes' => ['size' => '']]);
        $any->save();
        $this->remember($any->get_id());
        \WC_Product_Variable::sync($variable->get_id());

        return [$variable, $size, $any];
    }

    /**
     * Cart lines: [product_id, variation_id, quantity, variation attributes].
     *
     * @return list<array{0: int, 1: int, 2: int, 3: array<string, string>}>
     */
    private function lines(): array
    {
        return array_values(array_map(static function (array $item): array {
            return [(int) $item['product_id'], (int) $item['variation_id'], (int) $item['quantity'], $item['variation']];
        }, WC()->cart->get_cart()));
    }

    public function testAddsSeveralProductsAndAVariationWithItsAttributes(): void
    {
        $sneakers = $this->product();
        $socks = $this->product(['name' => 'Socks', 'regular_price' => '5.00']);
        [$runner, $size, $any] = $this->variable();

        $added = CartLinks::add(sprintf('%d:2,%d:1,%d:3,%d:1,%d:1', $sneakers->get_id(), $socks->get_id(), $size->get_id(), $runner->get_id(), $any->get_id()));

        self::assertSame(3, $added, 'A variable product without a variation and an "any" variation are skipped.');
        self::assertSame([
            [$sneakers->get_id(), 0, 2, []],
            [$socks->get_id(), 0, 1, []],
            [$runner->get_id(), $size->get_id(), 3, ['attribute_size' => '40']],
        ], $this->lines());
    }

    public function testTheSkuModeFindsProductsAndVariationsBySku(): void
    {
        update_option(Settings::OPTION, ['product_id' => 'sku'] + (array) get_option(Settings::OPTION));
        $suffix = (string) wp_rand(10000, 99999);
        $shirt = $this->product(['name' => 'Shirt', 'sku' => 'SHIRT:' . $suffix]);
        $withoutSku = $this->product(['name' => 'Cap']);
        [$runner, $size] = $this->variable(['sku' => 'RUN-40-' . $suffix]);

        // A SKU with a colon: the pair is split on the last one.
        self::assertSame(3, CartLinks::add('SHIRT:' . $suffix . ':2,RUN-40-' . $suffix . ':1,' . $withoutSku->get_id() . ':4'));
        self::assertSame([
            [$shirt->get_id(), 0, 2, []],
            [$runner->get_id(), $size->get_id(), 1, ['attribute_size' => '40']],
            [$withoutSku->get_id(), 0, 4, []],
        ], $this->lines());
    }

    public function testSkipsUnknownUnpublishedOutOfStockNotPurchasableAndOverTheStock(): void
    {
        $sneakers = $this->product();
        $draft = $this->product(['status' => 'draft']);
        $outOfStock = $this->product(['stock_status' => 'outofstock']);
        $noPrice = $this->product(['regular_price' => '']);
        $limited = $this->product(['manage_stock' => true, 'stock_quantity' => 2]);
        [, $disabled] = $this->variable(['status' => 'private']);
        [, $soldOut] = $this->variable(['stock_status' => 'outofstock']);

        $added = CartLinks::add(implode(',', [
            '999999999:1',
            $draft->get_id() . ':1',
            $outOfStock->get_id() . ':1',
            $noPrice->get_id() . ':1',
            $limited->get_id() . ':5',
            $disabled->get_id() . ':1',
            $soldOut->get_id() . ':1',
            $sneakers->get_id() . ':1',
        ]));

        self::assertSame(1, $added);
        self::assertSame([[$sneakers->get_id(), 0, 1, []]], $this->lines(), 'Only what is for sale, within the stock.');

        self::assertSame(0, CartLinks::add($limited->get_id() . ':3,' . $outOfStock->get_id() . ':1'));
        self::assertSame(1, CartLinks::add($limited->get_id() . ':2'), 'The stock allows two.');
    }

    public function testReadsAtMostTwentyPairsAndClampsTheQuantity(): void
    {
        self::assertSame([['7', 1], ['8', 99], ['9', 1], ['10', 5], ['A:B', 2]], CartLinks::parse(' 7:0, 8:150 ,9:-3,,10:5,A:B:2,'));
        self::assertSame([], CartLinks::parse('7,8:x,9:1.5,:2,10:'), 'No quantity, not an integer, no id.');
        self::assertSame([], CartLinks::parse(''));

        $pairs = array_map(static function (int $i): string {
            return $i . ':1';
        }, range(1, 25));

        self::assertCount(CartLinks::MAX_ITEMS, CartLinks::parse(implode(',', $pairs)));
        self::assertSame('20', CartLinks::parse(implode(',', $pairs))[19][0]);

        $sneakers = $this->product();
        self::assertSame(0, CartLinks::add(str_repeat('999999999:1,', 20) . $sneakers->get_id() . ':1'), 'The 21st pair is not read.');
        self::assertSame(1, CartLinks::add(str_repeat('999999999:1,', 19) . $sneakers->get_id() . ':1'));
        self::assertSame([[$sneakers->get_id(), 0, 1, []]], $this->lines());
    }

    public function testRedirectsToTheCartWithTheOtherParametersOrBackWithoutTheCartParameter(): void
    {
        $cart = wc_get_cart_url();
        $link = '/?utm_source=chatgpt&onetrace_cart=12%3A2%2C13%3A1&utm_medium=ai_assistant&utm_campaign=shopper_mcp';

        self::assertSame(add_query_arg(['utm_source' => 'chatgpt', 'utm_medium' => 'ai_assistant', 'utm_campaign' => 'shopper_mcp'], $cart), CartLinks::location($link, true));
        self::assertSame($cart, CartLinks::location('/shop/?onetrace_cart=12:2', true));
        self::assertSame(add_query_arg('gclid', 'a+b', $cart), CartLinks::location('/shop/?onetrace_cart=12:2&gclid=a%20b', true));

        self::assertSame('/?utm_source=chatgpt&utm_medium=ai_assistant&utm_campaign=shopper_mcp', CartLinks::location($link, false));
        self::assertSame('/shop/', CartLinks::location('/shop/?onetrace_cart=12:2', false));
        self::assertSame('/evil.example/', CartLinks::location('//evil.example/?onetrace_cart=12:2', false), 'Never another host.');
    }

    public function testTheStorefrontRedirectsAGuestToTheCartNotCached(): void
    {
        $sneakers = $this->product();
        $utm = ['utm_source' => 'chatgpt', 'utm_medium' => 'ai_assistant', 'utm_campaign' => 'shopper_mcp'];
        $link = 'http://localhost/?utm_source=chatgpt&onetrace_cart=' . rawurlencode($sneakers->get_id() . ':2') . '&utm_medium=ai_assistant&utm_campaign=shopper_mcp';

        $response = wp_remote_get($link, ['redirection' => 0]);
        $cookies = [];

        foreach (wp_remote_retrieve_cookies($response) as $cookie) {
            $cookies[strpos($cookie->name, 'wp_woocommerce_session_') === 0 ? 'session' : $cookie->name] = $cookie->value;
        }

        self::assertSame(302, wp_remote_retrieve_response_code($response));
        self::assertSame(add_query_arg($utm, wc_get_cart_url()), wp_remote_retrieve_header($response, 'location'));
        self::assertStringContainsString('no-cache', (string) wp_remote_retrieve_header($response, 'cache-control'));
        self::assertArrayHasKey('session', $cookies, 'The guest keeps the cart in the WooCommerce session.');
        self::assertSame('1', $cookies['woocommerce_items_in_cart'] ?? null);

        // Nothing to add: the same page without the parameter.
        $nothing = wp_remote_get('http://localhost/?p=1&onetrace_cart=999999999:1&utm_source=chatgpt', ['redirection' => 0]);
        self::assertSame(302, wp_remote_retrieve_response_code($nothing));
        self::assertSame('/?p=1&utm_source=chatgpt', wp_remote_retrieve_header($nothing, 'location'));
        self::assertStringContainsString('no-cache', (string) wp_remote_retrieve_header($nothing, 'cache-control'));
    }

    public function testOnlyStorefrontGetRequests(): void
    {
        $sneakers = $this->product();
        $param = 'onetrace_cart=' . $sneakers->get_id() . ':1';

        $post = wp_remote_post('http://localhost/?' . $param, ['redirection' => 0]);
        $rest = wp_remote_get('http://localhost/?rest_route=/wc/store/v1/cart&' . $param, ['redirection' => 0]);
        $ajax = wp_remote_get('http://localhost/?wc-ajax=get_refreshed_fragments&' . $param, ['redirection' => 0]);

        self::assertNotSame(302, wp_remote_retrieve_response_code($post));
        self::assertSame(200, wp_remote_retrieve_response_code($rest));
        self::assertSame(200, wp_remote_retrieve_response_code($ajax));
    }
}
