<?php

declare(strict_types=1);

namespace Clikgate\WP\Tests\Unit;

use Clikgate\WP\Connection;
use Clikgate\WP\Plugin;
use Clikgate\WP\Tests\TestCase;
use WpStub;

final class PluginTest extends TestCase
{
    public function test_send_a_test(): void
    {
        $this->assertSame('not_connected', (new Plugin())->send_test()->code);
        $this->connect();
        WpStub::respond(200, ['object' => 'plugin_test', 'ok' => true, 'received_at' => '2026-10-27T10:00:00Z']);
        $response = (new Plugin())->send_test();
        $this->assertTrue($response->ok());
        $request = $this->lastRequest();
        $this->assertSame('https://api.clikgate.com/v1/plugin/test', $request['url']);
        $this->assertSame([], $request['body']);
        $this->assertSame('2026-10-27T10:00:00Z', Connection::remote()['last_test_at']);
    }

    public function test_disconnect_revokes_the_key_in_clikgate_first(): void
    {
        $this->connect();
        \Clikgate\WP\Queue::push('/v1/plugin/payments', ['provider' => 'paystack'], 'k', []);
        WpStub::respond(200, ['object' => 'plugin_disconnect', 'connection_id' => 'slc_01TEST', 'disconnected' => true]);
        $this->assertTrue((new Plugin())->disconnect());
        $request = $this->lastRequest();
        $this->assertSame('https://api.clikgate.com/v1/plugin/disconnect', $request['url']);
        $this->assertSame('POST', $request['method']);
        $this->assertSame([], $request['body']);
        $this->assertSame('Bearer ' . self::PLUGIN_KEY, $request['headers']['Authorization']);
        $this->assertNull(Connection::get());
        $this->assertCount(1, \Clikgate\WP\Queue::all(), 'waiting results are kept for a reconnect');
    }

    public function test_disconnect_deletes_the_key_locally_when_clikgate_is_unreachable(): void
    {
        $this->connect();
        WpStub::network_error();
        $this->assertFalse((new Plugin())->disconnect());
        $this->assertNull(Connection::get());
        $this->assertStringNotContainsString(self::PLUGIN_KEY, serialize(WpStub::$options));
        WpStub::reset();
        $this->connect();
        WpStub::respond(401, ['error' => ['code' => 'invalid_api_key']]);
        $this->assertFalse((new Plugin())->disconnect());
        $this->assertNull(Connection::get());
    }

    public function test_plan_refusal_on_refresh_is_shown(): void
    {
        $this->connect();
        WpStub::respond(403, ['error' => ['code' => 'plan_upgrade_required', 'message' => 'Upgrade to Starter']]);
        (new Plugin())->refresh_remote();
        $this->assertTrue(Connection::connected());
        $this->assertSame('plan_upgrade_required', Connection::remote()['blocked']['code']);
    }

    public function test_refresh_marks_a_deleted_connection(): void
    {
        $this->connect();
        WpStub::respond(403, ['error' => ['code' => 'plugin_disconnected']]);
        (new Plugin())->refresh_remote();
        $this->assertFalse(Connection::connected());
    }

    public function test_only_active_plugins_are_hooked(): void
    {
        $ids = array_map(static fn ($i) => $i->id(), (new Plugin())->available());
        // The test stubs define WooCommerce, EDD (edd_get_order) and Gravity Forms helpers but not GFForms
        $this->assertSame(['woocommerce', 'edd'], $ids);
        $this->assertCount(8, (new Plugin())->integrations());
    }

    public function test_every_source_plugin_id_is_valid_for_clikgate(): void
    {
        foreach ((new Plugin())->integrations() as $integration) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_-]{1,40}$/', $integration->id());
        }
    }
}
