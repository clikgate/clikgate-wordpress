<?php

declare(strict_types=1);

namespace Clikgate\WP\Tests\Unit;

use Clikgate\WP\AppBanners;
use Clikgate\WP\PageContext;
use Clikgate\WP\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WpStub;

/** window.ClikgatePage on each kind of page, its escaping, and the pages that never show a banner. */
final class PageContextTest extends TestCase
{
    /** @return array<string, array{0: array<string, bool|string>, 1: string}> */
    public static function pages(): array
    {
        return [
            'product' => [['is_product' => true, 'singular' => 'product'], 'product'],
            'product category' => [['is_product_category' => true], 'category'],
            'product tag' => [['is_product_tag' => true], 'category'],
            'shop' => [['is_shop' => true], 'shop'],
            'cart' => [['is_cart' => true, 'singular' => 'page'], 'cart'],
            'account' => [['is_account_page' => true, 'singular' => 'page'], 'account'],
            'home' => [['front_page' => true, 'singular' => 'page'], 'home'],
            'blog home' => [['home' => true], 'home'],
            'post' => [['singular' => 'post'], 'post'],
            'page' => [['singular' => 'page'], 'page'],
            'search or archive' => [[], 'other'],
            'checkout' => [['is_checkout' => true, 'singular' => 'page'], 'checkout'],
            'order-pay' => [['is_checkout' => true, 'endpoint:order-pay' => true], 'checkout'],
            'order-received' => [['endpoint:order-received' => true], 'checkout'],
            'EDD checkout' => [['edd_is_checkout' => true, 'singular' => 'page'], 'checkout'],
            'Paid Memberships Pro checkout' => [['pmpro_is_checkout' => true], 'checkout'],
            'MemberPress membership' => [['singular' => 'memberpressproduct'], 'checkout'],
            'GiveWP donation form' => [['singular' => 'give_forms'], 'checkout'],
            'CheckoutWC' => [['cfw_is_checkout' => true], 'checkout'],
            'CartFlows checkout step' => [['_is_wcf_checkout_type' => true, 'singular' => 'cartflows_step'], 'checkout'],
            'FunnelKit checkout' => [['singular' => 'wfacp_checkout'], 'checkout'],
        ];
    }

    /** @param array<string, bool|string> $is */
    #[DataProvider('pages')]
    public function test_page_type(array $is, string $type): void
    {
        WpStub::$is = $is;
        $this->assertSame($type, PageContext::current()['type']);
    }

    /** @return array<string, array{0: string}> */
    public static function payment_contents(): array
    {
        return [
            'WPForms' => ['<p>Pay below</p>[wpforms id="12"]'],
            'Gravity Forms' => ['[gravityform id="3" title="false"]'],
            'Fluent Forms block' => ['<!-- wp:fluentfom/guten-block {"formId":"4"} /-->'],
            'WP Simple Pay' => ['[simpay id="77"]'],
            'Paid Memberships Pro' => ['[pmpro_checkout]'],
            'MemberPress' => ['[mepr-membership-registration-form id="8"]'],
            'EDD block' => ['<!-- wp:edd/checkout /-->'],
            'GiveWP' => ['[give_form id="5"]'],
            'Amelia booking' => ['[ameliabooking]'],
            'WooCommerce checkout block' => ['<!-- wp:woocommerce/checkout {"align":"wide"} -->'],
        ];
    }

    #[DataProvider('payment_contents')]
    public function test_pages_with_a_payment_donation_or_booking_form_are_checkout(string $content): void
    {
        WpStub::$is = ['singular' => 'page'];
        WpStub::$post = (object) ['post_content' => $content];
        $this->assertSame(['type' => 'checkout'], PageContext::current());
    }

    public function test_a_cartflows_landing_step_is_not_checkout(): void
    {
        WpStub::$is = ['singular' => 'cartflows_step'];
        WpStub::$queried = 9;
        WpStub::$meta[9] = ['wcf-step-type' => 'landing'];
        $this->assertSame('other', PageContext::current()['type']);
        WpStub::$meta[9] = ['wcf-step-type' => 'checkout'];
        $this->assertSame('checkout', PageContext::current()['type']);
    }

    public function test_a_plain_page_with_text_about_forms_is_not_checkout(): void
    {
        WpStub::$is = ['singular' => 'page'];
        WpStub::$post = (object) ['post_content' => '<p>Use wpforms or gravityform for contact.</p>'];
        $this->assertSame(['type' => 'page'], PageContext::current());
    }

    public function test_product_pages_carry_the_product(): void
    {
        WpStub::$is = ['is_product' => true, 'singular' => 'product'];
        WpStub::$queried = 41;
        new \FakeWcProduct(41, 'Jollof spice mix', 'JSM-250', 'jollof-spice-mix', '4500.00');
        WpStub::$terms[41] = ['spices', 'pantry', 'spices'];
        $this->assertSame([
            'type' => 'product',
            'product_id' => 41,
            'sku' => 'JSM-250',
            'product_slug' => 'jollof-spice-mix',
            'name' => 'Jollof spice mix',
            'category_slugs' => ['spices', 'pantry'],
        ], PageContext::current());
        $this->assertStringNotContainsString('4500', AppBanners::inline(), 'no prices');
    }

    public function test_other_pages_carry_no_product(): void
    {
        WpStub::$is = ['is_shop' => true];
        WpStub::$queried = 41;
        new \FakeWcProduct(41, 'Jollof spice mix', 'JSM-250', 'jollof-spice-mix');
        $this->assertSame(['type' => 'shop'], PageContext::current());
    }

    public function test_names_are_escaped_for_the_inline_script(): void
    {
        WpStub::$is = ['is_product' => true, 'singular' => 'product'];
        WpStub::$queried = 7;
        $name = "Ada's \"best\" </script><script>alert(1)</script> <!-- x --> & co\u{2028}line\u{2029}end";
        new \FakeWcProduct(7, $name, '</script>', 'ada-best');
        WpStub::$terms[7] = ['<!--', 'a&b'];
        $inline = AppBanners::inline();
        $this->assertStringStartsWith('window.ClikgatePage = {', $inline);
        $this->assertStringEndsWith('};', $inline);
        foreach (['</script', '<script', '<!--', '-->', '<', '>', '&', "'", "\u{2028}", "\u{2029}"] as $raw) {
            $this->assertStringNotContainsString($raw, $inline, 'raw ' . json_encode($raw));
        }
        $this->assertStringContainsString('\\u2028', $inline);
        $this->assertStringContainsString('\u003C\/script\u003E', $inline);
        // The data round-trips unchanged: the loader shows it as text
        $json = substr($inline, strlen('window.ClikgatePage = '), -1);
        $decoded = json_decode($json, true);
        $this->assertSame($name, $decoded['name']);
        $this->assertSame('</script>', $decoded['sku']);
        $this->assertSame(['<!--', 'a&b'], $decoded['category_slugs']);
    }

    public function test_long_values_are_trimmed_and_control_characters_removed(): void
    {
        WpStub::$is = ['is_product' => true, 'singular' => 'product'];
        WpStub::$queried = 8;
        new \FakeWcProduct(8, str_repeat('é', 300) . "\x00", str_repeat('S', 100), str_repeat('s', 200));
        WpStub::$terms[8] = array_map(static fn (int $i): string => 'cat-' . $i, range(1, 15));
        $context = PageContext::current();
        $this->assertSame(PageContext::MAX_NAME, mb_strlen($context['name']));
        $this->assertSame(PageContext::MAX_SKU, strlen($context['sku']));
        $this->assertSame(PageContext::MAX_SLUG, strlen($context['product_slug']));
        $this->assertCount(PageContext::MAX_CATEGORIES, $context['category_slugs']);
    }

    public function test_a_filter_can_add_data_but_never_uncheck_checkout(): void
    {
        add_filter('clikgate_page_context', static fn (array $c): array => ['type' => 'product', 'name' => 'x', 'price' => '100', 'email' => 'tobi@example.com']);
        WpStub::$is = ['is_checkout' => true];
        $this->assertSame(['type' => 'checkout'], PageContext::current());
        WpStub::$is = ['singular' => 'page'];
        $this->assertSame(['type' => 'product', 'name' => 'x'], PageContext::current(), 'unknown keys are dropped');
    }

    public function test_a_filter_can_mark_more_checkout_pages(): void
    {
        add_filter('clikgate_is_checkout_page', static fn (bool $checkout): bool => true);
        WpStub::$is = ['singular' => 'page'];
        $this->assertSame(['type' => 'checkout'], PageContext::current());
    }

    public function test_an_unknown_type_from_a_filter_becomes_other(): void
    {
        add_filter('clikgate_page_context', static fn (array $c): array => ['type' => '<b>landing</b>']);
        $this->assertSame(['type' => 'other'], PageContext::current());
    }
}
