<?php

declare(strict_types=1);

namespace Clikgate\WP\Integrations;

use Clikgate\WP\Dispatcher;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One supported plugin. Each uses only the hooks its official docs document as "payment completed" (and refunded
 * where documented); see https://clikgate.com/docs/wordpress.
 */
interface Integration
{
    /** The `source_plugin` sent to Clikgate ([a-z0-9_-]{1,40}). */
    public function id(): string;

    public function label(): string;

    /** What the per-item goal overrides are keyed by (e.g. "Product ID"). */
    public function item_label(): string;

    /** Whether the plugin is active on this site. */
    public function available(): bool;

    public function register(Dispatcher $dispatcher): void;
}
