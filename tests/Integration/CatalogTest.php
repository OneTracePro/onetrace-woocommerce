<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\WooCommerce\Tests\TestCase;

final class CatalogTest extends TestCase
{
    public function testUploadsAVariableProductOnceWithItsCategoryTreeAndRemovesItWhenTrashed(): void
    {
        $parent = wp_insert_term('Shoes ' . wp_rand(), 'product_cat');
        $child = wp_insert_term('Sneakers ' . wp_rand(), 'product_cat', ['parent' => $parent['term_id']]);

        $attribute = new \WC_Product_Attribute();
        $attribute->set_name('Size');
        $attribute->set_options(['40', '41']);
        $attribute->set_visible(true);
        $attribute->set_variation(true);

        $product = new \WC_Product_Variable();
        $product->set_props(['name' => 'Runner', 'status' => 'publish', 'category_ids' => [$parent['term_id'], $child['term_id']], 'attributes' => [$attribute]]);
        $product->save();
        $this->remember($product->get_id());

        foreach (['40' => '59.00', '41' => '49.00'] as $size => $price) {
            $variation = new \WC_Product_Variation();
            $variation->set_props(['parent_id' => $product->get_id(), 'regular_price' => $price, 'attributes' => ['size' => $size]]);
            $variation->save();
        }

        \WC_Product_Variable::sync($product->get_id());
        $this->flush();

        $upload = array_values(array_filter($this->platform->requests, static function (array $request): bool {
            return $request['method'] === 'POST' && $request['path'] === '/api/v1/products';
        }));
        $item = end($upload)['body']['items'][0];

        self::assertSame((string) $product->get_id(), $item['id']);
        self::assertCount(1, end($upload)['body']['items'], 'Variations are not separate products.');
        self::assertSame(49.0, $item['price']);
        self::assertSame([(string) $child['term_id'], (string) $parent['term_id']], $item['category_ids'], 'The deepest category first.');
        self::assertSame('40, 41', $item['params']['Size']);
        self::assertContains(['id' => (string) $child['term_id'], 'name' => get_term($child['term_id'])->name, 'parent_id' => (string) $parent['term_id']], end($upload)['body']['categories']);

        wp_trash_post($product->get_id());
        $this->flush();

        $last = end($this->platform->requests);
        self::assertSame(['DELETE', ['ids' => [(string) $product->get_id()]]], [$last['method'], $last['body']]);

        wp_delete_term($child['term_id'], 'product_cat');
        wp_delete_term($parent['term_id'], 'product_cat');
    }

    public function testMarksHiddenAndOutOfStockProductsUnavailable(): void
    {
        $product = $this->product(['stock_status' => 'outofstock', 'sale_price' => '39.95']);
        $this->flush();

        $item = end($this->platform->requests)['body']['items'][0];

        self::assertFalse($item['available']);
        self::assertSame([39.95, 49.95], [$item['price'], $item['old_price']]);
        self::assertSame((string) $product->get_id(), $item['id']);
    }

    public function testSendsTheStockOnlyWhenTheStoreTracksIt(): void
    {
        $tracked = $this->product(['manage_stock' => true, 'stock_quantity' => 7]);
        $backorders = $this->product(['manage_stock' => true, 'stock_quantity' => -2, 'backorders' => 'yes']);
        $none = $this->product(['manage_stock' => true, 'stock_quantity' => -1]);
        $untracked = $this->product();
        $variations = $this->variable([['manage_stock' => true, 'stock_quantity' => 3], ['manage_stock' => true, 'stock_quantity' => 4], ['manage_stock' => true, 'stock_quantity' => 0]]);
        $variationBackorders = $this->variable([['manage_stock' => true, 'stock_quantity' => 3], ['manage_stock' => true, 'stock_quantity' => 0, 'backorders' => 'notify']]);
        $parentBackorders = $this->variable([[], []], ['manage_stock' => true, 'stock_quantity' => 0, 'backorders' => 'yes']);
        $parent = $this->variable([[], []], ['manage_stock' => true, 'stock_quantity' => 5]);
        $mixed = $this->variable([['manage_stock' => true, 'stock_quantity' => 3], []]);
        $this->flush();

        $items = $this->uploaded();

        self::assertSame(7, $items[$tracked->get_id()]['stock']);
        self::assertArrayNotHasKey('stock', $items[$backorders->get_id()], 'Backorders allowed: zero stock does not take it off sale.');
        self::assertArrayNotHasKey('stock', $items[$variationBackorders->get_id()], 'A variation allows backorders.');
        self::assertArrayNotHasKey('stock', $items[$parentBackorders->get_id()], 'The parent managing stock allows backorders.');
        self::assertArrayNotHasKey('stock', $items[$untracked->get_id()]);
        self::assertSame(0, $items[$none->get_id()]['stock'], 'Below zero is zero.');
        self::assertSame(7, $items[$variations->get_id()]['stock'], 'The sum of the variations.');
        self::assertSame(5, $items[$parent->get_id()]['stock'], 'Stock of the parent, once.');
        self::assertArrayNotHasKey('stock', $items[$mixed->get_id()], 'A variation in stock without stock management: the count is unknown.');
        self::assertTrue($items[$tracked->get_id()]['available']);
    }

    public function testUploadsTheProductAgainWhenItsStockChanges(): void
    {
        $product = $this->product(['manage_stock' => true, 'stock_quantity' => 5]);
        $order = $this->order($product, 2);
        $this->flush();

        wc_reduce_stock_levels($order->get_id());
        $this->flush();
        self::assertSame(3, $this->uploaded()[$product->get_id()]['stock'], 'The order took two.');

        wc_increase_stock_levels($order->get_id());
        $this->flush();
        self::assertSame(5, $this->uploaded()[$product->get_id()]['stock'], 'The cancelled order returned them.');

        // Without a product save (wc_update_product_stock() during an update routine) the stock action still counts.
        $this->platform->requests = [];
        wc_update_product_stock($product, 0, 'set', true);
        $this->flush();
        self::assertSame(0, $this->uploaded()[$product->get_id()]['stock']);

        $variable = $this->variable([['manage_stock' => true, 'stock_quantity' => 3], ['manage_stock' => true, 'stock_quantity' => 4]]);
        $this->flush();
        $this->platform->requests = [];
        wc_update_product_stock((int) $variable->get_children()[0], 1, 'set', true);
        $this->flush();
        self::assertSame(5, $this->uploaded()[$variable->get_id()]['stock'], 'A variation uploads its parent.');
    }

    /**
     * @param list<array<string, mixed>> $variations
     * @param array<string, mixed> $props
     */
    private function variable(array $variations, array $props = []): \WC_Product_Variable
    {
        $attribute = new \WC_Product_Attribute();
        $attribute->set_name('Size');
        $attribute->set_options(array_map('strval', array_keys($variations)));
        $attribute->set_visible(true);
        $attribute->set_variation(true);

        $product = new \WC_Product_Variable();
        $product->set_props($props + ['name' => 'Runner', 'status' => 'publish', 'attributes' => [$attribute]]);
        $product->save();
        $this->remember($product->get_id());

        foreach ($variations as $size => $variationProps) {
            $variation = new \WC_Product_Variation();
            $variation->set_props($variationProps + ['parent_id' => $product->get_id(), 'regular_price' => '49.00', 'attributes' => ['size' => (string) $size]]);
            $variation->save();
        }

        \WC_Product_Variable::sync($product->get_id());

        return new \WC_Product_Variable($product->get_id());
    }

    /**
     * The latest uploaded item of each product, by post id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function uploaded(): array
    {
        $items = [];

        foreach ($this->platform->requests as $request) {
            if ($request['method'] === 'POST' && $request['path'] === '/api/v1/products') {
                foreach ($request['body']['items'] as $item) {
                    $items[(int) $item['id']] = $item;
                }
            }
        }

        return $items;
    }
}
