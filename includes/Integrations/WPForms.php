<?php
/**
 * WPForms with Stripe: `wpforms_stripe_process_complete` ($fields, $form_data, $entry_id, $payment,
 * $subscription, $customer) runs in the submitter's request after the Stripe payment; `$payment` is the
 * Stripe PaymentIntent (or Charge). Sent only when Stripe says `succeeded`. Other WPForms gateways have no
 * documented payment-completed hook and aren't sent; refunds aren't documented either.
 */

declare(strict_types=1);

namespace Clikgate\WP\Integrations;

use Clikgate\WP\Click;
use Clikgate\WP\Confirmation;
use Clikgate\WP\Dispatcher;

if (!defined('ABSPATH')) {
    exit;
}

final class WPForms implements Integration
{
    private Dispatcher $dispatcher;

    public function id(): string
    {
        return 'wpforms';
    }

    public function label(): string
    {
        return 'WPForms (Stripe)';
    }

    public function item_label(): string
    {
        return __('Form ID', 'clikgate');
    }

    public function available(): bool
    {
        return function_exists('wpforms');
    }

    public function register(Dispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
        add_action('wpforms_stripe_process_complete', [$this, 'stripe_complete'], 20, 4);
    }

    /** @param array<mixed> $fields @param array<string, mixed> $form_data @param int|string $entry_id @param object|array<string, mixed> $payment */
    public function stripe_complete($fields, $form_data, $entry_id, $payment): void
    {
        $confirmation = $this->confirmation(is_array($form_data) ? $form_data : [], (string) $entry_id, $payment);
        if ($confirmation !== null) {
            $this->dispatcher->confirm($confirmation);
        }
    }

    /** @param array<string, mixed> $form_data */
    public function confirmation(array $form_data, string $entry_id, $payment): ?Confirmation
    {
        $stripe = Stripe::read($payment);
        if ($stripe === null || $stripe['status'] !== 'success') {
            return null;
        }
        return new Confirmation(
            source_plugin: $this->id(),
            gateway: 'stripe',
            reference: $stripe['reference'],
            status: 'success',
            amount: $stripe['amount'],
            currency: $stripe['currency'],
            occurred_at: $stripe['created'] !== null ? Confirmation::iso($stripe['created']) : null,
            click_id: Click::valid($stripe['click_id']) ? $stripe['click_id'] : Click::from_request(),
            order_id: $entry_id !== '' && $entry_id !== '0' ? $entry_id : null,
            test: $stripe['test'],
            items: [(string) ($form_data['id'] ?? '')]
        );
    }
}
