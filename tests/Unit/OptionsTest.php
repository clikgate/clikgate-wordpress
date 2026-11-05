<?php

declare(strict_types=1);

namespace Clikgate\WP\Tests\Unit;

use Clikgate\WP\Goals;
use Clikgate\WP\Options;
use Clikgate\WP\Tests\TestCase;

final class OptionsTest extends TestCase
{
    public function test_defaults(): void
    {
        $options = Options::all();
        $this->assertTrue($options['script']);
        $this->assertFalse($options['consent_required']);
        $this->assertFalse($options['payments_auto']);
        $this->assertTrue(Options::integration_enabled('woocommerce'));
    }

    public function test_sanitize_the_settings_form(): void
    {
        Options::save([
            'script' => '1',
            'payments_auto' => '1',
            'integrations' => ['woocommerce' => '0', 'gravityforms' => '1'],
            'goals' => ['woocommerce' => 'Order_Paid', 'gravityforms' => 'bad goal!'],
            'overrides' => ['woocommerce' => "12 = vip_order\n# comment\n13=  \n bad line\n14 = lead # trailing"],
        ]);
        $options = Options::all();
        $this->assertTrue($options['payments_auto']);
        $this->assertFalse(Options::integration_enabled('woocommerce'));
        $this->assertTrue(Options::integration_enabled('gravityforms'));
        $this->assertSame(['woocommerce' => 'order_paid'], $options['goals']);
        $this->assertSame(['12' => 'vip_order', '14' => 'lead'], $options['overrides']['woocommerce']);
        $this->assertSame("12 = vip_order\n14 = lead", Options::format_overrides($options['overrides']['woocommerce']));
    }

    public function test_goal_resolution_order(): void
    {
        $this->connect();
        Options::save(['goals' => ['woocommerce' => 'order_paid'], 'overrides' => ['woocommerce' => "55 = lead"]]);
        $this->assertSame('lead', Goals::resolve('woocommerce', ['54', '55']));
        $this->assertSame('order_paid', Goals::resolve('woocommerce', ['54']));
        $this->assertNull(Goals::resolve('gravityforms', ['1']));
        $this->assertTrue(Goals::is_plain_goal('lead'));
        $this->assertFalse(Goals::is_plain_goal('order_paid'));
        $this->assertFalse(Goals::is_plain_goal('unknown_goal'), 'unknown goals go to /v1/plugin/payments and Clikgate decides');
        $this->assertFalse(Goals::is_plain_goal(null));
    }
}
