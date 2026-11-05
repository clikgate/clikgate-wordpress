<?php
/**
 * `window.ClikgatePage`: what kind of page this is, and on product pages which product, so the App banners loader
 * can choose a banner and open the same product in the app. Public page information only: never prices, cart
 * contents or anything about the visitor. It depends only on the page (never the visitor), so cached pages stay
 * correct.
 *
 *   {type, product_id, sku, product_slug, name, category_slugs}
 *
 * `type` is product|category|shop|cart|checkout|account|home|post|page|other. `checkout` (never a banner) is the
 * WooCommerce checkout, order-pay and order-received pages, pages with a supported payment, donation or booking
 * form, and the checkout pages of the funnel builders listed in FUNNEL_BUILDERS. Developers can change the data
 * with the `clikgate_page_context` filter and add checkout pages with `clikgate_is_checkout_page`; a page detected
 * as checkout stays checkout whatever a filter returns.
 */

declare(strict_types=1);

namespace Clikgate\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class PageContext
{
    public const TYPES = ['product', 'category', 'shop', 'cart', 'checkout', 'account', 'home', 'post', 'page', 'other'];
    public const MAX_NAME = 200;
    public const MAX_SKU = 64;
    public const MAX_SLUG = 120;
    public const MAX_CATEGORIES = 10;

    /** Shortcodes of the supported payment-form, donation and booking plugins (a page with one counts as checkout) */
    public const PAYMENT_SHORTCODES = [
        'wpforms', 'gravityform', 'gravityforms', 'fluentform', 'simpay', 'pmpro_checkout',
        'mepr-membership-registration-form', 'download_checkout', 'give_form', 'give_donation_form',
        'ameliabooking', 'ameliastepbooking', 'bookly-form', 'woocommerce_checkout',
    ];
    /** Their blocks */
    public const PAYMENT_BLOCKS = [
        'woocommerce/checkout', 'wpforms/form-selector', 'gravityforms/form', 'fluentfom/guten-block',
        'simpay/payment-form', 'pmpro/checkout-page', 'edd/checkout', 'give/donation-form', 'give/donation-form-grid',
        'amelia/booking', 'bookly/form',
    ];
    /** Post types whose single pages are a checkout or payment step */
    public const CHECKOUT_POST_TYPES = ['memberpressproduct', 'give_forms', 'wfacp_checkout'];
    /** Funnel builders whose checkout pages are recognised (also listed in the plugin's docs) */
    public const FUNNEL_BUILDERS = ['CartFlows', 'FunnelKit (WooFunnels) checkout', 'CheckoutWC'];

    /** @return array<string, mixed> */
    public static function current(): array
    {
        $type = self::type();
        $context = ['type' => $type];
        if ($type === 'product') {
            $context += self::product();
        }
        $filtered = apply_filters('clikgate_page_context', $context);
        $context = self::clean(is_array($filtered) ? $filtered : $context);
        if ($type === 'checkout') {
            // Nobody paying is interrupted, whatever a filter says
            $context = ['type' => 'checkout'];
        }
        return $context;
    }

    public static function type(): string
    {
        if (self::is_checkout()) {
            return 'checkout';
        }
        if (self::wc('is_product')) {
            return 'product';
        }
        if (self::wc('is_product_category') || self::wc('is_product_tag') || self::wc('is_product_taxonomy')) {
            return 'category';
        }
        if (self::wc('is_shop')) {
            return 'shop';
        }
        if (self::wc('is_cart') || self::has_any_block(['woocommerce/cart'])) {
            return 'cart';
        }
        if (self::wc('is_account_page')) {
            return 'account';
        }
        if (function_exists('is_front_page') && is_front_page()) {
            return 'home';
        }
        if (function_exists('is_home') && is_home()) {
            return 'home';
        }
        if (function_exists('is_singular') && is_singular('post')) {
            return 'post';
        }
        if (function_exists('is_page') && is_page()) {
            return 'page';
        }
        return 'other';
    }

    /** Checkout, payment, donation and booking pages, and funnel-builder checkouts. */
    public static function is_checkout(): bool
    {
        $checkout = self::wc('is_checkout')
            || (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received')))
            || (function_exists('edd_is_checkout') && edd_is_checkout())
            || (function_exists('pmpro_is_checkout') && pmpro_is_checkout())
            // CheckoutWC replaces the WooCommerce checkout (is_checkout already covers it); its own conditional too
            || (function_exists('cfw_is_checkout') && cfw_is_checkout())
            // CartFlows: a checkout step
            || (function_exists('_is_wcf_checkout_type') && _is_wcf_checkout_type())
            || self::checkout_post_type()
            || self::has_any_shortcode(self::PAYMENT_SHORTCODES)
            || self::has_any_block(self::PAYMENT_BLOCKS);
        return (bool) apply_filters('clikgate_is_checkout_page', $checkout) || $checkout;
    }

    private static function checkout_post_type(): bool
    {
        if (!function_exists('is_singular')) {
            return false;
        }
        foreach (self::CHECKOUT_POST_TYPES as $post_type) {
            if (is_singular($post_type)) {
                return true;
            }
        }
        // CartFlows steps are `cartflows_step` posts; only the checkout step type is a checkout
        if (is_singular('cartflows_step') && function_exists('get_post_meta') && function_exists('get_queried_object_id')) {
            return (string) get_post_meta((int) get_queried_object_id(), 'wcf-step-type', true) === 'checkout';
        }
        return false;
    }

    /** @param string[] $tags */
    private static function has_any_shortcode(array $tags): bool
    {
        $content = self::content();
        if ($content === '' || !function_exists('has_shortcode')) {
            return false;
        }
        foreach ($tags as $tag) {
            if (has_shortcode($content, $tag)) {
                return true;
            }
        }
        return false;
    }

    /** @param string[] $blocks */
    private static function has_any_block(array $blocks): bool
    {
        $content = self::content();
        if ($content === '' || !function_exists('has_block')) {
            return false;
        }
        foreach ($blocks as $block) {
            if (has_block($block, $content)) {
                return true;
            }
        }
        return false;
    }

    private static function content(): string
    {
        if (!function_exists('is_singular') || !is_singular() || !function_exists('get_post')) {
            return '';
        }
        $post = get_post();
        return is_object($post) && isset($post->post_content) ? (string) $post->post_content : '';
    }

    /** @return array<string, mixed> */
    private static function product(): array
    {
        if (!function_exists('wc_get_product') || !function_exists('get_queried_object_id')) {
            return [];
        }
        $product = wc_get_product((int) get_queried_object_id());
        if (!is_object($product)) {
            return [];
        }
        $id = (int) $product->get_id();
        $categories = function_exists('wp_get_post_terms') ? wp_get_post_terms($id, 'product_cat', ['fields' => 'slugs']) : [];
        return [
            'product_id' => $id > 0 ? $id : null,
            'sku' => (string) $product->get_sku(),
            'product_slug' => (string) $product->get_slug(),
            'name' => (string) $product->get_name(),
            'category_slugs' => is_array($categories) ? $categories : [],
        ];
    }

    /**
     * Only the known keys, each with its type and length: the data is shown as text by the loader, never as HTML.
     *
     * @param array<mixed> $context
     * @return array<string, mixed>
     */
    public static function clean(array $context): array
    {
        $type = (string) ($context['type'] ?? 'other');
        $out = ['type' => in_array($type, self::TYPES, true) ? $type : 'other'];
        if (isset($context['product_id']) && is_numeric($context['product_id']) && (int) $context['product_id'] > 0) {
            $out['product_id'] = (int) $context['product_id'];
        }
        foreach (['sku' => self::MAX_SKU, 'product_slug' => self::MAX_SLUG, 'name' => self::MAX_NAME] as $key => $max) {
            $value = self::text($context[$key] ?? null, $max);
            if ($value !== '') {
                $out[$key] = $value;
            }
        }
        if (isset($context['category_slugs']) && is_array($context['category_slugs'])) {
            $slugs = [];
            foreach ($context['category_slugs'] as $slug) {
                $slug = self::text($slug, self::MAX_SLUG);
                if ($slug !== '' && !in_array($slug, $slugs, true)) {
                    $slugs[] = $slug;
                }
            }
            $out['category_slugs'] = array_slice($slugs, 0, self::MAX_CATEGORIES);
        }
        return $out;
    }

    /** Text without control characters, trimmed to $max characters (the JSON encoding escapes the rest). */
    private static function text($value, int $max): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $text = trim((string) preg_replace('/[\x00-\x1f\x7f]/u', '', (string) $value));
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }

    private static function wc(string $conditional): bool
    {
        return function_exists($conditional) && (bool) $conditional();
    }
}
