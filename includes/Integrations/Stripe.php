<?php

declare(strict_types=1);

namespace Clikgate\WP\Integrations;

use Clikgate\WP\Money;

if (!defined('ABSPATH')) {
    exit;
}

/** Reads a Stripe PaymentIntent or Charge object (or array) the way Clikgate's server helper fromStripe() does. */
final class Stripe
{
    /** @return array{reference: string, status: string, amount: ?string, currency: ?string, created: ?int, test: ?bool, click_id: ?string, metadata: array<string, mixed>}|null */
    public static function read($object): ?array
    {
        $o = self::to_array($object);
        $id = (string) ($o['id'] ?? '');
        if ($id === '') {
            return null;
        }
        $currency = strtoupper((string) ($o['currency'] ?? ''));
        $minor = $o['amount_received'] ?? null;
        if (!is_numeric($minor) || (int) $minor <= 0) {
            $minor = $o['amount'] ?? null;
        }
        // A Charge belongs to its PaymentIntent: the PaymentIntent id is the canonical reference
        $reference = str_starts_with($id, 'ch_') && !empty($o['payment_intent']) && is_string($o['payment_intent']) ? $o['payment_intent'] : $id;
        $status = match ((string) ($o['status'] ?? '')) {
            'succeeded' => 'success',
            'canceled', 'failed' => 'failed',
            default => 'pending',
        };
        $metadata = self::to_array($o['metadata'] ?? []);
        return [
            'reference' => $reference,
            'status' => $status,
            'amount' => Money::from_stripe($minor, $currency),
            'currency' => Money::currency($currency),
            'created' => isset($o['created']) && is_numeric($o['created']) ? (int) $o['created'] : null,
            'test' => array_key_exists('livemode', $o) ? !$o['livemode'] : null,
            'click_id' => isset($metadata['cg_click_id']) ? (string) $metadata['cg_click_id'] : null,
            'metadata' => $metadata,
        ];
    }

    /** @return array<string, mixed> */
    private static function to_array($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                $array = $value->toArray();
                return is_array($array) ? $array : [];
            }
            return get_object_vars($value);
        }
        return [];
    }
}
