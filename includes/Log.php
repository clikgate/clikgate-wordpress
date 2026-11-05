<?php
/**
 * The status panel's local log: the last 30 things the plugin sent, skipped or failed to send. It holds no
 * plugin key, no customer data and no payment references: only the source, record id, provider, status,
 * amount, outcome and Clikgate's error code.
 */

declare(strict_types=1);

namespace Clikgate\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Log
{
    public const OPTION = 'clikgate_log';
    private const KEEP = 30;

    /** @param array<string, scalar|null> $entry */
    public static function add(string $outcome, array $entry): void
    {
        $log = self::all();
        array_unshift($log, ['at' => gmdate('c'), 'outcome' => $outcome] + array_map(
            static fn ($v) => is_string($v) ? sanitize_text_field($v) : $v,
            array_intersect_key($entry, array_flip(['source', 'order_id', 'provider', 'status', 'amount', 'currency', 'goal', 'code', 'message', 'match_status']))
        ));
        update_option(self::OPTION, array_slice($log, 0, self::KEEP), false);
    }

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $log = get_option(self::OPTION, []);
        return is_array($log) ? array_values(array_filter($log, 'is_array')) : [];
    }
}
