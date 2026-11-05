<?php
/**
 * Turns a source plugin's gateway id into a Clikgate provider key (`GET /v1/plugin/settings` `providers`). Exact ids
 * come first (from the gateway plugins' own code), then a provider key contained in the id
 * ("woo-monnify" → monnify). Manual payments (bank transfer, cash on delivery, cheque) and unknown gateways use the
 * generic provider `custom`: the business's own order record is the trust, and Clikgate counts them
 * through its fallback (click ID saved on the order, else unattributed). Filter: `clikgate_provider_for_gateway`.
 */

declare(strict_types=1);

namespace Clikgate\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Providers
{
    /** Clikgate's provider list, used until the first GET /v1/plugin/settings answer. */
    public const FALLBACK = [
        'paystack', 'flutterwave', 'monnify', 'squad', 'opay', 'interswitch', 'remita', 'korapay', 'paga',
        'moniepoint', 'hubtel', 'mpesa', 'pesapal', 'mtn_momo', 'airtel_money', 'yoco', 'payfast',
        'peach_payments', 'dpo_pay', 'stripe', 'paypal', 'adyen', 'square', 'checkout_com',
        'shopify', 'woocommerce', 'custom',
    ];

    /** The generic provider for manual payments and gateways without their own provider key. */
    public const GENERIC = 'custom';

    /** Gateway ids that don't contain their provider key. */
    private const EXACT = [
        'rave' => 'flutterwave',               // Flutterwave for WooCommerce (rave-woocommerce-payment-gateway)
        'ppcp-gateway' => 'paypal',            // WooCommerce PayPal Payments
        'ppcp-credit-card-gateway' => 'paypal',
        'ppec_paypal' => 'paypal',
        'square_credit_card' => 'square',
        'squareup' => 'square',
        'checkoutcom_card_payment' => 'checkout_com',
        'wc_checkout_com_cards' => 'checkout_com',
        'dpo' => 'dpo_pay',
        'dpo_pay' => 'dpo_pay',
        'woocommerce_payments' => 'custom',     // WooPayments: no provider key of its own
    ];

    /** Provider keys that are ordinary words or other providers' names in gateway ids. */
    private const EXACT_ONLY = ['remita', 'paga', 'shopify', 'woocommerce', 'custom'];

    /** Manual payment methods: sent as `custom` once the order is marked paid (processing/completed). */
    public const MANUAL = ['bacs', 'cheque', 'cod', 'manual', 'offline', 'free', 'check'];

    public static function resolve(string $gateway, string $source_plugin = ''): ?string
    {
        $id = strtolower(trim($gateway));
        $known = self::known();
        $provider = null;
        if ($id !== '' && !self::manual($id)) {
            if (isset(self::EXACT[$id])) {
                $provider = self::EXACT[$id];
            } elseif (in_array($id, $known, true)) {
                $provider = $id;
            } else {
                $normalised = str_replace(['-', ' '], '_', $id);
                $sorted = $known;
                usort($sorted, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
                foreach ($sorted as $key) {
                    // Generic words only match exactly ("woocommerce_x" is not the provider `woocommerce`)
                    if (strlen($key) >= 4 && !in_array($key, self::EXACT_ONLY, true) && str_contains($normalised, $key)) {
                        $provider = $key;
                        break;
                    }
                }
            }
        }
        $provider = apply_filters('clikgate_provider_for_gateway', $provider, $gateway, $source_plugin);
        if (is_string($provider) && in_array($provider, $known, true)) {
            return $provider;
        }
        // Manual and unknown gateways: the generic provider, when Clikgate lists it
        return in_array(self::GENERIC, $known, true) ? self::GENERIC : null;
    }

    public static function manual(string $gateway): bool
    {
        return in_array(strtolower(trim($gateway)), self::MANUAL, true);
    }

    /** @return string[] */
    public static function known(): array
    {
        $remote = Connection::remote()['providers'] ?? [];
        return is_array($remote) && $remote !== [] ? array_values(array_map('strval', $remote)) : self::FALLBACK;
    }
}
