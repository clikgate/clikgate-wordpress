<?php
/**
 * Amounts as Clikgate wants them: decimal strings in normal units, never floats in exponent
 * form, never kobo or cents.
 */

declare(strict_types=1);

namespace Clikgate\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Money
{
    /** Stripe zero-decimal currencies. ISK and UGX are sent ×100 by Stripe, so they divide by 100. */
    private const STRIPE_ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** A normal-units amount (number or numeric string) as a plain decimal string, or null when it isn't one. */
    public static function decimal($amount, int $max_decimals = 4): ?string
    {
        if (is_int($amount)) {
            return (string) $amount;
        }
        if (is_float($amount)) {
            if (!is_finite($amount)) {
                return null;
            }
            $amount = number_format($amount, $max_decimals, '.', '');
        }
        if (!is_string($amount)) {
            return null;
        }
        $amount = trim($amount);
        if (preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1) {
            return null;
        }
        if (str_contains($amount, '.')) {
            $amount = rtrim(rtrim($amount, '0'), '.');
        }
        return $amount === '-0' ? '0' : $amount;
    }

    /** An absolute decimal string greater than zero, or null. */
    public static function positive($amount): ?string
    {
        $decimal = self::decimal($amount);
        if ($decimal === null) {
            return null;
        }
        $decimal = ltrim($decimal, '-');
        return preg_match('/[1-9]/', $decimal) === 1 ? $decimal : null;
    }

    /** A Stripe amount (smallest unit, Stripe's rules) in normal units. */
    public static function from_stripe($minor, string $currency): ?string
    {
        if (!is_numeric($minor)) {
            return null;
        }
        $minor = (int) $minor;
        $currency = strtoupper($currency);
        if (in_array($currency, self::STRIPE_ZERO_DECIMAL, true)) {
            return (string) $minor;
        }
        return self::from_cents($minor);
    }

    /** An amount in hundredths (cents, kobo) in normal units, without floats. */
    public static function from_cents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        $whole = intdiv($cents, 100);
        $rest = $cents % 100;
        return (string) self::decimal($sign . $whole . '.' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT));
    }

    public static function currency($currency): ?string
    {
        $currency = strtoupper(trim((string) $currency));
        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null;
    }
}
