<?php
/**
 * The visit's Clikgate click ID. The web script stores it in the first-party cookie `_cg_{click_id_param}`
 * (`_cg_cgid` by default) and its attachToForms() adds it to forms as a hidden `{click_id_param}` field. The plugin
 * reads it when the order, entry or signup is created (the shopper's own request), keeps it with that record,
 * and sends it with the confirmation later, which often comes from a gateway webhook without cookies.
 *
 * The plugin never sets cookies while a page renders and never changes cached pages (works with page-caching plugins).
 * Its only cookies come from the keep REST route (Keep.php), which the web script calls; a visitor who hasn't given
 * consent (data-consent="required") has no click ID here either.
 */

declare(strict_types=1);

namespace Clikgate\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Click
{
    public const PATTERN = '/^clk_[0-9A-Z]{26}$/';
    private const TRANSIENT = 'clikgate_click_';
    private const KEEP = 45 * DAY_IN_SECONDS;

    public static function param(): string
    {
        $param = (string) (Connection::remote()['click_id_param'] ?? 'cgid');
        return preg_match('/^[A-Za-z0-9_]{1,32}$/', $param) === 1 ? $param : 'cgid';
    }

    /** The click ID of the current request: the web script's cookie, a form field, or the URL parameter. */
    public static function from_request(): ?string
    {
        $param = self::param();
        // phpcs:disable WordPress.Security.NonceVerification -- read-only, validated against the click ID pattern
        $candidates = [
            $_COOKIE['_cg_' . $param] ?? null,
            $_POST[$param] ?? null,
            $_GET[$param] ?? null,
            $_COOKIE['cg_click_id'] ?? null, // set by the earlier "Clikgate for WooCommerce" plugin
        ];
        // phpcs:enable
        foreach ($candidates as $value) {
            if (is_string($value)) {
                $value = sanitize_text_field(wp_unslash($value));
                if (self::valid($value)) {
                    return $value;
                }
            }
        }
        return null;
    }

    public static function valid(?string $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    /** Remember the click of a record created in the shopper's request (entry, signup, order). */
    public static function remember(string $source, string $id, ?string $click = null): void
    {
        $click ??= self::from_request();
        if ($click !== null && self::valid($click) && $id !== '') {
            set_transient(self::TRANSIENT . md5($source . ':' . $id), $click, self::KEEP);
        }
    }

    /** The remembered click of a record, else the current request's (the confirmation may run in the shopper's request). */
    public static function recall(string $source, string $id): ?string
    {
        $saved = $id !== '' ? get_transient(self::TRANSIENT . md5($source . ':' . $id)) : false;
        if (is_string($saved) && self::valid($saved)) {
            return $saved;
        }
        // On admin screens (an admin marking an entry paid) the request's cookie is the admin's, not the payer's
        if (function_exists('is_admin') && is_admin() && !(function_exists('wp_doing_ajax') && wp_doing_ajax())) {
            return null;
        }
        return self::from_request();
    }
}
