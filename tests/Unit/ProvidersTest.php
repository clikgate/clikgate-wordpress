<?php

declare(strict_types=1);

namespace Clikgate\WP\Tests\Unit;

use Clikgate\WP\Providers;
use Clikgate\WP\Tests\TestCase;

final class ProvidersTest extends TestCase
{
    public function test_gateway_ids_map_to_providers(): void
    {
        $this->connect();
        $this->assertSame('paystack', Providers::resolve('paystack'));
        $this->assertSame('paystack', Providers::resolve('paystack-one'));
        $this->assertSame('flutterwave', Providers::resolve('rave'));
        $this->assertSame('paypal', Providers::resolve('ppcp-gateway'));
        $this->assertSame('square', Providers::resolve('square_credit_card'));
        $this->assertSame('stripe', Providers::resolve('stripe_cc'));
        $this->assertSame('stripe', Providers::resolve('stripe_checkout'), 'generic words never win over a provider');
        $this->assertSame('monnify', Providers::resolve('woo-monnify'));
        $this->assertSame('checkout_com', Providers::resolve('checkoutcom_card_payment'));
        $this->assertSame('checkout_com', Providers::resolve('checkout_com'));
        $this->assertSame('dpo_pay', Providers::resolve('dpo'));
        $this->assertSame('custom', Providers::resolve('woocommerce_payments'), 'WooPayments has no provider key');
    }

    public function test_manual_and_unknown_gateways_use_the_generic_provider(): void
    {
        $this->connect();
        foreach (['bacs', 'cod', 'cheque', '', 'some_unknown_gateway', 'woocommerce_checkout_thing'] as $gateway) {
            $this->assertSame('custom', Providers::resolve($gateway), $gateway);
        }
        $this->assertTrue(Providers::manual('BACS'));
        $this->assertFalse(Providers::manual('paystack'));
    }

    public function test_only_providers_clikgate_lists(): void
    {
        $this->connect('live', ['providers' => ['stripe']]);
        $this->assertNull(Providers::resolve('paystack'), 'without `custom` listed, nothing to send');
        $this->assertSame('stripe', Providers::resolve('stripe'));
    }

    public function test_fallback_list_has_the_exact_backend_keys(): void
    {
        $this->assertCount(27, Providers::FALLBACK);
        foreach (Providers::FALLBACK as $key) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{1,29}$/', $key);
        }
        $this->assertContains('dpo_pay', Providers::FALLBACK);
        $this->assertContains('checkout_com', Providers::FALLBACK);
        $this->assertNotContains('dpo', Providers::FALLBACK);
        $this->assertNotContains('checkout', Providers::FALLBACK);
    }

    public function test_falls_back_to_the_built_in_list_before_the_first_settings_fetch(): void
    {
        $this->assertSame('hubtel', Providers::resolve('hubtel'));
    }

    public function test_filter(): void
    {
        $this->connect();
        add_filter('clikgate_provider_for_gateway', static fn ($p, $gateway) => $gateway === 'my_gateway' ? 'paystack' : $p, 10, 2);
        $this->assertSame('paystack', Providers::resolve('my_gateway'));
    }
}
