<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\WooCommerce\Shipments;
use OneTrace\WooCommerce\Tests\TestCase;

/**
 * order_shipped and order_delivered: tracking items of WooCommerce Shipment Tracking / Advanced Shipment Tracking /
 * TrackShip (the last two installed by tests/env/run.sh), the completed status without tracking, custom statuses and
 * the filter for other tracking plugins.
 */
final class ShipmentsTest extends TestCase
{
    /** @var list<int> providers added to the list of AST */
    private $providers = [];

    protected function setUp(): void
    {
        parent::setUp();
        add_filter('wc_order_statuses', [self::class, 'statuses']);
    }

    protected function tearDown(): void
    {
        global $wpdb;

        foreach ($this->providers as $id) {
            $wpdb->delete($wpdb->prefix . 'woo_shippment_provider', ['id' => $id]);
        }

        remove_filter('wc_order_statuses', [self::class, 'statuses']);
        remove_all_filters('onetrace_woocommerce_order_shipments');
        parent::tearDown();
    }

    /**
     * Custom statuses of tracking plugins (AST, TrackShip) for the tests that run without them.
     *
     * @param array<string, string> $statuses
     *
     * @return array<string, string>
     */
    public static function statuses(array $statuses): array
    {
        return $statuses + ['wc-shipped' => 'Shipped', 'wc-delivered' => 'Delivered'];
    }

    public function testEveryTrackingItemIsOneShipmentAndRepeatedSavesSendNothingAgain(): void
    {
        $order = $this->order($this->product(), 2);
        $order->update_status('processing');
        $first = ['tracking_provider' => '', 'custom_tracking_provider' => 'Hermes', 'custom_tracking_link' => 'https://tracking.example.com/H-123', 'tracking_number' => 'H-123', 'date_shipped' => (string) strtotime('2026-10-15'), 'tracking_id' => 'a1'];
        $second = ['tracking_provider' => '', 'custom_tracking_provider' => 'DPD', 'custom_tracking_link' => '', 'tracking_number' => 'D 456', 'date_shipped' => (string) time(), 'tracking_id' => 'b2'];

        // WooCommerce Shipment Tracking: update_meta_data() + save_meta_data(), then the order is saved again.
        $this->track($order->get_id(), [$first], false);
        $this->track($order->get_id(), [$first], true);
        // The second parcel; AST saves with save().
        $this->track($order->get_id(), [$first, $second], true);
        $order = wc_get_order($order->get_id());
        $order->update_status('completed');
        $order->save();
        $this->flush();

        $shipped = $this->platform->events('order_shipped');

        self::assertCount(2, $shipped, 'One event per parcel, none for repeated saves and none without tracking for "completed".');
        self::assertSame(['order_id' => (string) $order->get_order_number(), 'carrier' => 'Hermes', 'tracking_number' => 'H-123', 'tracking_url' => 'https://tracking.example.com/H-123'], $shipped[0]['properties']);
        self::assertSame('2026-10-15T00:00:00.000Z', $shipped[0]['timestamp'], 'The shipping date of the tracking item.');
        self::assertStringEndsWith(':order_shipped:H-123', $shipped[0]['messageId']);
        self::assertSame(['order_id' => (string) $order->get_order_number(), 'carrier' => 'DPD', 'tracking_number' => 'D 456'], $shipped[1]['properties']);
        self::assertStringEndsWith(':order_shipped:D456', $shipped[1]['messageId']);

        foreach ($shipped as $message) {
            self::assertContract($message);
        }
    }

    public function testAdvancedShipmentTrackingParcelMarkedAsShipped(): void
    {
        if (!\function_exists('ast_add_tracking_number')) {
            self::markTestSkipped('Advanced Shipment Tracking is not active.');
        }

        $carrier = $this->astProvider('ups', 'UPS', 'https://wwwapps.ups.com/WebTracking/track?track=yes&trackNums=%number%');
        $order = $this->order($this->product());
        $order->update_status('processing');

        // "Mark order as shipped": AST saves the item, then sets the completed status.
        ast_add_tracking_number($order->get_id(), '1Z999AA10123456784', 'ups', time(), 1);
        $this->flush();

        $shipped = $this->platform->events('order_shipped');

        self::assertSame('completed', wc_get_order($order->get_id())->get_status());
        self::assertCount(1, $shipped, 'The parcel only, no shipment without tracking for the completed status.');
        self::assertSame('1Z999AA10123456784', $shipped[0]['properties']['tracking_number']);
        self::assertSame($carrier, $shipped[0]['properties']['carrier'], 'The provider name of AST, not its slug.');
        self::assertStringContainsString('1Z999AA10123456784', $shipped[0]['properties']['tracking_url']);
        self::assertContract($shipped[0]);
    }

    public function testCompletedWithoutTrackingIsOneShipment(): void
    {
        $order = $this->order($this->product());

        $order->update_status('processing');
        $order->update_status('completed');
        $order->update_status('processing');
        $order->update_status('completed');
        $virtual = $this->order($this->product(['virtual' => true]));
        $virtual->update_status('completed');
        $this->flush();

        $shipped = $this->platform->events('order_shipped');

        self::assertCount(1, $shipped, 'Once per order; a virtual order is not shipped.');
        self::assertSame(['order_id' => (string) $order->get_order_number()], $shipped[0]['properties']);
        self::assertStringEndsWith(':' . $order->get_order_number() . ':order_shipped', $shipped[0]['messageId']);
        self::assertContract($shipped[0]);
    }

    public function testShippedAndDeliveredStatusesOfTrackingPlugins(): void
    {
        $order = $this->order($this->product());

        $order->update_status('processing');
        $order->update_status('wc-shipped');
        $order->update_status('completed');
        $order->update_status('delivered');
        $order->update_status('completed');
        $order->update_status('delivered');
        $this->flush();

        $shipped = $this->platform->events('order_shipped');
        $delivered = $this->platform->events('order_delivered');

        self::assertCount(1, $shipped);
        self::assertCount(1, $delivered, 'Delivered once.');
        self::assertSame(['order_id' => (string) $order->get_order_number()], $delivered[0]['properties']);
        self::assertStringEndsWith(':order_delivered', $delivered[0]['messageId']);
        self::assertContract($shipped[0]);
        self::assertContract($delivered[0]);
    }

    public function testTrackShipDeliveredWhenEveryParcelIsDelivered(): void
    {
        if (!\function_exists('trackship_for_woocommerce')) {
            self::markTestSkipped('TrackShip is not active.');
        }

        // TrackShip creates its tables in the admin (admin_init).
        $install = \WC_Trackship_Install::get_instance();
        $install->create_shipment_table();
        $install->create_shipment_meta_table();
        $order = $this->order($this->product());
        $order->update_status('processing');
        $this->track($order->get_id(), [
            ['tracking_provider' => 'usps', 'tracking_number' => 'T1', 'date_shipped' => (string) time(), 'tracking_id' => 't1'],
            ['tracking_provider' => 'usps', 'tracking_number' => 'T2', 'date_shipped' => (string) time(), 'tracking_id' => 't2'],
        ], true);
        $actions = trackship_for_woocommerce()->actions;
        $actions->update_shipment_data($order->get_id(), 'T1', ['shipment_status' => 'delivered']);
        $actions->update_shipment_data($order->get_id(), 'T2', ['shipment_status' => 'in_transit']);

        do_action('trackship_shipment_status_trigger', $order->get_id(), 'in_transit', 'delivered', 'T1');
        $this->flush();
        self::assertCount(0, $this->platform->events('order_delivered'), 'The second parcel is on the way.');

        $actions->update_shipment_data($order->get_id(), 'T2', ['shipment_status' => 'delivered']);
        do_action('trackship_shipment_status_trigger', $order->get_id(), 'in_transit', 'delivered', 'T2');
        $this->flush();

        self::assertCount(2, $this->platform->events('order_shipped'));
        self::assertCount(1, $this->platform->events('order_delivered'));
    }

    public function testOtherTrackingPluginsSupplyParcelsWithTheFilter(): void
    {
        $product = $this->product();
        $order = $this->order($product, 2);
        $order->update_status('processing');
        $itemId = (int) array_key_first($order->get_items());
        add_filter('onetrace_woocommerce_order_shipments', static function (array $shipments, \WC_Order $order) use ($itemId): array {
            $shipments[] = ['carrier' => 'Nova Poshta', 'tracking_number' => '20450000000001', 'tracking_url' => 'https://novaposhta.ua/tracking/20450000000001', 'estimated_delivery' => '2026-10-22', 'items' => [$itemId => 1]];

            return $shipments;
        }, 10, 2);

        do_action('onetrace_woocommerce_sync_shipments', $order->get_id());
        do_action('onetrace_woocommerce_sync_shipments', $order);
        $order->update_status('completed');
        $this->flush();

        $shipped = $this->platform->events('order_shipped');

        self::assertCount(1, $shipped);
        self::assertSame('2026-10-22', $shipped[0]['properties']['estimated_delivery']);
        self::assertSame([['product_id' => (string) $product->get_id(), 'product_name' => 'Sneakers', 'price' => 49.95, 'quantity' => 1, 'category_id' => (string) get_option('default_product_cat')]], $shipped[0]['properties']['products'], 'One of the two pieces in this parcel.');
        self::assertContract($shipped[0]);
    }

    /**
     * The provider of AST's list: a fresh install fills the list later (Action Scheduler, from the TrackShip API).
     */
    private function astProvider(string $slug, string $name, string $url): string
    {
        global $wpdb;

        $table = $wpdb->prefix . 'woo_shippment_provider';
        $known = $wpdb->get_var($wpdb->prepare('SELECT COALESCE(NULLIF(custom_provider_name, \'\'), provider_name) FROM ' . $table . ' WHERE ts_slug = %s', $slug));

        if (\is_string($known) && $known !== '') {
            return $known;
        }

        $wpdb->insert($table, ['provider_name' => $name, 'ts_slug' => $slug, 'provider_url' => $url, 'shipping_country' => 'Global']);
        $this->providers[] = (int) $wpdb->insert_id;

        return $name;
    }

    /**
     * Saves tracking items like the tracking plugins: with save_meta_data() or with save().
     *
     * @param list<array<string, string>> $items
     */
    private function track(int $orderId, array $items, bool $save): void
    {
        $order = wc_get_order($orderId);
        $order->update_meta_data(Shipments::TRACKING_META, $items);
        $save ? $order->save() : $order->save_meta_data();
    }
}
