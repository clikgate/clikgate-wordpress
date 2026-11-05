<?php

declare(strict_types=1);

namespace Clikgate\WP\Tests\Unit;

use Clikgate\WP\Confirmation;
use Clikgate\WP\Dispatcher;
use Clikgate\WP\Log;
use Clikgate\WP\Options;
use Clikgate\WP\Queue;
use Clikgate\WP\Tests\TestCase;
use WpStub;

final class DispatcherTest extends TestCase
{
    private function confirmation(array $over = []): Confirmation
    {
        return new Confirmation(...($over + [
            'source_plugin' => 'woocommerce', 'gateway' => 'paystack', 'reference' => 'T_ref_001', 'status' => 'success',
            'amount' => '7500.00', 'currency' => 'NGN', 'click_id' => self::CLICK, 'order_id' => '1234', 'items' => ['55'],
        ]));
    }

    public function test_nothing_is_queued_before_connecting(): void
    {
        $this->assertSame('skipped', (new Dispatcher())->confirm($this->confirmation()));
        $this->assertSame([], Queue::all());
    }

    public function test_a_payment_is_queued_for_route_c_and_flushed_by_cron(): void
    {
        $this->connect();
        $this->assertSame('queued', (new Dispatcher())->confirm($this->confirmation()));
        $queue = Queue::all();
        $this->assertCount(1, $queue);
        $this->assertSame('/v1/plugin/payments', $queue[0]['path']);
        $this->assertSame('paystack', $queue[0]['body']['provider']);
        $this->assertSame(self::CLICK, $queue[0]['body']['click_id']);
        $this->assertSame([], WpStub::$requests, 'nothing is sent in the shopper\'s request');
        $this->assertNotFalse(wp_next_scheduled('clikgate_flush'));
        $this->assertSame('duplicate', (new Dispatcher())->confirm($this->confirmation()));
    }

    public function test_goal_mapping_picks_the_endpoint(): void
    {
        $this->connect();
        Options::save(['goals' => ['woocommerce' => 'order_paid'], 'overrides' => ['woocommerce' => '55 = lead']]);
        (new Dispatcher())->confirm($this->confirmation());
        $item = Queue::all()[0];
        $this->assertSame('/v1/plugin/goals', $item['path'], 'a Standard goal gets a goal event');
        $this->assertSame('lead', $item['body']['goal']);
        $this->assertSame('7500', $item['body']['value']);

        $this->assertSame('skipped', (new Dispatcher())->confirm($this->confirmation(['status' => 'refunded', 'amount' => '100'])), 'refunds of Standard goals are not sent');

        (new Dispatcher())->confirm($this->confirmation(['reference' => 'T_ref_002', 'items' => ['77']]));
        $this->assertSame('/v1/plugin/payments', Queue::all()[1]['path']);
        $this->assertSame('order_paid', Queue::all()[1]['body']['goal']);
    }

    public function test_private_mode_leaves_out_the_order_number(): void
    {
        $this->connect('live', ['private_mode' => true]);
        (new Dispatcher())->confirm($this->confirmation());
        $body = Queue::all()[0]['body'];
        $this->assertArrayNotHasKey('order_id', $body);
        $this->assertSame(['paystack', 'T_ref_001', self::CLICK], [$body['provider'], $body['reference'], $body['click_id']]);
    }

    public function test_unknown_and_manual_gateways_go_as_custom(): void
    {
        $this->connect();
        $this->assertSame('queued', (new Dispatcher())->confirm($this->confirmation(['gateway' => 'bacs', 'reference' => 'wc-order-1'])));
        $this->assertSame('queued', (new Dispatcher())->confirm($this->confirmation(['gateway' => 'mystery'])));
        $this->assertSame(['custom', 'custom'], array_map(static fn ($i) => $i['body']['provider'], Queue::all()));
        $this->assertSame(self::CLICK, Queue::all()[0]['body']['click_id']);
    }

    public function test_without_custom_listed_unknown_gateways_are_logged_not_sent(): void
    {
        $this->connect('live', ['providers' => ['paystack']]);
        $this->assertSame('skipped', (new Dispatcher())->confirm($this->confirmation(['gateway' => 'mystery'])));
        $this->assertSame('unknown_gateway', Log::all()[0]['code']);
        $this->assertSame([], Queue::all());
    }

    public function test_test_payments_never_go_to_a_live_connection(): void
    {
        $this->connect('live');
        $this->assertSame('skipped', (new Dispatcher())->confirm($this->confirmation(['test' => true])));
        $this->assertSame('environment_mismatch', Log::all()[0]['code']);
        $this->assertSame('queued', (new Dispatcher())->confirm($this->confirmation(['test' => false])));
    }

    public function test_invalid_results_and_turned_off_integrations(): void
    {
        $this->connect();
        $this->assertSame('skipped', (new Dispatcher())->confirm($this->confirmation(['amount' => '0'])));
        $this->assertSame('invalid_amount', Log::all()[0]['code']);
        Options::save(['integrations' => ['woocommerce' => '0']]);
        $this->assertSame('skipped', (new Dispatcher())->confirm($this->confirmation()));
    }

    public function test_the_filter_can_drop_a_result(): void
    {
        $this->connect();
        add_filter('clikgate_confirmation', static fn () => null);
        $this->assertSame('skipped', (new Dispatcher())->confirm($this->confirmation()));
    }

    public function test_the_log_holds_no_references_or_keys(): void
    {
        $this->connect('live', ['providers' => ['paystack']]);
        (new Dispatcher())->confirm($this->confirmation(['gateway' => 'mystery']));
        $log = serialize(Log::all());
        $this->assertStringNotContainsString('T_ref_001', $log);
        $this->assertStringNotContainsString(self::PLUGIN_KEY, $log);
    }
}
