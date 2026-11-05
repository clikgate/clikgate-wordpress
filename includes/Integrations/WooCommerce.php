<?php
/**
 * WooCommerce: payment confirmations sent with the plugin key.
 *
 * - Checkout (classic and block / Store API): the click ID is saved in the order meta `_cg_click_id`.
 * - Paid: `woocommerce_payment_complete` (the gateway plugin called WC_Order::payment_complete()) and
 *   `woocommerce_order_payment_status_changed` (pending/failed → a paid status, also for gateways that don't call
 *   payment_complete()); the meta `_cg_confirmed` makes the second one a no-op.
 * - Marked paid by hand: `woocommerce_order_status_changed` to processing/completed (bank transfer, cash on
 *   delivery, cheque), sent with the generic provider `custom`.
 * - Refunded: `woocommerce_order_refunded` (each refund once, with its own amount and `refund_id`).
 *
 * Reference: WC_Order::get_transaction_id() (the official Paystack plugin stores the Paystack reference there).
 * The official Flutterwave plugin (gateway id `rave`) stores the order id there, so its `tx_ref` comes from the
 * order meta `_flw_payment_txn_ref`. Without a transaction id the reference is `wc-order-{order number}`.
 */

declare(strict_types=1);

namespace Clikgate\WP\Integrations;

use Clikgate\WP\Click;
use Clikgate\WP\Confirmation;
use Clikgate\WP\Dispatcher;

if (!defined('ABSPATH')) {
    exit;
}

final class WooCommerce implements Integration
{
    public const META_CLICK = '_cg_click_id';
    public const META_CONFIRMED = '_cg_confirmed';
    public const META_REFUNDS = '_cg_refunds_sent';

    private Dispatcher $dispatcher;

    public function id(): string
    {
        return 'woocommerce';
    }

    public function label(): string
    {
        return 'WooCommerce';
    }

    public function item_label(): string
    {
        return __('Product ID', 'clikgate');
    }

    public function available(): bool
    {
        return class_exists('WooCommerce') && function_exists('wc_get_order');
    }

    public function register(Dispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
        add_action('woocommerce_checkout_create_order', [$this, 'remember_click'], 10, 1);
        add_action('woocommerce_store_api_checkout_update_order_meta', [$this, 'remember_click_and_save'], 10, 1);
        add_action('woocommerce_payment_complete', [$this, 'payment_complete'], 20, 1);
        add_action('woocommerce_order_payment_status_changed', [$this, 'payment_complete'], 20, 1);
        add_action('woocommerce_order_status_changed', [$this, 'status_changed'], 20, 3);
        add_action('woocommerce_order_refunded', [$this, 'refunded'], 20, 2);
    }

    /** @param \WC_Order $order */
    public function remember_click($order): void
    {
        if (!is_object($order) || !method_exists($order, 'get_meta')) {
            return;
        }
        $click = Click::from_request();
        if ($click !== null && (string) $order->get_meta(self::META_CLICK) === '') {
            $order->update_meta_data(self::META_CLICK, $click);
        }
    }

    /** @param \WC_Order $order */
    public function remember_click_and_save($order): void
    {
        $this->remember_click($order);
        if (is_object($order) && method_exists($order, 'save')) {
            $order->save();
        }
    }

    /** @param int|string $order_id */
    public function payment_complete($order_id): void
    {
        $order = wc_get_order((int) $order_id);
        if (!is_object($order) || !method_exists($order, 'is_paid') || !$order->is_paid()) {
            return;
        }
        if ((string) $order->get_meta(self::META_CONFIRMED) !== '') {
            return;
        }
        $confirmation = $this->confirmation($order, 'success', $this->amount($order->get_total()));
        if ($this->dispatcher->confirm($confirmation) !== 'skipped') {
            $order->update_meta_data(self::META_CONFIRMED, gmdate('c'));
            $order->save();
        }
    }

    /**
     * Orders marked paid by hand (bank transfer, cash on delivery, cheque) never call payment_complete(): they
     * count when they reach processing or completed. Other gateways are handled by the two hooks above.
     *
     * @param int|string $order_id
     */
    public function status_changed($order_id, $from = '', $to = ''): void
    {
        if (!in_array((string) $to, ['processing', 'completed'], true)) {
            return;
        }
        $order = wc_get_order((int) $order_id);
        if (is_object($order) && method_exists($order, 'get_payment_method') && \Clikgate\WP\Providers::manual((string) $order->get_payment_method())) {
            $this->payment_complete($order_id);
        }
    }

    /** @param int|string $order_id @param int|string $refund_id */
    public function refunded($order_id, $refund_id): void
    {
        $order = wc_get_order((int) $order_id);
        $refund = wc_get_order((int) $refund_id);
        if (!is_object($order) || !is_object($refund) || !method_exists($refund, 'get_amount')) {
            return;
        }
        // Only refunds of payments the plugin confirmed
        if ((string) $order->get_meta(self::META_CONFIRMED) === '') {
            return;
        }
        $sent = array_filter(explode(',', (string) $order->get_meta(self::META_REFUNDS)));
        if (in_array((string) $refund_id, $sent, true)) {
            return;
        }
        $amount = $this->amount(abs((float) $refund->get_amount()));
        $confirmation = $this->confirmation($order, 'refunded', $amount, (string) $refund_id);
        if ($this->dispatcher->confirm($confirmation) !== 'skipped') {
            $sent[] = (string) $refund_id;
            $order->update_meta_data(self::META_REFUNDS, implode(',', $sent));
            $order->save();
        }
    }

    /** @param \WC_Order $order */
    public function confirmation($order, string $status, ?string $amount, ?string $refund_id = null): Confirmation
    {
        $gateway = (string) $order->get_payment_method();
        $transaction = trim((string) $order->get_transaction_id());
        $aliases = [];
        if ($gateway === 'rave') {
            $tx_ref = trim((string) $order->get_meta('_flw_payment_txn_ref'));
            if ($tx_ref !== '') {
                $transaction = $tx_ref;
            } elseif ($transaction === (string) $order->get_id()) {
                $transaction = '';
            }
        }
        $number = method_exists($order, 'get_order_number') ? (string) $order->get_order_number() : (string) $order->get_id();
        $reference = $transaction !== '' && !\Clikgate\WP\Providers::manual($gateway) ? $transaction : 'wc-order-' . $number;
        $items = [];
        foreach ((array) $order->get_items() as $item) {
            if (is_object($item) && method_exists($item, 'get_product_id')) {
                $items[] = (string) $item->get_product_id();
                if (method_exists($item, 'get_variation_id') && (int) $item->get_variation_id() > 0) {
                    array_unshift($items, (string) $item->get_variation_id());
                }
            }
        }
        $click = (string) $order->get_meta(self::META_CLICK);
        $paid = $order->get_date_paid() ?? $order->get_date_created();
        return new Confirmation(
            source_plugin: 'woocommerce',
            gateway: $gateway,
            reference: $reference,
            status: $status,
            amount: $amount,
            currency: (string) $order->get_currency(),
            occurred_at: $status === 'success' ? Confirmation::iso($paid) : null,
            click_id: Click::valid($click) ? $click : null,
            order_id: (string) $order->get_id(),
            test: null,
            items: array_values(array_unique($items)),
            aliases: $aliases,
            refund_id: $refund_id
        );
    }

    private function amount($value): ?string
    {
        $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
        return function_exists('wc_format_decimal') ? (string) wc_format_decimal($value, $decimals) : (string) $value;
    }
}
