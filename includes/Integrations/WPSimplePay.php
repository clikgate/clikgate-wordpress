<?php
/**
 * WP Simple Pay (Stripe): `simpay_webhook_payment_intent_succeeded` ($event, $payment_intent), documented in
 * WP Simple Pay's webhook guide. It runs when Stripe's webhook reaches the site, so the site must have WP Simple
 * Pay's Stripe webhook set up. There is no visitor cookie in that request: the click ID comes from the
 * PaymentIntent metadata `cg_click_id` when the site adds it (see https://clikgate.com/docs/wordpress). No refund hook is documented.
 */

declare(strict_types=1);

namespace Clikgate\WP\Integrations;

use Clikgate\WP\Click;
use Clikgate\WP\Confirmation;
use Clikgate\WP\Dispatcher;

if (!defined('ABSPATH')) {
    exit;
}

final class WPSimplePay implements Integration
{
    private Dispatcher $dispatcher;

    public function id(): string
    {
        return 'wpsimplepay';
    }

    public function label(): string
    {
        return 'WP Simple Pay';
    }

    public function item_label(): string
    {
        return __('Payment form ID', 'clikgate');
    }

    public function available(): bool
    {
        return defined('SIMPLE_PAY_VERSION');
    }

    public function register(Dispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
        add_action('simpay_webhook_payment_intent_succeeded', [$this, 'succeeded'], 20, 2);
    }

    /** @param object $event @param object|array<string, mixed> $payment_intent */
    public function succeeded($event, $payment_intent): void
    {
        $confirmation = $this->confirmation($payment_intent);
        if ($confirmation !== null) {
            $this->dispatcher->confirm($confirmation);
        }
    }

    public function confirmation($payment_intent): ?Confirmation
    {
        $stripe = Stripe::read($payment_intent);
        if ($stripe === null || $stripe['status'] !== 'success') {
            return null;
        }
        $form = (string) ($stripe['metadata']['simpay_form_id'] ?? '');
        return new Confirmation(
            source_plugin: $this->id(),
            gateway: 'stripe',
            reference: $stripe['reference'],
            status: 'success',
            amount: $stripe['amount'],
            currency: $stripe['currency'],
            occurred_at: $stripe['created'] !== null ? Confirmation::iso($stripe['created']) : null,
            click_id: Click::valid($stripe['click_id']) ? $stripe['click_id'] : null,
            order_id: null,
            test: $stripe['test'],
            items: $form !== '' ? [$form] : []
        );
    }
}
