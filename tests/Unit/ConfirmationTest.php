<?php

declare(strict_types=1);

namespace Clikgate\WP\Tests\Unit;

use Clikgate\WP\Confirmation;
use Clikgate\WP\Tests\TestCase;

final class ConfirmationTest extends TestCase
{
    private function make(array $over = []): Confirmation
    {
        $args = $over + [
            'source_plugin' => 'woocommerce', 'gateway' => 'paystack', 'reference' => ' T_ref_001 ', 'status' => 'success',
            'amount' => '7500.00', 'currency' => 'ngn', 'occurred_at' => '2026-10-27 10:00:00', 'click_id' => self::CLICK,
            'order_id' => '1234',
        ];
        return new Confirmation(...$args);
    }

    public function test_payment_body_has_only_the_contract_fields(): void
    {
        $body = $this->make(['aliases' => ['T_ref_001', 'alias-2', 'alias-2', '']])->payment_body('paystack', 'order_paid');
        $this->assertSame([
            'provider' => 'paystack', 'reference' => 'T_ref_001', 'status' => 'success', 'source_plugin' => 'woocommerce',
            'amount' => '7500', 'currency' => 'NGN', 'occurred_at' => '2026-10-27T10:00:00Z', 'click_id' => self::CLICK,
            'goal' => 'order_paid', 'order_id' => '1234', 'aliases' => ['alias-2'],
        ], $body);
    }

    public function test_private_mode_leaves_out_the_order_number(): void
    {
        $body = $this->make()->payment_body('paystack', 'order_paid', true);
        $this->assertArrayNotHasKey('order_id', $body);
        $this->assertSame(['paystack', 'T_ref_001', 'success', 'order_paid'], [$body['provider'], $body['reference'], $body['status'], $body['goal']]);
    }

    public function test_optional_fields_are_left_out(): void
    {
        $body = $this->make(['click_id' => 'not-a-click', 'order_id' => null, 'occurred_at' => null, 'test' => true])->payment_body('paystack', null);
        $this->assertArrayNotHasKey('click_id', $body);
        $this->assertArrayNotHasKey('goal', $body);
        $this->assertArrayNotHasKey('order_id', $body);
        $this->assertArrayNotHasKey('occurred_at', $body);
        $this->assertTrue($body['test']);
    }

    public function test_refund_id_only_on_refunds(): void
    {
        $refund = $this->make(['status' => 'refunded', 'refund_id' => ' 1300 '])->payment_body('paystack', null);
        $this->assertSame('1300', $refund['refund_id']);
        $this->assertArrayNotHasKey('refund_id', $this->make(['refund_id' => '1300'])->payment_body('paystack', null), 'refused by Clikgate on success');
        $this->assertSame(64, strlen($this->make(['status' => 'reversed', 'refund_id' => str_repeat('r', 80)])->payment_body('paystack', null)['refund_id']));
        $this->assertNotSame(
            $this->make(['status' => 'refunded', 'refund_id' => '1'])->dedupe_key(),
            $this->make(['status' => 'refunded', 'refund_id' => '2'])->dedupe_key()
        );
    }

    public function test_problems(): void
    {
        $this->assertNull($this->make()->problem());
        $this->assertSame('invalid_reference', $this->make(['reference' => '  '])->problem());
        $this->assertSame('invalid_reference', $this->make(['reference' => str_repeat('a', 129)])->problem());
        $this->assertSame('invalid_status', $this->make(['status' => 'paid'])->problem());
        $this->assertSame('invalid_amount', $this->make(['amount' => '0'])->problem());
        $this->assertSame('invalid_currency', $this->make(['currency' => null])->problem());
        $this->assertNull($this->make(['status' => 'failed', 'amount' => null, 'currency' => null])->problem());
    }

    public function test_goal_body_for_ordinary_goals(): void
    {
        $this->assertSame(
            ['goal' => 'lead', 'reference' => 'woocommerce:T_ref_001', 'click_id' => self::CLICK, 'value' => '7500', 'currency' => 'NGN', 'occurred_at' => '2026-10-27T10:00:00Z'],
            $this->make()->goal_body('lead')
        );
    }

    public function test_dedupe_key_changes_with_status_amount_and_event(): void
    {
        $a = $this->make()->dedupe_key();
        $this->assertSame($a, $this->make()->dedupe_key());
        $this->assertNotSame($a, $this->make(['status' => 'refunded'])->dedupe_key());
        $this->assertNotSame($a, $this->make(['amount' => '100'])->dedupe_key());
        $this->assertNotSame($a, $this->make(['event_id' => '99'])->dedupe_key());
    }

    public function test_iso(): void
    {
        $this->assertSame('2026-10-27T10:00:00Z', Confirmation::iso('2026-10-27 10:00:00'));
        $this->assertSame('2026-10-27T09:00:00Z', Confirmation::iso('2026-10-27T10:00:00+01:00'));
        $this->assertSame('2026-10-27T09:00:00Z', Confirmation::iso(new \DateTimeImmutable('2026-10-27T09:00:00Z')));
        $this->assertSame('2023-11-14T22:13:20Z', Confirmation::iso(1700000000));
        $this->assertNull(Confirmation::iso(''));
        $this->assertNull(Confirmation::iso('not a date'));
        $this->assertLessThanOrEqual(time(), strtotime((string) Confirmation::iso('2099-01-01 00:00:00')), 'never in the future');
    }
}
