<?php

declare(strict_types=1);

namespace Clikgate\WP\Tests\Unit;

use Clikgate\WP\AppBanners;
use Clikgate\WP\Plugin;
use Clikgate\WP\Tests\TestCase;
use WpStub;

/** "Show my App banners on this site": approval, site creation, the loader tag and its page data. */
final class AppBannersTest extends TestCase
{
    private const SITE_KEY = 'cg_site_live_FakeSiteKey0000000000000';
    private const TAG = "<script src=\"https://api.clikgate.com/v1/static/app-banners.js\" id=\"clikgate-app-banners-js\" async data-wp-strategy=\"async\"></script>\n";

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private static function answer(array $changes = []): array
    {
        return $changes + [
            'object' => 'plugin_app_banners',
            'available' => true,
            'plan_required' => null,
            'upgrade_url' => null,
            'website_verified' => true,
            'site_host' => 'shop.example',
            'verify_url' => 'https://app.clikgate.com/settings/developers/websites',
            'approval' => null,
            'can_create' => false,
            'site' => null,
            'loader_url' => 'https://api.clikgate.com/v1/static/app-banners.js',
            'dashboard_url' => 'https://app.clikgate.com/app-banners',
        ];
    }

    private static function site(): array
    {
        return ['id' => 'abs_01TEST', 'site_key' => self::SITE_KEY, 'allowed_origins' => ['https://shop.example'], 'source' => 'wordpress_plugin'];
    }

    private static function approval(string $status): array
    {
        return ['id' => 'pap_01TEST', 'status' => $status, 'approve_url' => 'https://app.clikgate.com/app-banners/approvals/pap_01TEST', 'requested_at' => '2026-10-31T09:00:00Z', 'decided_at' => null];
    }

    /** @param array<string, mixed> $changes */
    private static function store(array $changes): void
    {
        WpStub::$options[AppBanners::OPTION] = $changes + (array) (WpStub::$options[AppBanners::OPTION] ?? []);
    }

    /** @return list<string> "METHOD path" of every request */
    private static function calls(): array
    {
        return array_map(static fn (array $r): string => $r['method'] . ' ' . (string) parse_url($r['url'], PHP_URL_PATH), WpStub::$requests);
    }

    public function test_off_by_default_and_no_loader(): void
    {
        $this->connect();
        $this->assertSame(AppBanners::STATE_OFF, AppBanners::state());
        AppBanners::enqueue();
        $this->assertSame([], WpStub::$enqueued);
        $this->assertSame(self::TAG, AppBanners::tag(self::TAG, AppBanners::HANDLE));
    }

    public function test_turning_on_asks_for_approval_then_creates_the_site_and_adds_the_loader(): void
    {
        $this->connect();
        WpStub::respond(200, self::answer());
        WpStub::respond(201, self::answer(['approval' => self::approval('pending')]));
        $this->assertSame(AppBanners::STATE_PENDING, AppBanners::turn_on());
        $this->assertSame(['GET /v1/plugin/app-banners', 'POST /v1/plugin/app-banners/approval'], self::calls());
        $this->assertSame([], $this->lastRequest()['body']);
        $this->assertSame('Bearer ' . self::PLUGIN_KEY, $this->lastRequest()['headers']['Authorization']);
        $notice = AppBanners::notice();
        $this->assertStringContainsString('Waiting for approval in your Clikgate dashboard', $notice['message']);
        $this->assertStringContainsString('security code', $notice['message']);
        $this->assertSame('https://app.clikgate.com/app-banners/approvals/pap_01TEST', $notice['link']);
        AppBanners::enqueue();
        $this->assertSame([], WpStub::$enqueued, 'no loader while waiting');

        // The Owner approved: the next check creates the site (create only, its own address)
        WpStub::$requests = [];
        WpStub::respond(200, self::answer(['approval' => self::approval('approved'), 'can_create' => true]));
        WpStub::respond(201, self::answer(['approval' => self::approval('approved'), 'can_create' => true, 'site' => self::site()]));
        $this->assertSame(AppBanners::STATE_ACTIVE, AppBanners::sync());
        $this->assertSame(['GET /v1/plugin/app-banners', 'POST /v1/plugin/app-banners/site'], self::calls());
        $this->assertSame([], $this->lastRequest()['body'], 'the site address comes from the connection, never from the plugin');
        $this->assertSame(self::SITE_KEY, AppBanners::site_key());

        AppBanners::enqueue();
        $this->assertSame([AppBanners::HANDLE, 'https://api.clikgate.com/v1/static/app-banners.js', [], null, ['strategy' => 'async', 'in_footer' => true]], WpStub::$enqueued[0]);
        $this->assertSame(AppBanners::HANDLE, WpStub::$inline[0][0]);
        $this->assertSame('before', WpStub::$inline[0][2]);
        $this->assertStringStartsWith('window.ClikgatePage = {', WpStub::$inline[0][1]);
        $tag = AppBanners::tag(self::TAG, AppBanners::HANDLE);
        $this->assertStringStartsWith('<script data-site-key="' . self::SITE_KEY . '" src=', $tag);
        $this->assertSame('<script src="other.js"></script>', AppBanners::tag('<script src="other.js"></script>', 'jquery'));
        $this->assertStringContainsString('Design your App banners', AppBanners::notice()['link_text']);
    }

    public function test_the_tag_attribute_goes_on_the_loader_not_on_the_inline_data(): void
    {
        $this->connect();
        WpStub::respond(200, self::answer(['site' => self::site()]));
        AppBanners::turn_on();
        $tag = "<script id=\"clikgate-app-banners-js-before\">\nwindow.ClikgatePage = {\"type\":\"home\"};\n</script>\n" . self::TAG;
        $out = AppBanners::tag($tag, AppBanners::HANDLE);
        $this->assertStringContainsString("<script id=\"clikgate-app-banners-js-before\">", $out);
        $this->assertStringContainsString('<script data-site-key="' . self::SITE_KEY . '" src="https://api.clikgate.com/v1/static/app-banners.js"', $out);
        $this->assertSame(1, substr_count($out, 'data-site-key'));
    }

    public function test_an_already_linked_site_is_used_without_a_new_request(): void
    {
        $this->connect();
        WpStub::respond(200, self::answer(['approval' => self::approval('approved'), 'can_create' => true, 'site' => self::site()]));
        $this->assertSame(AppBanners::STATE_ACTIVE, AppBanners::turn_on());
        $this->assertSame(['GET /v1/plugin/app-banners'], self::calls());
        $this->assertTrue(AppBanners::active());
    }

    public function test_turning_off_removes_the_loader_and_keeps_the_site(): void
    {
        $this->connect();
        WpStub::respond(200, self::answer(['site' => self::site()]));
        AppBanners::turn_on();
        WpStub::$requests = [];
        AppBanners::turn_off();
        $this->assertSame([], WpStub::$requests, 'turning off calls nothing in Clikgate');
        $this->assertFalse(AppBanners::active());
        AppBanners::enqueue();
        $this->assertSame([], WpStub::$enqueued);
        $this->assertSame(self::SITE_KEY, AppBanners::site_key(), 'the site is kept');
        $this->assertSame(AppBanners::STATE_OFF, AppBanners::state());
    }

    public function test_below_growth_shows_the_upgrade_note(): void
    {
        $this->connect();
        WpStub::respond(200, self::answer(['available' => false, 'plan_required' => 'growth', 'upgrade_url' => 'https://app.clikgate.com/settings/billing?plan=growth']));
        $this->assertSame(AppBanners::STATE_UPGRADE, AppBanners::turn_on());
        $this->assertSame(['GET /v1/plugin/app-banners'], self::calls(), 'no approval is requested below Growth');
        $notice = AppBanners::notice();
        $this->assertSame('App banners are on Growth and up.', $notice['message']);
        $this->assertSame('https://app.clikgate.com/settings/billing?plan=growth', $notice['link']);
        $this->assertSame('Upgrade', $notice['link_text']);
    }

    public function test_a_downgrade_stops_the_loader(): void
    {
        $this->connect();
        WpStub::respond(200, self::answer(['site' => self::site()]));
        AppBanners::turn_on();
        $this->assertTrue(AppBanners::active());
        WpStub::respond(200, self::answer(['available' => false, 'plan_required' => 'growth', 'site' => self::site()]));
        AppBanners::sync();
        $this->assertFalse(AppBanners::active());
    }

    public function test_an_unverified_website_shows_the_verify_note(): void
    {
        $this->connect();
        WpStub::respond(200, self::answer(['website_verified' => false]));
        $this->assertSame(AppBanners::STATE_VERIFY, AppBanners::turn_on());
        $this->assertSame(['GET /v1/plugin/app-banners'], self::calls());
        $notice = AppBanners::notice();
        $this->assertSame('Verify your site in Clikgate first.', $notice['message']);
        $this->assertSame('https://app.clikgate.com/settings/developers/websites', $notice['link']);
    }

    public function test_a_refused_site_creation_shows_why(): void
    {
        $this->connect();
        WpStub::respond(200, self::answer(['approval' => self::approval('approved'), 'can_create' => true]));
        WpStub::respond(409, ['error' => ['code' => 'website_not_verified', 'message' => 'Verify the website first.']]);
        WpStub::respond(200, self::answer(['website_verified' => false, 'approval' => self::approval('approved'), 'can_create' => true]));
        $this->assertSame(AppBanners::STATE_VERIFY, AppBanners::turn_on());
        $this->assertSame(['GET /v1/plugin/app-banners', 'POST /v1/plugin/app-banners/site', 'GET /v1/plugin/app-banners'], self::calls());
    }

    public function test_a_declined_request_is_asked_again_only_by_the_admin(): void
    {
        $this->connect();
        WpStub::respond(200, self::answer(['approval' => self::approval('denied')]));
        WpStub::respond(201, self::answer(['approval' => self::approval('pending')]));
        self::store(['enabled' => true]);
        $this->assertSame(AppBanners::STATE_DENIED, AppBanners::sync(false));
        $this->assertSame(['GET /v1/plugin/app-banners'], self::calls(), 'background checks never request approval');
        $this->assertSame(AppBanners::STATE_PENDING, AppBanners::turn_on());
    }

    public function test_the_cron_rechecks_a_waiting_approval_now_and_then(): void
    {
        $this->connect();
        WpStub::respond(200, self::answer());
        WpStub::respond(201, self::answer(['approval' => self::approval('pending')]));
        AppBanners::turn_on();
        WpStub::$requests = [];
        AppBanners::maybe_sync();
        $this->assertSame([], WpStub::$requests, 'checked a moment ago');
        self::store(['checked_at' => time() - AppBanners::CHECK_EVERY - 1]);
        WpStub::respond(200, self::answer(['approval' => self::approval('pending')]));
        $this->assertSame(AppBanners::STATE_PENDING, AppBanners::maybe_sync());
        $this->assertSame(['GET /v1/plugin/app-banners'], self::calls());
    }

    public function test_the_front_end_never_calls_clikgate(): void
    {
        $this->connect();
        WpStub::respond(200, self::answer(['site' => self::site()]));
        AppBanners::turn_on();
        WpStub::$requests = [];
        AppBanners::enqueue();
        AppBanners::tag(self::TAG, AppBanners::HANDLE);
        $this->assertSame([], WpStub::$requests);
    }

    public function test_only_plugin_paths_and_never_an_update_or_delete(): void
    {
        $this->connect();
        WpStub::respond(200, self::answer());
        WpStub::respond(201, self::answer(['approval' => self::approval('approved'), 'can_create' => true]));
        WpStub::respond(201, self::answer(['approval' => self::approval('approved'), 'can_create' => true, 'site' => self::site()]));
        AppBanners::turn_on();
        self::store(['site' => null]);
        WpStub::respond(200, self::answer(['approval' => self::approval('approved'), 'can_create' => true]));
        WpStub::respond(201, self::answer(['approval' => self::approval('approved'), 'can_create' => true, 'site' => self::site()]));
        AppBanners::sync();
        AppBanners::turn_off();
        foreach (WpStub::$requests as $request) {
            $this->assertStringStartsWith('https://api.clikgate.com/v1/plugin/app-banners', $request['url']);
            $this->assertContains($request['method'], ['GET', 'POST']);
            $this->assertNull($request['body']['allowed_origins'] ?? null);
        }
        $source = (string) file_get_contents(CLIKGATE_DIR . '/includes/AppBanners.php');
        $this->assertStringNotContainsString("'PATCH'", $source);
        $this->assertStringNotContainsString("'DELETE'", $source);
        $this->assertStringNotContainsString('/v1/app-banner-sites', $source);
    }

    public function test_a_site_key_from_clikgate_must_look_like_one(): void
    {
        $this->connect();
        WpStub::respond(200, self::answer(['site' => ['id' => 'abs_x', 'site_key' => '"><script>alert(1)</script>']]));
        AppBanners::turn_on();
        $this->assertSame('', AppBanners::site_key());
        $this->assertFalse(AppBanners::active());
    }

    public function test_disconnecting_forgets_the_approval(): void
    {
        $this->connect();
        WpStub::respond(200, self::answer(['site' => self::site()]));
        AppBanners::turn_on();
        WpStub::respond(200, ['object' => 'plugin_disconnect', 'disconnected' => true]);
        (new Plugin())->disconnect();
        $this->assertSame(AppBanners::STATE_OFF, AppBanners::state());
        $this->assertArrayNotHasKey(AppBanners::OPTION, WpStub::$options);
    }

    public function test_the_tag_and_data_are_the_same_for_every_visitor(): void
    {
        $this->connect();
        WpStub::respond(200, self::answer(['site' => self::site()]));
        AppBanners::turn_on();
        WpStub::$is = ['is_product' => true, 'singular' => 'product'];
        WpStub::$queried = 41;
        new \FakeWcProduct(41, 'Jollof spice mix', 'JSM-250', 'jollof-spice-mix');
        $first = [AppBanners::inline(), AppBanners::tag(self::TAG, AppBanners::HANDLE)];
        $_COOKIE = ['_cg_cgid' => self::CLICK, 'wordpress_logged_in_x' => 'chiamaka', 'woocommerce_items_in_cart' => '1'];
        $second = [AppBanners::inline(), AppBanners::tag(self::TAG, AppBanners::HANDLE)];
        $this->assertSame($first, $second);
        $this->assertStringNotContainsString('9999', $first[0], 'no prices');
        $this->assertStringNotContainsString('chiamaka', $first[0]);
    }
}
