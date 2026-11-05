<?php

declare(strict_types=1);

namespace Clikgate\WP\Tests\Unit;

use Clikgate\WP\Connection;
use Clikgate\WP\DeviceFlow;
use Clikgate\WP\Tests\TestCase;
use WpStub;

final class DeviceFlowTest extends TestCase
{
    private function start(): array
    {
        WpStub::respond(200, [
            'device_code' => 'dev_secret_code', 'user_code' => 'ABCD-EFGH',
            'verification_uri' => 'https://app.clikgate.com/connect/wordpress',
            'verification_uri_complete' => 'https://app.clikgate.com/connect/wordpress?code=ABCD-EFGH',
            'expires_in' => 600, 'interval' => 5,
        ]);
        return DeviceFlow::start(7);
    }

    /** Lets the next poll go out now. */
    private function due(): void
    {
        $pending = WpStub::$transients['clikgate_device_7'];
        $pending['next_poll_at'] = time() - 1;
        WpStub::$transients['clikgate_device_7'] = $pending;
    }

    public function test_start_asks_for_a_wordpress_plugin_code_and_keeps_the_device_code_on_the_server(): void
    {
        $result = $this->start();
        $request = $this->lastRequest();
        $this->assertSame('https://api.clikgate.com/v1/cli/device-codes', $request['url']);
        $this->assertSame('POST', $request['method']);
        $this->assertArrayNotHasKey('Authorization', $request['headers']);
        $this->assertSame('wordpress_plugin', $request['body']['client_type']);
        $this->assertSame('1.0.0', $request['body']['client_info']['plugin_version']);
        $this->assertSame('https://shop.example/', $request['body']['client_info']['site_url']);
        $this->assertTrue($request['body']['client_info']['woocommerce']);
        $this->assertTrue($result['ok']);
        $this->assertSame('ABCD-EFGH', $result['user_code']);
        $this->assertStringContainsString('code=ABCD-EFGH', $result['verification_uri_complete']);
        $this->assertArrayNotHasKey('device_code', $result, 'the device code never reaches the browser');
        $this->assertSame('dev_secret_code', WpStub::$transients['clikgate_device_7']['device_code']);
    }

    public function test_start_failure_is_reported(): void
    {
        WpStub::respond(429, ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Too many requests']]);
        $result = DeviceFlow::start(7);
        $this->assertFalse($result['ok']);
        $this->assertSame('rate_limit_exceeded', $result['code']);
    }

    public function test_poll_waits_for_the_interval_without_calling_clikgate(): void
    {
        $this->start();
        $before = count(WpStub::$requests);
        $this->assertSame('pending', DeviceFlow::poll(7)['state']);
        $this->assertCount($before, WpStub::$requests);
    }

    public function test_pending_and_slow_down(): void
    {
        $this->start();
        $this->due();
        WpStub::respond(428, ['error' => ['code' => 'authorization_pending']]);
        $this->assertSame(['state' => 'pending', 'interval' => 5], DeviceFlow::poll(7));
        $this->assertSame(['device_code' => 'dev_secret_code'], $this->lastRequest()['body']);
        $this->due();
        WpStub::respond(429, ['error' => ['code' => 'slow_down']]);
        $this->assertSame(['state' => 'pending', 'interval' => 10], DeviceFlow::poll(7));
    }

    public function test_denied_and_expired_end_the_flow(): void
    {
        $this->start();
        $this->due();
        WpStub::respond(403, ['error' => ['code' => 'access_denied']]);
        $this->assertSame('denied', DeviceFlow::poll(7)['state']);
        $this->assertArrayNotHasKey('clikgate_device_7', WpStub::$transients);

        $this->start();
        $this->due();
        WpStub::respond(410, ['error' => ['code' => 'expired_token']]);
        $this->assertSame('expired', DeviceFlow::poll(7)['state']);
        $this->assertSame('none', DeviceFlow::poll(7)['state']);
    }

    public function test_approval_stores_the_plugin_key_and_fetches_the_settings(): void
    {
        $this->start();
        $this->due();
        WpStub::respond(200, self::token());
        WpStub::respond(200, [
            'object' => 'plugin_settings', 'web_key' => 'cgw_live_' . self::WEB_KEY_BODY, 'click_id_param' => 'dcid',
            'goals' => [['key' => 'order_paid', 'name' => 'Order paid', 'kind' => 'payment']],
            'providers' => ['paystack'], 'last_results' => [], 'last_test_at' => null,
        ]);
        $result = DeviceFlow::poll(7);
        $this->assertSame(['state' => 'connected', 'organization' => 'Shopwave', 'mode' => 'live'], $result);
        $this->assertSame(self::PLUGIN_KEY, Connection::key());
        $settings = $this->lastRequest();
        $this->assertSame('https://api.clikgate.com/v1/plugin/settings', $settings['url']);
        $this->assertSame('GET', $settings['method']);
        $this->assertSame('Bearer ' . self::PLUGIN_KEY, $settings['headers']['Authorization']);
        $this->assertSame('dcid', Connection::remote()['click_id_param']);
        $this->assertArrayNotHasKey('clikgate_device_7', WpStub::$transients);
    }

    public function test_network_errors_keep_waiting(): void
    {
        $this->start();
        $this->due();
        WpStub::network_error();
        $this->assertSame('pending', DeviceFlow::poll(7)['state']);
    }
}
