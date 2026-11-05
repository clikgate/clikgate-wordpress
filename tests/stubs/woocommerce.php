<?php
/** Minimal WooCommerce, Easy Digital Downloads and Gravity Forms stand-ins for the integration tests. */

declare(strict_types=1);

final class WooCommerce
{
}

final class FakeWcItem
{
    public function __construct(private int $product, private int $variation = 0)
    {
    }

    public function get_product_id(): int
    {
        return $this->product;
    }

    public function get_variation_id(): int
    {
        return $this->variation;
    }
}

class WC_Order
{
    /** @var array<int, WC_Order> */
    public static array $store = [];
    /** @var array<string, mixed> */
    public array $meta = [];
    public int $saves = 0;

    /** @param FakeWcItem[] $items */
    public function __construct(
        public int $id,
        public string $total = '7500.00',
        public string $currency = 'NGN',
        public string $payment_method = 'paystack',
        public string $transaction_id = 'T_ref_001',
        public bool $paid = true,
        public array $items = [],
        public ?DateTimeImmutable $date_paid = null
    ) {
        self::$store[$id] = $this;
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_total(): string
    {
        return $this->total;
    }

    public function get_order_number(): string
    {
        return 'SW-' . $this->id;
    }

    public function get_currency(): string
    {
        return $this->currency;
    }

    public function get_payment_method(): string
    {
        return $this->payment_method;
    }

    public function get_transaction_id(): string
    {
        return $this->transaction_id;
    }

    public function is_paid(): bool
    {
        return $this->paid;
    }

    public function get_items(): array
    {
        return $this->items;
    }

    public function get_date_paid(): ?DateTimeImmutable
    {
        return $this->date_paid;
    }

    public function get_date_created(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-27T09:00:00Z');
    }

    public function get_meta($key)
    {
        return $this->meta[$key] ?? '';
    }

    public function update_meta_data($key, $value): void
    {
        $this->meta[$key] = $value;
    }

    public function save(): int
    {
        $this->saves++;
        return $this->id;
    }
}

final class WC_Order_Refund extends WC_Order
{
    public string $amount = '0';

    public function get_amount(): string
    {
        return $this->amount;
    }
}

function wc_get_order($id)
{
    return WC_Order::$store[(int) $id] ?? false;
}

function wc_get_price_decimals(): int
{
    return 2;
}

function wc_format_decimal($number, $dp = false): string
{
    return $dp === false ? (string) $number : number_format((float) $number, (int) $dp, '.', '');
}

/** @var array<int, object> */
$GLOBALS['edd_orders'] = [];

function edd_get_order($id)
{
    return $GLOBALS['edd_orders'][(int) $id] ?? false;
}

/** @var array<int, array<string, string>> */
$GLOBALS['gform_meta'] = [];

function gform_get_meta($entry_id, $key)
{
    return $GLOBALS['gform_meta'][(int) $entry_id][$key] ?? false;
}

final class FakeWcProduct
{
    public function __construct(public int $id, public string $name, public string $sku = '', public string $slug = '', public string $price = '9999.00')
    {
        WpStub::$products[$id] = $this;
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_name(): string
    {
        return $this->name;
    }

    public function get_sku(): string
    {
        return $this->sku;
    }

    public function get_slug(): string
    {
        return $this->slug;
    }

    public function get_price(): string
    {
        return $this->price;
    }
}

function wc_get_product($id)
{
    return WpStub::$products[(int) $id] ?? false;
}
