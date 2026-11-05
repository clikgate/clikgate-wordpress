<?php
/**
 * Admin AJAX: device sign-in (start, poll, cancel), "Send a test", refresh the status. Every call needs
 * `manage_options` and the `clikgate_admin` nonce. The plugin key and the device code never reach the browser.
 */

declare(strict_types=1);

namespace Clikgate\WP\Admin;

use Clikgate\WP\Connection;
use Clikgate\WP\DeviceFlow;
use Clikgate\WP\Plugin;
use Clikgate\WP\Queue;

if (!defined('ABSPATH')) {
    exit;
}

final class Ajax
{
    public const NONCE = 'clikgate_admin';

    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('wp_ajax_clikgate_connect_start', [$this, 'connect_start']);
        add_action('wp_ajax_clikgate_connect_poll', [$this, 'connect_poll']);
        add_action('wp_ajax_clikgate_connect_cancel', [$this, 'connect_cancel']);
        add_action('wp_ajax_clikgate_send_test', [$this, 'send_test']);
        add_action('wp_ajax_clikgate_refresh', [$this, 'refresh']);
    }

    public function connect_start(): void
    {
        $this->guard();
        $result = DeviceFlow::start(get_current_user_id());
        $result['ok'] ? wp_send_json_success($result) : wp_send_json_error($result, 502);
    }

    public function connect_poll(): void
    {
        $this->guard();
        wp_send_json_success(DeviceFlow::poll(get_current_user_id()));
    }

    public function connect_cancel(): void
    {
        $this->guard();
        DeviceFlow::cancel(get_current_user_id());
        wp_send_json_success(['state' => 'none']);
    }

    public function send_test(): void
    {
        $this->guard();
        $response = $this->plugin->send_test();
        if ($response->ok()) {
            wp_send_json_success(['received_at' => (string) ($response->body['received_at'] ?? '')]);
        }
        wp_send_json_error(['code' => $response->code, 'message' => $response->message ?: __('The test could not reach Clikgate.', 'clikgate')]);
    }

    public function refresh(): void
    {
        $this->guard();
        $response = $this->plugin->refresh_remote();
        Queue::flush(5);
        $response->ok()
            ? wp_send_json_success(['connected' => Connection::connected()])
            : wp_send_json_error(['code' => $response->code, 'message' => $response->message]);
    }

    private function guard(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['code' => 'forbidden'], 403);
        }
    }
}
