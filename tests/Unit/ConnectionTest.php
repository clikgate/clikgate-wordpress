<?php

declare(strict_types=1);

namespace Clikgate\WP\Tests\Unit;

use Clikgate\WP\Connection;
use Clikgate\WP\Tests\TestCase;
use WpStub;

final class ConnectionTest extends TestCase
{
    public function test_stores_the_plugin_key_encrypted_and_reads_it_back(): void
    {
        $this->assertTrue(Connection::save_from_token(self::token()));
        $stored = (string) WpStub::$options[Connection::OPTION]['key'];
        $this->assertStringStartsWith('sb1:', $stored);
        $this->assertStringNotContainsString(self::PLUGIN_KEY, serialize(WpStub::$options), 'the key is never stored in clear');
        $this->assertSame(self::PLUGIN_KEY, Connection::key());
        $this->assertTrue(Connection::connected());
        $this->assertSame('live', Connection::mode());
        $this->assertSame('Shopwave', Connection::get()['organization']['name']);
        $this->assertSame('cgw_live_' . self::WEB_KEY_BODY, Connection::remote()['web_key']);
    }

    public function test_refuses_tokens_that_are_not_plugin_keys(): void
    {
        $cli = self::token();
        $cli['token_type'] = 'bearer';
        $this->assertFalse(Connection::save_from_token($cli));
        foreach (['sk_live_wrong', 'cg_live_short', 'cg_live_' . str_repeat('a', 39) . '_', 'cg_prod_' . str_repeat('a', 40)] as $key) {
            $bad = self::token();
            $bad['access_token'] = $key;
            $this->assertFalse(Connection::save_from_token($bad), $key);
        }
        $this->assertFalse(Connection::connected());
    }

    public function test_a_key_sealed_with_other_salts_is_unreadable(): void
    {
        Connection::save_from_token(self::token());
        $saved = WpStub::$options[Connection::OPTION];
        $saved['key'] = 'sb1:' . base64_encode(str_repeat('x', 60));
        WpStub::$options[Connection::OPTION] = $saved;
        $this->assertNull(Connection::key());
        $this->assertFalse(Connection::connected());
    }

    public function test_disconnected_stops_sending(): void
    {
        Connection::save_from_token(self::token('test'));
        $this->assertSame('test', Connection::mode());
        Connection::mark_disconnected('plugin_disconnected');
        $this->assertFalse(Connection::connected());
        $this->assertSame('plugin_disconnected', Connection::get()['disconnected']['code']);
    }

    public function test_remote_settings_are_sanitised(): void
    {
        $this->connect();
        Connection::save_remote([
            'web_key' => '<script>',
            'click_id_param' => 'bad param!',
            'goals' => [
                ['key' => 'Order_Paid', 'name' => '<b>Order</b>', 'kind' => 'payment'],
                ['key' => 'lead', 'name' => 'Lead', 'kind' => 'something_else'],
                ['key' => '!!'],
            ],
            'private_mode' => true,
            'providers' => ['paystack', 'Bad Provider'],
            'last_results' => array_fill(0, 12, ['id' => 'pcf_x']),
        ]);
        $remote = Connection::remote();
        $this->assertSame('', $remote['web_key']);
        $this->assertSame('cgid', $remote['click_id_param']);
        $this->assertSame([
            ['key' => 'order_paid', 'name' => 'Order', 'kind' => 'payment', 'default_currency' => ''],
            ['key' => 'lead', 'name' => 'Lead', 'kind' => 'standard', 'default_currency' => ''],
        ], $remote['goals']);
        $this->assertTrue(Connection::private_mode());
        $this->assertSame(['paystack'], $remote['providers']);
        $this->assertCount(10, $remote['last_results']);
        $this->assertSame('payment', Connection::goal('order_paid')['kind']);
        $this->assertNull(Connection::goal('missing'));
    }

    public function test_forget_removes_everything(): void
    {
        $this->connect();
        Connection::forget();
        $this->assertNull(Connection::get());
        $this->assertSame([], Connection::remote());
    }
}
