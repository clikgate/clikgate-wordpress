<?php
/**
 * "Keep the click ID from your server". Safari, and every browser on iPhone, keeps a click ID
 * saved by a script for up to 7 days (sometimes less). When your server saves it, it lasts as long as you choose, up
 * to the browser's limit.
 *
 * The web script (data-server-endpoint) posts the click ID once to this site's own REST route
 * `POST /wp-json/clikgate/v1/keep`, and WordPress sets the cookie in that answer. It is a REST route, not a cookie
 * set while a page renders, so full-page caches never serve one visitor's cookie to everyone. Rules (the same as the
 * server SDKs' keep route): POST only; same site only (`Sec-Fetch-Site: same-origin`, or an Origin matching the host),
 * else 403; `click_id` must match `^clk_[0-9A-Z]{26}$` and equal the page URL's click ID (`page_click_id`), else 400;
 * the cookie name comes from the connection's click ID parameter, never from the request; `_cg_{param}` and the
 * marker `_cg_kept=s` are readable by the page on purpose (the web script reads them), Secure, SameSite=Lax, Path=/,
 * 30 days (filter `clikgate_keep_days`, 1-400); 204 with `Cache-Control: no-store, private`; never a redirect, never
 * the input echoed or logged. On by default; Settings › Clikgate › "Keep the click ID from your server" turns it off.
 */

declare(strict_types=1);

namespace Clikgate\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Keep
{
    public const NAMESPACE = 'clikgate/v1';
    public const ROUTE = '/keep';
    public const MARKER = '_cg_kept';
    public const MAX_BODY = 1024;
    public const NO_STORE = 'no-store, private';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function active(): bool
    {
        return Options::all()['keep_click_id'];
    }

    public static function routes(): void
    {
        if (!self::active()) {
            return;
        }
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            // Any method reaches the handler, which answers 405 itself (with no-store)
            'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
            'callback' => [self::class, 'rest'],
            // Visitors are anonymous; the same-site check is in respond()
            'permission_callback' => '__return_true',
        ]);
    }

    /** The web script's data-server-endpoint (same origin as the page). */
    public static function endpoint(): string
    {
        return rest_url(self::NAMESPACE . self::ROUTE);
    }

    public static function days(): int
    {
        $days = (int) apply_filters('clikgate_keep_days', 30);
        return $days >= 1 && $days <= 400 ? $days : 30;
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function rest($request)
    {
        $headers = [];
        foreach ((array) $request->get_headers() as $name => $values) {
            $headers[strtolower(str_replace('_', '-', (string) $name))] = is_array($values) ? (string) reset($values) : (string) $values;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared, never stored or echoed
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        if (!isset($headers['host']) && $host !== '') {
            $headers['host'] = $host;
        }
        $answer = self::respond((string) $request->get_method(), $headers, (string) $request->get_body());
        $response = new \WP_REST_Response(null, $answer['status']);
        foreach ($answer['headers'] as [$name, $value]) {
            if (strtolower($name) === 'set-cookie') {
                // Several Set-Cookie headers: WP_REST_Response keeps one value per name
                header('Set-Cookie: ' . $value, false);
            } else {
                $response->header($name, $value);
            }
        }
        return $response;
    }

    /**
     * The whole rule set, framework-free (tested on its own).
     *
     * @param array<string, string> $headers lower-case names
     * @return array{status: int, headers: list<array{0: string, 1: string}>}
     */
    public static function respond(string $method, array $headers, string $body): array
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $out = [['Cache-Control', self::NO_STORE]];
        if (strtoupper($method) !== 'POST') {
            $out[] = ['Allow', 'POST'];
            return ['status' => 405, 'headers' => $out];
        }
        if (!self::same_site($headers)) {
            return ['status' => 403, 'headers' => $out];
        }
        $id = self::click_id($body);
        if ($id === null) {
            return ['status' => 400, 'headers' => $out];
        }
        $age = self::days() * DAY_IN_SECONDS;
        $out[] = ['Set-Cookie', self::cookie('_cg_' . Click::param(), $id, $age)];
        $out[] = ['Set-Cookie', self::cookie(self::MARKER, 's', $age)];
        return ['status' => 204, 'headers' => $out];
    }

    /** @param array<string, string> $headers */
    private static function same_site(array $headers): bool
    {
        if (isset($headers['sec-fetch-site'])) {
            return strtolower(trim($headers['sec-fetch-site'])) === 'same-origin';
        }
        $origin = strtolower(trim($headers['origin'] ?? ''));
        $forwarded = trim(explode(',', $headers['x-forwarded-host'] ?? '')[0]);
        $host = strtolower($forwarded !== '' ? $forwarded : trim($headers['host'] ?? ''));
        if ($origin === '' || $origin === 'null' || $host === '') {
            return false;
        }
        return $origin === 'https://' . $host || $origin === 'http://' . $host;
    }

    private static function click_id(string $body): ?string
    {
        if ($body === '' || strlen($body) > self::MAX_BODY) {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || array_is_list($data)) {
            return null;
        }
        $id = $data['click_id'] ?? null;
        $page = $data['page_click_id'] ?? null;
        if (!is_string($id) || !is_string($page) || !Click::valid($id) || $id !== $page) {
            return null;
        }
        return $id;
    }

    private static function cookie(string $name, string $value, int $age): string
    {
        // Not HttpOnly: the web script reads the click ID and the marker
        return $name . '=' . $value . '; Path=/; Max-Age=' . $age . '; SameSite=Lax; Secure';
    }
}
