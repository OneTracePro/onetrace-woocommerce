<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests;

use JsonSchema\Validator;
use OneTrace\WooCommerce\Connection;
use OneTrace\WooCommerce\Queue;
use OneTrace\WooCommerce\Settings;
use OneTrace\WooCommerce\Tests\Support\RecordingTransport;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** @var RecordingTransport */
    protected $platform;

    /** @var list<int> */
    private $posts = [];

    protected function setUp(): void
    {
        global $wpdb;

        parent::setUp();
        $wpdb->query('DELETE FROM ' . Queue::table());
        delete_option(Queue::PAUSED_OPTION);
        update_option(Settings::OPTION, ['url' => 'https://cdp.example.com', 'write_key' => 'cdp_wk_test', 'secret_key' => 'cdp_sk_test'] + Settings::DEFAULTS);

        $this->platform = new RecordingTransport();
        $platform = $this->platform;
        remove_all_filters('onetrace_woocommerce_client_options');
        add_filter('onetrace_woocommerce_client_options', static function (array $options) use ($platform): array {
            return ['transport' => $platform] + $options;
        });
        Connection::reset();
        $_COOKIE = [];
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->posts) as $id) {
            $order = wc_get_order($id);
            $order ? $order->delete(true) : wp_delete_post($id, true);
        }

        parent::tearDown();
    }

    protected function product(array $props = []): \WC_Product_Simple
    {
        $product = new \WC_Product_Simple();
        $product->set_props($props + ['name' => 'Sneakers', 'regular_price' => '49.95', 'status' => 'publish']);
        $product->save();
        $this->posts[] = $product->get_id();

        return $product;
    }

    protected function order(\WC_Product $product, int $quantity = 2, array $billing = []): \WC_Order
    {
        $order = wc_create_order();
        $order->add_product($product, $quantity);
        $order->set_address($billing + ['first_name' => 'Anna', 'last_name' => 'Schmidt', 'email' => 'anna@example.com', 'phone' => '+49 151 1234-5678', 'country' => 'DE', 'city' => 'Berlin'], 'billing');
        $order->set_payment_method('bacs');
        $order->calculate_totals();
        $order->save();
        $this->posts[] = $order->get_id();

        return $order;
    }

    protected function remember(int $postId): void
    {
        $this->posts[] = $postId;
    }

    /**
     * Sends the queue to the recording platform.
     *
     * @return array{sent: int, retried: int, dropped: int}
     */
    protected function flush(): array
    {
        return Queue::flush(PHP_INT_MAX);
    }

    /**
     * @param array<string, mixed> $message
     */
    protected static function assertContract(array $message): void
    {
        $validator = new Validator();
        $data = json_decode((string) json_encode($message));
        $validator->validate($data, (object) ['$ref' => 'file://' . realpath(dirname(__DIR__) . '/vendor/onetracepro/onetrace-php/resources/ecommerce-events.schema.json')]);

        self::assertTrue($validator->isValid(), json_encode(['message' => $message, 'errors' => $validator->getErrors()], JSON_PRETTY_PRINT) ?: '');
    }
}
