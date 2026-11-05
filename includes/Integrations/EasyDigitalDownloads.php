<?php
/**
 * Easy Digital Downloads 3.x.
 *
 * - `edd_built_order` ($order_id): the order is created in the buyer's checkout request; its click ID is kept.
 * - `edd_complete_purchase` ($order_id): documented as running when an order is marked complete.
 * - `edd_refund_order` ($order_id, $refund_id, $all_refunded): a refund order (its total is the refunded amount).
 * Order fields (EDD\Orders\Order): total, currency, gateway, mode (live/test), date_completed,
 * get_transaction_id(), get_items() (product_id).
 */

declare(strict_types=1);

namespace Clikgate\WP\Integrations;

use Clikgate\WP\Click;
use Clikgate\WP\Confirmation;
use Clikgate\WP\Dispatcher;
use Clikgate\WP\Money;

if (!defined('ABSPATH')) {
    exit;
}

final class EasyDigitalDownloads implements Integration
{
    private Dispatcher $dispatcher;

    public function id(): string
    {
        return 'edd';
    }

    public function label(): string
    {
        return 'Easy Digital Downloads';
    }

    public function item_label(): string
    {
        return __('Download ID', 'clikgate');
    }

    public function available(): bool
    {
        return function_exists('edd_get_order');
    }

    public function register(Dispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
        add_action('edd_built_order', [$this, 'built'], 10, 1);
        add_action('edd_complete_purchase', [$this, 'completed'], 20, 1);
        add_action('edd_refund_order', [$this, 'refunded'], 20, 2);
    }

    /** @param int|string $order_id */
    public function built($order_id): void
    {
        Click::remember($this->id(), (string) $order_id);
    }

    /** @param int|string $order_id */
    public function completed($order_id): void
    {
        $order = edd_get_order((int) $order_id);
        if (is_object($order) && ($order->status ?? '') === 'complete') {
            $this->dispatcher->confirm($this->confirmation($order, 'success', $order->total ?? null));
        }
    }

    /** @param int|string $order_id @param int|string $refund_id */
    public function refunded($order_id, $refund_id): void
    {
        $order = edd_get_order((int) $order_id);
        $refund = edd_get_order((int) $refund_id);
        if (is_object($order) && is_object($refund)) {
            $this->dispatcher->confirm($this->confirmation($order, 'refunded', $refund->total ?? null, (string) $refund_id));
        }
    }

    /** @param object $order */
    public function confirmation($order, string $status, $amount, ?string $refund_id = null): Confirmation
    {
        $id = (string) ($order->id ?? '');
        $transaction = method_exists($order, 'get_transaction_id') ? trim((string) $order->get_transaction_id()) : '';
        $items = [];
        if (method_exists($order, 'get_items')) {
            foreach ((array) $order->get_items() as $item) {
                if (is_object($item) && isset($item->product_id)) {
                    $items[] = (string) $item->product_id;
                }
            }
        }
        $mode = (string) ($order->mode ?? '');
        return new Confirmation(
            source_plugin: $this->id(),
            gateway: (string) ($order->gateway ?? ''),
            reference: $transaction !== '' ? $transaction : 'edd-' . $id,
            status: $status,
            amount: is_numeric($amount) ? Money::positive($amount) : null,
            currency: (string) ($order->currency ?? ''),
            occurred_at: $status === 'success' ? Confirmation::iso((string) ($order->date_completed ?? '')) : null,
            click_id: Click::recall($this->id(), $id),
            order_id: $id,
            test: $mode === '' ? null : $mode === 'test',
            items: array_values(array_unique($items)),
            refund_id: $refund_id
        );
    }
}
