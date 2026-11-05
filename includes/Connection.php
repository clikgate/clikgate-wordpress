<?php
/**
 * The site's Clikgate connection: the plugin key from device sign-in and the settings Clikgate returns for it.
 *
 * - `clikgate_connection` (not autoloaded): the plugin key, encrypted with a key derived from this site's
 *   WordPress salts (libsodium secretbox), plus its id, mode, organisation and connection id. If the salts
 *   change, the key can't be read any more and the admin connects again.
 * - `clikgate_remote` (autoloaded, no secrets): the latest `GET /v1/plugin/settings` (web key, click ID
 *   parameter, goals with their kind, private mode, providers, last results).
 *
 * The plugin key is never shown in the admin, never sent to the browser and never logged.
 */

declare(strict_types=1);

namespace Clikgate\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Connection
{
    public const OPTION = 'clikgate_connection';
    public const REMOTE = 'clikgate_remote';
    /** base62 after the mode prefix */
    public const KEY_PATTERN = '/^cg_(live|test)_[A-Za-z0-9]{40}$/';
    public const WEB_KEY_PATTERN = '/^cgw_(live|test)_[A-Za-z0-9]{32}$/';

    /** @param array<string, mixed> $token The 200 body of POST /v1/cli/token for a plugin code */
    public static function save_from_token(array $token): bool
    {
        $key = (string) ($token['access_token'] ?? '');
        $plugin = is_array($token['plugin'] ?? null) ? $token['plugin'] : [];
        if (($token['token_type'] ?? '') !== 'plugin_key' || preg_match(self::KEY_PATTERN, $key) !== 1) {
            return false;
        }
        $organization = is_array($token['organization'] ?? null) ? $token['organization'] : [];
        update_option(self::OPTION, [
            'key' => self::seal($key),
            'key_id' => sanitize_text_field((string) ($plugin['key_id'] ?? '')),
            'mode' => ($plugin['mode'] ?? '') === 'test' ? 'test' : 'live',
            'connection_id' => sanitize_text_field((string) ($plugin['connection_id'] ?? '')),
            'organization' => [
                'id' => sanitize_text_field((string) ($organization['id'] ?? '')),
                'name' => sanitize_text_field((string) ($organization['name'] ?? '')),
            ],
            'connected_at' => gmdate('c'),
            'disconnected' => null,
        ], false);
        update_option(self::REMOTE, [
            'web_key' => self::web_key((string) ($plugin['web_key'] ?? '')),
            'click_id_param' => 'cgid',
            'goals' => [],
            'providers' => [],
            'last_results' => [],
            'last_test_at' => null,
            'fetched_at' => null,
        ], true);
        return true;
    }

    /** @return array<string, mixed>|null */
    public static function get(): ?array
    {
        $saved = get_option(self::OPTION, null);
        return is_array($saved) && !empty($saved['key']) ? $saved : null;
    }

    public static function connected(): bool
    {
        $saved = self::get();
        return $saved !== null && empty($saved['disconnected']) && self::key() !== null;
    }

    public static function key(): ?string
    {
        $saved = self::get();
        return $saved === null ? null : self::open((string) $saved['key']);
    }

    public static function mode(): string
    {
        return (self::get()['mode'] ?? 'live') === 'test' ? 'test' : 'live';
    }

    /** Clikgate said the key was revoked or the website disconnected: stop sending until the admin reconnects. */
    public static function mark_disconnected(string $code): void
    {
        $saved = self::get();
        if ($saved !== null) {
            $saved['disconnected'] = ['code' => sanitize_key($code), 'at' => gmdate('c')];
            update_option(self::OPTION, $saved, false);
        }
    }

    public static function forget(): void
    {
        delete_option(self::OPTION);
        delete_option(self::REMOTE);
    }

    /** @return array<string, mixed> */
    public static function remote(): array
    {
        $remote = get_option(self::REMOTE, []);
        return is_array($remote) ? $remote : [];
    }

    /** Stores GET /v1/plugin/settings (only the fields the plugin uses). @param array<string, mixed> $settings */
    public static function save_remote(array $settings): void
    {
        $goals = [];
        foreach ((array) ($settings['goals'] ?? []) as $goal) {
            if (is_array($goal) && Options::goal_key((string) ($goal['key'] ?? '')) !== '') {
                $goals[] = [
                    'key' => Options::goal_key((string) $goal['key']),
                    'name' => sanitize_text_field((string) ($goal['name'] ?? $goal['key'])),
                    'kind' => ($goal['kind'] ?? 'standard') === 'payment' ? 'payment' : 'standard',
                    'default_currency' => strtoupper(sanitize_text_field((string) ($goal['default_currency'] ?? ''))),
                ];
            }
        }
        $param = (string) ($settings['click_id_param'] ?? 'cgid');
        update_option(self::REMOTE, [
            'web_key' => self::web_key((string) ($settings['web_key'] ?? '')),
            'click_id_param' => preg_match('/^[A-Za-z0-9_]{1,32}$/', $param) === 1 ? $param : 'cgid',
            'organization_name' => sanitize_text_field((string) ($settings['organization']['name'] ?? '')),
            'private_mode' => !empty($settings['private_mode']),
            'default_payment_goal' => Options::goal_key((string) ($settings['default_payment_goal'] ?? '')),
            'connection_goal' => Options::goal_key((string) ($settings['connection_goal'] ?? '')),
            'goals' => $goals,
            'providers' => array_values(array_filter(array_map(
                static fn ($p): string => preg_match('/^[a-z0-9_-]{1,40}$/', (string) $p) === 1 ? (string) $p : '',
                (array) ($settings['providers'] ?? [])
            ))),
            'last_results' => array_slice(array_values(array_filter((array) ($settings['last_results'] ?? []), 'is_array')), 0, 10),
            'last_test_at' => isset($settings['last_test_at']) ? sanitize_text_field((string) $settings['last_test_at']) : null,
            'fetched_at' => gmdate('c'),
            'blocked' => null,
        ], true);
    }

    /**
     * Clikgate refuses this site's results for a reason the admin must fix (a plan without Payment goals, a key
     * without the plugin scope). Shown in the status panel; cleared by the next accepted call.
     */
    public static function set_blocked(?string $code, ?string $message = null): void
    {
        $remote = self::remote();
        if ($remote === [] || ($code === null && empty($remote['blocked']))) {
            return;
        }
        $remote['blocked'] = $code === null ? null : ['code' => sanitize_key($code), 'message' => sanitize_text_field((string) $message), 'at' => gmdate('c')];
        update_option(self::REMOTE, $remote, true);
    }

    /** The organisation uses private mode: payment results leave out the order number. */
    public static function private_mode(): bool
    {
        return !empty(self::remote()['private_mode']);
    }

    /** @return array<string, mixed>|null the goal from the remote settings */
    public static function goal(string $key): ?array
    {
        foreach ((array) (self::remote()['goals'] ?? []) as $goal) {
            if (is_array($goal) && ($goal['key'] ?? '') === $key) {
                return $goal;
            }
        }
        return null;
    }

    private static function web_key(string $key): string
    {
        return preg_match(self::WEB_KEY_PATTERN, $key) === 1 ? $key : '';
    }

    private static function secret(): ?string
    {
        if (!function_exists('sodium_crypto_secretbox') || !function_exists('wp_salt')) {
            return null;
        }
        return hash('sha256', wp_salt('auth') . '|clikgate-plugin-key', true);
    }

    private static function seal(string $plain): string
    {
        $secret = self::secret();
        if ($secret === null) {
            return 'plain:' . $plain;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'sb1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $secret));
    }

    private static function open(string $sealed): ?string
    {
        if (str_starts_with($sealed, 'plain:')) {
            return substr($sealed, 6);
        }
        $secret = self::secret();
        $raw = str_starts_with($sealed, 'sb1:') ? base64_decode(substr($sealed, 4), true) : false;
        if ($secret === null || !is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $secret
        );
        return is_string($plain) ? $plain : null;
    }
}
