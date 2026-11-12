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
 * The admin side of invoices and part refunds: the Documents panel and Partly refunded chip on
 * the order page, the Refund part form (record and through the gateway), the Invoices list with
 * its filters and paging, the admin document page, and the CSV. Each post checks the token and
 * refuses what it should.
 * Usage: php tests/admin-billing-documents.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\Document;
use mindstellar\billing\DocumentExport;
use mindstellar\billing\DocumentStore;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\OrderStock;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PartialRefundableGateway;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Receipts;
use mindstellar\listing\VariantStore;

$admin = api_admin_boot('osc_admin_billing_documents');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
$cat = seed_category($admin, 'Goods', null, $locale);
$tom = seed_user($admin, 'tom', 'tom@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD', 'site_mode' => 'shop', 'billing_receipt_email' => '0'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}
foreach (['_e' => 'echo $key;', '_n' => 'return $key;'] as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '($key, $plural = "", $n = 0) { ' . $body . ' }');
    }
}
if (!function_exists('osc_admin_base_url')) {
    function osc_admin_base_url($index = false)
    {
        return 'https://example.test/oc-admin/' . ($index ? 'index.php' : '');
    }
}
if (!function_exists('osc_current_admin_locale')) {
    function osc_current_admin_locale()
    {
        return 'en_US';
    }
}
if (!function_exists('osc_current_admin_theme_path')) {
    // The header draws the title row the screen declared, so its buttons are on the page.
    function osc_current_admin_theme_path($file = '')
    {
        if ($file === 'parts/header.php') {
            $declared = osc_admin_page_declared();
            osc_admin_page_header('', array(
                'heading'      => $declared['heading'] ?? null,
                'buttons'      => $declared['buttons'] ?? array(),
                'buttons_html' => $declared['buttons_html'] ?? null,
            ));
        }
    }
}
foreach (array('error', 'ok', 'warning', 'info') as $kind) {
    if (!function_exists('osc_add_flash_' . $kind . '_message')) {
        eval('function osc_add_flash_' . $kind . '_message($msg, $section = "pubMessages") {'
             . '$GLOBALS["flashes"][] = "' . $kind . ':" . $msg; }');
    }
}
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hValidate.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';

/** The base controller, stubbed: redirects are recorded, and the view is drawn into the output. */
class AdminSecBaseModel
{
    protected $action;

    protected $page;

    public function __construct()
    {
        $this->action = Params::getParam('action');
        $this->page   = Params::getParam('page');
    }

    public function doModel()
    {
    }

    public function redirectTo($url, $code = null)
    {
        $GLOBALS['redirects'][] = $url;
    }

    public function _exportVariableToView($key, $value)
    {
        View::getInstance()->_exportVariableToView($key, $value);
    }

    public function doView($view)
    {
        include ABS_PATH . 'oc-admin/themes/modern/' . $view;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminBilling.php';

/** The real controller, with the download captured instead of sent. */
final class TestBilling extends CAdminBilling
{
    public static string $csv = '';

    public static string $name = '';

    protected function sendCsv(string $csv, string $name): void
    {
        self::$csv  = $csv;
        self::$name = $name;
    }
}

Receipts::$mailer = static fn (array $params): bool => true;
osc_add_filter('init_send_mail', static fn ($mail) => new class (true) extends PHPMailer\PHPMailer\PHPMailer {
    public function send()
    {
        return true;
    }
});

/** A gateway that refunds parts. */
final class AdminPartGateway implements PartialRefundableGateway
{
    /** @var list<array{int,string}> */
    public array $calls = [];

    public function getId(): string
    {
        return 'adminpart';
    }

    public function getName(): string
    {
        return 'Admin part pay';
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
        return CheckoutIntent::redirect('https://example.test/pay');
    }

    public function handleCallback(array $request): CallbackResult
    {
        return CallbackResult::ignored();
    }

    public function refund(Order $order): CallbackResult
    {
        return CallbackResult::refunded($order->getId(), 'full');
    }

    public function refundPart(Order $order, int $amount, string $key): CallbackResult
    {
        $this->calls[] = [$amount, $key];

        return CallbackResult::refunded($order->getId(), 're_' . count($this->calls));
    }
}
$gw = new AdminPartGateway();
PaymentGatewayRegistry::getInstance()->register($gw);

/** One request to the screen. */
function drive(string $action, array $fields = array(), array $query = array(), bool $token = true): array
{
    $csrf  = new \mindstellar\security\Csrf();
    $_GET  = $query + array('page' => 'billing', 'action' => $action);
    $_POST = $fields + ($token ? array('CSRFName' => $csrf->getCsrfTokenName(), 'CSRFToken' => $csrf->getCsrfTokenValue()) : array());
    Params::init();
    $GLOBALS['flashes']   = array();
    $GLOBALS['redirects'] = array();

    ob_start();
    (new TestBilling())->doModel();

    return array(
        'flashes'   => $GLOBALS['flashes'],
        'redirects' => $GLOBALS['redirects'],
        'drawn'     => (string) ob_get_clean(),
    );
}

$m     = static fn (float $units): int => (int) round($units * 1000000);
$tee   = seed_item($admin, $cat, $tom, 'Tee', 10.0);
$mug   = seed_item($admin, $cat, $tom, 'Mug', 5.0);
$vTee  = VariantStore::saveDefault($tee, ['i_stock' => 10]);
$vMug  = VariantStore::saveDefault($mug, ['i_stock' => 10]);
$stock = static fn (int $variant): int => (int) $admin->query("SELECT i_stock FROM {$p}t_item_variant WHERE pk_i_id = $variant")->fetch_row()[0];
$notes = static fn (int $id): array => array_values(array_filter(DocumentStore::forOrder($id), static fn (Document $d): bool => $d->isCreditNote()));
$_SERVER['REQUEST_METHOD'] = 'POST';
$admin->query("UPDATE {$p}t_user SET s_name = '=Tom' WHERE pk_i_id = " . (int) $tom);
scratchdb_forget_cache();

/** Three tees at 10.00, a mug at 5.00, delivery 5.00 and tax 4.00 on top: 44.00, paid, stock taken. */
$order = static function (string $gateway = 'offline') use ($tom, $m, $tee, $mug, $vTee, $vMug): Order {
    $o = OrderStore::create($tom, $gateway, $m(44), 'USD', 0, ['_kind' => Order::KIND_GOODS, '_label' => 'Tee × 3, Mug', '_buyer' => ['email' => 'tom@example.test', 'name' => '=Tom', 'phone' => ''], '_address' => ['name' => 'Tom', 'line1' => '1 Main St', 'city' => 'Austin', 'country' => 'US']]);
    OrderLineStore::add($o->getId(), [
        ['kind' => 'item', 'item_id' => $tee, 'variant_id' => $vTee, 'title' => 'Tee', 'qty' => 3, 'unit' => $m(10), 'amount' => $m(30)],
        ['kind' => 'item', 'item_id' => $mug, 'variant_id' => $vMug, 'title' => 'Mug', 'qty' => 1, 'unit' => $m(5), 'amount' => $m(5)],
        ['kind' => 'shipping', 'title' => 'Standard delivery', 'qty' => 1, 'unit' => $m(5), 'amount' => $m(5)],
        ['kind' => 'tax', 'title' => 'VAT 10%', 'qty' => 1, 'unit' => $m(4), 'amount' => $m(4), 'meta' => ['id' => 'vat']],
    ]);
    OrderStock::take($o->getId());
    Billing::markPaid($o, 'ref-' . $o->getId());

    return OrderStore::find($o->getId());
};
$lineOf = static function (Order $o, string $title): int {
    foreach (OrderLineStore::itemLines($o->getId()) as $line) {
        if ($line['title'] === $title) {
            return $line['id'];
        }
    }

    return 0;
};
$status = static fn (int $id): string => OrderStore::find($id)->getStatus();

harness_section('the Documents panel');
$a    = $order();
$aid  = $a->getId();
$teeA = $lineOf($a, 'Tee');
$mugA = $lineOf($a, 'Mug');
$page = drive('order', [], ['id' => $aid]);
$inv  = DocumentStore::invoiceOf($aid);
check('the invoice is listed with its number and total', $inv !== null && str_contains($page['drawn'], 'Documents') && str_contains($page['drawn'], $inv->number) && str_contains($page['drawn'], '44.00'));
check('and links to the printable page', $inv !== null && str_contains($page['drawn'], 'rel="noopener"'));
check('no chip yet, and Refund part is offered', !str_contains($page['drawn'], 'Partly refunded') && str_contains($page['drawn'], 'value="order_refund_part"'));
check('an offline order is recorded only: no gateway box', !str_contains($page['drawn'], 'name="via"') && str_contains($page['drawn'], 'Nothing is sent to the payment provider'));
check('each open line has a units box, stock box is on', str_contains($page['drawn'], 'name="qty[' . $teeA . ']"') && str_contains($page['drawn'], 'name="qty[' . $mugA . ']"') && preg_match('/name="restock"[^>]*checked/', $page['drawn']) === 1);
$unpaid = OrderStore::create($tom, 'offline', $m(8), 'USD', 0, ['_kind' => Order::KIND_GOODS]);
check('an unpaid order shows neither', !str_contains(drive('order', [], ['id' => $unpaid->getId()])['drawn'], 'Refund part'));
$old = OrderStore::create($tom, 'offline', $m(8), 'USD', 0, ['_kind' => Order::KIND_GOODS]);
OrderStore::settle($old->getId(), Order::STATUS_PAID, 'old-ref');
check('an old order without an invoice has no Refund part', !str_contains(drive('order', [], ['id' => $old->getId()])['drawn'], 'order_refund_part'));

harness_section('Refund part');
$before = $stock($vTee);
$page   = drive('order_refund_part', ['id' => $aid, 'qty' => [$teeA => '1'], 'reason' => 'Too small', 'restock' => '1']);
$n      = $notes($aid);
pin('one unit is refunded with its tax share, back in stock', [1, $m(11), $before + 1, $page['redirects'] !== []], [count($n), $n[0]->total ?? null, $stock($vTee), true]);
pin('the reason is on the note', 'Too small', $n[0]->data['refund']['reason'] ?? null);
pin('a success message', true, str_contains(implode(' ', $page['flashes']), 'ok:Part of order #' . $aid . ' is refunded.'));
$page = drive('order', [], ['id' => $aid]);
check('the order page now has the chip, the note and two documents', str_contains($page['drawn'], 'Partly refunded') && str_contains($page['drawn'], $n[0]->number) && str_contains($page['drawn'], '−11.00') && str_contains($page['drawn'], 'Credit note'));
pin('the order stays paid', Order::STATUS_PAID, $status($aid));

$page = drive('order_refund_part', ['id' => $aid, 'amount' => '2.50', 'reason' => 'Goodwill']);
pin('an amount alone is recorded', [2, $m(13.5)], [count($notes($aid)), DocumentStore::refundedTotal($aid)]);
$page = drive('order_refund_part', ['id' => $aid, 'amount' => '999']);
pin('too much is refused with the amount left', [2, true], [count($notes($aid)), str_contains(implode(' ', $page['flashes']), 'At most ')]);
$page = drive('order_refund_part', ['id' => $aid, 'amount' => 'abc']);
pin('a word is not an amount', [2, true], [count($notes($aid)), str_contains(implode(' ', $page['flashes']), 'Enter the amount as a number')]);
$page = drive('order_refund_part', ['id' => $aid]);
pin('nothing chosen is refused', [2, true], [count($notes($aid)), str_contains(implode(' ', $page['flashes']), 'Enter an amount or choose')]);
$page = drive('order_refund_part', ['id' => $aid, 'qty' => [$teeA => '5']]);
pin('more units than are left', [2, true], [count($notes($aid)), str_contains(implode(' ', $page['flashes']), 'error:')]);
$mugAt = $stock($vMug);
$page  = drive('order_refund_part', ['id' => $aid, 'qty' => [$mugA => '1'], 'restock' => '0']);
pin('a kept unit is not restocked', [3, $mugAt], [count($notes($aid)), $stock($vMug)]);

harness_section('through the gateway');
$b    = $order('adminpart');
$bid  = $b->getId();
$teeB = $lineOf($b, 'Tee');
$page = drive('order', [], ['id' => $bid]);
check('the gateway box shows its name, on by default', str_contains($page['drawn'], 'name="via"') && str_contains($page['drawn'], 'Admin part pay'));
drive('order_refund_part', ['id' => $bid, 'amount' => '5', 'via' => '1']);
pin('the gateway got the amount and its key', [[$m(5), 'order:' . $bid . ':refund:1']], $gw->calls);
drive('order_refund_part', ['id' => $bid, 'amount' => '3']);
pin('without the box it only records', [1, 2], [count($gw->calls), count($notes($bid))]);

harness_section('closing the order');
$admin->query("INSERT INTO {$p}t_admin (s_name, s_username, s_password, s_email) VALUES ('Refund Rita', 'rita', 'x', 'rita@example.test')");
$rita = (int) $admin->insert_id;
\mindstellar\billing\OrderHistory::actAs(\mindstellar\billing\OrderHistory::BY_ADMIN, $rita);
$rest = $m(44) - DocumentStore::refundedTotal($bid);
$page = drive('order_refund_part', ['id' => $bid, 'amount' => (string) ($rest / 1000000)]);
\mindstellar\billing\OrderHistory::actAs(null);
pin('the notes reaching the paid amount refund the order', 'refunded', $status($bid));
pin('the message says the order is refunded', true, str_contains(implode(' ', $page['flashes']), 'ok:Order #' . $bid . ' is refunded.'));
$page = drive('order', [], ['id' => $bid]);
check('and the chip and the form are gone', !str_contains($page['drawn'], 'Partly refunded') && !str_contains($page['drawn'], 'order_refund_part'));
$last = $notes($bid)[count($notes($bid)) - 1] ?? null;
check('the History row of the last refund names the admin', $last !== null && preg_match('#' . preg_quote($last->number, '#') . '\)</td>\s*<td[^>]*>Refund Rita<#', $page['drawn']) === 1);

harness_section('the token and the method');
$c = $order();
$source = harness_method_source(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminBilling.php', 'orderRefundPartPost');
$csrf   = strpos($source, 'osc_csrf_check()');
$write  = strpos($source, 'Billing::refundPart');
check('scan: orderRefundPartPost checks the token before it refunds', $csrf !== false && $write !== false && $csrf < $write);
check('scan: it refuses anything but POST', str_contains($source, "REQUEST_METHOD') !== 'POST'"));

harness_section('the Invoices list');
$page = drive('documents');
check('lists invoices and credit notes with their orders', str_contains($page['drawn'], $inv->number) && str_contains($page['drawn'], $n[0]->number) && str_contains($page['drawn'], 'id=' . $aid . '"'));
check('has the tabs and the CSV button', str_contains($page['drawn'], 'osc-tabnav') && str_contains($page['drawn'], 'action=documents_csv'));
$page = drive('documents', [], ['type' => 'credit_note']);
check('a type filter hides invoices', str_contains($page['drawn'], $n[0]->number) && !str_contains($page['drawn'], $inv->number));
$page = drive('documents', [], ['from' => '2999-01-01']);
check('a date range with nothing says so', str_contains($page['drawn'], 'No documents match this filter'));
$page = drive('documents', [], ['from' => 'x; DROP', 'type' => 'bogus']);
check('a bad date or type is ignored', str_contains($page['drawn'], $inv->number));
$total = DocumentStore::count([]);
$page  = drive('documents', [], ['iPage' => '2']);
check('the pager only shows past one page', $total <= 25 ? !str_contains($page['drawn'], 'pagination') : true);
check('the orders list has the Invoices tab', str_contains(drive('index')['drawn'], 'action=documents'));
Preference::getInstance()->set('billing_invoice_on', '0');
scratchdb_forget_cache();
osc_reset_preferences();
check('with invoices off but some issued, the tab stays', str_contains(drive('index')['drawn'], 'action=documents'));
Preference::getInstance()->set('billing_invoice_on', '1');
scratchdb_forget_cache();
osc_reset_preferences();

harness_section('the admin document page');
check('the order page and the list link to the admin page', str_contains(drive('order', [], ['id' => $aid])['drawn'], 'action=document&amp;id=' . $inv->id . '"') && str_contains(drive('documents')['drawn'], 'action=document&amp;id=' . $n[0]->id . '"'));
$page = drive('document', [], ['id' => $inv->id]);
check('an admin opens any invoice, with a link back to its order', str_contains($page['drawn'], $inv->number) && str_contains($page['drawn'], 'action=order&amp;id=' . $aid) && str_contains($page['drawn'], 'Back to the order'));
$page = drive('document', [], ['id' => $n[0]->id]);
check('and a credit note, negated', str_contains($page['drawn'], $n[0]->number) && str_contains($page['drawn'], "\u{2212}"));
$page = drive('document', [], ['id' => 999999]);
check('an unknown one goes back to the list', $page['redirects'] !== [] && str_contains(implode(' ', $page['flashes']), 'error:'));

harness_section('the admin order with line tax');
$plain = drive('order', [], ['id' => $aid])['drawn'];
check('without line tax there is no Tax column and the refund help is unchanged', !str_contains($plain, '>Tax</th>') && !str_contains($plain, 'their own tax'));
$tx = OrderStore::create($tom, 'offline', $m(11), 'USD', 0, ['_kind' => Order::KIND_GOODS, '_buyer' => ['email' => 'tom@example.test', 'name' => 'Tom', 'phone' => ''], '_fields' => ['tax_id' => ['label' => 'Tax ID', 'value' => 'DE1<b>', 'invoice' => true]]]);
OrderLineStore::add($tx->getId(), [['kind' => 'item', 'title' => 'Cap', 'qty' => 1, 'unit' => $m(10), 'amount' => $m(10), 'meta' => ['tax' => ['included' => false, 'base' => $m(10), 'amount' => $m(1), 'code' => '6505', 'parts' => [['tax' => 'tax.vat.10', 'label' => 'VAT', 'bp' => 1000, 'amount' => $m(1)]]]]]]);
Billing::markPaid($tx, 'ref-tx');
$taxed = drive('order', [], ['id' => $tx->getId()])['drawn'];
check('with line tax the column, code, buyer field and refund help show', str_contains($taxed, '>Tax</th>') && str_contains($taxed, '10% · ') && str_contains($taxed, 'Tax code 6505') && str_contains($taxed, 'their own tax') && str_contains($taxed, 'Tax ID: DE1&lt;b&gt;') && !str_contains($taxed, 'DE1<b>'));

harness_section('the CSV');
$csv = DocumentExport::csv([]);
$rows = array_map('str_getcsv', array_filter(explode("\n", $csv)));
pin('the header', ['number', 'date', 'order', 'buyer', 'net', 'tax', 'total', 'currency', 'buyer_details'], $rows[0]);
$byNumber = [];
foreach (array_slice($rows, 1) as $r) {
    $byNumber[$r[0]] = $r;
}
pin('an invoice row', [(string) $aid, '44.00', 'USD'], [$byNumber[$inv->number][2] ?? null, $byNumber[$inv->number][6] ?? null, $byNumber[$inv->number][7] ?? null]);
pin('an invoice splits net and tax', ['40.00', '4.00'], [$byNumber[$inv->number][4] ?? null, $byNumber[$inv->number][5] ?? null]);
$txInv = DocumentStore::invoiceOf($tx->getId());
pin('the buyer tax number is in the last column', 'Tax ID: DE1<b>', $txInv === null ? null : ($byNumber[$txInv->number][8] ?? null));
pin('a credit note is negative', '-11.00', $byNumber[$n[0]->number][6] ?? null);
$cn = $byNumber[$n[0]->number] ?? array();
pin('its tax is negative and net plus tax is the total', [true, true], [(float) ($cn[5] ?? 0) < 0, abs((float) ($cn[4] ?? 0) + (float) ($cn[5] ?? 0) - (float) ($cn[6] ?? 0)) < 0.005]);
check('the buyer name and email are there, a formula is quoted', str_starts_with($byNumber[$inv->number][3] ?? '', "'=Tom") && str_contains($byNumber[$inv->number][3] ?? '', 'tom@example.test'));
pin('the type filter narrows the file', true, count(array_filter(array_slice(array_map('str_getcsv', array_filter(explode("\n", DocumentExport::csv(['type' => 'credit_note'])))), 1), static fn (array $r): bool => str_starts_with($r[0], 'CN-'))) === count(array_slice(array_filter(explode("\n", DocumentExport::csv(['type' => 'credit_note']))), 1)));
pin('a date range with nothing gives the header only', 1, count(array_filter(explode("\n", DocumentExport::csv(['from' => '2999-01-01'])))));
drive('documents_csv', [], ['type' => 'invoice']);
check('the action sends a dated csv file of the filtered list', str_starts_with(TestBilling::$name, 'invoices-') && str_ends_with(TestBilling::$name, '.csv') && str_contains(TestBilling::$csv, $inv->number) && !str_contains(TestBilling::$csv, 'CN-'));

exit(harness_result());
