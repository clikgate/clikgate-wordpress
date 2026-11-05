<?php
/**
 * Settings › Clikgate: connection (device sign-in), status panel, "Send a test", goal mapping, the web
 * script switches and App banners.
 */

declare(strict_types=1);

namespace Clikgate\WP\Admin;

use Clikgate\WP\AppBanners;
use Clikgate\WP\Connection;
use Clikgate\WP\DeviceFlow;
use Clikgate\WP\Log;
use Clikgate\WP\Options;
use Clikgate\WP\Plugin;
use Clikgate\WP\Queue;
use Clikgate\WP\Response;
use Clikgate\WP\Script;

if (!defined('ABSPATH')) {
    exit;
}

final class SettingsPage
{
    public const SLUG = 'clikgate';

    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_clikgate_disconnect', [$this, 'disconnect']);
        add_action('admin_post_clikgate_app_banners', [$this, 'app_banners']);
        add_filter('plugin_action_links_' . plugin_basename(CLIKGATE_FILE), [$this, 'action_links']);
    }

    public function menu(): void
    {
        add_options_page(__('Clikgate', 'clikgate'), __('Clikgate', 'clikgate'), 'manage_options', self::SLUG, [$this, 'render']);
    }

    public function settings(): void
    {
        register_setting('clikgate', Options::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [Options::class, 'sanitize'],
            'default' => [],
        ]);
    }

    /** @param string[] $links */
    public function action_links($links): array
    {
        $links = is_array($links) ? $links : [];
        array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=' . self::SLUG)) . '">' . esc_html__('Settings', 'clikgate') . '</a>');
        return $links;
    }

    public function assets(string $hook): void
    {
        if ($hook !== 'settings_page_' . self::SLUG) {
            return;
        }
        wp_enqueue_style('clikgate-admin', plugins_url('assets/admin.css', CLIKGATE_FILE), [], CLIKGATE_VERSION);
        wp_enqueue_script('clikgate-admin', plugins_url('assets/admin.js', CLIKGATE_FILE), [], CLIKGATE_VERSION, ['in_footer' => true]);
        wp_localize_script('clikgate-admin', 'clikgateAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(Ajax::NONCE),
            'i18n' => [
                'starting' => __('Starting…', 'clikgate'),
                'waiting' => __('Waiting for approval in Clikgate…', 'clikgate'),
                'connected' => __('Connected. Reloading…', 'clikgate'),
                'testOk' => __('Clikgate received the test.', 'clikgate'),
                'failed' => __('Something went wrong. Try again.', 'clikgate'),
            ],
        ]);
    }

    public function disconnect(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'clikgate'), 403);
        }
        check_admin_referer('clikgate_disconnect');
        $confirmed = $this->plugin->disconnect();
        DeviceFlow::cancel(get_current_user_id());
        wp_safe_redirect(admin_url('options-general.php?page=' . self::SLUG . '&clikgate=' . ($confirmed ? 'disconnected' : 'disconnected_locally')));
        exit;
    }

    /** "Show my App banners on this site" (on, off, check again): Clikgate is called here, never on the front end. */
    public function app_banners(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'clikgate'), 403);
        }
        check_admin_referer('clikgate_app_banners');
        $do = isset($_POST['clikgate_do']) ? sanitize_key(wp_unslash($_POST['clikgate_do'])) : '';
        if ($do === 'on') {
            AppBanners::turn_on();
        } elseif ($do === 'off') {
            AppBanners::turn_off();
        } elseif ($do === 'check') {
            AppBanners::sync(false);
        }
        wp_safe_redirect(admin_url('options-general.php?page=' . self::SLUG . '#clikgate-app-banners'));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $connection = Connection::get();
        $connected = Connection::connected();
        echo '<div class="wrap clikgate">';
        echo '<h1>' . esc_html__('Clikgate', 'clikgate') . '</h1>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
        $notice = isset($_GET['clikgate']) ? sanitize_key(wp_unslash($_GET['clikgate'])) : '';
        if ($notice === 'disconnected') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Disconnected. Clikgate revoked this website’s key.', 'clikgate') . '</p></div>';
        } elseif ($notice === 'disconnected_locally') {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Disconnected here, but Clikgate could not be reached. Revoke the plugin key in Clikgate › Settings › API keys, or delete the WordPress connection there.', 'clikgate') . '</p></div>';
        }
        $this->connection_section($connection, $connected);
        if ($connection !== null) {
            $this->status_section($connected);
            $this->settings_form();
            if ($connected) {
                $this->app_banners_section();
            }
        }
        echo '</div>';
    }

    /** @param array<string, mixed>|null $connection */
    private function connection_section(?array $connection, bool $connected): void
    {
        echo '<h2>' . esc_html__('Connection', 'clikgate') . '</h2>';
        if ($connection === null || !$connected) {
            if ($connection !== null) {
                echo '<div class="notice notice-error inline"><p>' . esc_html__('Clikgate no longer accepts this website’s key (it was revoked or the connection was deleted). Connect again.', 'clikgate') . '</p></div>';
            }
            echo '<p>' . esc_html__('Connect this site to your Clikgate organisation. You approve a short code in the Clikgate dashboard; no keys to copy.', 'clikgate') . '</p>';
            echo '<p><button type="button" class="button button-primary" id="clikgate-connect">' . esc_html__('Connect to Clikgate', 'clikgate') . '</button></p>';
            echo '<div id="clikgate-device" class="clikgate-device" hidden>';
            echo '<p>' . esc_html__('Your code:', 'clikgate') . ' <code id="clikgate-user-code" class="clikgate-code"></code></p>';
            echo '<p><a id="clikgate-approve" class="button" href="#" target="_blank" rel="noopener noreferrer">' . esc_html__('Open Clikgate to approve', 'clikgate') . '</a> ';
            echo '<button type="button" class="button-link" id="clikgate-cancel">' . esc_html__('Cancel', 'clikgate') . '</button></p>';
            echo '</div>';
            echo '<p id="clikgate-connect-status" class="clikgate-status-line" role="status" aria-live="polite"></p>';
            return;
        }
        $mode = Connection::mode();
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->row(__('Organisation', 'clikgate'), esc_html((string) ($connection['organization']['name'] ?? '')));
        $this->row(__('Mode', 'clikgate'), '<span class="clikgate-badge clikgate-badge-' . esc_attr($mode) . '">' . esc_html($mode === 'test' ? __('Test', 'clikgate') : __('Live', 'clikgate')) . '</span>');
        $this->row(__('Connected', 'clikgate'), esc_html((string) ($connection['connected_at'] ?? '')));
        echo '</tbody></table>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="clikgate_disconnect">';
        wp_nonce_field('clikgate_disconnect');
        submit_button(__('Disconnect', 'clikgate'), 'secondary', 'submit', false);
        echo '</form>';
    }

    private function status_section(bool $connected): void
    {
        $remote = Connection::remote();
        echo '<h2>' . esc_html__('Status', 'clikgate') . '</h2>';
        $blocked = is_array($remote['blocked'] ?? null) ? $remote['blocked'] : null;
        if ($blocked !== null) {
            $text = Response::plan_code((string) ($blocked['code'] ?? ''))
                ? __('Clikgate is not accepting results: Payment goals need the Starter plan or above. Results wait here and are sent after you upgrade.', 'clikgate')
                : __('Clikgate refused this website’s key for these calls. Disconnect and connect again.', 'clikgate');
            echo '<div class="notice notice-error inline"><p>' . esc_html($text) . '</p></div>';
        }
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->row(__('Web script', 'clikgate'), Script::active()
            ? esc_html__('Added to every page', 'clikgate')
            : esc_html__('Not added', 'clikgate'));
        $active = array_map(static fn ($i): string => $i->label(), $this->plugin->available());
        $this->row(__('Sending from', 'clikgate'), esc_html($active === [] ? __('No supported plugin is active', 'clikgate') : implode(', ', $active)));
        $this->row(__('Waiting to send', 'clikgate'), esc_html((string) count(Queue::all())));
        $this->row(__('Last test', 'clikgate'), esc_html((string) ($remote['last_test_at'] ?? '') ?: __('Never', 'clikgate')));
        $this->row(__('Settings fetched', 'clikgate'), esc_html((string) ($remote['fetched_at'] ?? '') ?: __('Not yet', 'clikgate')));
        echo '</tbody></table>';
        echo '<p><button type="button" class="button" id="clikgate-test"' . disabled(!$connected, true, false) . '>' . esc_html__('Send a test', 'clikgate') . '</button> ';
        echo '<button type="button" class="button" id="clikgate-refresh"' . disabled(!$connected, true, false) . '>' . esc_html__('Refresh', 'clikgate') . '</button></p>';
        echo '<p id="clikgate-test-status" class="clikgate-status-line" role="status" aria-live="polite"></p>';

        echo '<h3>' . esc_html__('Last results in Clikgate', 'clikgate') . '</h3>';
        $results = (array) ($remote['last_results'] ?? []);
        if ($results === []) {
            echo '<p>' . esc_html__('No confirmed payments yet.', 'clikgate') . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Received', 'clikgate') . '</th><th>' . esc_html__('Provider', 'clikgate') . '</th><th>' . esc_html__('Order', 'clikgate') . '</th><th>' . esc_html__('Amount', 'clikgate') . '</th><th>' . esc_html__('Payment', 'clikgate') . '</th><th>' . esc_html__('Match', 'clikgate') . '</th></tr></thead><tbody>';
            foreach ($results as $r) {
                $r = (array) $r;
                echo '<tr><td>' . esc_html((string) ($r['received_at'] ?? '')) . '</td><td>' . esc_html((string) ($r['provider'] ?? '')) . '</td><td>' . esc_html((string) ($r['order_id'] ?? '')) . '</td><td>' . esc_html(trim((string) ($r['amount'] ?? '') . ' ' . (string) ($r['currency'] ?? ''))) . '</td><td>' . esc_html((string) ($r['status'] ?? '')) . '</td><td>' . esc_html((string) ($r['match_status'] ?? '')) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h3>' . esc_html__('This site’s recent activity', 'clikgate') . '</h3>';
        $log = Log::all();
        if ($log === []) {
            echo '<p>' . esc_html__('Nothing sent yet.', 'clikgate') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Time', 'clikgate') . '</th><th>' . esc_html__('Result', 'clikgate') . '</th><th>' . esc_html__('Source', 'clikgate') . '</th><th>' . esc_html__('Order', 'clikgate') . '</th><th>' . esc_html__('Amount', 'clikgate') . '</th><th>' . esc_html__('Details', 'clikgate') . '</th></tr></thead><tbody>';
        foreach ($log as $e) {
            $details = trim(implode(' ', array_filter([(string) ($e['code'] ?? ''), (string) ($e['message'] ?? ''), (string) ($e['match_status'] ?? '')])));
            echo '<tr><td>' . esc_html((string) ($e['at'] ?? '')) . '</td><td>' . esc_html((string) ($e['outcome'] ?? '')) . '</td><td>' . esc_html((string) ($e['source'] ?? '')) . '</td><td>' . esc_html((string) ($e['order_id'] ?? '')) . '</td><td>' . esc_html(trim((string) ($e['amount'] ?? '') . ' ' . (string) ($e['currency'] ?? ''))) . '</td><td>' . esc_html($details) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function settings_form(): void
    {
        $options = Options::all();
        $remote = Connection::remote();
        $goals = (array) ($remote['goals'] ?? []);
        $name = Options::OPTION;
        echo '<form method="post" action="options.php">';
        settings_fields('clikgate');

        echo '<h2>' . esc_html__('Goals', 'clikgate') . '</h2>';
        echo '<p>' . esc_html__('Choose the goal each plugin records. “Clikgate decides” uses the goal set for this website in Clikgate, else your default Payment goal. A Payment goal counts only confirmed payments, with the confirmed amount.', 'clikgate') . '</p>';
        $available = $this->plugin->available();
        if ($available === []) {
            echo '<p><em>' . esc_html__('No supported plugin is active. See the list of supported plugins in the Clikgate docs.', 'clikgate') . '</em></p>';
        } else {
            echo '<table class="widefat clikgate-goals"><thead><tr><th>' . esc_html__('Plugin', 'clikgate') . '</th><th>' . esc_html__('Send', 'clikgate') . '</th><th>' . esc_html__('Goal', 'clikgate') . '</th><th>' . esc_html__('Per item (one “ID = goal_key” per line)', 'clikgate') . '</th></tr></thead><tbody>';
            foreach ($available as $integration) {
                $id = $integration->id();
                $enabled = $options['integrations'][$id] ?? true;
                $current = $options['goals'][$id] ?? '';
                echo '<tr><td><strong>' . esc_html($integration->label()) . '</strong></td>';
                echo '<td><input type="hidden" name="' . esc_attr($name) . '[integrations][' . esc_attr($id) . ']" value="0"><label><input type="checkbox" name="' . esc_attr($name) . '[integrations][' . esc_attr($id) . ']" value="1"' . checked($enabled, true, false) . '> ' . esc_html__('On', 'clikgate') . '</label></td>';
                echo '<td><select name="' . esc_attr($name) . '[goals][' . esc_attr($id) . ']" aria-label="' . esc_attr(sprintf(/* translators: %s: plugin name */ __('Goal for %s', 'clikgate'), $integration->label())) . '">';
                echo '<option value="">' . esc_html__('Clikgate decides', 'clikgate') . '</option>';
                foreach ($goals as $goal) {
                    $goal = (array) $goal;
                    $suffix = ($goal['kind'] ?? 'standard') === 'payment' ? ' — ' . __('Payment goal', 'clikgate') : '';
                    echo '<option value="' . esc_attr((string) $goal['key']) . '"' . selected($current, (string) $goal['key'], false) . '>' . esc_html((string) $goal['name'] . $suffix) . '</option>';
                }
                if ($current !== '' && !in_array($current, array_column($goals, 'key'), true)) {
                    echo '<option value="' . esc_attr($current) . '" selected>' . esc_html($current) . '</option>';
                }
                echo '</select></td>';
                echo '<td><textarea rows="2" cols="28" class="code" placeholder="' . esc_attr($integration->item_label() . ' = goal_key') . '" name="' . esc_attr($name) . '[overrides][' . esc_attr($id) . ']" aria-label="' . esc_attr($integration->item_label()) . '">' . esc_textarea(Options::format_overrides($options['overrides'][$id] ?? [])) . '</textarea></td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h2>' . esc_html__('Web script', 'clikgate') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->row(__('Add to the site', 'clikgate'), '<label><input type="checkbox" name="' . esc_attr($name) . '[script]" value="1"' . checked($options['script'], true, false) . '> ' . esc_html__('Add the Clikgate web script to every page (remove any copy you added by hand)', 'clikgate') . '</label>');
        $this->row(__('Consent', 'clikgate'), '<label><input type="checkbox" name="' . esc_attr($name) . '[consent_required]" value="1"' . checked($options['consent_required'], true, false) . '> ' . esc_html__('Wait for visitor consent before remembering the click (your consent banner calls clikgate.setConsent)', 'clikgate') . '</label>');
        $this->row(__('Automatic payment detection', 'clikgate'), '<label><input type="checkbox" name="' . esc_attr($name) . '[payments_auto]" value="1"' . checked($options['payments_auto'], true, false) . '> ' . esc_html__('Report payments from supported checkout pop-ups automatically (may stop working if a gateway changes its checkout)', 'clikgate') . '</label>');
        $this->row(__('Keep the click ID from your server', 'clikgate'), '<label><input type="checkbox" name="' . esc_attr($name) . '[keep_click_id]" value="1"' . checked($options['keep_click_id'], true, false) . '> ' . esc_html__('Safari, and every browser on iPhone, keeps a click ID saved by a script for up to 7 days (sometimes less). When your server saves it, it lasts as long as you choose, up to the browser\'s limit.', 'clikgate') . '</label>');
        echo '</tbody></table>';
        submit_button();
        echo '</form>';
    }

    private function app_banners_section(): void
    {
        // Re-checked on this page now and then (a waiting approval, a plan change); never on the front end
        AppBanners::maybe_sync();
        $settings = AppBanners::settings();
        $notice = AppBanners::notice();
        echo '<h2 id="clikgate-app-banners">' . esc_html__('App banners', 'clikgate') . '</h2>';
        echo '<p>' . esc_html($notice['message']);
        if ($notice['link'] !== '') {
            echo ' <a href="' . esc_url($notice['link']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($notice['link_text']) . '</a>';
        }
        echo '</p>';
        if ($notice['state'] === AppBanners::STATE_ACTIVE) {
            echo '<p class="description">' . esc_html__('Banners never show on checkout, cart, order and payment pages, or while a payment window is open. Add more pages under “Never show on” for this site in Clikgate. Pages you map to app screens there open the same screen in your app; other pages open your app’s home screen.', 'clikgate') . '</p>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="clikgate_app_banners">';
        wp_nonce_field('clikgate_app_banners');
        echo '<p><label><input type="checkbox" disabled' . checked($settings['enabled'], true, false) . '> ' . esc_html__('Show my App banners on this site', 'clikgate') . '</label></p>';
        echo '<p>';
        if ($settings['enabled']) {
            echo '<button type="submit" class="button" name="clikgate_do" value="off">' . esc_html__('Turn off', 'clikgate') . '</button> ';
            if ($notice['state'] === AppBanners::STATE_DENIED) {
                echo '<button type="submit" class="button" name="clikgate_do" value="on">' . esc_html__('Ask again', 'clikgate') . '</button> ';
            } elseif ($notice['state'] !== AppBanners::STATE_ACTIVE) {
                echo '<button type="submit" class="button" name="clikgate_do" value="check">' . esc_html__('Check again', 'clikgate') . '</button>';
            }
        } else {
            echo '<button type="submit" class="button button-primary" name="clikgate_do" value="on">' . esc_html__('Turn on', 'clikgate') . '</button>';
        }
        echo '</p></form>';
    }

    private function row(string $label, string $html): void
    {
        // $html is escaped by the callers
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
