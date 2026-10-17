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
 * GET /listings?page=N: page 1 is the cursor's first page, page 2 equals the cursor's second
 * page, any sort pages, meta.page and meta.pages with count=true, links.next carries page=N+1,
 * a page past the cap is 422 page_too_deep, the last page inside the cap has no next and says
 * truncated, and page with cursor or page 0 is 422.
 * Usage: php tests/api-listings-page.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

$admin = api_admin_boot('osc_api_listings_page');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Mugs', seed_category($admin, 'Goods', null, $locale), $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
$ids = [];
for ($i = 0; $i < 12; $i++) {
    $id = seed_item($admin, $cat, $sue, 'Mug ' . $i, 5.0 + $i);
    $admin->query("UPDATE {$p}t_item SET dt_pub_date = DATE_SUB('2026-10-01 12:00:00', INTERVAL $i MINUTE) WHERE pk_i_id = $id");
    $ids[] = $id;
}
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'rewriteEnabled' => '0',
    'defaultResultsPerPage@search' => '5', 'maxResultsPerPage@search' => '50', 'defaultOrderField@search' => 'dt_pub_date', 'defaultOrderType@search' => '1'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_COOKIE['oc_userLocale'] = 'en_US';

$key  = (new \mindstellar\apikey\ApiKeys(new \mindstellar\model\ApiCredential(), new \mindstellar\apikey\Scopes(), new \mindstellar\utility\SystemClock()))
    ->create('key', 'sue', ['listings:read'], \mindstellar\apikey\KeyOwner::user($sue))->token();
$call = api_admin_caller();
$list = static fn (array $query): \mindstellar\api\Response => $call('GET', 'listings', null, $key, [], $query);
$of   = static fn (\mindstellar\api\Response $r): array => array_column($r->body()['data'] ?? [], 'id');

harness_section('page and cursor agree');
$first = $list(['limit' => 5, 'count' => 'true']);
pin('page 1 is the first page', $of($first), $of($list(['limit' => 5, 'page' => 1])));
$cursor = [];
parse_str((string) parse_url((string) $first->body()['links']['next'], PHP_URL_QUERY), $cursor);
pin('page 2 equals the cursor\'s second page', $of($list(['limit' => 5, 'cursor' => $cursor['cursor']])), $of($list(['limit' => 5, 'page' => 2])));
pin('page 3 holds the last two', 2, count($of($list(['limit' => 5, 'page' => 3]))));
pin('past the last page is empty', [], $of($list(['limit' => 5, 'page' => 4])));

harness_section('meta and links');
$r    = $list(['limit' => 5, 'page' => 2, 'count' => 'true']);
$meta = $r->body()['meta'];
pin('meta carries total, page and pages', [12, 2, 3], [$meta['total'], $meta['page'], $meta['pages']]);
$next = [];
parse_str((string) parse_url((string) $r->body()['links']['next'], PHP_URL_QUERY), $next);
pin('links.next carries page 3 and the filters', ['3', '5', '1'], [$next['page'], $next['limit'], $next['count']]);
check('and no cursor', !isset($next['cursor']));
pin('the last page has no next', null, $list(['limit' => 5, 'page' => 3])->body()['links']['next']);
pin('without count there is no pages', [2, false], [$list(['limit' => 5, 'page' => 2])->body()['meta']['page'], isset($list(['limit' => 5, 'page' => 2])->body()['meta']['pages'])]);
pin('without page there is no page member', false, isset($first->body()['meta']['page']));

harness_section('any sort');
$byPrice = $of($list(['limit' => 5, 'sort' => 'price', 'order' => 'asc', 'page' => 2]));
pin('price sort pages by number', [$ids[5], $ids[6], $ids[7], $ids[8], $ids[9]], $byPrice);
pin('relevance pages by number', 200, $list(['limit' => 5, 'q' => 'Mug', 'sort' => 'relevance', 'page' => 2])->status());

harness_section('refusals');
$r = $list(['limit' => 5, 'page' => 2002]);
pin('a page past the cap is 422 page_too_deep', '422 page_too_deep', api_admin_code($r));
pin('the last page inside the cap answers', 200, $list(['limit' => 5, 'page' => 2001])->status());
pin('page 0 is 422', 422, $list(['page' => 0])->status());
pin('page with a cursor is 422', 422, $list(['limit' => 5, 'page' => 2, 'cursor' => $cursor['cursor']])->status());
pin('page that is not a number is 422', 422, $list(['page' => 'x'])->status());

harness_section('the cap ends paging');
// 10 060 more rows, so rows remain past the deepest page offset paging reaches (offset 10 000).
$admin->query('SET SESSION cte_max_recursion_depth = 20000');
$admin->query("INSERT INTO {$p}t_item (fk_i_user_id, fk_i_category_id, dt_pub_date, dt_first_pub_date, dt_mod_date, f_price, i_price,
    fk_c_currency_code, s_contact_name, s_contact_email, s_ip, b_premium, b_enabled, b_active, b_spam, s_secret, dt_expiration)
    WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 10060)
    SELECT $sue, $cat, DATE_SUB('2026-09-01 12:00:00', INTERVAL i MINUTE), NOW(), NOW(), 1, 1000000,
        'USD', 'Contact', 'contact@example.test', '127.0.0.1', 0, 1, 1, 0, 'bulk', DATE_ADD(NOW(), INTERVAL 30 DAY) FROM n");
$admin->query("INSERT INTO {$p}t_item_description (fk_i_item_id, fk_c_locale_code, s_title, s_description)
    SELECT pk_i_id, 'en_US', 'Bulk', 'Bulk body' FROM {$p}t_item WHERE s_secret = 'bulk'");
$admin->query("INSERT INTO {$p}t_item_location (fk_i_item_id, fk_c_country_code, s_country)
    SELECT pk_i_id, 'US', 'United States' FROM {$p}t_item WHERE s_secret = 'bulk'");
$admin->query("INSERT INTO {$p}t_item_stats (fk_i_item_id, dt_date) SELECT pk_i_id, CURDATE() FROM {$p}t_item WHERE s_secret = 'bulk'");
scratchdb_forget_cache();

$last = $list(['limit' => 50, 'page' => 201]);
pin('the last page inside the cap is full', 50, count($of($last)));
pin('and has no next link', null, $last->body()['links']['next']);
pin('and says the list is truncated', true, $last->body()['meta']['truncated'] ?? false);
$before = $list(['limit' => 50, 'page' => 200]);
$next   = [];
parse_str((string) parse_url((string) $before->body()['links']['next'], PHP_URL_QUERY), $next);
pin('the page before it links to it', '201', $next['page'] ?? null);
pin('and is not truncated', false, $before->body()['meta']['truncated'] ?? false);

exit(harness_result());
