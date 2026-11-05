<?php
/**
 * "Connect to Clikgate": Clikgate's device sign-in with `client_type: "wordpress_plugin"`.
 *
 * 1. start(): POST /v1/cli/device-codes. The device code stays on the server (a transient per admin user); the
 *    admin only sees the short user code and the approval link `{dashboard}/connect/wordpress?code=...`.
 * 2. The admin approves in the Clikgate dashboard (owner or admin, and picks live or test).
 * 3. poll(): POST /v1/cli/token every `interval` seconds: 428 authorization_pending, 429 slow_down (+5 s),
 *    403 access_denied, 410 expired_token, or 200 with the plugin key, which is stored encrypted and never shown.
 */

declare(strict_types=1);

namespace Clikgate\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class DeviceFlow
{
    private const TRANSIENT = 'clikgate_device_';

    /** @return array{ok: bool, user_code?: string, verification_uri?: string, verification_uri_complete?: string, interval?: int, expires_in?: int, code?: string, message?: string} */
    public static function start(int $user_id): array
    {
        $response = Api::request('POST', '/v1/cli/device-codes', [
            'client_type' => 'wordpress_plugin',
            'client_info' => self::client_info(),
        ]);
        $body = $response->body;
        if (!$response->ok() || empty($body['device_code']) || empty($body['user_code'])) {
            return ['ok' => false, 'code' => $response->code ?? 'unexpected_response', 'message' => $response->message ?? __('Clikgate could not start the sign-in. Try again in a minute.', 'clikgate')];
        }
        $interval = max(5, (int) ($body['interval'] ?? 5));
        $expires = max(60, (int) ($body['expires_in'] ?? 600));
        $pending = [
            'device_code' => (string) $body['device_code'],
            'user_code' => sanitize_text_field((string) $body['user_code']),
            'verification_uri' => esc_url_raw((string) ($body['verification_uri'] ?? '')),
            'verification_uri_complete' => esc_url_raw((string) ($body['verification_uri_complete'] ?? ($body['verification_uri'] ?? ''))),
            'interval' => $interval,
            'next_poll_at' => time() + $interval,
            'expires_at' => time() + $expires,
        ];
        set_transient(self::TRANSIENT . $user_id, $pending, $expires);
        return [
            'ok' => true,
            'user_code' => $pending['user_code'],
            'verification_uri' => $pending['verification_uri'],
            'verification_uri_complete' => $pending['verification_uri_complete'],
            'interval' => $interval,
            'expires_in' => $expires,
        ];
    }

    /**
     * One poll. `state`: pending | connected | denied | expired | none | error.
     *
     * @return array{state: string, interval?: int, organization?: string, mode?: string, message?: string}
     */
    public static function poll(int $user_id): array
    {
        $pending = get_transient(self::TRANSIENT . $user_id);
        if (!is_array($pending) || empty($pending['device_code'])) {
            return ['state' => 'none'];
        }
        if (time() >= (int) $pending['expires_at']) {
            self::cancel($user_id);
            return ['state' => 'expired', 'message' => __('The code expired. Start again.', 'clikgate')];
        }
        if (time() < (int) $pending['next_poll_at']) {
            // The browser polls a little faster than the interval: never make Clikgate say slow_down
            return ['state' => 'pending', 'interval' => (int) $pending['interval']];
        }
        $response = Api::request('POST', '/v1/cli/token', ['device_code' => (string) $pending['device_code']]);
        if ($response->ok()) {
            self::cancel($user_id);
            if (!Connection::save_from_token($response->body)) {
                return ['state' => 'error', 'message' => __('Clikgate sent an unexpected answer. Start again.', 'clikgate')];
            }
            Plugin::instance()->refresh_remote();
            return [
                'state' => 'connected',
                'organization' => (string) (Connection::get()['organization']['name'] ?? ''),
                'mode' => Connection::mode(),
            ];
        }
        switch ($response->code) {
            case 'authorization_pending':
                return self::wait($user_id, $pending, (int) $pending['interval']);
            case 'slow_down':
                return self::wait($user_id, $pending, (int) $pending['interval'] + 5);
            case 'access_denied':
                self::cancel($user_id);
                return ['state' => 'denied', 'message' => __('The connection was denied in Clikgate.', 'clikgate')];
            case 'expired_token':
                self::cancel($user_id);
                return ['state' => 'expired', 'message' => __('The code expired. Start again.', 'clikgate')];
        }
        if ($response->retryable()) {
            return self::wait($user_id, $pending, (int) $pending['interval']);
        }
        self::cancel($user_id);
        return ['state' => 'error', 'message' => $response->message ?: __('Clikgate refused the sign-in.', 'clikgate')];
    }

    public static function cancel(int $user_id): void
    {
        delete_transient(self::TRANSIENT . $user_id);
    }

    /** @return array<string, mixed> */
    public static function client_info(): array
    {
        global $wp_version;
        return [
            'plugin_version' => CLIKGATE_VERSION,
            'site_url' => (string) home_url('/'),
            'wp_version' => is_string($wp_version ?? null) ? $wp_version : '',
            'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'woocommerce' => class_exists('WooCommerce'),
        ];
    }

    /** @param array<string, mixed> $pending */
    private static function wait(int $user_id, array $pending, int $interval): array
    {
        $pending['interval'] = $interval;
        $pending['next_poll_at'] = time() + $interval;
        set_transient(self::TRANSIENT . $user_id, $pending, max(1, (int) $pending['expires_at'] - time()));
        return ['state' => 'pending', 'interval' => $interval];
    }
}
