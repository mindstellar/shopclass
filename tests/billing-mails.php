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
 * The mails of a goods order (placed, paid, shipped, delivered, cancelled, refunded). A paid order sends one confirmation with every line and the
 * address, never a second on a replay; the placed mail goes only for gateways that wait for
 * the money; the shipped mail carries the tracking link and only an https one; the admin
 * hears once per order; titles from a seller are escaped; a failed send is queued and tried
 * again; a credits order keeps its old receipt and sends no admin mail.
 * Usage: php tests/billing-mails.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\event\OrderPlaced;
use mindstellar\billing\GoodsCheckout;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PaymentGateway;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Receipts;
use mindstellar\boot\CoreServices;
use PHPMailer\PHPMailer\PHPMailer;

$admin = api_admin_boot('osc_billing_mails');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Goods', null, $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
$tom = seed_user($admin, 'tom', 'tom@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD', 'contactEmail' => 'owner@shop.test'] as $k => $v) {
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

    public function send()
    {
        if (self::$fail) {
            throw new \PHPMailer\PHPMailer\Exception('SMTP Error: could not connect');
        }
        $to = $this->getToAddresses();
        self::$sent[] = ['to' => (string) ($to[0][0] ?? ''), 'subject' => $this->Subject, 'body' => $this->Body];

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

harness_section('a paid goods order');
$online = $make('fake', 'Tee <b>& "Co"</b>');
Billing::markPaid($online, 'ext-1');
$sent = $mails();
pin('the buyer gets one confirmation and the owner one new-order mail', ['tom@example.test', 'owner@shop.test'], array_column($sent, 'to'));
$confirm = $sent[0];
pin('the subject names the order and the site', 'Your order #' . $online->getId() . ' from ' . osc_page_title(), $confirm['subject']);
foreach (['2', 'M', 'TEE-M', 'Standard delivery', '1 Main St', 'Austin', 'TX'] as $word) {
    check("the confirmation shows $word", str_contains($confirm['body'], $word));
}
check('a seller title is escaped', str_contains($confirm['body'], 'Tee &lt;b&gt;&amp; &quot;Co&quot;&lt;/b&gt;') && !str_contains($confirm['body'], '<b>&'));
check('it links the receipt', str_contains($confirm['body'], Receipts::url($online->getId())));
pin('a replayed callback sends nothing', false, Billing::markPaid($online, 'ext-1'));
Receipts::send($online->getId());
Receipts::notify(Receipts::MAIL_ADMIN, $online->getId());
pin('nor does sending again', [], $mails());
$row = OrderStore::find($online->getId());
check('each mail leaves a mark', $row->meta('_receipt_sent') !== null && $row->meta('_mail_admin') !== null);
check('the owner mail links the admin order', str_contains($sent[1]['body'], 'page=billing') && str_contains($sent[1]['body'], 'id=' . $online->getId()));

harness_section('the receipt page data');
$vars = Receipts::vars($row);
pin('lines, address and delivery are on the receipt', [3, ['Tom', '5550100', '1 Main St', 'Austin', 'TX', '73301', 'US'], 'Standard delivery'], [count($vars['details']['lines']) + 1, $vars['details']['address'], $vars['details']['shipping']]);
check('the page prints them', (static function () use ($row): bool {
    ob_start();
    Receipts::render($row, 'https://example.test/', 'Back');

    return str_contains((string) ob_get_clean(), '1 Main St');
})());

harness_section('placed mail');
$held = $make('offline');
CoreServices::dispatcher()->dispatch(new OrderPlaced($held->getId(), $tom));
$sent = $mails();
pin('a bank-transfer order tells the buyer and the owner', ['tom@example.test', 'owner@shop.test'], array_column($sent, 'to'));
check('the placed mail lists the lines', str_contains($sent[0]['body'], 'TEE-M') && str_contains($sent[0]['body'], '1 Main St'));
CoreServices::dispatcher()->dispatch(new OrderPlaced($held->getId(), $tom));
pin('firing it again sends nothing', [], $mails());
Billing::markPaid(OrderStore::find($held->getId()), null);
$sent = $mails();
pin('paying it later sends the confirmation, not a second owner mail', ['tom@example.test'], array_column($sent, 'to'));

$cod = $make('cod');
CoreServices::dispatcher()->dispatch(new OrderPlaced($cod->getId(), $tom));
pin('cash on delivery is held too', 2, count($mails()));

$checkout = new GoodsCheckout();
$tee   = seed_item($admin, $cat, $sue, 'Tee', 699.0);
$teeV  = \mindstellar\listing\VariantStore::saveDefault($tee, ['i_stock' => null]);
$checkout->place($tom, [['variant' => $teeV, 'qty' => 1]], $address, 'standard', 'fake');
pin('an online gateway sends nothing at placement', [], $mails());

harness_section('shipped mail');
$ship = $make('fake');
Billing::markPaid($ship, 'ext-2');
$mails();
OrderStore::ship($ship->getId(), 'DHL', 'JD0123', 'https://track.example.test/JD0123');
$sent = $mails();
pin('the buyer is told it shipped', ['tom@example.test'], array_column($sent, 'to'));
check('with the carrier, number and link', str_contains($sent[0]['body'], 'DHL') && str_contains($sent[0]['body'], 'JD0123') && str_contains($sent[0]['body'], 'href="https://track.example.test/JD0123"'));
check('and the address', str_contains($sent[0]['body'], '1 Main St'));
OrderStore::deliver($ship->getId());
$sent = $mails();
pin('delivering tells the buyer once', ['tom@example.test'], array_column($sent, 'to'));

$ship = OrderStore::find($ship->getId());
check('the delivered mail names the order and offers a rating link', str_contains($sent[0]['subject'], 'was delivered') && str_contains($sent[0]['subject'], Receipts::number($ship)) && str_contains($sent[0]['body'], 'Rate it'));
check('it is marked sent and a replay sends nothing', $ship->meta('_mail_delivered') !== null && Receipts::sendNotice(Receipts::MAIL_DELIVERED, $ship->getId()) && $mails() === []);

$bad = $make('fake');
Billing::markPaid($bad, 'ext-3');
$mails();
OrderStore::ship($bad->getId(), 'Post', 'X1', 'javascript:alert(1)');
$sent = $mails();
check('a link that is not https is left out of the mail', count($sent) === 1 && !str_contains($sent[0]['body'], 'javascript') && !str_contains($sent[0]['body'], 'href="j') && str_contains($sent[0]['body'], 'X1'));
pin('and the page data says so', '', Receipts::details(OrderStore::find($bad->getId()))['tracking_url']);
pin('only full https addresses pass', ['', '', '', 'https://a.test/x?y=1'], [Receipts::httpsUrl('http://a.test'), Receipts::httpsUrl('https://a.test/ "x"'), Receipts::httpsUrl('//a.test'), Receipts::httpsUrl(' https://a.test/x?y=1 ')]);

harness_section('cancelled and refunded mails');
$cancel = $make('offline');
OrderStore::cancel($cancel->getId());
$sent = $mails();
pin('cancelling tells the buyer, not the owner', ['tom@example.test'], array_column($sent, 'to'));
check('with the order number and the lines', str_contains($sent[0]['subject'], 'was cancelled') && str_contains($sent[0]['subject'], Receipts::number($cancel)) && str_contains($sent[0]['body'], 'TEE-M'));
Receipts::afterCancelled($cancel->getId(), $tom);
pin('a second hook call sends nothing', [], $mails());
$gone = $make('fake', 'Mug');
Billing::markPaid($gone, 'ext-7');
$mails();
Billing::refund(OrderStore::find($gone->getId()));
$sent = $mails();
pin('refunding tells the buyer', ['tom@example.test'], array_column($sent, 'to'));
check('with the amount, the method and the wait', str_contains($sent[0]['subject'], 'was refunded') && str_contains($sent[0]['body'], '1447.00 USD') && str_contains($sent[0]['body'], 'Fake') && str_contains($sent[0]['body'], '3-5 working days'));
Receipts::afterRefunded($gone->getId(), $tom, 0);
pin('a second refund hook sends nothing', [], $mails());
$unpaid = $make('fake');
check('a mail for the wrong status is nothing to send', Receipts::sendNotice(Receipts::MAIL_DELIVERED, $unpaid->getId()) && Receipts::sendNotice(Receipts::MAIL_CANCELLED, $unpaid->getId()) && Receipts::sendNotice(Receipts::MAIL_REFUNDED, $unpaid->getId()) && $mails() === []);

harness_section('a failed send is queued and tried again');
$retry = $make('fake');
MailsRecorder::$fail = true;
Billing::markPaid($retry, 'ext-4');
pin('both the owner mail and the receipt are queued', [1, 1], [$queued(), (int) $admin->query("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = 'billing.receipt'")->fetch_row()[0]]);
check('nothing is marked sent', OrderStore::find($retry->getId())->meta('_mail_admin') === null && OrderStore::find($retry->getId())->meta('_receipt_sent') === null);
MailsRecorder::$fail = false;
pin('the retry sends it', true, Receipts::sendNotice(Receipts::MAIL_ADMIN, $retry->getId()));
pin('to the owner', ['owner@shop.test'], array_column($mails(), 'to'));
pin('and once only', [true, []], [Receipts::sendNotice(Receipts::MAIL_ADMIN, $retry->getId()), $mails()]);
$late = $make('fake');
Billing::markPaid($late, 'ext-5');
MailsRecorder::$fail = true;
OrderStore::ship($late->getId(), 'DHL', '1', '');
$first = Receipts::sendNotice(Receipts::MAIL_SHIPPED, $late->getId());
MailsRecorder::$fail = false;
$gone2 = $make('fake');
Billing::markPaid($gone2, 'ext-8');
$mails();
MailsRecorder::$fail = true;
Billing::refund(OrderStore::find($gone2->getId()));
Receipts::afterRefunded($gone2->getId(), 0, 0);
MailsRecorder::$fail = false;
pin('a failed refund mail is queued once, even when the hook runs again', 1, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_unique = 'order:" . $gone2->getId() . ":refunded'")->fetch_row()[0]);
pin('and the retry sends it', true, Receipts::sendNotice(Receipts::MAIL_REFUNDED, $gone2->getId()));
$mails();
pin('a failed shipped mail is reported, not thrown, and goes on the retry', [false, 1], [$first, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = 'billing.order_mail' AND s_unique = 'order:" . $late->getId() . ":shipped'")->fetch_row()[0]]);
pin('the retry sends it', true, Receipts::sendNotice(Receipts::MAIL_SHIPPED, $late->getId()));
$mails();

harness_section('nothing to send');
pin('an unknown order is nothing to send', true, Receipts::sendNotice(Receipts::MAIL_PLACED, 999999));
pin('so is an unknown kind', true, Receipts::sendNotice('nope', $online->getId()));
pin('a shipped mail for an order that did not ship', true, Receipts::sendNotice(Receipts::MAIL_SHIPPED, $make('fake')->getId()));
pin('sent nothing', [], $mails());

harness_section('a credits order');
$credits = OrderStore::create($tom, 'fake', $m(10), 'USD', 100);
Billing::markPaid($credits, 'ext-6');
$sent = $mails();
pin('keeps the old receipt and tells the owner nothing', ['tom@example.test'], array_column($sent, 'to'));
pin('with the old subject', 'Your receipt from ' . osc_page_title() . ', order #' . $credits->getId(), $sent[0]['subject']);
foreach ([Receipts::MAIL_DELIVERED => Order::STATUS_DELIVERED, Receipts::MAIL_CANCELLED => Order::STATUS_CANCELLED, Receipts::MAIL_REFUNDED => Order::STATUS_REFUNDED] as $kind => $status) {
    $admin->query("UPDATE {$p}t_billing_order SET s_status = '$status' WHERE pk_i_id = {$credits->getId()}");
    pin("a credits order in $status gets no $kind mail, and no mark", [true, [], null], [Receipts::sendNotice($kind, $credits->getId()), $mails(), OrderStore::find($credits->getId())->meta('_mail_' . $kind)]);
}

harness_section('the hooks');
$names = [];
foreach (['hook_email_order_placed', 'hook_email_order_shipped', 'hook_email_order_delivered', 'hook_email_order_cancelled', 'hook_email_order_refunded', 'hook_email_admin_new_order'] as $hook) {
    $names[$hook] = Plugins::hasHook($hook);
}
pin('core listens on the six mail hooks', [true, true, true, true, true, true], array_values($names));

exit(harness_result());
