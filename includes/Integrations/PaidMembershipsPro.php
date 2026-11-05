<?php
/**
 * Paid Memberships Pro (documented hooks):
 * - `pmpro_added_order` ($order): the order is created at checkout in the member's request; its click ID is kept.
 * - `pmpro_order_status_success` ($order, $original_status): the order is paid.
 * - `pmpro_order_status_refunded` ($order, $original_status): refunded (the order total).
 * MemberOrder: id, code, total, payment_transaction_id, gateway, gateway_environment (sandbox/live),
 * membership_id, timestamp. The currency is the site's `pmpro_currency` setting.
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

final class PaidMembershipsPro implements Integration
{
    private Dispatcher $dispatcher;

    public function id(): string
    {
        return 'pmpro';
    }

    public function label(): string
    {
        return 'Paid Memberships Pro';
    }

    public function item_label(): string
    {
        return __('Level ID', 'clikgate');
    }

    public function available(): bool
    {
        return defined('PMPRO_VERSION');
    }

    public function register(Dispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
        add_action('pmpro_added_order', [$this, 'added'], 10, 1);
        add_action('pmpro_order_status_success', [$this, 'success'], 20, 1);
        add_action('pmpro_order_status_refunded', [$this, 'refunded'], 20, 1);
    }

    /** @param object $order */
    public function added($order): void
    {
        if (is_object($order) && isset($order->id)) {
            Click::remember($this->id(), (string) $order->id);
        }
    }

    /** @param object $order */
    public function success($order): void
    {
        if (is_object($order)) {
            $this->dispatcher->confirm($this->confirmation($order, 'success'));
        }
    }

    /** @param object $order */
    public function refunded($order): void
    {
        if (is_object($order)) {
            $this->dispatcher->confirm($this->confirmation($order, 'refunded'));
        }
    }

    /** @param object $order */
    public function confirmation($order, string $status): Confirmation
    {
        $id = (string) ($order->id ?? '');
        $reference = trim((string) ($order->payment_transaction_id ?? ''));
        $environment = (string) ($order->gateway_environment ?? '');
        $timestamp = $order->timestamp ?? null;
        return new Confirmation(
            source_plugin: $this->id(),
            gateway: (string) ($order->gateway ?? ''),
            reference: $reference !== '' ? $reference : 'pmpro-' . ((string) ($order->code ?? '') ?: $id),
            status: $status,
            amount: is_numeric($order->total ?? null) ? Money::positive($order->total) : null,
            currency: (string) get_option('pmpro_currency', ''),
            occurred_at: $status === 'success' && $timestamp !== null ? Confirmation::iso(is_numeric($timestamp) ? (int) $timestamp : (string) $timestamp) : null,
            click_id: Click::recall($this->id(), $id),
            order_id: (string) ($order->code ?? $id),
            test: $environment === '' ? null : $environment === 'sandbox',
            items: [(string) ($order->membership_id ?? '')]
        );
    }
}
