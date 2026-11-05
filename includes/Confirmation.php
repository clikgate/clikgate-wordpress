<?php
/**
 * One confirmed payment result from a supported plugin, before it is queued. `gateway` is the source plugin's
 * own gateway id ("paystack", "rave", "stripe"...); the dispatcher turns it into a Clikgate provider key.
 */

declare(strict_types=1);

namespace Clikgate\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Confirmation
{
    public const STATUSES = ['success', 'partial', 'pending', 'failed', 'refunded', 'reversed'];

    /**
     * @param string[] $items IDs used for goal mapping (product, form, level...), most specific first
     * @param string[] $aliases other references of the same payment (≤ 5)
     */
    public function __construct(
        public readonly string $source_plugin,
        public readonly string $gateway,
        public readonly string $reference,
        public readonly string $status,
        public readonly ?string $amount,
        public readonly ?string $currency,
        public readonly ?string $occurred_at = null,
        public readonly ?string $click_id = null,
        public readonly ?string $order_id = null,
        public readonly ?bool $test = null,
        public readonly array $items = [],
        public readonly array $aliases = [],
        public readonly ?string $event_id = null,
        public readonly ?string $refund_id = null
    ) {
    }

    /** Every confirmation needs a reference, a known status and (except pending/failed) an amount above zero and a currency. */
    public function problem(): ?string
    {
        $reference = trim($this->reference);
        if ($reference === '' || strlen($reference) > 128) {
            return 'invalid_reference';
        }
        if (!in_array($this->status, self::STATUSES, true)) {
            return 'invalid_status';
        }
        if (!in_array($this->status, ['pending', 'failed'], true)) {
            if (Money::positive($this->amount) === null) {
                return 'invalid_amount';
            }
            if (Money::currency($this->currency) === null) {
                return 'invalid_currency';
            }
        }
        return null;
    }

    /**
     * The body of POST /v1/plugin/payments. In private mode Clikgate refuses `order_id`, so it is left out.
     */
    public function payment_body(string $provider, ?string $goal, bool $private_mode = false): array
    {
        $body = [
            'provider' => $provider,
            'reference' => trim($this->reference),
            'status' => $this->status,
            'source_plugin' => $this->source_plugin,
        ];
        if (Money::positive($this->amount) !== null) {
            $body['amount'] = Money::positive($this->amount);
        }
        if (Money::currency($this->currency) !== null) {
            $body['currency'] = Money::currency($this->currency);
        }
        $body += array_filter([
            'occurred_at' => self::iso($this->occurred_at),
            'click_id' => Click::valid($this->click_id) ? $this->click_id : null,
            'goal' => $goal !== null && $goal !== '' ? $goal : null,
            'order_id' => !$private_mode && $this->order_id !== null && $this->order_id !== '' ? substr($this->order_id, 0, 64) : null,
        ], static fn ($v) => $v !== null);
        $aliases = array_values(array_unique(array_filter(
            array_map(static fn ($a): string => substr(trim((string) $a), 0, 128), $this->aliases),
            fn (string $a): bool => $a !== '' && $a !== trim($this->reference)
        )));
        if ($aliases !== []) {
            $body['aliases'] = array_slice($aliases, 0, 5);
        }
        // refund_id (1-64) makes each refund its own confirmation; Clikgate allows it only on refunded/reversed
        $refund_id = $this->refund_id !== null ? substr(trim($this->refund_id), 0, 64) : '';
        if ($refund_id !== '' && in_array($this->status, ['refunded', 'reversed'], true)) {
            $body['refund_id'] = $refund_id;
        }
        if ($this->test !== null) {
            $body['test'] = $this->test;
        }
        return $body;
    }

    /** The body of POST /v1/plugin/goals, for a result mapped to a Standard goal. */
    public function goal_body(string $goal): array
    {
        return array_filter([
            'goal' => $goal,
            'reference' => substr($this->source_plugin . ':' . trim($this->reference), 0, 128),
            'click_id' => Click::valid($this->click_id) ? $this->click_id : null,
            'value' => Money::positive($this->amount),
            'currency' => Money::currency($this->currency),
            'occurred_at' => self::iso($this->occurred_at),
        ], static fn ($v) => $v !== null);
    }

    /** Same plugin, record, status and amount = the same confirmation (refunds add their own event id). */
    public function dedupe_key(): string
    {
        return md5(implode('|', [$this->source_plugin, $this->order_id ?? '', trim($this->reference), $this->status, (string) Money::positive($this->amount), $this->event_id ?? '', $this->refund_id ?? '']));
    }

    /** ISO 8601 (UTC) from a timestamp, a WC_DateTime / DateTimeInterface, or a date string; null when unknown. */
    public static function iso($when): ?string
    {
        if ($when instanceof \DateTimeInterface) {
            return gmdate('Y-m-d\TH:i:s\Z', $when->getTimestamp());
        }
        if (is_int($when) || (is_string($when) && ctype_digit($when) && strlen($when) >= 9)) {
            return gmdate('Y-m-d\TH:i:s\Z', (int) $when);
        }
        if (!is_string($when) || trim($when) === '') {
            return null;
        }
        // Plugins store MySQL dates in UTC ("Y-m-d H:i:s"); other strings must carry their own zone
        $utc = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $when) === 1 ? $when . ' UTC' : $when;
        $time = strtotime($utc);
        if ($time === false) {
            return null;
        }
        // Never in the future (Clikgate refuses more than 5 minutes ahead)
        return gmdate('Y-m-d\TH:i:s\Z', min($time, time()));
    }
}
