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
 * On 1,200 listings: a price sort keeps its order (listings with no price last, equal prices by
 * id, no listing on two pages) and reads idx_price instead of sorting every listing; a rare option
 * or custom-field value and `on_sale` with few listings on sale read a few rows, not every listing.
 * A stock change shows in `in_stock` results at once.
 *
 * Usage: php tests/search-rows-read.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\database\Db;
use mindstellar\search\FacetIndex;
use mindstellar\search\query\FacetFilter;

const LISTINGS = 1200;

$admin = api_admin_boot('osc_search_rows_read');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Goods', null, $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'rewriteEnabled' => '0'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}

// Prices repeat every 300 listings, so most prices are shared; every 40th listing has none.
$rows = [];
for ($i = 1; $i <= LISTINGS; $i++) {
    $price  = $i % 40 === 0 ? 'NULL' : ($i % 300 + 1) * 1000000;
    $rows[] = "($i, $sue, $cat, NOW() - INTERVAL $i MINUTE, NOW() - INTERVAL $i MINUTE, NOW(), $price, 'USD', 'Sue', 'sue@example.test', 1, 1, 0, 'x')";
}
$admin->query("INSERT INTO {$p}t_item (pk_i_id, fk_i_user_id, fk_i_category_id, dt_pub_date, dt_first_pub_date, dt_mod_date, i_price, fk_c_currency_code, s_contact_name, s_contact_email, b_enabled, b_active, b_spam, s_secret) VALUES " . implode(',', $rows));
$admin->query("INSERT INTO {$p}t_item_description (fk_i_item_id, fk_c_locale_code, s_title, s_description) SELECT pk_i_id, 'en_US', CONCAT('Item ', pk_i_id), '' FROM {$p}t_item");
$admin->query("INSERT INTO {$p}t_item_location (fk_i_item_id, fk_c_country_code, s_country) SELECT pk_i_id, 'US', 'United States' FROM {$p}t_item");
$admin->query("INSERT INTO {$p}t_item_stats (fk_i_item_id, dt_date) SELECT pk_i_id, CURDATE() FROM {$p}t_item");
// One Black variant per listing; three are on sale. 601 adds a Teal variant not on sale, 900 one on sale.
$admin->query("INSERT INTO {$p}t_item_variant (pk_i_id, fk_i_item_id, i_price, i_compare_price, i_stock) SELECT pk_i_id, pk_i_id, NULL, IF(pk_i_id IN (5, 601, 1100), 999000000, NULL), 5 FROM {$p}t_item");
$admin->query("INSERT INTO {$p}t_item_variant (pk_i_id, fk_i_item_id, i_price, i_compare_price, i_stock) VALUES (1201, 601, NULL, NULL, 5), (1202, 900, NULL, 999000000, 5)");
// Listing 7's one variant is Teal too. Every listing has Brand Acme on field 1; two have Zeta.
$admin->query("INSERT INTO {$p}t_facet_term (pk_i_id, s_key, s_value) VALUES (1, 'oColour', 'Black'), (2, 'oColour', 'Teal'), (3, 'f1', 'Acme'), (4, 'f1', 'Zeta')");
$admin->query("INSERT INTO {$p}t_item_facet (fk_i_term_id, d_number, fk_i_item_id, fk_i_variant_id) SELECT 1, 0, pk_i_id, pk_i_id FROM {$p}t_item");
$admin->query("INSERT INTO {$p}t_item_facet (fk_i_term_id, d_number, fk_i_item_id, fk_i_variant_id) VALUES (2, 0, 7, 7), (2, 0, 601, 1201), (2, 0, 900, 1202)");
$admin->query("INSERT INTO {$p}t_item_facet (fk_i_term_id, d_number, fk_i_item_id, fk_i_variant_id) SELECT 3, 0, pk_i_id, 0 FROM {$p}t_item");
$admin->query("INSERT INTO {$p}t_item_facet (fk_i_term_id, d_number, fk_i_item_id, fk_i_variant_id) VALUES (4, 0, 11, 0), (4, 0, 1150, 0)");
$admin->query("ANALYZE TABLE {$p}t_item, {$p}t_item_variant, {$p}t_item_facet");
FacetIndex::markReady();
scratchdb_forget_cache();
osc_reset_preferences();
$_COOKIE['oc_userLocale'] = 'en_US';

// Index and table rows the app's connection read, and rows it sorted, while $run ran.
$work = static function (callable $run): array {
    $status = static fn (): array => array_column(Db::select("SHOW SESSION STATUS WHERE Variable_name LIKE 'Handler_read%' OR Variable_name = 'Sort_rows'"), 'Value', 'Variable_name');
    scratchdb_forget_cache();
    $before = $status();
    $result = $run();
    $after  = $status();
    $read   = 0;
    foreach ($after as $name => $value) {
        if ($name !== 'Sort_rows') {
            $read += (int) $value - (int) ($before[$name] ?? 0);
        }
    }

    return [$result, $read, (int) $after['Sort_rows'] - (int) $before['Sort_rows']];
};

harness_section('price sort');
$prices = [];
foreach ($admin->query("SELECT pk_i_id, i_price FROM {$p}t_item") as $row) {
    $prices[(int) $row['pk_i_id']] = $row['i_price'] === null ? null : (int) $row['i_price'];
}
$page = static function (string $direction, int $page, int $size): array {
    $s = new Search();
    $s->order('i_price', $direction);
    $s->page($page, $size);

    return array_map('intval', array_column($s->doSearch(false, false), 'pk_i_id'));
};
foreach (['ASC', 'DESC'] as $direction) {
    $want = array_keys($prices);
    usort($want, static function (int $a, int $b) use ($prices, $direction): int {
        if (($prices[$a] === null) !== ($prices[$b] === null)) {
            return $prices[$a] === null ? 1 : -1;
        }
        $by = $prices[$a] <=> $prices[$b] ?: $a <=> $b;

        return $direction === 'ASC' ? $by : -$by;
    });
    $got = [];
    for ($i = 0; $i * 70 < LISTINGS; $i++) {
        $got = array_merge($got, $page($direction, $i, 70));
    }
    pin("price $direction, 70 a page: every listing once, priced by price then id, no price last", $want, $got);
    [$ids, $read, $sorted] = $work(static fn (): array => $page($direction, 0, 10));
    pin("price $direction, first page", array_slice($want, 0, 10), $ids);
    check("price $direction, first page reads the price index: no sort, about one row a listing", $sorted === 0 && $read <= 20, "read $read, sorted $sorted");
}
$s = new Search();
$s->order('i_price', 'ASC');
$s->page(0, 10);
$s->addCondition($p . 't_item.pk_i_id > ?', [1190]);
pin('a page with nine priced listings ends with the one with no price', [1191, 1192, 1193, 1194, 1195, 1196, 1197, 1198, 1199, 1200], array_map('intval', array_column($s->doSearch(false, false), 'pk_i_id')));
$s = new Search();
$s->order('i_price', 'ASC');
$s->fromPrimaryKeys([1200, 1199, 1198], false);
pin('both reads of a split page keep the one-time conditions', [1198, 1199, 1200], array_map('intval', array_column($s->doSearch(false, false), 'pk_i_id')));

harness_section('rare option and field values');
$key  = (new \mindstellar\apikey\ApiKeys(new \mindstellar\model\ApiCredential(), new \mindstellar\apikey\Scopes(), new \mindstellar\utility\SystemClock()))
    ->create('key', 'sue', ['listings:read'], \mindstellar\apikey\KeyOwner::user($sue))->token();
$call = api_admin_caller();
$list = static fn (array $query): array => array_map('intval', array_column($call('GET', 'listings', null, $key, [], $query)->body()['data'] ?? [], 'id'));
// The first call reads the key and the settings; after it an API page reads about 40 rows of its own.
$list([]);
[$ids, $read] = $work(static fn (): array => $list(['option' => ['Colour' => ['Teal']]]));
pin('Teal: its three listings, newest first', [7, 601, 900], $ids);
check('Teal reads a few rows, not every listing', $read <= 100, "read $read");
[$ids, $read] = $work(static fn (): array => $list(['option' => ['Colour' => ['Teal', 'Black']], 'on_sale' => 'true']));
pin('Teal or Black and on sale: the four on sale', [5, 601, 900, 1100], $ids);
check('Teal or Black and on sale reads a few rows', $read <= 100, "read $read");
pin('Teal and on sale: only 900, whose Teal variant is on sale', [900], $list(['option' => ['Colour' => ['Teal']], 'on_sale' => 'true']));
pin('Black: newest first', [1, 2, 3], array_slice($list(['option' => ['Colour' => ['Black']]]), 0, 3));

harness_section('on_sale');
[$ids, $read] = $work(static fn (): array => $list(['on_sale' => 'true']));
pin('the four on sale, newest first', [5, 601, 900, 1100], $ids);
check('on_sale reads the sale-price index, not every listing', $read <= 100, "read $read");
[$ids, $read] = $work(static fn (): array => $list(['on_sale' => 'true', 'in_stock' => 'true']));
pin('on sale and in stock', [5, 601, 900, 1100], $ids);
check('on sale and in stock reads a few rows', $read <= 100, "read $read");

harness_section('stock changes');
$teal = ['option' => ['Colour' => ['Teal']], 'in_stock' => 'true'];
pin('Teal in stock', [7, 601, 900], $list($teal));
\mindstellar\listing\VariantStore::adjustStock(7, -5);
pin('a sold-out listing leaves Teal in stock at once', [601, 900], $list($teal));

harness_section('few-match id lists');
pin('field terms on more than 500 listings give no id list', null, FacetFilter::fieldIds([3]));
pin('field terms on a few listings give their ids', [11, 1150], FacetFilter::fieldIds([4]));
pin('an option with a few listings gives their ids', [7, 601, 900], FacetFilter::optionIds(['oColour' => [2]], false, false));
pin('a name with no term gives no listing', [[], FacetFilter::NO_MATCH], [FacetFilter::optionIds(['oColour' => [2], 'oSize' => []], true, false), FacetFilter::listingIn([])]);

exit(harness_result());
