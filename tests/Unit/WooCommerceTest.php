<?php

declare(strict_types=1);

namespace Clikgate\WP\Tests\Unit;

use Clikgate\WP\Dispatcher;
use Clikgate\WP\Integrations\WooCommerce;
use Clikgate\WP\Queue;
use Clikgate\WP\Tests\TestCase;
use FakeWcItem;
use WC_Order;
use WC_Order_Refund;
use WpStub;

final class WooCommerceTest extends TestCase
{
    private WooCommerce $wc;

    protected function setUp(): void
    {
        parent::setUp();
        WC_Order::$store = [];
        $this->connect();
        $this->wc = new WooCommerce();
        $this->wc->register(new Dispatcher());
    }

    public function test_registers_the_documented_hooks(): void
    {
        foreach (['woocommerce_checkout_create_order', 'woocommerce_store_api_checkout_update_order_meta', 'woocommerce_payment_complete', 'woocommerce_order_payment_status_changed', 'woocommerce_order_status_changed', 'woocommerce_order_refunded'] as $hook) {
            $this->assertArrayHasKey($hook, WpStub::$hooks, $hook);
        }
    }

    public function test_checkout_keeps_the_click_id_from_the_web_script_cookie(): void
    {
        $_COOKIE['_cg_cgid'] = self::CLICK;
        $order = new WC_Order(1);
        do_action('woocommerce_checkout_create_order', $order);
        $this->assertSame(self::CLICK, $order->meta[WooCommerce::META_CLICK]);

        $_COOKIE = ['_cg_cgid' => 'clk_garbage'];
        $block = new WC_Order(2);
        do_action('woocommerce_store_api_checkout_update_order_meta', $block);
        $this->assertArrayNotHasKey(WooCommerce::META_CLICK, $block->meta);
        $this->assertSame(1, $block->saves);
    }

    public function test_a_paid_paystack_order_is_confirmed_once(): void
    {
        $order = new WC_Order(1234, items: [new FakeWcItem(55), new FakeWcItem(56, 61)], date_paid: new \DateTimeImmutable('2026-10-27T10:00:00Z'));
        $order->meta[WooCommerce::META_CLICK] = self::CLICK;
        do_action('woocommerce_payment_complete', 1234, 'T_ref_001');
        do_action('woocommerce_order_payment_status_changed', 1234, $order);
        $queue = Queue::all();
        $this->assertCount(1, $queue);
        $this->assertSame([
            'provider' => 'paystack', 'reference' => 'T_ref_001', 'status' => 'success', 'source_plugin' => 'woocommerce',
            'amount' => '7500', 'currency' => 'NGN', 'occurred_at' => '2026-10-27T10:00:00Z', 'click_id' => self::CLICK,
            'order_id' => '1234',
        ], $queue[0]['body']);
        $this->assertNotSame('', $order->meta[WooCommerce::META_CONFIRMED]);
    }

    public function test_unpaid_orders_are_ignored(): void
    {
        new WC_Order(5, paid: false);
        do_action('woocommerce_payment_complete', 5);
        $this->assertSame([], Queue::all());
    }

    public function test_flutterwave_uses_the_tx_ref_meta(): void
    {
        $order = new WC_Order(77, total: '1500.25', payment_method: 'rave', transaction_id: '77');
        $order->meta['_flw_payment_txn_ref'] = 'WOOC_77_1730000000';
        do_action('woocommerce_payment_complete', 77);
        $body = Queue::all()[0]['body'];
        $this->assertSame('flutterwave', $body['provider']);
        $this->assertSame('WOOC_77_1730000000', $body['reference']);
        $this->assertSame('1500.25', $body['amount']);
    }

    public function test_without_a_transaction_id_the_order_number_is_the_reference(): void
    {
        new WC_Order(88, payment_method: 'stripe', transaction_id: '');
        do_action('woocommerce_payment_complete', 88);
        $this->assertSame('wc-order-SW-88', Queue::all()[0]['body']['reference']);
    }

    public function test_manual_payments_count_when_marked_processing(): void
    {
        $order = new WC_Order(9, total: '12000.00', payment_method: 'bacs', transaction_id: '', paid: false);
        $order->meta[WooCommerce::META_CLICK] = self::CLICK;
        do_action('woocommerce_order_status_changed', 9, 'pending', 'on-hold', $order);
        $this->assertSame([], Queue::all(), 'on-hold is not paid');
        $order->paid = true;
        do_action('woocommerce_order_status_changed', 9, 'on-hold', 'processing', $order);
        do_action('woocommerce_order_status_changed', 9, 'processing', 'completed', $order);
        $queue = Queue::all();
        $this->assertCount(1, $queue);
        $this->assertSame(['custom', 'wc-order-SW-9', '12000', self::CLICK], [$queue[0]['body']['provider'], $queue[0]['body']['reference'], $queue[0]['body']['amount'], $queue[0]['body']['click_id']]);
    }

    public function test_status_changes_of_gateway_orders_are_left_to_the_payment_hooks(): void
    {
        new WC_Order(10);
        do_action('woocommerce_order_status_changed', 10, 'pending', 'processing', wc_get_order(10));
        $this->assertSame([], Queue::all());
    }

    public function test_each_refund_is_sent_once_with_its_amount(): void
    {
        $order = new WC_Order(1234);
        do_action('woocommerce_payment_complete', 1234);
        $refund = new WC_Order_Refund(1300);
        $refund->amount = '-2500.00';
        do_action('woocommerce_order_refunded', 1234, 1300);
        do_action('woocommerce_order_refunded', 1234, 1300);
        $second = new WC_Order_Refund(1301);
        $second->amount = '2500.00';
        do_action('woocommerce_order_refunded', 1234, 1301);
        $queue = Queue::all();
        $this->assertCount(3, $queue, 'payment + two refunds of the same amount');
        $this->assertSame('refunded', $queue[1]['body']['status']);
        $this->assertSame('2500', $queue[1]['body']['amount']);
        $this->assertSame('T_ref_001', $queue[1]['body']['reference']);
        $this->assertSame('1300', $queue[1]['body']['refund_id']);
        $this->assertSame('1301', $queue[2]['body']['refund_id']);
        $this->assertArrayNotHasKey('refund_id', $queue[0]['body'], 'only refunds carry refund_id');
        $this->assertSame('1300,1301', $order->meta[WooCommerce::META_REFUNDS]);
    }

    public function test_refunds_of_orders_never_confirmed_are_not_sent(): void
    {
        new WC_Order(50);
        $refund = new WC_Order_Refund(51);
        $refund->amount = '10';
        do_action('woocommerce_order_refunded', 50, 51);
        $this->assertSame([], Queue::all());
    }
}
