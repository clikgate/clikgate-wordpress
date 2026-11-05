<?php
/** In-memory stand-ins for the WordPress functions the plugin calls. */

declare(strict_types=1);

final class WpStub
{
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<string, mixed> */
    public static array $transients = [];
    /** @var array<string, list<array{0: callable, 1: int}>> */
    public static array $hooks = [];
    /** @var list<array{status: int, body: mixed, headers: array<string, string>}|WP_Error> */
    public static array $responses = [];
    /** @var list<array{url: string, method: string, headers: array<string, string>, body: ?array<string, mixed>}> */
    public static array $requests = [];
    /** @var list<array{hook: string, at: int}> */
    public static array $cron = [];
    /** @var list<array<int, mixed>> */
    public static array $enqueued = [];
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    public static array $rest_routes = [];
    public static bool $admin = false;
    public static bool $ajax = false;
    /** @var list<array{0: string, 1: string, 2: string}> wp_add_inline_script calls */
    public static array $inline = [];
    /** @var array<string, bool|string> the page's conditionals (`is_product` => true, `singular` => 'page', ...) */
    public static array $is = [];
    public static ?object $post = null;
    public static int $queried = 0;
    /** @var array<int, object> products by id */
    public static array $products = [];
    /** @var array<int, string[]> product category slugs by product id */
    public static array $terms = [];
    /** @var array<int, array<string, string>> */
    public static array $meta = [];

    public static function reset(): void
    {
        self::$options = [];
        self::$transients = [];
        self::$hooks = [];
        self::$responses = [];
        self::$requests = [];
        self::$cron = [];
        self::$enqueued = [];
        self::$rest_routes = [];
        self::$admin = false;
        self::$ajax = false;
        self::$inline = [];
        self::$is = [];
        self::$post = null;
        self::$queried = 0;
        self::$products = [];
        self::$terms = [];
        self::$meta = [];
        $_COOKIE = [];
        $_GET = [];
        $_POST = [];
    }

    /** Queue the next HTTP answer. @param array<string, mixed>|string $body */
    public static function respond(int $status, $body = [], array $headers = []): void
    {
        self::$responses[] = ['status' => $status, 'body' => $body, 'headers' => $headers];
    }

    public static function network_error(): void
    {
        self::$responses[] = new WP_Error('http_request_failed', 'cURL error 28');
    }
}

final class WP_Error
{
    public function __construct(public string $code = '', public string $message = '')
    {
    }
}

function get_option($name, $default = false)
{
    return array_key_exists($name, WpStub::$options) ? WpStub::$options[$name] : $default;
}

function update_option($name, $value, $autoload = null): bool
{
    WpStub::$options[$name] = $value;
    return true;
}

function delete_option($name): bool
{
    unset(WpStub::$options[$name]);
    return true;
}

function get_transient($name)
{
    return WpStub::$transients[$name] ?? false;
}

function set_transient($name, $value, $expiration = 0): bool
{
    WpStub::$transients[$name] = $value;
    return true;
}

function delete_transient($name): bool
{
    unset(WpStub::$transients[$name]);
    return true;
}

function add_action($hook, $callback, $priority = 10, $args = 1): bool
{
    WpStub::$hooks[$hook][] = [$callback, (int) $args];
    return true;
}

function add_filter($hook, $callback, $priority = 10, $args = 1): bool
{
    return add_action($hook, $callback, $priority, $args);
}

function do_action($hook, ...$args): void
{
    foreach (WpStub::$hooks[$hook] ?? [] as [$callback, $count]) {
        $callback(...array_slice($args, 0, $count));
    }
}

function apply_filters($hook, $value, ...$args)
{
    foreach (WpStub::$hooks[$hook] ?? [] as [$callback, $count]) {
        $value = $callback(...array_slice([$value, ...$args], 0, max(1, $count)));
    }
    return $value;
}

function wp_json_encode($data, $flags = 0)
{
    return json_encode($data, $flags);
}

function wp_remote_request($url, $args = [])
{
    $body = isset($args['body']) ? json_decode((string) $args['body'], true) : null;
    WpStub::$requests[] = ['url' => $url, 'method' => $args['method'] ?? 'GET', 'headers' => $args['headers'] ?? [], 'body' => $body];
    $next = array_shift(WpStub::$responses);
    if ($next === null) {
        return new WP_Error('http_request_failed', 'no stubbed response');
    }
    return $next;
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code($response)
{
    return is_array($response) ? $response['status'] : '';
}

function wp_remote_retrieve_body($response)
{
    if (!is_array($response)) {
        return '';
    }
    return is_string($response['body']) ? $response['body'] : (string) json_encode($response['body']);
}

function wp_remote_retrieve_header($response, $header)
{
    return is_array($response) ? ($response['headers'][strtolower($header)] ?? '') : '';
}

function sanitize_text_field($value): string
{
    return trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string) $value)) ?? '');
}

function sanitize_key($key): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)) ?? '';
}

function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

function esc_url_raw($url): string
{
    return filter_var($url, FILTER_VALIDATE_URL) ? (string) $url : '';
}

function esc_attr($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}

function esc_html($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}

function home_url($path = ''): string
{
    return 'https://shop.example' . $path;
}

function register_rest_route($namespace, $route, $args = []): bool
{
    WpStub::$rest_routes[] = [$namespace, $route, $args];
    return true;
}

function rest_url($path = ''): string
{
    return 'https://shop.example/wp-json/' . ltrim($path, '/');
}

function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}

function __($text, $domain = 'default'): string
{
    return $text;
}

function wp_salt($scheme = 'auth'): string
{
    return 'test-salt-not-a-secret';
}

function wp_next_scheduled($hook)
{
    foreach (WpStub::$cron as $event) {
        if ($event['hook'] === $hook) {
            return $event['at'];
        }
    }
    return false;
}

function wp_schedule_single_event($at, $hook, $args = []): bool
{
    WpStub::$cron[] = ['hook' => $hook, 'at' => (int) $at];
    return true;
}

function wp_schedule_event($at, $recurrence, $hook, $args = []): bool
{
    return wp_schedule_single_event($at, $hook);
}

function is_admin(): bool
{
    return WpStub::$admin;
}

function wp_doing_ajax(): bool
{
    return WpStub::$ajax;
}

function wp_enqueue_script(...$args): void
{
    WpStub::$enqueued[] = $args;
}

function wp_add_inline_script($handle, $data, $position = 'after'): bool
{
    WpStub::$inline[] = [$handle, $data, $position];
    return true;
}

function get_queried_object_id(): int
{
    return WpStub::$queried;
}

function get_post($post = null)
{
    return WpStub::$post;
}

function get_post_meta($id, $key = '', $single = false)
{
    return WpStub::$meta[(int) $id][$key] ?? '';
}

function wp_get_post_terms($id, $taxonomy = 'post_tag', $args = [])
{
    return WpStub::$terms[(int) $id] ?? [];
}

function has_shortcode($content, $tag): bool
{
    return preg_match('/\[' . preg_quote((string) $tag, '/') . '[\s\]]/', (string) $content) === 1;
}

function has_block($block, $content = null): bool
{
    return str_contains((string) $content, '<!-- wp:' . $block . ' ') || str_contains((string) $content, '<!-- wp:' . $block . ' /-->');
}

function is_singular($type = ''): bool
{
    $current = WpStub::$is['singular'] ?? null;
    if (!is_string($current)) {
        return false;
    }
    return $type === '' || in_array($current, (array) $type, true);
}

function is_page($page = ''): bool
{
    return (WpStub::$is['singular'] ?? null) === 'page';
}

function is_front_page(): bool
{
    return !empty(WpStub::$is['front_page']);
}

function is_home(): bool
{
    return !empty(WpStub::$is['home']);
}

// The conditionals of WooCommerce and of the payment and funnel plugins the page detection knows
function is_product(): bool
{
    return !empty(WpStub::$is['is_product']);
}

function is_product_category(): bool
{
    return !empty(WpStub::$is['is_product_category']);
}

function is_product_tag(): bool
{
    return !empty(WpStub::$is['is_product_tag']);
}

function is_product_taxonomy(): bool
{
    return !empty(WpStub::$is['is_product_taxonomy']);
}

function is_shop(): bool
{
    return !empty(WpStub::$is['is_shop']);
}

function is_cart(): bool
{
    return !empty(WpStub::$is['is_cart']);
}

function is_checkout(): bool
{
    return !empty(WpStub::$is['is_checkout']);
}

function is_account_page(): bool
{
    return !empty(WpStub::$is['is_account_page']);
}

function edd_is_checkout(): bool
{
    return !empty(WpStub::$is['edd_is_checkout']);
}

function pmpro_is_checkout(): bool
{
    return !empty(WpStub::$is['pmpro_is_checkout']);
}

function cfw_is_checkout(): bool
{
    return !empty(WpStub::$is['cfw_is_checkout']);
}

function _is_wcf_checkout_type(): bool
{
    return !empty(WpStub::$is['_is_wcf_checkout_type']);
}

function is_wc_endpoint_url($endpoint = false): bool
{
    return !empty(WpStub::$is['endpoint:' . $endpoint]);
}
