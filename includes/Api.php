<?php
/**
 * HTTP calls to the Clikgate API through the WordPress HTTP API. `CLIKGATE_API_BASE` in wp-config.php points
 * the plugin at another API (local development only).
 */

declare(strict_types=1);

namespace Clikgate\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Api
{
    public const DEFAULT_BASE = 'https://api.clikgate.com';

    public static function base(): string
    {
        $base = defined('CLIKGATE_API_BASE') ? (string) constant('CLIKGATE_API_BASE') : self::DEFAULT_BASE;
        return rtrim($base, '/');
    }

    /**
     * @param array<string, mixed>|null $body JSON body (null = none)
     * @param string|null $key The plugin key (Authorization: Bearer), null for the device sign-in calls
     */
    public static function request(string $method, string $path, ?array $body = null, ?string $key = null, int $timeout = 10): Response
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'clikgate-wordpress/' . CLIKGATE_VERSION . '; ' . self::site_host(),
        ];
        if ($key !== null) {
            $headers['Authorization'] = 'Bearer ' . $key;
        }
        $args = ['method' => $method, 'timeout' => $timeout, 'headers' => $headers, 'redirection' => 0];
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = (string) wp_json_encode((object) $body);
        }
        $raw = wp_remote_request(self::base() . $path, $args);
        if (is_wp_error($raw)) {
            return new Response(0, [], 'network_error', 'Could not reach Clikgate.');
        }
        $status = (int) wp_remote_retrieve_response_code($raw);
        $decoded = json_decode((string) wp_remote_retrieve_body($raw), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : null;
        $retry = wp_remote_retrieve_header($raw, 'retry-after');
        return new Response(
            $status,
            $decoded,
            $error !== null ? sanitize_key((string) ($error['code'] ?? 'error')) : ($status >= 400 ? 'http_' . $status : null),
            $error !== null ? sanitize_text_field((string) ($error['message'] ?? '')) : null,
            is_numeric($retry) ? (int) $retry : null
        );
    }

    public static function site_host(): string
    {
        $host = wp_parse_url((string) home_url('/'), PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }
}
