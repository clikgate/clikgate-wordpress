<?php
/**
 * Which goal a result records: the first matching per-item override (product, form, download, membership
 * level...), else the integration's goal, else none (Clikgate then uses the report's goal, the connection's
 * goal or the default Payment goal).
 */

declare(strict_types=1);

namespace Clikgate\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Goals
{
    /** @param string[] $items */
    public static function resolve(string $source_plugin, array $items): ?string
    {
        $options = Options::all();
        $overrides = $options['overrides'][$source_plugin] ?? [];
        foreach ($items as $item) {
            $item = (string) $item;
            if ($item !== '' && isset($overrides[$item])) {
                return $overrides[$item];
            }
        }
        $goal = $options['goals'][$source_plugin] ?? '';
        return $goal !== '' ? $goal : null;
    }

    /** A goal known to be a Standard goal (`kind: standard`): its results go to /v1/plugin/goals. */
    public static function is_plain_goal(?string $goal): bool
    {
        if ($goal === null) {
            return false;
        }
        $known = Connection::goal($goal);
        return $known !== null && ($known['kind'] ?? 'standard') === 'standard';
    }
}
