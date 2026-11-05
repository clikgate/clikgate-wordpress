<?php

declare(strict_types=1);

namespace Clikgate\WP\Tests\Unit;

use Clikgate\WP\Options;
use Clikgate\WP\Script;
use Clikgate\WP\Tests\TestCase;
use WpStub;

final class ScriptTest extends TestCase
{
    private const TAG = "<script src=\"https://sdk.clikgate.com/v1/static/clikgate.js\" id=\"clikgate-js\" async data-wp-strategy=\"async\"></script>\n";

    public function test_not_added_before_connecting(): void
    {
        Script::enqueue();
        $this->assertSame([], WpStub::$enqueued);
        $this->assertSame(self::TAG, Script::tag(self::TAG, 'clikgate'));
    }

    public function test_added_with_the_web_key_and_click_parameter(): void
    {
        $this->connect('live', ['click_id_param' => 'dcid']);
        Script::enqueue();
        $this->assertSame(['clikgate', 'https://sdk.clikgate.com/v1/static/clikgate.js', [], null, ['strategy' => 'async', 'in_footer' => false]], WpStub::$enqueued[0]);
        $tag = Script::tag(self::TAG, 'clikgate');
        $this->assertStringStartsWith('<script data-web-key="cgw_live_FakeWebKey0000000000000000000000" data-param="dcid" data-server-endpoint="https://shop.example/wp-json/clikgate/v1/keep" src=', $tag);
        $this->assertStringNotContainsString('data-consent', $tag);
        $this->assertSame('<script src="other.js"></script>', Script::tag('<script src="other.js"></script>', 'jquery'));
    }

    public function test_consent_and_automatic_detection(): void
    {
        $this->connect();
        Options::save(['script' => '1', 'consent_required' => '1', 'payments_auto' => '1']);
        $this->assertSame('data-web-key="cgw_live_FakeWebKey0000000000000000000000" data-param="cgid" data-consent="required" data-clikgate-payments="auto"', Script::attributes());
    }

    public function test_can_be_turned_off(): void
    {
        $this->connect();
        Options::save(['script' => '']);
        Script::enqueue();
        $this->assertSame([], WpStub::$enqueued);
    }

    public function test_no_tag_without_a_web_key(): void
    {
        $this->connect('live', ['web_key' => '']);
        $this->assertFalse(Script::active());
    }
}
