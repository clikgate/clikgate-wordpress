<?php
/**
 * Adds the Clikgate web script to every front-end page, with this connection's web key (no theme editing):
 *
 *   <script src="https://sdk.clikgate.com/v1/static/clikgate.js" data-web-key="cgw_live_..." data-param="cgid" async></script>
 *
 * Options: data-consent="required" (wait for consent before storing the click ID) and
 * data-clikgate-payments="auto" (automatic payment detection on the default Payment goal), and (on by default)
 * data-server-endpoint, the plugin's keep route (Keep.php). The tag is the same on every
 * page and for every visitor, so page caches keep working. `CLIKGATE_SCRIPT_URL` overrides the URL (local
 * development only).
 */

declare(strict_types=1);

namespace Clikgate\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Script
{
    public const HANDLE = 'clikgate';
    public const DEFAULT_URL = 'https://sdk.clikgate.com/v1/static/clikgate.js';

    public static function register(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
        add_filter('script_loader_tag', [self::class, 'tag'], 10, 2);
    }

    public static function url(): string
    {
        return defined('CLIKGATE_SCRIPT_URL') ? (string) constant('CLIKGATE_SCRIPT_URL') : self::DEFAULT_URL;
    }

    public static function active(): bool
    {
        return Connection::connected() && Options::all()['script'] && self::web_key() !== '';
    }

    public static function enqueue(): void
    {
        if (!self::active()) {
            return;
        }
        // No version query: the script is versioned by its /v1/ path and cached by Clikgate's CDN
        wp_enqueue_script(self::HANDLE, self::url(), [], null, ['strategy' => 'async', 'in_footer' => false]);
    }

    /** Adds the data attributes to the plugin's own tag only. */
    public static function tag(string $tag, string $handle): string
    {
        if ($handle !== self::HANDLE || !self::active()) {
            return $tag;
        }
        return (string) preg_replace('/<script\b/', '<script ' . self::attributes(), $tag, 1);
    }

    public static function attributes(): string
    {
        $options = Options::all();
        $attributes = [
            'data-web-key' => self::web_key(),
            'data-param' => Click::param(),
        ];
        if ($options['consent_required']) {
            $attributes['data-consent'] = 'required';
        }
        if ($options['payments_auto']) {
            $attributes['data-clikgate-payments'] = 'auto';
        }
        if ($options['keep_click_id']) {
            // The plugin's own REST route keeps the click ID from this server (same origin as the page)
            $attributes['data-server-endpoint'] = Keep::endpoint();
        }
        $parts = [];
        foreach ($attributes as $name => $value) {
            $parts[] = $name . '="' . esc_attr($value) . '"';
        }
        return implode(' ', $parts);
    }

    private static function web_key(): string
    {
        return (string) (Connection::remote()['web_key'] ?? '');
    }
}
