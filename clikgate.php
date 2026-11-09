<?php
/**
 * Plugin Name:       Clikgate
 * Plugin URI:        https://clikgate.com/docs/wordpress
 * Description:       Connects your site to Clikgate without copying keys: adds the Clikgate web script and confirms payments from WooCommerce and supported payment-form plugins, matched to the click that brought the customer.
 * Version:           2.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            Clikgate
 * Author URI:        https://clikgate.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       clikgate
 * WC requires at least: 8.0
 *
 * "Clikgate for WordPress". The WordPress.org name is "Clikgate": plugin names may not contain "WordPress".
 *
 * After the business's own payment plugin (or WooCommerce) has marked a payment as paid, the plugin confirms it
 * to Clikgate (POST /v1/plugin/payments) with a plugin key obtained through device sign-in, so it counts on the
 * site's Payment goal. The key can only send payment results and goals and read its own settings. No gateway key
 * is ever involved.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('CLIKGATE_VERSION', '2.0.0');
define('CLIKGATE_FILE', __FILE__);
define('CLIKGATE_DIR', __DIR__);

require_once __DIR__ . '/includes/autoload.php';

// HPOS (custom order tables) and block checkout: the WooCommerce part uses only WC_Order methods
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', CLIKGATE_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', CLIKGATE_FILE, true);
    }
});

register_activation_hook(__FILE__, [\Clikgate\WP\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\Clikgate\WP\Plugin::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    \Clikgate\WP\Plugin::instance()->boot();
});
