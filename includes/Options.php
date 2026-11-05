<?php
/**
 * The site's own Clikgate settings (wp_options `clikgate_options`): the web script switches, which
 * integrations send confirmations, and which goal each plugin, form, product or level records.
 */

declare(strict_types=1);

namespace Clikgate\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Options
{
    public const OPTION = 'clikgate_options';

    /** @return array{script: bool, consent_required: bool, payments_auto: bool, keep_click_id: bool, integrations: array<string, bool>, goals: array<string, string>, overrides: array<string, array<string, string>>} */
    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];
        return [
            'script' => !array_key_exists('script', $saved) || !empty($saved['script']),
            'consent_required' => !empty($saved['consent_required']),
            'payments_auto' => !empty($saved['payments_auto']),
            // On until the admin turns it off
            'keep_click_id' => !array_key_exists('keep_click_id', $saved) || !empty($saved['keep_click_id']),
            'integrations' => self::bool_map($saved['integrations'] ?? []),
            'goals' => self::string_map($saved['goals'] ?? []),
            'overrides' => self::overrides($saved['overrides'] ?? []),
        ];
    }

    public static function integration_enabled(string $id): bool
    {
        $integrations = self::all()['integrations'];
        // Every detected integration is on until the admin turns it off
        return $integrations[$id] ?? true;
    }

    /** @param array<string, mixed> $values */
    public static function save(array $values): void
    {
        update_option(self::OPTION, self::sanitize($values), false);
    }

    /**
     * Sanitises the settings form. Goal keys follow Clikgate's goal key rules (lower-case letters, digits,
     * `_`, `-`, `.`); overrides come from a textarea of `ID = goal_key` lines.
     *
     * @param mixed $value
     * @return array<string, mixed>
     */
    public static function sanitize($value): array
    {
        $value = is_array($value) ? $value : [];
        $overrides = [];
        foreach ((array) ($value['overrides'] ?? []) as $source => $text) {
            $source = self::key((string) $source);
            if ($source === '') {
                continue;
            }
            $overrides[$source] = is_array($text) ? self::string_map($text) : self::parse_overrides((string) $text);
        }
        $integrations = [];
        foreach ((array) ($value['integrations'] ?? []) as $id => $on) {
            $id = self::key((string) $id);
            if ($id !== '') {
                $integrations[$id] = !empty($on) && $on !== '0';
            }
        }
        return [
            'script' => !empty($value['script']),
            'consent_required' => !empty($value['consent_required']),
            'payments_auto' => !empty($value['payments_auto']),
            'keep_click_id' => !empty($value['keep_click_id']),
            'integrations' => $integrations,
            'goals' => self::string_map((array) ($value['goals'] ?? [])),
            'overrides' => $overrides,
        ];
    }

    /** @return array<string, string> `ID = goal_key` lines (one per line; `#` starts a comment) */
    public static function parse_overrides(string $text): array
    {
        $map = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$id, $goal] = array_map('trim', explode('=', $line, 2));
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?? '';
            $goal = self::goal_key($goal);
            if ($id !== '' && $goal !== '') {
                $map[$id] = $goal;
            }
        }
        return $map;
    }

    /** @param array<string, string> $map */
    public static function format_overrides(array $map): string
    {
        $lines = [];
        foreach ($map as $id => $goal) {
            $lines[] = $id . ' = ' . $goal;
        }
        return implode("\n", $lines);
    }

    public static function goal_key(string $goal): string
    {
        $goal = strtolower(trim($goal));
        return preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $goal) === 1 ? $goal : '';
    }

    private static function key(string $key): string
    {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower($key)) ?? '';
    }

    /** @return array<string, bool> */
    private static function bool_map($map): array
    {
        $out = [];
        foreach ((array) $map as $k => $v) {
            $out[(string) $k] = (bool) $v;
        }
        return $out;
    }

    /** @return array<string, string> goal keys by source (empty values dropped) */
    private static function string_map($map): array
    {
        $out = [];
        foreach ((array) $map as $k => $v) {
            $k = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $k) ?? '';
            $goal = self::goal_key((string) $v);
            if ($k !== '' && $goal !== '') {
                $out[$k] = $goal;
            }
        }
        return $out;
    }

    /** @return array<string, array<string, string>> */
    private static function overrides($value): array
    {
        $out = [];
        foreach ((array) $value as $source => $map) {
            $out[(string) $source] = self::string_map(is_array($map) ? $map : []);
        }
        return $out;
    }
}
