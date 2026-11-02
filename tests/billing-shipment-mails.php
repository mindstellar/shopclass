<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Mails for parcels. A parcel that leaves items open sends one "part of your order" mail with
 * its own lines and tracking; a retry or replay sends nothing more; the parcel that finishes
 * the order sends the normal shipped mail, once.
 * Usage: php tests/billing-shipment-mails.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PaymentGateway;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Receipts;
use mindstellar\boot\CoreServices;
use PHPMailer\PHPMailer\PHPMailer;

$admin = api_admin_boot('osc_billing_shipment_mails');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Goods', null, $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
$tom = seed_user($admin, 'tom', 'tom@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD', 'contactEmail' => 'owner@shop.test', 'billing_offline_enabled' => '1', 'billing_offline_instructions' => "Bank: First Bank\nAccount 12345"] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
require_once ABS_PATH . 'oc-includes/osclass/emails.php';

foreach (['osc_get_domain' => 'shop.test', 'osc_mailserver_name_from' => '', 'osc_mailserver_mail_from' => 'noreply@shop.test', 'osc_themes_path' => ABS_PATH . 'oc-content/themes/', 'osc_theme' => ''] as $fn => $value) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '() { return ' . var_export($value, true) . '; }');
    }
}

if (!function_exists('osc_item_url_ns')) {
    function osc_item_url_ns($id, $locale = '')
    {
        return 'https://shop.test/item/' . (int) $id;
    }
}

if (!function_exists('osc_admin_base_url')) {
    function osc_admin_base_url($withIndex = false)
    {
        return 'https://shop.test/oc-admin/' . ($withIndex ? 'index.php' : '');
    }
}

/** A mailer that keeps what it would have sent, and fails on demand. */
final class MailsRecorder extends PHPMailer
{
    /** @var array<int,array<string,string>> */
    public static array $sent = [];
    public static bool $fail  = false;
    /** Runs once while the next mail is being sent, as a second sender would. */
    public static ?Closure $during = null;

    public function send()
    {
        if (self::$during !== null) {
            $during       = self::$during;
            self::$during = null;
            $during();
        }
        if (self::$fail) {
            throw new \PHPMailer\PHPMailer\Exception('SMTP Error: could not connect');
        }
        $to = $this->getToAddresses();
        self::$sent[] = ['to' => (string) ($to[0][0] ?? ''), 'subject' => $this->Subject, 'body' => $this->Body, 'text' => $this->AltBody];

        return true;
    }
}
osc_add_filter('init_send_mail', static fn ($mail) => new MailsRecorder(true));

final class MailsGateway implements PaymentGateway
{
    public function getId(): string
    {
        return 'fake';
    }

    public function getName(): string
    {
        return 'Fake';
    }

    public function getSupportedCurrencies(): array
    {
        return ['USD'];
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createCheckout(Order $order): CheckoutIntent
    {
        return CheckoutIntent::redirect('https://pay.example.test/' . $order->getId());
    }

    public function handleCallback(array $request): CallbackResult
    {
        return CallbackResult::ignored('none');
    }
}
PaymentGatewayRegistry::getInstance()->register(new MailsGateway());

$m = static fn (float $units): int => (int) round($units * 1000000);
$address = ['name' => 'Tom', 'phone' => '5550100', 'line1' => '1 Main St', 'line2' => '', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '73301', 'country' => 'US'];

/** A goods order written straight to the tables, so the gateway can be any id. */
$make = static function (string $gateway, string $title = 'Tee') use ($m, $tom, $address): Order {
    $order = OrderStore::create($tom, $gateway, $m(1398 + 49), 'USD', 0, [
        '_kind'     => Order::KIND_GOODS,
        '_label'    => $title . ' × 2',
        '_address'  => $address,
        '_shipping' => ['method' => 'standard', 'label' => 'Standard delivery'],
        '_buyer'    => ['email' => 'tom@example.test', 'name' => 'Tom', 'phone' => '5550100'],
    ]);
    OrderLineStore::add($order->getId(), [
        ['kind' => 'item', 'item_id' => 1, 'variant_id' => 1, 'title' => $title, 'options' => ['Size' => 'M'], 'sku' => 'TEE-M', 'qty' => 2, 'unit' => $m(699), 'amount' => $m(1398)],
        ['kind' => 'shipping', 'title' => 'Standard delivery', 'amount' => $m(49)],
    ]);

    return $order;
};
$mails = static function (): array {
    $out = MailsRecorder::$sent;
    MailsRecorder::$sent = [];

    return $out;
};
$queued = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = 'billing.order_mail'")->fetch_row()[0];

use mindstellar\billing\event\OrderShipped;
use mindstellar\billing\event\ShipmentAdded;

/** An order of 2 Tees and 1 Mug, paid, with no mail kept from the paying. */
$paid = static function () use ($make, $mails, $p, $admin): Order {
    $order = $make('fake');
    Billing::markPaid($order, 'ext-' . $order->getId());
    OrderLineStore::add($order->getId(), [
        ['kind' => 'item', 'item_id' => 2, 'variant_id' => 2, 'title' => 'Mug', 'qty' => 1, 'unit' => 5000000, 'amount' => 5000000],
    ]);
    $mails();

    return OrderStore::find($order->getId());
};
$itemLine = static function (int $orderId, string $title): int {
    foreach (OrderLineStore::ofOrder($orderId) as $line) {
        if ($line['title'] === $title) {
            return $line['id'];
        }
    }

    return 0;
};
/** Write a parcel the way the shipments code does, then fire its event. */
$parcel = static function (int $orderId, int $n, array $lines, string $tracking) use ($admin, $p): void {
    $row  = $admin->query("SELECT s_meta FROM {$p}t_billing_order WHERE pk_i_id = $orderId")->fetch_row();
    $meta = json_decode((string) $row[0], true) ?: [];
    $meta['_shipments'][] = ['n' => $n, 'at' => date('Y-m-d H:i:s'), 'by' => 'admin', 'id' => 1, 'lines' => $lines, 'carrier' => 'DHL', 'tracking' => $tracking, 'url' => 'https://track.example.test/' . $tracking];
    $json = $admin->real_escape_string(json_encode($meta));
    $admin->query("UPDATE {$p}t_billing_order SET s_meta = '$json' WHERE pk_i_id = $orderId");
};

harness_section('a parcel that leaves items open');
$order = $paid();
$tee   = $itemLine($order->getId(), 'Tee');
$mug   = $itemLine($order->getId(), 'Mug');
$parcel($order->getId(), 1, [['line' => $tee, 'qty' => 1]], 'P1');
CoreServices::dispatcher()->dispatch(new ShipmentAdded($order->getId(), $tom, 1));
$sent = $mails();
pin('the buyer gets one part mail', ['tom@example.test'], array_column($sent, 'to'));
check('with the part subject', str_contains($sent[0]['subject'], 'Part of your order') && str_contains($sent[0]['subject'], Receipts::number($order)));
check('its lines and tracking', str_contains($sent[0]['body'], 'Tee') && !str_contains($sent[0]['body'], 'Mug') && str_contains($sent[0]['body'], 'P1') && str_contains($sent[0]['body'], 'href="https://track.example.test/P1"'));
check('the parcel is marked mailed', !empty(OrderStore::find($order->getId())->shipments()[0]['mailed']));
CoreServices::dispatcher()->dispatch(new ShipmentAdded($order->getId(), $tom, 1));
Receipts::afterShipmentAdded($order->getId(), $tom, 1);
pin('a replay sends nothing', [], $mails());

harness_section('the next parcel has its own mail');
$parcel($order->getId(), 2, [['line' => $tee, 'qty' => 1]], 'P2');
CoreServices::dispatcher()->dispatch(new ShipmentAdded($order->getId(), $tom, 2));
$sent = $mails();
check('one more part mail, with its own tracking', count($sent) === 1 && str_contains($sent[0]['body'], 'P2') && !str_contains($sent[0]['body'], 'P1'));

harness_section('the last parcel sends the shipped mail only');
$parcel($order->getId(), 3, [['line' => $mug, 'qty' => 1]], 'P3');
CoreServices::dispatcher()->dispatch(new ShipmentAdded($order->getId(), $tom, 3));
pin('no part mail for the finishing parcel', [], $mails());
$admin->query("UPDATE {$p}t_billing_order SET s_status = 'shipped' WHERE pk_i_id = " . $order->getId());
CoreServices::dispatcher()->dispatch(new OrderShipped($order->getId(), $tom));
CoreServices::dispatcher()->dispatch(new OrderShipped($order->getId(), $tom));
$sent = $mails();
check('the shipped mail goes once', count($sent) === 1 && str_contains($sent[0]['subject'], 'has shipped') && !str_contains($sent[0]['subject'], 'Part of'));

harness_section('a failed send is retried once');
$order = $paid();
$tee   = $itemLine($order->getId(), 'Tee');
$parcel($order->getId(), 1, [['line' => $tee, 'qty' => 2]], 'R1');
MailsRecorder::$fail = true;
CoreServices::dispatcher()->dispatch(new ShipmentAdded($order->getId(), $tom, 1));
Receipts::afterShipmentAdded($order->getId(), $tom, 1);
MailsRecorder::$fail = false;
pin('queued once under the parcel key, not marked', [1, true], [
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_unique = 'order:" . $order->getId() . ":shipment:1'")->fetch_row()[0],
    empty(OrderStore::find($order->getId())->shipments()[0]['mailed']),
]);
pin('the retry sends it', true, Receipts::sendPartShipped($order->getId(), 1));
pin('to the buyer', ['tom@example.test'], array_column($mails(), 'to'));
pin('and once only', [true, []], [Receipts::sendPartShipped($order->getId(), 1), $mails()]);
pin('an unknown parcel or order is nothing to send', [true, true], [Receipts::sendPartShipped($order->getId(), 9), Receipts::sendPartShipped(999999, 1)]);

harness_section('two senders of one parcel');
$order = $paid();
$tee   = $itemLine($order->getId(), 'Tee');
$parcel($order->getId(), 1, [['line' => $tee, 'qty' => 1]], 'S1');
$inner = null;
MailsRecorder::$during = static function () use ($order, &$inner): void {
    $inner = Receipts::sendPartShipped($order->getId(), 1);
};
pin('the first sender mails', true, Receipts::sendPartShipped($order->getId(), 1));
pin('the second finds the parcel taken and mails nothing', [true, ['tom@example.test']], [$inner, array_column($mails(), 'to')]);
pin('marking it again changes nothing', false, OrderStore::markShipmentMailed($order->getId(), 1));

exit(harness_result());
