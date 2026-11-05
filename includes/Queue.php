<?php
/**
 * Results wait in a small queue and are sent by WP-Cron (`clikgate_flush` right away, `clikgate_cron` every
 * five minutes for retries), so the shopper's checkout and the gateway's webhook never wait for Clikgate.
 * 5xx, 408, 429 and network errors retry with backoff (1, 5, 15 minutes, 1, 3, 6, 12 hours), within Clikgate's
 * 24-hour matching window; other 4xx answers are final and shown in the status panel.
 */

declare(strict_types=1);

namespace Clikgate\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Queue
{
    public const OPTION = 'clikgate_queue';
    public const SENT = 'clikgate_sent';
    public const MAX = 500;
    public const BACKOFF = [60, 300, 900, 3600, 10800, 21600, 43200];

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null> $label what the status panel shows (no references)
     */
    public static function push(string $path, array $body, string $key, array $label): bool
    {
        if (in_array($key, self::sent(), true)) {
            return false;
        }
        $queue = self::all();
        foreach ($queue as $item) {
            if (($item['key'] ?? '') === $key) {
                return false;
            }
        }
        $queue[] = ['key' => $key, 'path' => $path, 'body' => $body, 'label' => $label, 'attempts' => 0, 'next_at' => 0];
        if (count($queue) > self::MAX) {
            $dropped = array_shift($queue);
            Log::add('dropped', ($dropped['label'] ?? []) + ['code' => 'queue_full']);
        }
        update_option(self::OPTION, $queue, false);
        if (function_exists('wp_next_scheduled') && !wp_next_scheduled('clikgate_flush')) {
            wp_schedule_single_event(time(), 'clikgate_flush');
        }
        return true;
    }

    /** Sends the due items (at most $limit). Returns how many were sent. */
    public static function flush(int $limit = 20): int
    {
        $key = Connection::key();
        if ($key === null || !Connection::connected()) {
            return 0;
        }
        // One flush at a time (Clikgate also ignores repeats, so a lost lock only costs a duplicate request)
        if (get_transient('clikgate_flush_lock')) {
            return 0;
        }
        set_transient('clikgate_flush_lock', 1, 120);
        $sent = 0;
        $done = [];
        $retry = [];
        foreach (self::all() as $item) {
            if ($sent >= $limit) {
                break;
            }
            $item_key = (string) ($item['key'] ?? '');
            if ((int) ($item['next_at'] ?? 0) > time()) {
                continue;
            }
            $body = (array) $item['body'];
            // Private mode switched on after the result was queued: Clikgate would refuse the order number
            if (Connection::private_mode()) {
                unset($body['order_id']);
            }
            $response = Api::request('POST', (string) $item['path'], $body, $key);
            $label = (array) ($item['label'] ?? []);
            if ($response->ok()) {
                Connection::set_blocked(null);
                $sent++;
                $done[] = $item_key;
                self::remember_sent($item_key);
                Log::add(!empty($response->body['duplicate']) ? 'duplicate' : 'sent', $label + [
                    'match_status' => isset($response->body['match_status']) ? (string) $response->body['match_status'] : null,
                ]);
                continue;
            }
            if ($response->disconnected()) {
                Connection::mark_disconnected((string) $response->code);
                Log::add('error', $label + ['code' => $response->code, 'message' => $response->message]);
                break; // keep the rest: they go out once the site is connected again
            }
            if ($response->blocked()) {
                // Nothing will be accepted until the admin fixes it: keep everything, try again in an hour
                Connection::set_blocked((string) $response->code, $response->message);
                foreach (self::all() as $waiting) {
                    $retry[(string) ($waiting['key'] ?? '')] = ['next_at' => time() + HOUR_IN_SECONDS];
                }
                break;
            }
            if ($response->retryable()) {
                $attempts = (int) ($item['attempts'] ?? 0) + 1;
                if ($attempts > count(self::BACKOFF)) {
                    $done[] = $item_key;
                    Log::add('gave_up', $label + ['code' => $response->code]);
                    continue;
                }
                $wait = self::BACKOFF[$attempts - 1];
                if ($response->retry_after !== null) {
                    $wait = max($wait, min($response->retry_after, 3600));
                }
                $retry[$item_key] = ['attempts' => $attempts, 'next_at' => time() + $wait];
                if ($response->status === 429) {
                    break;
                }
                continue;
            }
            $done[] = $item_key;
            Log::add('error', $label + ['code' => $response->code, 'message' => $response->message]);
        }
        // Merge into the queue as it is now: items pushed while this flush ran are kept
        $queue = [];
        foreach (self::all() as $item) {
            $item_key = (string) ($item['key'] ?? '');
            if (in_array($item_key, $done, true)) {
                continue;
            }
            $queue[] = isset($retry[$item_key]) ? array_merge($item, $retry[$item_key]) : $item;
        }
        update_option(self::OPTION, $queue, false);
        delete_transient('clikgate_flush_lock');
        return $sent;
    }

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $queue = get_option(self::OPTION, []);
        return is_array($queue) ? array_values(array_filter($queue, 'is_array')) : [];
    }

    /** @return string[] the last 1000 sent keys (a repeat of a hook never sends twice) */
    private static function sent(): array
    {
        $sent = get_option(self::SENT, []);
        return is_array($sent) ? $sent : [];
    }

    private static function remember_sent(string $key): void
    {
        $sent = self::sent();
        $sent[] = $key;
        update_option(self::SENT, array_slice($sent, -1000), false);
    }
}
