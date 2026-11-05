<?php

declare(strict_types=1);

namespace Clikgate\WP\Tests\Unit;

use Clikgate\WP\Connection;
use Clikgate\WP\Log;
use Clikgate\WP\Queue;
use Clikgate\WP\Tests\TestCase;
use WpStub;

final class QueueTest extends TestCase
{
    private function push(string $key = 'k1'): void
    {
        Queue::push('/v1/plugin/payments', ['provider' => 'paystack', 'reference' => 'T_' . $key, 'status' => 'success'], $key, ['source' => 'woocommerce', 'order_id' => '1']);
    }

    public function test_sends_with_the_plugin_key_and_logs_the_match(): void
    {
        $this->connect();
        $this->push();
        WpStub::respond(200, ['object' => 'payment_confirmation', 'id' => 'pcf_1', 'match_status' => 'matched', 'duplicate' => false]);
        $this->assertSame(1, Queue::flush());
        $request = $this->lastRequest();
        $this->assertSame('https://api.clikgate.com/v1/plugin/payments', $request['url']);
        $this->assertSame('Bearer ' . self::PLUGIN_KEY, $request['headers']['Authorization']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);
        $this->assertSame([], Queue::all());
        $this->assertSame('sent', Log::all()[0]['outcome']);
        $this->assertSame('matched', Log::all()[0]['match_status']);
        // The same key never goes out twice
        $this->push();
        $this->assertSame([], Queue::all());
    }

    public function test_private_mode_switched_on_later_drops_the_queued_order_number(): void
    {
        $this->connect();
        Queue::push('/v1/plugin/payments', ['provider' => 'paystack', 'reference' => 'T_1', 'status' => 'success', 'order_id' => '1234'], 'k1', ['source' => 'woocommerce']);
        $remote = Connection::remote();
        $remote['private_mode'] = true;
        update_option(Connection::REMOTE, $remote);
        WpStub::respond(200, ['duplicate' => false]);
        $this->assertSame(1, Queue::flush());
        $this->assertSame(['provider' => 'paystack', 'reference' => 'T_1', 'status' => 'success'], $this->lastRequest()['body']);
    }

    public function test_retries_server_errors_with_backoff(): void
    {
        $this->connect();
        $this->push();
        WpStub::respond(503, ['error' => ['code' => 'unavailable']]);
        $this->assertSame(0, Queue::flush());
        $item = Queue::all()[0];
        $this->assertSame(1, $item['attempts']);
        $this->assertGreaterThan(time() + 30, $item['next_at']);
        // Not due yet: no request
        $before = count(WpStub::$requests);
        Queue::flush();
        $this->assertCount($before, WpStub::$requests);
    }

    public function test_respects_retry_after_and_network_errors(): void
    {
        $this->connect();
        $this->push();
        WpStub::respond(429, ['error' => ['code' => 'rate_limit_exceeded']], ['retry-after' => '900']);
        Queue::flush();
        $this->assertGreaterThanOrEqual(time() + 899, Queue::all()[0]['next_at']);

        WpStub::reset();
        $this->connect();
        $this->push();
        WpStub::network_error();
        Queue::flush();
        $this->assertSame(1, Queue::all()[0]['attempts']);
    }

    public function test_gives_up_after_the_last_backoff(): void
    {
        $this->connect();
        $this->push();
        $queue = Queue::all();
        $queue[0]['attempts'] = count(Queue::BACKOFF);
        update_option(Queue::OPTION, $queue);
        WpStub::respond(500);
        Queue::flush();
        $this->assertSame([], Queue::all());
        $this->assertSame('gave_up', Log::all()[0]['outcome']);
    }

    public function test_client_errors_are_final(): void
    {
        $this->connect();
        $this->push();
        WpStub::respond(422, ['error' => ['code' => 'unknown_provider', 'message' => 'Not a provider key.']]);
        Queue::flush();
        $this->assertSame([], Queue::all());
        $this->assertSame('unknown_provider', Log::all()[0]['code']);
    }

    public function test_a_disconnected_site_keeps_its_queue(): void
    {
        $this->connect();
        $this->push('a');
        $this->push('b');
        WpStub::respond(403, ['error' => ['code' => 'plugin_disconnected', 'message' => 'This website was disconnected.']]);
        Queue::flush();
        $this->assertFalse(Connection::connected());
        $this->assertCount(2, Queue::all());
        $this->assertCount(1, WpStub::$requests);
        $this->assertSame(0, Queue::flush(), 'nothing is sent while disconnected');
    }

    public function test_a_revoked_key(): void
    {
        $this->connect();
        $this->push();
        WpStub::respond(401, ['error' => ['code' => 'invalid_api_key']]);
        Queue::flush();
        $this->assertFalse(Connection::connected());
    }

    public function test_plan_and_scope_refusals_keep_the_queue_and_show_a_notice(): void
    {
        $this->connect();
        $this->push('a');
        $this->push('b');
        WpStub::respond(403, ['error' => ['code' => 'plan_feature_unavailable', 'message' => 'Upgrade', 'feature' => 'payment_goals', 'required_plan' => 'starter']]);
        Queue::flush();
        $this->assertCount(1, WpStub::$requests, 'stops at the first refusal');
        $this->assertCount(2, Queue::all());
        $this->assertGreaterThan(time() + 3000, Queue::all()[1]['next_at']);
        $this->assertSame(0, Queue::all()[0]['attempts']);
        $this->assertTrue(Connection::connected());
        $this->assertSame('plan_feature_unavailable', Connection::remote()['blocked']['code']);

        // After the upgrade the next accepted send clears the notice
        $queue = Queue::all();
        foreach ($queue as $i => $item) {
            $queue[$i]['next_at'] = 0;
        }
        update_option(Queue::OPTION, $queue);
        WpStub::respond(200, ['duplicate' => false]);
        WpStub::respond(200, ['duplicate' => false]);
        $this->assertSame(2, Queue::flush());
        $this->assertNull(Connection::remote()['blocked']);
    }

    public function test_insufficient_scope_is_blocking_too(): void
    {
        $this->connect();
        $this->push();
        WpStub::respond(403, ['error' => ['code' => 'insufficient_scope']]);
        Queue::flush();
        $this->assertCount(1, Queue::all());
        $this->assertSame('insufficient_scope', Connection::remote()['blocked']['code']);
    }

    public function test_the_queue_is_bounded(): void
    {
        $this->connect();
        for ($i = 0; $i < Queue::MAX + 2; $i++) {
            $this->push('k' . $i);
        }
        $this->assertCount(Queue::MAX, Queue::all());
        $this->assertSame('queue_full', Log::all()[0]['code']);
    }

    public function test_a_flush_already_running_is_left_alone(): void
    {
        $this->connect();
        $this->push();
        set_transient('clikgate_flush_lock', 1);
        $this->assertSame(0, Queue::flush());
        $this->assertSame([], WpStub::$requests);
    }
}
