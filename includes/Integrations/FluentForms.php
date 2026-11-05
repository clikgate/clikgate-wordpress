<?php
/**
 * Fluent Forms payments (Fluent Forms Pro payment module: Stripe, PayPal, Mollie, Razorpay, Paystack, Square...).
 *
 * - `fluentform/submission_inserted` ($insert_id, $form_data, $form): the submitter's click ID is kept.
 * - `fluentform/after_payment_status_change` ($new_status, $submission): documented; sent when the status is
 *   `paid`. The submission row has id, form_id, payment_total (in cents), currency, payment_method,
 *   payment_mode.
 * The documented hook carries no gateway transaction id, so the reference is `ff-{submission id}`; attribution
 * comes from the click ID. Refunds aren't sent (no documented refund hook with a stable signature).
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

final class FluentForms implements Integration
{
    private Dispatcher $dispatcher;

    public function id(): string
    {
        return 'fluentforms';
    }

    public function label(): string
    {
        return 'Fluent Forms';
    }

    public function item_label(): string
    {
        return __('Form ID', 'clikgate');
    }

    public function available(): bool
    {
        return defined('FLUENTFORM') || function_exists('wpFluentForm');
    }

    public function register(Dispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
        add_action('fluentform/submission_inserted', [$this, 'inserted'], 10, 1);
        add_action('fluentform/after_payment_status_change', [$this, 'status_changed'], 20, 2);
    }

    /** @param int|string $insert_id */
    public function inserted($insert_id): void
    {
        Click::remember($this->id(), (string) $insert_id);
    }

    /** @param object|array<string, mixed> $submission */
    public function status_changed($new_status, $submission): void
    {
        if ($new_status === 'paid' && (is_object($submission) || is_array($submission))) {
            $this->dispatcher->confirm($this->confirmation((array) $submission));
        }
    }

    /** @param array<string, mixed> $row */
    public function confirmation(array $row): Confirmation
    {
        $id = (string) ($row['id'] ?? '');
        $total = $row['payment_total'] ?? null;
        $mode = (string) ($row['payment_mode'] ?? '');
        return new Confirmation(
            source_plugin: $this->id(),
            gateway: (string) ($row['payment_method'] ?? ''),
            reference: 'ff-' . $id,
            status: 'success',
            amount: is_numeric($total) ? Money::from_cents((int) $total) : null,
            currency: (string) ($row['currency'] ?? ''),
            occurred_at: null,
            click_id: Click::recall($this->id(), $id),
            order_id: $id,
            test: $mode === '' ? null : $mode === 'test',
            items: [(string) ($row['form_id'] ?? '')]
        );
    }
}
