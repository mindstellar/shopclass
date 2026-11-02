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
 * Document mails: the receipt names and links the invoice; a credit note that leaves money open
 * sends one "part refunded" mail with its link; the note that closes the order sends none, and the
 * refunded mail links it; a retry or replay sends nothing more.
 * Usage: php tests/billing-document-mails.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\DocumentStore;
use mindstellar\billing\Invoices;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PaymentGateway;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Receipts;
use PHPMailer\PHPMailer\PHPMailer;

$admin = api_admin_boot('osc_billing_document_mails');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Goods', null, $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
$tom = seed_user($admin, 'tom', 'tom@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD', 'contactEmail' => 'owner@shop.test', 'billing_receipt_email' => '1', 'billing_offline_enabled' => '1', 'billing_offline_instructions' => "Bank: First Bank\nAccount 12345"] as $k => $v) {
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
final class DocMailRecorder extends PHPMailer
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
osc_add_filter('init_send_mail', static fn ($mail) => new DocMailRecorder(true));

final class DocMailGateway implements PaymentGateway
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
PaymentGatewayRegistry::getInstance()->register(new DocMailGateway());

$m = static fn (float $units): int => (int) round($units * 1000000);
$address = ['name' => 'Tom', 'phone' => '5550100', 'line1' => '1 Main St', 'line2' => '', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '73301', 'country' => 'US'];

$make = static function () use ($m, $tom, $address): Order {
    $order = OrderStore::create($tom, 'fake', $m(1398 + 49), 'USD', 0, [
        '_kind'     => Order::KIND_GOODS,
        '_label'    => 'Tee × 2',
        '_address'  => $address,
        '_shipping' => ['method' => 'standard', 'label' => 'Standard delivery'],
        '_buyer'    => ['email' => 'tom@example.test', 'name' => 'Tom', 'phone' => '5550100'],
    ]);
    OrderLineStore::add($order->getId(), [
        ['kind' => 'item', 'item_id' => 1, 'variant_id' => 1, 'title' => 'Tee', 'options' => [], 'sku' => 'TEE-M', 'qty' => 2, 'unit' => $m(699), 'amount' => $m(1398)],
        ['kind' => 'shipping', 'title' => 'Standard delivery', 'amount' => $m(49)],
    ]);

    return $order;
};
$mails = static function (): array {
    $out = DocMailRecorder::$sent;
    DocMailRecorder::$sent = [];

    return $out;
};
$ofType = static fn (array $sent, string $needle): array => array_values(array_filter($sent, static fn (array $s): bool => str_contains($s['subject'], $needle)));

harness_section('the receipt links the invoice');
$order = $make();
Billing::markPaid($order, 'ext-1');
$invoice = DocumentStore::invoiceOf($order->getId());
$sent    = $mails();
$receipt = $ofType($sent, 'Your order');
check('an invoice was issued', $invoice !== null);
check('the receipt names its number', count($receipt) === 1 && str_contains($receipt[0]['body'], $invoice->number));
check('and links it', str_contains($receipt[0]['body'], 'href="' . Invoices::url($invoice) . '"'));

harness_section('a part refund sends one mail');
$tee = 0;
foreach (OrderLineStore::ofOrder($order->getId()) as $line) {
    if ($line['kind'] === 'item') {
        $tee = $line['id'];
    }
}
Billing::refundPart(OrderStore::find($order->getId()), 0, [$tee => 1], 'Broken', false, false);
$note = array_values(array_filter(DocumentStore::forOrder($order->getId()), static fn ($d) => $d->isCreditNote()));
$sent = $mails();
check('one note', count($note) === 1);
check('one part refund mail to the buyer', count($sent) === 1 && $sent[0]['to'] === 'tom@example.test' && str_contains($sent[0]['subject'], 'Part of your order'));
check('with the note number and link', str_contains($sent[0]['body'], $note[0]->number) && str_contains($sent[0]['body'], 'href="' . Invoices::url($note[0]) . '"') && str_contains($sent[0]['body'], 'We refunded'));
$orderId = $order->getId();
Receipts::afterDocumentIssued($note[0]->id, $orderId, 'credit_note');
pin('a replay sends nothing', [], $mails());
pin('the document is marked', true, OrderStore::find($orderId)->meta('_mail_doc:' . $note[0]->id) !== null);

harness_section('a failed send is retried once');
DocMailRecorder::$fail = true;
Billing::refundPart(OrderStore::find($orderId), $m(1), [], 'Goodwill', false, false);
DocMailRecorder::$fail = false;
$second = array_values(array_filter(DocumentStore::forOrder($orderId), static fn ($d) => $d->isCreditNote()))[1];
pin('queued under the document key', 1, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_unique = 'order:{$orderId}:doc:{$second->id}'")->fetch_row()[0]);
$mails();
pin('the retry sends it', true, Receipts::sendPartRefunded($second->id));
pin('once', 1, count($mails()));
pin('and no more', [true, []], [Receipts::sendPartRefunded($second->id), $mails()]);

harness_section('the closing note sends the refunded mail with its link');
Billing::refund(OrderStore::find($orderId));
$notes = array_values(array_filter(DocumentStore::forOrder($orderId), static fn ($d) => $d->isCreditNote()));
$sent  = $mails();
$last  = end($notes);
pin('three notes', 3, count($notes));
pin('no part refund mail for it', [], $ofType($sent, 'Part of'));
$ref = $ofType($sent, 'refunded');
check('the refunded mail goes once and links the last note', count($ref) === 1 && str_contains($ref[0]['body'], 'href="' . Invoices::url($last) . '"') && str_contains($ref[0]['body'], $last->number));

harness_section('a closing part refund sends no part mail');
$order2 = $make();
Billing::markPaid($order2, 'ext-2');
$mails();
Billing::refundPart(OrderStore::find($order2->getId()), $m(1447), [], 'All of it', false, false);
$sent = $mails();
pin('no part mail', [], $ofType($sent, 'Part of'));

exit(harness_result());
