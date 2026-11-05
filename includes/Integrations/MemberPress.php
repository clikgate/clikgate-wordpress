<?php
/**
 * MemberPress (documented action hooks):
 * - `mepr-signup` ($txn): the checkout creates the transaction in the member's request; its click ID is kept.
 * - `mepr-event-transaction-completed` ($event): a completed one-time or recurring payment ($event->get_data()
 *   is the MeprTransaction: id, total, amount, trans_num, gateway, product_id, created_at).
 * - `mepr-event-transaction-refunded` ($event): a full refund of that transaction.
 * The currency is MemberPress's store currency (MeprOptions currency_code).
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

final class MemberPress implements Integration
{
    private Dispatcher $dispatcher;

    public function id(): string
    {
        return 'memberpress';
    }

    public function label(): string
    {
        return 'MemberPress';
    }

    public function item_label(): string
    {
        return __('Membership ID', 'clikgate');
    }

    public function available(): bool
    {
        return defined('MEPR_VERSION');
    }

    public function register(Dispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
        add_action('mepr-signup', [$this, 'signup'], 10, 1);
        add_action('mepr-event-transaction-completed', [$this, 'completed'], 20, 1);
        add_action('mepr-event-transaction-refunded', [$this, 'refunded'], 20, 1);
    }

    /** @param object $txn */
    public function signup($txn): void
    {
        if (is_object($txn) && isset($txn->id)) {
            Click::remember($this->id(), (string) $txn->id);
        }
    }

    /** @param object $event */
    public function completed($event): void
    {
        $txn = $this->txn($event);
        if ($txn !== null) {
            $this->dispatcher->confirm($this->confirmation($txn, 'success'));
        }
    }

    /** @param object $event */
    public function refunded($event): void
    {
        $txn = $this->txn($event);
        if ($txn !== null) {
            $this->dispatcher->confirm($this->confirmation($txn, 'refunded'));
        }
    }

    /** @param object $txn */
    public function confirmation($txn, string $status): Confirmation
    {
        $id = (string) ($txn->id ?? '');
        $total = $txn->total ?? null;
        if (!is_numeric($total) || (float) $total <= 0) {
            $total = $txn->amount ?? null;
        }
        $reference = trim((string) ($txn->trans_num ?? ''));
        return new Confirmation(
            source_plugin: $this->id(),
            gateway: $this->gateway($txn),
            reference: $reference !== '' ? $reference : 'mepr-' . $id,
            status: $status,
            amount: is_numeric($total) ? Money::positive($total) : null,
            currency: $this->currency(),
            occurred_at: $status === 'success' ? Confirmation::iso((string) ($txn->created_at ?? '')) : null,
            click_id: Click::recall($this->id(), $id),
            order_id: $id,
            items: [(string) ($txn->product_id ?? '')]
        );
    }

    /** @param object $event */
    private function txn($event): ?object
    {
        $txn = is_object($event) && method_exists($event, 'get_data') ? $event->get_data() : null;
        return is_object($txn) ? $txn : null;
    }

    /** The gateway's class or key ("MeprStripeGateway" → stripe), not the per-site payment method id. */
    private function gateway(object $txn): string
    {
        if (method_exists($txn, 'payment_method')) {
            $method = $txn->payment_method();
            if (is_object($method)) {
                $key = isset($method->key) ? (string) $method->key : '';
                return $key !== '' ? $key : str_replace(['mepr', 'gateway'], '', strtolower(get_class($method)));
            }
        }
        return (string) ($txn->gateway ?? '');
    }

    private function currency(): ?string
    {
        if (class_exists('MeprOptions') && method_exists('MeprOptions', 'fetch')) {
            $options = \MeprOptions::fetch();
            return isset($options->currency_code) ? (string) $options->currency_code : null;
        }
        return null;
    }
}
