<?php

declare(strict_types=1);

namespace Clikgate\WP\Tests;

use Clikgate\WP\Connection;
use WpStub;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** Made-up values only: none of these is a real key */
    public const PLUGIN_KEY = 'cg_live_FakeTestKey00000000000000000000000000000';
    public const WEB_KEY_BODY = 'FakeWebKey0000000000000000000000';
    public const CLICK = 'clk_01JABCDEFGHJKMNPQRSTVWXYZ0';

    protected function setUp(): void
    {
        parent::setUp();
        WpStub::reset();
    }

    /** @return array<string, mixed> */
    public static function token(string $mode = 'live'): array
    {
        return [
            'access_token' => $mode === 'test' ? str_replace('cg_live_', 'cg_test_', self::PLUGIN_KEY) : self::PLUGIN_KEY,
            'token_type' => 'plugin_key',
            'expires_in' => null,
            'organization' => ['id' => 'org_01TEST', 'name' => 'Shopwave'],
            'plugin' => [
                'key_id' => 'key_01TEST', 'mode' => $mode, 'connection_id' => 'slc_01TEST',
                'web_key' => 'cgw_' . $mode . '_' . self::WEB_KEY_BODY, 'settings_url' => 'https://api.clikgate.com/v1/plugin/settings',
            ],
        ];
    }

    protected function connect(string $mode = 'live', array $remote = []): void
    {
        Connection::save_from_token(self::token($mode));
        Connection::save_remote($remote + [
            'web_key' => 'cgw_' . $mode . '_' . self::WEB_KEY_BODY,
            'click_id_param' => 'cgid',
            'goals' => [
                ['key' => 'order_paid', 'name' => 'Order paid', 'kind' => 'payment', 'default_currency' => 'NGN'],
                ['key' => 'lead', 'name' => 'Lead', 'kind' => 'standard', 'default_currency' => 'NGN'],
            ],
            'providers' => ['paystack', 'flutterwave', 'stripe', 'paypal', 'square', 'monnify', 'checkout_com', 'dpo_pay', 'woocommerce', 'custom'],
        ]);
    }

    /** @return array{url: string, method: string, headers: array<string, string>, body: ?array<string, mixed>} */
    protected function lastRequest(): array
    {
        $this->assertNotEmpty(WpStub::$requests, 'no HTTP request was made');
        return WpStub::$requests[array_key_last(WpStub::$requests)];
    }
}
