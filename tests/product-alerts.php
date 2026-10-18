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
 * Back-in-stock and price-drop alerts: POST account/alerts with `product`, what is refused, the
 * dedupe, the Alert answer, and the job the variant stock and price hooks queue: it mails once
 * and stops the alert, and leaves saved searches alone. Also the account text and the e-mail.
 * Usage: php tests/product-alerts.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\listing\ProductAlerts;
use mindstellar\listing\VariantStore;
use mindstellar\search\AlertEnvelope;

$admin = api_admin_boot('osc_product_alerts');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Tees', seed_category($admin, 'Goods', null, $locale), $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
$tom = seed_user($admin, 'tom', 'tom@example.test');
$ann = seed_user($admin, 'ann', 'ann@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'rewriteEnabled' => '0', 'throttle_alert_subscribe' => '0', 'mailserver_mail_from' => 'shop@example.test', 'mailserver_name_from' => 'Shop'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

$micros = static fn (int $units): int => $units * 1000000;
$tee    = seed_item($admin, $cat, $sue, 'Tee', 699.0);
$mug    = seed_item($admin, $cat, $sue, 'Mug', 9.0);
$plain  = seed_item($admin, $cat, $sue, 'Not for sale', 9.0);
$hidden = seed_item($admin, $cat, $sue, 'Hidden', 9.0, 0);
$row    = static fn (string $size, int $stock, int $price): array => ['options' => ['Size' => $size], 'fields' => ['i_stock' => $stock, 'i_price' => $price]];
[$s, $m, $l] = VariantStore::saveSet($tee, [$row('S', 0, $micros(699)), $row('M', 3, $micros(699)), $row('L', 0, $micros(799))]);
[$mugRow]    = VariantStore::saveSet($mug, [$row('One', 0, $micros(9))]);
VariantStore::saveDefault($hidden, ['i_stock' => 0]);
$other = (int) VariantStore::defaultRow($hidden)['pk_i_id'];

$keys  = new \mindstellar\apikey\ApiKeys(new \mindstellar\model\ApiCredential(), new \mindstellar\apikey\Scopes(), new \mindstellar\utility\SystemClock());
$key   = static fn (int $user): string => $keys->create('key', 'k' . $user, ['alerts:write'], \mindstellar\apikey\KeyOwner::user($user))->token();
$sueK  = $key($sue);
$tomK  = $key($tom);
$annK  = $key($ann);
$call  = api_admin_caller();
$post  = static fn (string $token, array $body): \mindstellar\api\Response => $call('POST', 'account/alerts', $body, $token);
$alert = static fn (int $id): array => $admin->query("SELECT * FROM {$p}t_alerts WHERE pk_i_id = $id")->fetch_assoc() ?: [];

harness_section('subscribing');
$r = $post($sueK, ['product' => ['listing' => $tee, 'variant' => $s, 'kind' => 'stock']]);
pin('a stock alert on a variant is saved', 201, $r->status());
$stockId = (int) ($r->body()['data']['id'] ?? 0);
pin('it answers its product, no filters', [['listing' => $tee, 'variant' => $s, 'kind' => 'stock', 'below' => null], [], 'custom', true], [
    $r->body()['data']['product'] ?? null, $r->body()['data']['filters'] ?? null, $r->body()['data']['type'] ?? null, $r->body()['data']['active'] ?? null,
]);
pin('the answer matches the schema', [], api_admin_schema_errors('AlertDocument', $r));
pin('it is stored as a CUSTOM row with the product envelope', ['CUSTOM', AlertEnvelope::product($tee, $s, 'stock')], [$alert($stockId)['e_type'] ?? '', $alert($stockId)['s_search'] ?? '']);
$again = $post($sueK, ['product' => ['listing' => $tee, 'variant' => $s, 'kind' => 'stock']]);
pin('the same alert again is answered as it is', [200, $stockId], [$again->status(), $again->body()['data']['id'] ?? null]);

$r       = $post($sueK, ['product' => ['listing' => $tee, 'kind' => 'price']]);
$priceId = (int) ($r->body()['data']['id'] ?? 0);
pin('a price alert on any variant goes under the lowest price today', [201, null, '699.00'], [$r->status(), array_key_exists('variant', $r->body()['data']['product'] ?? []) ? $r->body()['data']['product']['variant'] : 'x', $r->body()['data']['product']['below'] ?? null]);
$r = $post($tomK, ['product' => ['listing' => $tee, 'variant' => $l, 'kind' => 'price']]);
pin('on one variant, under that variant\'s price', [201, '799.00'], [$r->status(), $r->body()['data']['product']['below'] ?? null]);
$tomPriceId = (int) ($r->body()['data']['id'] ?? 0);
$admin->query("UPDATE {$p}t_item_variant SET i_price = " . $micros(790) . " WHERE pk_i_id = $l");
$r = $post($tomK, ['product' => ['listing' => $tee, 'variant' => $l, 'kind' => 'price']]);
pin('the same price alert again takes the new price', [200, $tomPriceId, '790.00'], [$r->status(), $r->body()['data']['id'] ?? null, $r->body()['data']['product']['below'] ?? null]);
$admin->query("UPDATE {$p}t_item_variant SET i_price = " . $micros(799) . " WHERE pk_i_id = $l");
$post($tomK, ['product' => ['listing' => $tee, 'variant' => $l, 'kind' => 'price']]);

foreach ([
    'an unknown kind'               => [['product' => ['listing' => $tee, 'kind' => 'soon']], '422 validation_failed', '/product/kind'],
    'a hidden listing'              => [['product' => ['listing' => $hidden, 'kind' => 'stock']], '404 not_found', ''],
    'a listing that does not exist' => [['product' => ['listing' => 999999, 'kind' => 'stock']], '404 not_found', ''],
    'a listing not for sale'        => [['product' => ['listing' => $plain, 'kind' => 'stock']], '422 validation_failed', '/product/listing'],
    'another listing\'s variant'    => [['product' => ['listing' => $tee, 'variant' => $mugRow, 'kind' => 'stock']], '422 validation_failed', '/product/variant'],
    'a missing listing'             => [['product' => ['kind' => 'stock']], '422 validation_failed', '/product/listing'],
    'filters and product together'  => [['product' => ['listing' => $tee, 'kind' => 'stock'], 'filters' => ['q' => 'tee']], '422 validation_failed', '/'],
    'neither'                       => [[], '422 validation_failed', '/'],
] as $label => [$body, $code, $pointer]) {
    $r = $post($annK, $body);
    pin($label . ' is refused', [$code, $pointer], [api_admin_code($r), $pointer === '' ? '' : ($r->body()['errors'][0]['pointer'] ?? '')]);
}
pin('nothing refused was saved', 0, (int) $admin->query("SELECT COUNT(*) c FROM {$p}t_alerts WHERE fk_i_user_id = $ann")->fetch_assoc()['c']);
pin('a signed-out caller cannot save one', 401, $call('POST', 'account/alerts', ['product' => ['listing' => $tee, 'kind' => 'stock']])->status());

$search   = $post($sueK, ['filters' => ['q' => 'tee']]);
$searchId = (int) ($search->body()['data']['id'] ?? 0);
pin('a saved search still saves, with a null product', [201, null, 'daily'], [$search->status(), array_key_exists('product', $search->body()['data'] ?? []) ? $search->body()['data']['product'] : 'x', $search->body()['data']['type'] ?? null]);
$list = $call('GET', 'account/alerts', null, $sueK);
pin('the list holds both kinds and matches the schema', [3, []], [count($list->body()['data'] ?? []), api_admin_schema_errors('AlertList', $list)]);

harness_section('what the account page and admin show');
pin('a stock alert reads "Back in stock: <title>"', 'Back in stock: Tee', (static function () use ($alert, $stockId): string {
    View::getInstance()->_exportVariableToView('alerts', [$alert($stockId)]);
    View::getInstance()->_reset('alerts');
    osc_has_alerts();

    return osc_alert_summary();
})());
$parts = osc_alert_criteria($alert($priceId));
pin('a price alert reads "Price drop" and names its price', ['Price drop', 'Tee', true], [$parts['product']['label'] ?? '', $parts['product']['value'] ?? '', isset($parts['below'])]);
pin('a variant is named by its options', 'S', osc_alert_criteria($alert($stockId))['variant']['value'] ?? null);
check('a product alert never replays as a search', \mindstellar\search\AlertReplay::search($alert($stockId)) === null);

harness_section('the hooks queue a job that mails once');
$wiring = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/functions.php');
check('functions.php wires both hooks', str_contains($wiring, "osc_add_hook('variant_stock_changed', array(\\mindstellar\\listing\\ProductAlerts::class, 'stockChanged'));")
    && str_contains($wiring, "osc_add_hook('variant_price_changed', array(\\mindstellar\\listing\\ProductAlerts::class, 'priceChanged'));"));
osc_add_hook('variant_stock_changed', [ProductAlerts::class, 'stockChanged']);
osc_add_hook('variant_price_changed', [ProductAlerts::class, 'priceChanged']);
$mails = [];
$capture = static function (\mindstellar\mail\event\MailComposing $e) use (&$mails): void {
    $alert   = (array) $e->alert();
    $mails[] = [(int) $alert['pk_i_id'], (int) ($e->item()['pk_i_id'] ?? 0), str_contains($e->subject(), 'Price') ? 'price' : 'stock', $e->words()['{ITEM_PRICE}'] ?? ''];
    $e->suppress();
};
osc_add_filter('email_product_alert_title', $capture);
$jobs = static fn (): int => (int) $admin->query("SELECT COUNT(*) c FROM {$p}t_job_queue WHERE s_type = '" . ProductAlerts::JOB . "'")->fetch_assoc()['c'];
$live = static fn (int $id): bool => ($a = $alert($id)) !== [] && $a['dt_unsub_date'] === null && (int) $a['b_active'] === 1;

VariantStore::saveSet($tee, [$row('S', 0, $micros(699)), $row('M', 5, $micros(699)), $row('L', 0, $micros(799))]);
pin('stock 3 to 5 queues nothing', 0, $jobs());
VariantStore::saveSet($tee, [$row('S', 3, $micros(699)), $row('M', 5, $micros(699)), $row('L', 0, $micros(799))]);
pin('stock 0 to 3 queues one job', 1, $jobs());
osc_job_run(5);
pin('which mails the alert once', [[$stockId, $tee, 'stock', '']], $mails);
pin('and stops it', false, $live($stockId));
pin('the saved search is untouched', true, $live($searchId));
pin('the price alerts are untouched', [true, true], [$live($priceId), $live($tomPriceId)]);
$mails = [];
VariantStore::adjustStock($s, -3);
VariantStore::adjustStock($s, 2);
osc_job_run(5);
pin('a second restock sends nothing: the alert was one-shot', [], $mails);

$r     = $post($annK, ['product' => ['listing' => $tee, 'variant' => $l, 'kind' => 'stock']]);
$annId = (int) ($r->body()['data']['id'] ?? 0);
VariantStore::saveSet($tee, [$row('S', 2, $micros(699)), $row('M', 5, $micros(699)), $row('L', 0, $micros(799))]);
$mails = [];
VariantStore::adjustStock($s, -2);
VariantStore::adjustStock($s, 1);
osc_job_run(5);
pin('another variant\'s restock does not send an alert on L', [[], true], [$mails, $live($annId)]);

harness_section('price drops');
$mails = [];
VariantStore::saveSet($tee, [$row('S', 1, $micros(499)), $row('M', 5, $micros(699)), $row('L', 0, $micros(799))]);
osc_job_run(5);
pin('699 to 499 on S mails the any-variant alert under 699', [[$priceId, $tee, 'price', osc_format_price($micros(499))]], $mails);
pin('not the one on L under 799, which did not change', true, $live($tomPriceId));
$cheap = $post($tomK, ['product' => ['listing' => $tee, 'kind' => 'price']]);
pin('a new any-variant alert now goes under 499', '499.00', $cheap->body()['data']['product']['below'] ?? null);
$cheapId = (int) ($cheap->body()['data']['id'] ?? 0);
$admin->query("UPDATE {$p}t_alerts SET s_search = '" . $admin->real_escape_string(AlertEnvelope::product($tee, null, 'price', $micros(399))) . "' WHERE pk_i_id = $cheapId");
$mails = [];
VariantStore::saveSet($tee, [$row('S', 1, $micros(449)), $row('M', 5, $micros(699)), $row('L', 0, $micros(799))]);
osc_job_run(5);
pin('449 does not mail an alert under 399', [[], true], [$mails, $live($cheapId)]);
VariantStore::saveSet($tee, [$row('S', 1, $micros(449)), $row('M', 5, $micros(699)), $row('L', 0, $micros(749))]);
osc_job_run(5);
pin('799 to 749 on L mails the alert on L', [[$tomPriceId, $tee, 'price', osc_format_price($micros(749))]], $mails);
$mails = [];
VariantStore::saveSet($tee, [$row('S', 1, $micros(449)), $row('M', 5, $micros(699)), $row('L', 0, $micros(999))]);
osc_job_run(5);
pin('a rise sends nothing', [], $mails);
pin('the saved search is still untouched', true, $live($searchId));

harness_section('a variant with no price of its own');
$cap = seed_item($admin, $cat, $sue, 'Cap', 20.0);
VariantStore::saveDefault($cap, ['i_stock' => 3]);
$r        = $post($annK, ['product' => ['listing' => $cap, 'kind' => 'price']]);
$capAlert = (int) ($r->body()['data']['id'] ?? 0);
pin('a price alert on it goes under the listing\'s price', '20.00', $r->body()['data']['product']['below'] ?? null);
if (!function_exists('osc_content_path')) {
    // A listing edit reads the upload staging folder; this test sends no photos.
    function osc_content_path()
    {
        return sys_get_temp_dir() . '/';
    }
}
$owner = $keys->create('key', 'owner', ['listings:write'], \mindstellar\apikey\KeyOwner::user($sue))->token();
$mails = [];
pin('the edit is saved', 200, $call('PATCH', 'listings/' . $cap, ['price' => '25'], $owner)->status());
osc_job_run(5);
pin('a rise of the listing\'s price sends nothing', [[], true], [$mails, $live($capAlert)]);
$call('PATCH', 'listings/' . $cap, ['price' => '15'], $owner);
osc_job_run(5);
pin('a drop of the listing\'s price mails it', [[$capAlert, $cap, 'price', osc_format_price($micros(15))]], $mails);

harness_section('a listing that is hidden sends nothing');
$admin->query("UPDATE {$p}t_item SET b_enabled = 0 WHERE pk_i_id = $tee");
$mails = [];
VariantStore::saveSet($tee, [$row('S', 1, $micros(449)), $row('M', 5, $micros(699)), $row('L', 4, $micros(999))]);
osc_job_run(5);
pin('the alert on L waits', [[], true], [$mails, $live($annId)]);
$admin->query("UPDATE {$p}t_item SET b_enabled = 1 WHERE pk_i_id = $tee");

harness_section('batches');
$admin->query("DELETE FROM {$p}t_alerts WHERE e_type = 'CUSTOM'");
$values = [];
$json   = $admin->real_escape_string(AlertEnvelope::product($mug, null, 'stock'));
for ($i = 0; $i < 30; $i++) {
    $values[] = "($ann, 'ann@example.test', '$json', 's', 1, 'CUSTOM', NOW())";
}
$admin->query("INSERT INTO {$p}t_alerts (fk_i_user_id, s_email, s_search, s_secret, b_active, e_type, dt_date) VALUES " . implode(', ', $values));
$mails = [];
VariantStore::adjustStock($mugRow, 4);
$runs = osc_job_run(10);
pin('a batch is 25 alerts', 25, ProductAlerts::BATCH);
pin('30 waiting alerts take two job runs', 2, $runs);
pin('more than one batch of alerts are all mailed, each once', [30, 30], [count($mails), count(array_unique(array_column($mails, 0)))]);
pin('and all are stopped', 0, (int) $admin->query("SELECT COUNT(*) c FROM {$p}t_alerts WHERE e_type = 'CUSTOM' AND dt_unsub_date IS NULL")->fetch_assoc()['c']);

harness_section('the e-mail');
osc_remove_hook('email_product_alert_title', $capture);
$sent = [];
$item = Item::getInstance()->findByPrimaryKey($tee);
\mindstellar\utility\DeferredMail::during(static function () use ($sue, $tom, $item, $micros): void {
    \mindstellar\mail\CoreEmails::productAlert(['fk_i_user_id' => $sue, 's_email' => 'old@example.test'], $item, 'stock');
    \mindstellar\mail\CoreEmails::productAlert(['fk_i_user_id' => $tom, 's_email' => 'tom@example.test'], $item, 'price', $micros(449));
}, static function (array $mail) use (&$sent): void {
    $sent[] = $mail;
});
pin('a stock mail goes to the account\'s address and names the listing', ['sue@example.test', 'Back in stock: Tee'], [$sent[0]['to'] ?? '', $sent[0]['subject'] ?? '']);
check('it links the listing', str_contains($sent[0]['body'] ?? '', '<a href="'));
pin('a price mail fills in the new price', ['Price drop: Tee', true, false], [$sent[1]['subject'] ?? '', str_contains($sent[1]['body'] ?? '', 'now sells for'), str_contains($sent[1]['body'] ?? '', '{ITEM_PRICE}')]);

exit(harness_result());
