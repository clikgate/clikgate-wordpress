<?php

declare(strict_types=1);

namespace Clikgate\WP\Tests\Unit;

use Clikgate\WP\Money;
use Clikgate\WP\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class MoneyTest extends TestCase
{
    public static function decimals(): array
    {
        return [
            [7500, '7500'], ['7500.00', '7500'], [1500.25, '1500.25'], ['10.70', '10.7'], ['0.10', '0.1'],
            ['-25.50', '-25.5'], [1e3, '1000'], ['abc', null], ['1e5', null], [null, null], [INF, null],
        ];
    }

    #[DataProvider('decimals')]
    public function test_decimal($input, ?string $expected): void
    {
        $this->assertSame($expected, Money::decimal($input));
    }

    public function test_positive(): void
    {
        $this->assertSame('25.5', Money::positive('-25.50'));
        $this->assertNull(Money::positive('0.00'));
        $this->assertNull(Money::positive(0));
    }

    /** Stripe amounts in minor units: two-decimal, zero-decimal and the ISK/UGX special case */
    public static function stripe(): array
    {
        return [[2000, 'usd', '20'], [500, 'jpy', '500'], [50000, 'ugx', '500'], [50000, 'isk', '500'], [1000, 'xof', '1000'], [1999, 'eur', '19.99'], [5, 'usd', '0.05']];
    }

    #[DataProvider('stripe')]
    public function test_from_stripe(int $minor, string $currency, string $expected): void
    {
        $this->assertSame($expected, Money::from_stripe($minor, $currency));
    }

    public function test_from_cents_and_currency(): void
    {
        $this->assertSame('100', Money::from_cents(10000));
        $this->assertSame('-0.05', Money::from_cents(-5));
        $this->assertSame('NGN', Money::currency(' ngn '));
        $this->assertNull(Money::currency('naira'));
    }
}
