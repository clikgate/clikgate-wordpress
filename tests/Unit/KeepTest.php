<?php

declare(strict_types=1);

namespace Clikgate\WP\Tests\Unit;

use Clikgate\WP\Keep;
use Clikgate\WP\Options;
use Clikgate\WP\Script;
use Clikgate\WP\Tests\TestCase;
use WpStub;

/** The keep REST route, on by default, with the web script attribute. */
final class KeepTest extends TestCase
{
    private const KEEP_CLICK = 'clk_01JCK8Z9V7Q4M2N6P8R0S2T4W6';
    private const KEEP_OTHER = 'clk_01JCK8Z9V7Q4M2N6P8R0S2T4W7';
    private const SAME = ['sec-fetch-site' => 'same-origin', 'host' => 'shop.example', 'content-type' => 'application/json'];

    private static function body(string $id, ?string $page = null): string
    {
        return (string) json_encode(['click_id' => $id, 'page_click_id' => $page ?? $id]);
    }

    /** @param array{status: int, headers: list<array{0: string, 1: string}>} $answer @return list<string> */
    private static function cookies(array $answer): array
    {
        return array_values(array_map(static fn (array $h): string => $h[1], array_filter($answer['headers'], static fn (array $h): bool => $h[0] === 'Set-Cookie')));
    }

    public function test_same_origin_sets_the_cookie_and_the_marker(): void
    {
        $this->connect('live', ['click_id_param' => 'dcid']);
        $answer = Keep::respond('POST', self::SAME, self::body(self::KEEP_CLICK));
        $this->assertSame(204, $answer['status']);
        $this->assertContains(['Cache-Control', 'no-store, private'], $answer['headers']);
        $this->assertSame([
            '_cg_dcid=' . self::KEEP_CLICK . '; Path=/; Max-Age=2592000; SameSite=Lax; Secure',
            '_cg_kept=s; Path=/; Max-Age=2592000; SameSite=Lax; Secure',
        ], self::cookies($answer));
        foreach (self::cookies($answer) as $cookie) {
            $this->assertStringNotContainsString('HttpOnly', $cookie);
        }
    }

    public function test_origin_matching_the_host_is_accepted(): void
    {
        $this->connect();
        $answer = Keep::respond('POST', ['origin' => 'https://shop.example', 'host' => 'shop.example'], self::body(self::KEEP_CLICK));
        $this->assertSame(204, $answer['status']);
    }

    /** @return array<string, array{0: string, 1: array<string, string>, 2: string, 3: int}> */
    public static function refusals(): array
    {
        $body = self::body(self::KEEP_CLICK);
        return [
            'GET' => ['GET', self::SAME, $body, 405],
            'cross-site' => ['POST', ['sec-fetch-site' => 'cross-site', 'origin' => 'https://evil.example', 'host' => 'shop.example'], $body, 403],
            'same-site subdomain' => ['POST', ['sec-fetch-site' => 'same-site', 'host' => 'shop.example'], $body, 403],
            'other origin' => ['POST', ['origin' => 'https://evil.example', 'host' => 'shop.example'], $body, 403],
            'other port' => ['POST', ['origin' => 'https://shop.example:8443', 'host' => 'shop.example'], $body, 403],
            'no origin' => ['POST', ['host' => 'shop.example'], $body, 403],
            'null origin' => ['POST', ['origin' => 'null', 'host' => 'shop.example'], $body, 403],
            'mismatch' => ['POST', self::SAME, self::body(self::KEEP_CLICK, self::KEEP_OTHER), 400],
            'invalid' => ['POST', self::SAME, self::body('clk_bad'), 400],
            'markup' => ['POST', self::SAME, self::body('<script>alert(1)</script>'), 400],
            'not json' => ['POST', self::SAME, 'click_id=' . self::KEEP_CLICK, 400],
            'list' => ['POST', self::SAME, '[]', 400],
            'too large' => ['POST', self::SAME, (string) json_encode(['click_id' => self::KEEP_CLICK, 'page_click_id' => self::KEEP_CLICK, 'pad' => str_repeat('x', 1100)]), 400],
        ];
    }

    /**
     * @dataProvider refusals
     * @param array<string, string> $headers
     */
    public function test_refused_without_cookies_or_echo(string $method, array $headers, string $body, int $status): void
    {
        $this->connect();
        $answer = Keep::respond($method, $headers, $body);
        $this->assertSame($status, $answer['status']);
        $this->assertSame([], self::cookies($answer));
        $this->assertContains(['Cache-Control', 'no-store, private'], $answer['headers']);
        $this->assertStringNotContainsString('script', (string) json_encode($answer));
        foreach ($answer['headers'] as [$name]) {
            $this->assertNotSame('Location', $name);
        }
    }

    public function test_the_cookie_name_never_comes_from_the_request(): void
    {
        $this->connect();
        $body = (string) json_encode(['click_id' => self::KEEP_CLICK, 'page_click_id' => self::KEEP_CLICK, 'name' => 'wordpress_logged_in', 'param' => 'x']);
        $cookies = self::cookies(Keep::respond('POST', self::SAME, $body));
        $this->assertStringStartsWith('_cg_cgid=', $cookies[0]);
    }

    public function test_days_filter(): void
    {
        $this->connect();
        add_filter('clikgate_keep_days', static fn (): int => 90);
        $this->assertStringContainsString('Max-Age=7776000', self::cookies(Keep::respond('POST', self::SAME, self::body(self::KEEP_CLICK)))[0]);
    }

    public function test_on_by_default_with_the_script_attribute_and_can_be_turned_off(): void
    {
        $this->connect();
        $this->assertTrue(Options::all()['keep_click_id']);
        $this->assertStringContainsString('data-server-endpoint="https://shop.example/wp-json/clikgate/v1/keep"', Script::attributes());
        Keep::routes();
        $this->assertTrue(WpStub::$rest_routes !== []);

        Options::save(['script' => '1']);
        WpStub::$rest_routes = [];
        $this->assertFalse(Options::all()['keep_click_id']);
        $this->assertStringNotContainsString('data-server-endpoint', Script::attributes());
        Keep::routes();
        $this->assertSame([], WpStub::$rest_routes);
    }
}
