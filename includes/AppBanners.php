<?php
/**
 * "Show my App banners on this site": the Clikgate App banners loader on every page, with this site's key.
 *
 * Turning it on asks Clikgate (plugin key, /v1/plugin/* only):
 *
 *   GET  /v1/plugin/app-banners           what Clikgate says about this site (plan, verified website, approval, site)
 *   POST /v1/plugin/app-banners/approval  an Owner or Admin approves App banners for this site in the Clikgate
 *                                         dashboard, with a security code (the key gets no new power until then)
 *   POST /v1/plugin/app-banners/site      after approval: creates this site's App banner site (its own address
 *                                         only), or returns the one already linked to it
 *
 * The plugin key can only create its own site: it never changes or deletes App banner sites. Turning the setting
 * off removes the loader and keeps the site in Clikgate. The front end never calls Clikgate: the loader tag and the
 * page data are the same for every visitor, so page caches keep working.
 *
 * Option `clikgate_app_banners` (autoloaded, no secrets): `enabled`, `site` (`id`, `site_key`, `allowed_origins`,
 * `source`), `status` (the last answer of GET /v1/plugin/app-banners), `checked_at`. `CLIKGATE_APP_BANNERS_URL`
 * overrides the loader URL (local development only).
 */

declare(strict_types=1);

namespace Clikgate\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class AppBanners
{
    public const OPTION = 'clikgate_app_banners';
    public const HANDLE = 'clikgate-app-banners';
    public const DEFAULT_URL = 'https://api.clikgate.com/v1/static/app-banners.js';
    public const SITE_KEY_PATTERN = '/^cg_site_(live|test)_[A-Za-z0-9]{24}$/';
    /** The cron re-checks a waiting approval at most this often */
    public const CHECK_EVERY = 10 * MINUTE_IN_SECONDS;
    /** When the banners are on, the stored answer is refreshed this often (plan changes, a removed site) */
    public const REFRESH_EVERY = 6 * HOUR_IN_SECONDS;
    /** The page data is escaped for an inline script: no raw <, >, &, ' or " (so no </script> or <!--) */
    public const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    public const STATE_OFF = 'off';
    public const STATE_UPGRADE = 'upgrade';
    public const STATE_VERIFY = 'verify';
    public const STATE_PENDING = 'pending';
    public const STATE_DENIED = 'denied';
    public const STATE_ACTIVE = 'active';
    public const STATE_ERROR = 'error';

    public static function register(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
        add_filter('script_loader_tag', [self::class, 'tag'], 10, 2);
    }

    public static function url(): string
    {
        return defined('CLIKGATE_APP_BANNERS_URL') ? (string) constant('CLIKGATE_APP_BANNERS_URL') : self::DEFAULT_URL;
    }

    /** @return array{enabled: bool, site: ?array<string, mixed>, status: array<string, mixed>, checked_at: ?int, error: ?string} */
    public static function settings(): array
    {
        $saved = get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];
        $site = is_array($saved['site'] ?? null) ? $saved['site'] : null;
        return [
            'enabled' => !empty($saved['enabled']),
            'site' => $site !== null && preg_match(self::SITE_KEY_PATTERN, (string) ($site['site_key'] ?? '')) === 1 ? $site : null,
            'status' => is_array($saved['status'] ?? null) ? $saved['status'] : [],
            'checked_at' => isset($saved['checked_at']) ? (int) $saved['checked_at'] : null,
            'error' => isset($saved['error']) ? sanitize_key((string) $saved['error']) : null,
        ];
    }

    /** @param array<string, mixed> $changes */
    private static function save(array $changes): void
    {
        update_option(self::OPTION, array_merge(self::settings(), $changes), true);
    }

    public static function site_key(): string
    {
        return (string) (self::settings()['site']['site_key'] ?? '');
    }

    /** The loader is added: connected, switched on, a site key from Clikgate, and the plan still has App banners. */
    public static function active(): bool
    {
        $settings = self::settings();
        return Connection::connected()
            && $settings['enabled']
            && $settings['site'] !== null
            && ($settings['status']['available'] ?? true) !== false;
    }

    public static function enqueue(): void
    {
        if (!self::active()) {
            return;
        }
        wp_enqueue_script(self::HANDLE, self::url(), [], null, ['strategy' => 'async', 'in_footer' => true]);
        wp_add_inline_script(self::HANDLE, self::inline(), 'before');
    }

    /** `window.ClikgatePage = {...};` (public page information only; the loader treats it as data). */
    public static function inline(): string
    {
        return 'window.ClikgatePage = ' . (string) wp_json_encode(PageContext::current(), self::JSON_FLAGS) . ';';
    }

    /** Adds data-site-key to the plugin's own loader tag only. */
    public static function tag(string $tag, string $handle): string
    {
        if ($handle !== self::HANDLE || !self::active()) {
            return $tag;
        }
        return (string) preg_replace('/<script\b(?=[^>]*\bsrc=)/', '<script data-site-key="' . esc_attr(self::site_key()) . '"', $tag, 1);
    }

    /** The admin ticked "Show my App banners on this site". Returns the resulting state. */
    public static function turn_on(): string
    {
        self::save(['enabled' => true]);
        return self::sync(true);
    }

    /** Removes the loader only: the App banner site stays in Clikgate (and stored here for turning on again). */
    public static function turn_off(): void
    {
        self::save(['enabled' => false]);
    }

    /** Disconnecting the site: a new connection needs a new approval. */
    public static function forget(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * Asks Clikgate and moves on as far as it can: status → (request approval) → (create the site).
     * `$request`: the admin just turned it on (or asked again), so an approval may be requested; background checks
     * never request one.
     */
    public static function sync(bool $request = false): string
    {
        $key = Connection::key();
        if ($key === null || !Connection::connected()) {
            self::save(['error' => 'not_connected', 'checked_at' => time()]);
            return self::state();
        }
        $status = self::fetch($key, 'GET', '/v1/plugin/app-banners');
        if ($status === null) {
            return self::state();
        }
        $approval = is_array($status['approval'] ?? null) ? $status['approval'] : null;
        if (empty($status['available']) || empty($status['website_verified'])) {
            return self::state();
        }
        if (self::settings()['site'] !== null) {
            // Already created or linked (Clikgate returns the site with the status)
            return self::state();
        }
        if (!empty($status['can_create'])) {
            self::fetch($key, 'POST', '/v1/plugin/app-banners/site', []);
            return self::state();
        }
        $answered = $approval !== null && in_array($approval['status'] ?? '', ['pending', 'approved'], true);
        if ($request && !$answered) {
            self::fetch($key, 'POST', '/v1/plugin/app-banners/approval', []);
        }
        return self::state();
    }

    /** The settings page and the cron: re-check now and then, never on a front-end request. */
    public static function maybe_sync(): string
    {
        $settings = self::settings();
        if (!$settings['enabled'] || !Connection::connected()) {
            return self::state();
        }
        $every = self::state() === self::STATE_ACTIVE ? self::REFRESH_EVERY : self::CHECK_EVERY;
        if ($settings['checked_at'] !== null && $settings['checked_at'] > time() - $every) {
            return self::state();
        }
        return self::sync(false);
    }

    /**
     * One call; stores the answer (every endpoint answers with the status object). On a refusal Clikgate explains
     * (plan, website not verified, approval needed), so the status is fetched again to show why.
     *
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|null the stored status, null when Clikgate couldn't be reached
     */
    private static function fetch(string $key, string $method, string $path, ?array $body = null): ?array
    {
        $response = Api::request($method, $path, $body, $key);
        if ($response->disconnected()) {
            Connection::mark_disconnected((string) $response->code);
            self::save(['error' => (string) $response->code, 'checked_at' => time()]);
            return null;
        }
        if (!$response->ok()) {
            self::save(['error' => (string) ($response->code ?? 'error'), 'checked_at' => time()]);
            Log::add('error', ['source' => 'app_banners', 'code' => $response->code, 'message' => $response->message]);
            if ($method === 'POST' && $response->status >= 400 && $response->status < 500) {
                $again = Api::request('GET', '/v1/plugin/app-banners', null, $key);
                if ($again->ok()) {
                    self::store_status($again->body);
                    return self::settings()['status'];
                }
            }
            return null;
        }
        self::store_status($response->body);
        if ($method === 'POST' && str_ends_with($path, '/site')) {
            Log::add('app_banners_site', ['source' => 'app_banners']);
        }
        return self::settings()['status'];
    }

    /** Keeps only the fields the plugin uses, sanitised. @param array<string, mixed> $body */
    private static function store_status(array $body): void
    {
        $url = static fn ($value): string => is_string($value) && preg_match('#^https://#', $value) === 1 ? esc_url_raw($value) : '';
        $approval = is_array($body['approval'] ?? null) ? $body['approval'] : null;
        $status = [
            'available' => !empty($body['available']),
            'plan_required' => isset($body['plan_required']) ? sanitize_key((string) $body['plan_required']) : null,
            'upgrade_url' => $url($body['upgrade_url'] ?? null),
            'website_verified' => !empty($body['website_verified']),
            'site_host' => sanitize_text_field((string) ($body['site_host'] ?? '')),
            'verify_url' => $url($body['verify_url'] ?? null),
            'approval' => $approval === null ? null : [
                'id' => sanitize_text_field((string) ($approval['id'] ?? '')),
                'status' => in_array($approval['status'] ?? '', ['pending', 'approved', 'denied'], true) ? (string) $approval['status'] : 'pending',
                'approve_url' => $url($approval['approve_url'] ?? null),
                'requested_at' => sanitize_text_field((string) ($approval['requested_at'] ?? '')),
            ],
            'can_create' => !empty($body['can_create']),
            'dashboard_url' => $url($body['dashboard_url'] ?? null),
        ];
        $changes = ['status' => $status, 'checked_at' => time(), 'error' => null];
        $site = is_array($body['site'] ?? null) ? $body['site'] : null;
        if ($site !== null && preg_match(self::SITE_KEY_PATTERN, (string) ($site['site_key'] ?? '')) === 1) {
            $changes['site'] = [
                'id' => sanitize_text_field((string) ($site['id'] ?? '')),
                'site_key' => (string) $site['site_key'],
                'allowed_origins' => array_values(array_filter(array_map($url, (array) ($site['allowed_origins'] ?? [])))),
                'source' => sanitize_key((string) ($site['source'] ?? 'wordpress_plugin')),
            ];
        }
        self::save($changes);
    }

    public static function state(): string
    {
        $settings = self::settings();
        if (!$settings['enabled']) {
            return self::STATE_OFF;
        }
        $status = $settings['status'];
        if ($status === []) {
            return self::STATE_ERROR;
        }
        if (empty($status['available'])) {
            return self::STATE_UPGRADE;
        }
        if (empty($status['website_verified'])) {
            return self::STATE_VERIFY;
        }
        if ($settings['site'] !== null) {
            return self::STATE_ACTIVE;
        }
        if (($status['approval']['status'] ?? '') === 'denied') {
            return self::STATE_DENIED;
        }
        if (($status['approval']['status'] ?? '') === 'pending' || !empty($status['can_create'])) {
            return self::STATE_PENDING;
        }
        return self::STATE_ERROR;
    }

    /**
     * What the settings page says, by state.
     *
     * @return array{state: string, message: string, link: string, link_text: string}
     */
    public static function notice(): array
    {
        $state = self::state();
        $status = self::settings()['status'];
        $dashboard = (string) ($status['dashboard_url'] ?? '') ?: 'https://app.clikgate.com/app-banners';
        [$message, $link, $text] = match ($state) {
            self::STATE_OFF => [__('App banners invite visitors on phones to open your app, or to install it and then open the same page.', 'clikgate'), '', ''],
            self::STATE_UPGRADE => [__('App banners are on Growth and up.', 'clikgate'), (string) ($status['upgrade_url'] ?? '') ?: 'https://app.clikgate.com/settings/billing', __('Upgrade', 'clikgate')],
            self::STATE_VERIFY => [__('Verify your site in Clikgate first.', 'clikgate'), (string) ($status['verify_url'] ?? '') ?: 'https://app.clikgate.com/settings/developers', __('Verify your site', 'clikgate')],
            self::STATE_PENDING => [__('Waiting for approval in your Clikgate dashboard (an Owner or Admin approves it with a security code).', 'clikgate'), (string) ($status['approval']['approve_url'] ?? '') ?: $dashboard, __('Open Clikgate to approve', 'clikgate')],
            self::STATE_DENIED => [__('App banners for this site were declined in Clikgate. Turn the setting on again to ask again.', 'clikgate'), '', ''],
            self::STATE_ACTIVE => [__('Your App banners are shown on this site. Design them, choose where they appear and which app screen each page opens in Clikgate.', 'clikgate'), $dashboard, __('Design your App banners in Clikgate', 'clikgate')],
            default => [__('Clikgate could not be reached. Try again in a moment.', 'clikgate'), '', ''],
        };
        return ['state' => $state, 'message' => $message, 'link' => $link, 'link_text' => $text];
    }
}
