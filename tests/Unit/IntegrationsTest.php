<?php

declare(strict_types=1);

namespace Clikgate\WP\Tests\Unit;

use Clikgate\WP\Click;
use Clikgate\WP\Dispatcher;
use Clikgate\WP\Integrations\EasyDigitalDownloads;
use Clikgate\WP\Integrations\FluentForms;
use Clikgate\WP\Integrations\GravityForms;
use Clikgate\WP\Integrations\MemberPress;
use Clikgate\WP\Integrations\PaidMembershipsPro;
use Clikgate\WP\Integrations\WPForms;
use Clikgate\WP\Integrations\WPSimplePay;
use Clikgate\WP\Queue;
use Clikgate\WP\Tests\TestCase;
use WpStub;

/** Every supported plugin turns its documented hook arguments into a payment confirmation. */
final class IntegrationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->connect('live', ['providers' => ['paystack', 'flutterwave', 'stripe', 'paypal']]);
    }

    /** @return array<string, mixed> */
    private function body(int $i = 0): array
    {
        $queue = Queue::all();
        $this->assertArrayHasKey($i, $queue, 'nothing was queued');
        return $queue[$i]['body'];
    }

    public function test_gravity_forms(): void
    {
        $gf = new GravityForms();
        $gf->register(new Dispatcher());
        $_COOKIE['_cg_cgid'] = self::CLICK;
        do_action('gform_entry_created', ['id' => '42', 'form_id' => '3'], []);
        $_COOKIE = [];
        $GLOBALS['gform_meta'][42] = ['payment_gateway' => 'gravityformsstripe'];
        $entry = ['id' => '42', 'form_id' => '3', 'currency' => 'USD', 'payment_date' => '2026-10-27 10:00:00', 'transaction_id' => 'pi_entry', 'payment_amount' => '20.00'];
        do_action('gform_post_payment_completed', $entry, ['type' => 'complete_payment', 'amount' => '20.00', 'transaction_id' => 'pi_3Mtw', 'payment_status' => 'Paid']);
        $this->assertSame([
            'provider' => 'stripe', 'reference' => 'pi_3Mtw', 'status' => 'success', 'source_plugin' => 'gravityforms',
            'amount' => '20', 'currency' => 'USD', 'occurred_at' => '2026-10-27T10:00:00Z', 'click_id' => self::CLICK, 'order_id' => '42',
        ], $this->body());
        do_action('gform_post_payment_refunded', $entry, ['type' => 'refund_payment', 'amount' => 5, 'transaction_id' => 'pi_3Mtw']);
        $this->assertSame('refunded', $this->body(1)['status']);
        $this->assertSame('5', $this->body(1)['amount']);
    }

    public function test_easy_digital_downloads(): void
    {
        $edd = new EasyDigitalDownloads();
        $edd->register(new Dispatcher());
        $_COOKIE['_cg_cgid'] = self::CLICK;
        do_action('edd_built_order', 10);
        $_COOKIE = [];
        $order = new class () {
            public int $id = 10;
            public string $status = 'complete';
            public string $total = '50.00';
            public string $currency = 'USD';
            public string $gateway = 'paypal_commerce';
            public string $mode = 'live';
            public string $date_completed = '2026-10-27 10:00:00';
            public function get_transaction_id(): string { return '8AB12345CD'; }
            public function get_items(): array { return [(object) ['product_id' => 900]]; }
        };
        $GLOBALS['edd_orders'][10] = $order;
        $refund = clone $order;
        $refund->id = 11;
        $refund->total = '-50.00';
        $GLOBALS['edd_orders'][11] = $refund;
        do_action('edd_complete_purchase', 10);
        $body = $this->body();
        $this->assertSame(['paypal', '8AB12345CD', '50', 'USD', self::CLICK, '10'], [$body['provider'], $body['reference'], $body['amount'], $body['currency'], $body['click_id'], $body['order_id']]);
        do_action('edd_refund_order', 10, 11, true);
        $this->assertSame(['refunded', '50', '11'], [$this->body(1)['status'], $this->body(1)['amount'], $this->body(1)['refund_id']]);
    }

    public function test_edd_test_mode_orders_skip_a_live_connection(): void
    {
        $order = (object) ['id' => 12, 'status' => 'complete', 'total' => '5', 'currency' => 'USD', 'gateway' => 'stripe', 'mode' => 'test'];
        $c = (new EasyDigitalDownloads())->confirmation($order, 'success', '5');
        $this->assertTrue($c->test);
        $this->assertSame('skipped', (new Dispatcher())->confirm($c));
    }

    public function test_fluent_forms(): void
    {
        $ff = new FluentForms();
        $ff->register(new Dispatcher());
        $_COOKIE['_cg_cgid'] = self::CLICK;
        do_action('fluentform/submission_inserted', 301, [], null);
        $_COOKIE = [];
        do_action('fluentform/after_payment_status_change', 'pending', (object) ['id' => 301]);
        $this->assertSame([], Queue::all());
        do_action('fluentform/after_payment_status_change', 'paid', (object) ['id' => 301, 'form_id' => 4, 'payment_total' => 750000, 'currency' => 'NGN', 'payment_method' => 'paystack', 'payment_mode' => 'live']);
        $body = $this->body();
        $this->assertSame(['paystack', 'ff-301', '7500', 'NGN', self::CLICK], [$body['provider'], $body['reference'], $body['amount'], $body['currency'], $body['click_id']]);
    }

    public function test_wpforms_stripe(): void
    {
        $wpf = new WPForms();
        $wpf->register(new Dispatcher());
        $_COOKIE['_cg_cgid'] = self::CLICK;
        $payment = (object) ['id' => 'pi_3Mtw', 'amount' => 2000, 'amount_received' => 2000, 'currency' => 'usd', 'status' => 'succeeded', 'livemode' => true, 'created' => 1761645600, 'metadata' => []];
        do_action('wpforms_stripe_process_complete', [], ['id' => 8], 99, $payment, null, null);
        $body = $this->body();
        $this->assertSame(['stripe', 'pi_3Mtw', '20', 'USD', self::CLICK, '99', false], [$body['provider'], $body['reference'], $body['amount'], $body['currency'], $body['click_id'], $body['order_id'], $body['test']]);
        $failed = (object) ['id' => 'pi_x', 'status' => 'requires_payment_method', 'amount' => 100, 'currency' => 'usd'];
        $this->assertNull($wpf->confirmation([], '1', $failed));
    }

    public function test_wp_simple_pay_reads_the_click_from_metadata(): void
    {
        $sp = new WPSimplePay();
        $sp->register(new Dispatcher());
        $_COOKIE['_cg_cgid'] = 'clk_01JZZZZZZZZZZZZZZZZZZZZZZZ'; // a webhook request: cookies are not the payer's
        $pi = ['id' => 'pi_9', 'amount_received' => 500, 'currency' => 'jpy', 'status' => 'succeeded', 'livemode' => true, 'metadata' => ['cg_click_id' => self::CLICK, 'simpay_form_id' => '12']];
        do_action('simpay_webhook_payment_intent_succeeded', (object) [], $pi);
        $body = $this->body();
        $this->assertSame(['pi_9', '500', 'JPY', self::CLICK], [$body['reference'], $body['amount'], $body['currency'], $body['click_id']]);
        $this->assertSame([], $sp->confirmation(['id' => 'pi_8', 'amount' => 1, 'currency' => 'usd', 'status' => 'succeeded'])->items);
        $this->assertNull($sp->confirmation(['id' => 'pi_8', 'amount' => 1, 'currency' => 'usd', 'status' => 'succeeded'])->click_id);
    }

    public function test_memberpress(): void
    {
        $mp = new MemberPress();
        $mp->register(new Dispatcher());
        $txn = new class () {
            public int $id = 7;
            public string $total = '29.99';
            public string $amount = '29.99';
            public string $trans_num = 'ch_mepr_1';
            public string $gateway = 'x1y2z3';
            public int $product_id = 400;
            public string $created_at = '2026-10-27 10:00:00';
            public function payment_method(): object { return new class () { public string $key = 'stripe'; }; }
        };
        $_COOKIE['_cg_cgid'] = self::CLICK;
        do_action('mepr-signup', $txn);
        $_COOKIE = [];
        $event = new class ($txn) {
            public function __construct(private object $txn) {}
            public function get_data(): object { return $this->txn; }
        };
        do_action('mepr-event-transaction-completed', $event);
        // MeprOptions isn't loaded here, so there is no currency: logged, not sent
        $this->assertSame([], Queue::all());
        add_filter('clikgate_confirmation', static fn ($c) => new \Clikgate\WP\Confirmation(
            $c->source_plugin, $c->gateway, $c->reference, $c->status, $c->amount, 'USD', $c->occurred_at, $c->click_id, $c->order_id
        ));
        do_action('mepr-event-transaction-completed', $event);
        $body = $this->body();
        $this->assertSame(['stripe', 'ch_mepr_1', '29.99', self::CLICK, '7'], [$body['provider'], $body['reference'], $body['amount'], $body['click_id'], $body['order_id']]);
    }

    public function test_paid_memberships_pro(): void
    {
        $pm = new PaidMembershipsPro();
        $pm->register(new Dispatcher());
        update_option('pmpro_currency', 'NGN');
        $order = (object) ['id' => 15, 'code' => 'A1B2C3', 'total' => '15000', 'payment_transaction_id' => 'T_pmpro_9', 'gateway' => 'paystack', 'gateway_environment' => 'live', 'membership_id' => 2, 'timestamp' => 1761645600];
        $_COOKIE['_cg_cgid'] = self::CLICK;
        do_action('pmpro_added_order', $order);
        $_COOKIE = [];
        do_action('pmpro_order_status_success', $order, 'pending');
        $body = $this->body();
        $this->assertSame(['paystack', 'T_pmpro_9', '15000', 'NGN', self::CLICK, 'A1B2C3'], [$body['provider'], $body['reference'], $body['amount'], $body['currency'], $body['click_id'], $body['order_id']]);
        $order->gateway_environment = 'sandbox';
        $this->assertTrue($pm->confirmation($order, 'success')->test);
        do_action('pmpro_order_status_refunded', (object) ['id' => 15, 'code' => 'A1B2C3', 'total' => '15000', 'payment_transaction_id' => 'T_pmpro_9', 'gateway' => 'paystack'], 'success');
        $this->assertSame('refunded', $this->body(1)['status']);
    }

    public function test_admin_screens_never_attach_the_admins_own_cookie(): void
    {
        WpStub::$admin = true;
        $_COOKIE['_cg_cgid'] = self::CLICK;
        $this->assertNull(Click::recall('gravityforms', '999'));
        WpStub::$ajax = true;
        $this->assertSame(self::CLICK, Click::recall('gravityforms', '999'));
    }
}
