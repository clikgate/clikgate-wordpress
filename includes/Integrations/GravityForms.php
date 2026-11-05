<?php
/**
 * Gravity Forms payment add-ons (Stripe, PayPal, Square, Mollie and third-party add-ons built on GFPaymentAddOn).
 *
 * - `gform_entry_created` ($entry, $form): the click ID of the submitting visitor is kept with the entry.
 * - `gform_post_payment_completed` ($entry, $action): the documented "payment completed" hook.
 * - `gform_post_payment_refunded` ($entry, $action).
 * `$action`: amount, transaction_id, payment_status...; the entry has currency, payment_date (UTC),
 * transaction_id and payment_method. The gateway is the add-on slug in the entry meta `payment_gateway`.
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

final class GravityForms implements Integration
{
    private Dispatcher $dispatcher;

    public function id(): string
    {
        return 'gravityforms';
    }

    public function label(): string
    {
        return 'Gravity Forms';
    }

    public function item_label(): string
    {
        return __('Form ID', 'clikgate');
    }

    public function available(): bool
    {
        return class_exists('GFForms');
    }

    public function register(Dispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
        add_action('gform_entry_created', [$this, 'entry_created'], 10, 2);
        add_action('gform_post_payment_completed', [$this, 'completed'], 10, 2);
        add_action('gform_post_payment_refunded', [$this, 'refunded'], 10, 2);
    }

    /** @param array<string, mixed> $entry */
    public function entry_created($entry, $form = null): void
    {
        if (is_array($entry) && isset($entry['id'])) {
            Click::remember($this->id(), (string) $entry['id']);
        }
    }

    /** @param array<string, mixed> $entry @param array<string, mixed> $action */
    public function completed($entry, $action): void
    {
        if (is_array($entry) && is_array($action)) {
            $this->dispatcher->confirm($this->confirmation($entry, $action, 'success'));
        }
    }

    /** @param array<string, mixed> $entry @param array<string, mixed> $action */
    public function refunded($entry, $action): void
    {
        if (is_array($entry) && is_array($action)) {
            $this->dispatcher->confirm($this->confirmation($entry, $action, 'refunded'));
        }
    }

    /** @param array<string, mixed> $entry @param array<string, mixed> $action */
    public function confirmation(array $entry, array $action, string $status): Confirmation
    {
        $entry_id = (string) ($entry['id'] ?? '');
        $gateway = function_exists('gform_get_meta') ? (string) gform_get_meta((int) $entry_id, 'payment_gateway') : '';
        if ($gateway === '') {
            $gateway = (string) ($entry['payment_method'] ?? '');
        }
        $reference = (string) (($action['transaction_id'] ?? '') ?: ($entry['transaction_id'] ?? ''));
        $amount = ($action['amount'] ?? '') !== '' ? $action['amount'] : ($entry['payment_amount'] ?? null);
        return new Confirmation(
            source_plugin: $this->id(),
            gateway: $gateway,
            reference: $reference !== '' ? $reference : 'gf-' . $entry_id,
            status: $status,
            amount: is_numeric($amount) ? Money::decimal(is_string($amount) ? trim($amount) : $amount) : null,
            currency: (string) ($entry['currency'] ?? ''),
            occurred_at: $status === 'success' ? Confirmation::iso((string) ($entry['payment_date'] ?? '')) : null,
            click_id: Click::recall($this->id(), $entry_id),
            order_id: $entry_id,
            items: [(string) ($entry['form_id'] ?? '')],
            event_id: $status === 'refunded' ? md5(wp_json_encode($action) ?: '') : null
        );
    }
}
