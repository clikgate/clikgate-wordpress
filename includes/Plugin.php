<?php
/**
 * Wires the plugin: the web script, the App banners loader, the supported integrations, WP-Cron (sending and a 6-hourly settings
 * refresh) and the admin screen.
 */

declare(strict_types=1);

namespace Clikgate\WP;

use Clikgate\WP\Integrations\EasyDigitalDownloads;
use Clikgate\WP\Integrations\FluentForms;
use Clikgate\WP\Integrations\GravityForms;
use Clikgate\WP\Integrations\Integration;
use Clikgate\WP\Integrations\MemberPress;
use Clikgate\WP\Integrations\PaidMembershipsPro;
use Clikgate\WP\Integrations\WooCommerce;
use Clikgate\WP\Integrations\WPForms;
use Clikgate\WP\Integrations\WPSimplePay;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?Plugin $instance = null;
    private Dispatcher $dispatcher;
    /** @var Integration[] */
    private array $integrations;

    public function __construct()
    {
        $this->dispatcher = new Dispatcher();
        $this->integrations = [
            new WooCommerce(),
            new GravityForms(),
            new EasyDigitalDownloads(),
            new FluentForms(),
            new WPForms(),
            new WPSimplePay(),
            new MemberPress(),
            new PaidMembershipsPro(),
        ];
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        Script::register();
        Keep::register();
        AppBanners::register();
        foreach ($this->available() as $integration) {
            $integration->register($this->dispatcher);
        }
        add_filter('cron_schedules', [self::class, 'schedules']);
        add_action('clikgate_flush', [Queue::class, 'flush']);
        add_action('clikgate_cron', [$this, 'cron']);
        // Updates replace the files without running the activation hook
        add_action('init', static function (): void {
            if (!wp_next_scheduled('clikgate_cron')) {
                wp_schedule_event(time() + MINUTE_IN_SECONDS, 'clikgate_five_minutes', 'clikgate_cron');
            }
        });
        if (is_admin()) {
            (new Admin\SettingsPage($this))->register();
            (new Admin\Ajax($this))->register();
        }
    }

    public function dispatcher(): Dispatcher
    {
        return $this->dispatcher;
    }

    /** @return Integration[] the supported plugins active on this site */
    public function available(): array
    {
        return array_values(array_filter($this->integrations, static fn (Integration $i): bool => $i->available()));
    }

    /** @return Integration[] */
    public function integrations(): array
    {
        return $this->integrations;
    }

    public function cron(): void
    {
        Queue::flush();
        $fetched = (string) (Connection::remote()['fetched_at'] ?? '');
        if (Connection::connected() && ($fetched === '' || strtotime($fetched) < time() - 6 * HOUR_IN_SECONDS)) {
            $this->refresh_remote();
        }
        // A waiting App banners approval is re-checked now and then (never on a visitor's request)
        AppBanners::maybe_sync();
    }

    /** GET /v1/plugin/settings. Returns the response (the status panel shows its error). */
    public function refresh_remote(): Response
    {
        $key = Connection::key();
        if ($key === null) {
            return new Response(0, [], 'not_connected', __('Not connected.', 'clikgate'));
        }
        $response = Api::request('GET', '/v1/plugin/settings', null, $key);
        if ($response->ok()) {
            Connection::save_remote($response->body);
        } elseif ($response->disconnected()) {
            Connection::mark_disconnected((string) $response->code);
        } elseif ($response->blocked()) {
            Connection::set_blocked((string) $response->code, $response->message);
        }
        return $response;
    }

    /**
     * "Disconnect": POST /v1/plugin/disconnect revokes the key in Clikgate at once, then
     * everything is deleted locally. Returns whether Clikgate confirmed; if not (offline, already revoked), the
     * key is still deleted here and the admin is told to revoke it in the dashboard.
     */
    public function disconnect(): bool
    {
        $key = Connection::key();
        $confirmed = false;
        if ($key !== null) {
            $response = Api::request('POST', '/v1/plugin/disconnect', [], $key);
            $confirmed = $response->ok() && !empty($response->body['disconnected']);
        }
        // Results still waiting stay queued: they go out if the site is connected again
        Connection::forget();
        // A new connection needs a new App banners approval
        AppBanners::forget();
        Log::add($confirmed ? 'disconnected' : 'disconnected_locally', ['source' => 'connection']);
        return $confirmed;
    }

    /** "Send a test": POST /v1/plugin/test (nothing is counted). */
    public function send_test(): Response
    {
        $key = Connection::key();
        if ($key === null || !Connection::connected()) {
            return new Response(0, [], 'not_connected', __('Connect to Clikgate first.', 'clikgate'));
        }
        $response = Api::request('POST', '/v1/plugin/test', [], $key);
        if ($response->ok()) {
            $remote = Connection::remote();
            $remote['last_test_at'] = sanitize_text_field((string) ($response->body['received_at'] ?? gmdate('c')));
            update_option(Connection::REMOTE, $remote, true);
        } elseif ($response->disconnected()) {
            Connection::mark_disconnected((string) $response->code);
        } elseif ($response->blocked()) {
            Connection::set_blocked((string) $response->code, $response->message);
        }
        Log::add($response->ok() ? 'test' : 'error', ['source' => 'test', 'code' => $response->code, 'message' => $response->message]);
        return $response;
    }

    /** @param array<string, array<string, mixed>> $schedules */
    public static function schedules($schedules): array
    {
        $schedules = is_array($schedules) ? $schedules : [];
        $schedules['clikgate_five_minutes'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every five minutes (Clikgate)'];
        return $schedules;
    }

    public static function activate(): void
    {
        add_filter('cron_schedules', [self::class, 'schedules']);
        if (!wp_next_scheduled('clikgate_cron')) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'clikgate_five_minutes', 'clikgate_cron');
        }
        // The earlier "Clikgate for WooCommerce" plugin kept a copied ingest URL and secret: no longer used
        delete_option('clikgate_wc_settings');
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('clikgate_cron');
        wp_clear_scheduled_hook('clikgate_flush');
    }
}
