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
 * The search count cap: the count statement takes LIMIT N+1, exactly N matches is not capped and
 * N+1 is, 0 counts exactly, only visitor and API searches are capped, and the theme helpers and
 * the API's meta.total_capped report it. New installs start at 1000, upgrades at 0.
 * Usage: php tests/search-count-cap.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\search\query\SearchCompiler;
use mindstellar\search\query\SearchParts;
use mindstellar\search\SearchCriteria;
use mindstellar\search\SearchRunner;

$admin = api_admin_boot('osc_search_count_cap');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
$admin->query("UPDATE {$p}t_locale SET s_thousands_sep = ',' WHERE pk_c_code = 'en_US'");
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Things', null, $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'rewriteEnabled' => '0',
    'defaultResultsPerPage@search' => '5', 'maxResultsPerPage@search' => '50', 'search_count_cap' => '0'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_COOKIE['oc_userLocale'] = 'en_US';

for ($i = 1; $i <= 12; $i++) {
    seed_item($admin, $cat, $sue, 'Item ' . $i, 10.0);
}

$setCap = static function (int $cap): void {
    Preference::getInstance()->set('search_count_cap', (string) $cap);
    scratchdb_forget_cache();
    osc_reset_preferences();
    osc_invalidate_search_cache();
};
$fresh = static function () use ($cat): Search {
    $s = new Search();
    $s->addCategory($cat);

    return $s;
};
$parts = static fn (Search $s): SearchParts => (new ReflectionProperty(Search::class, 'parts'))->getValue($s);

harness_section('the count statement');
pin('no limit: the statement is unchanged', ['SELECT COUNT(*) AS total FROM (SELECT 1) AS search_count', [7]], SearchCompiler::count(['SELECT 1', [7]]));
pin('a limit closes the inner statement', ['SELECT COUNT(*) AS total FROM (SELECT 1' . "\nLIMIT 6" . ') AS search_count', [7]], SearchCompiler::count(['SELECT 1', [7]], 6));
$added = static function ($conditions) use ($p) {
    $conditions[] = "{$p}t_item.b_premium = 0";

    return $conditions;
};
osc_add_filter('sql_search_conditions', $added);
$s = $fresh();
$s->addConditions("{$p}t_item.i_price > 0");
$capped = SearchCompiler::count(SearchCompiler::results($parts($s), null, true), 11)[0];
osc_remove_filter('sql_search_conditions', $added);
check('the inner statement has no ORDER BY', !str_contains($capped, 'ORDER BY'));
check('the limit comes last in the inner statement', str_ends_with($capped, "\nLIMIT 11) AS search_count"));
pin('the capped statement stops counting at its limit', 6, \mindstellar\search\query\SearchExecutor::total(SearchCompiler::count(SearchCompiler::results($parts($fresh()), null, true), 6)));
check('a sql_search_conditions condition is still applied', str_contains($capped, "{$p}t_item.b_premium = 0"));

harness_section('the N and N+1 edge');
$s = $fresh();
pin('12 matches, cap 12: 12, not capped', [12, false], [$s->countMatches(false, 12), $s->totalCapped()]);
$s = $fresh();
pin('12 matches, cap 11: 11, capped', [11, true], [$s->countMatches(false, 11), $s->totalCapped()]);
pin('count() gives the cap', 11, $s->count());
$s = $fresh();
pin('cap 0: exact', [12, false], [$s->countMatches(false, 0), $s->totalCapped()]);
$s = $fresh();
pin('cached, cap 11: 11, capped', [11, true], [$s->countMatches(true, 11), $s->totalCapped()]);
pin('cached, same search, no cap: the exact total, not the capped one', [12, false], [$s->countMatches(true), $s->totalCapped()]);

harness_section('capped and exact counts are cached apart');
osc_invalidate_search_cache();
$s = $fresh();
pin('exact, cached first', [12, false], [$s->countMatches(true), $s->totalCapped()]);
// A raw write, so the search cache is not dropped: only a fresh count sees one listing fewer.
$hidden = (int) $admin->query("SELECT MAX(pk_i_id) FROM {$p}t_item WHERE fk_i_category_id = " . (int) $cat)->fetch_row()[0];
$admin->query("UPDATE {$p}t_item SET b_enabled = 0 WHERE pk_i_id = $hidden");
pin('the exact count now comes from the cache', 12, $fresh()->countMatches(true));
$s = $fresh();
pin('a capped count is counted afresh, not cut from the cached exact one', [11, false], [$s->countMatches(true, 11), $s->totalCapped()]);
$s = $fresh();
pin('another cap has its own entry too', [11, false], [$s->countMatches(true, 20), $s->totalCapped()]);
$admin->query("UPDATE {$p}t_item SET b_enabled = 1 WHERE pk_i_id = $hidden");
osc_invalidate_search_cache();

harness_section('the upper bound');
pin('the most a cap can be', 100000, Search::COUNT_CAP_MAX);
$setCap(5000000);
pin('a stored cap past the most reads as the most', 100000, osc_search_count_cap());
$setCap(-4);
pin('a negative stored cap reads as 0', 0, osc_search_count_cap());
$s = $fresh();
pin('PHP_INT_MAX as a cap is lowered, and the count is exact', [12, false], [$s->countMatches(false, PHP_INT_MAX), $s->totalCapped()]);

harness_section('who is capped');
$run = static function (string $context, ?int $cap = null) use ($cat): \mindstellar\search\SearchResult {
    $criteria = SearchCriteria::fromRequest(['sCategory' => (string) $cat], ['pageSize' => 5]);

    return $cap === null
        ? SearchRunner::run($criteria, new Search(), $context)
        : SearchRunner::run($criteria, new Search(), $context, null, true, null, null, null, $cap);
};
$setCap(0);
$r = $run('request', osc_search_count_cap());
pin('setting 0: the visitor search is exact', [12, false], [$r->total(), $r->totalCapped()]);
$setCap(12);
$r = $run('request', osc_search_count_cap());
pin('setting 12, 12 matches: not capped', [12, false], [$r->total(), $r->totalCapped()]);
$setCap(10);
$r = $run('request', osc_search_count_cap());
pin('setting 10: a run given the cap stops at 10', [10, true], [$r->total(), $r->totalCapped()]);
$r = $run('request');
pin('setting 10: a run given no cap is exact', [12, false], [$r->total(), $r->totalCapped()]);
$s = $fresh();
$s->doSearch();
pin('setting 10: doSearch() and count() stay exact (admin lists, alerts)', [12, false], [$s->count(), $s->totalCapped()]);

harness_section('the last item on a page');
$rows = static fn (int $n): array => array_fill(0, $n, ['pk_i_id' => 1]);
$end  = static fn (array $items, ?int $total, bool $capped, int $page): int => (new \mindstellar\search\SearchResult($items, $total, new Search(), null, $capped))->endOffset($page, 5);
pin('a full first page ends at 5', 5, $end($rows(5), 12, false, 0));
pin('the last page ends at the total', 12, $end($rows(2), 12, false, 2));
pin('a capped page past the cap ends at its own rows', 12, $end($rows(2), 10, true, 2));
pin('a capped page before the cap ends at its own rows too', 5, $end($rows(5), 10, true, 0));
pin('an uncounted page ends at its own rows', 8, $end($rows(3), null, false, 1));

harness_section('the page count');
$pages = static fn (array $items, ?int $total, bool $capped, int $page): int => (new \mindstellar\search\SearchResult($items, $total, new Search(), null, $capped))->pageCount($page, 5);
pin('exact: from the total', 3, $pages($rows(5), 12, false, 0));
pin('uncounted: none', 0, $pages($rows(5), null, false, 0));
pin('capped, first page: the pages under the cap', 2, $pages($rows(5), 10, true, 0));
pin('capped, a full last page under the cap: one more page', 3, $pages($rows(5), 10, true, 1));
pin('capped, a full page past the cap: one more page', 5, $pages($rows(5), 10, true, 3));
pin('capped, a short page: it is the last one', 3, $pages($rows(2), 10, true, 2));

harness_section('theme helpers');
View::getInstance()->_exportVariableToView('search_total_items', 1000);
View::getInstance()->_exportVariableToView('search_total_capped', true);
pin('capped', [1000, true, '1,000+'], [osc_search_total_items(), osc_search_total_capped(), osc_search_total_text()]);
View::getInstance()->_exportVariableToView('search_total_items', 1234);
View::getInstance()->_exportVariableToView('search_total_capped', false);
pin('exact', [1234, false, '1,234'], [osc_search_total_items(), osc_search_total_capped(), osc_search_total_text()]);

harness_section('API meta.total_capped');
$key  = (new \mindstellar\apikey\ApiKeys(new \mindstellar\model\ApiCredential(), new \mindstellar\apikey\Scopes(), new \mindstellar\utility\SystemClock()))
    ->create('key', 'sue', ['listings:read'], \mindstellar\apikey\KeyOwner::user($sue))->token();
$call = api_admin_caller();
$list = static fn (array $query): array => $call('GET', 'listings', null, $key, [], $query)->body()['meta'] ?? [];
$meta = $list(['limit' => 5, 'page' => 1, 'count' => 'true']);
pin('capped: total is the cap, pages stop there', [10, 2, true], [$meta['total'] ?? null, $meta['pages'] ?? null, $meta['total_capped'] ?? null]);
pin('a page past the cap still answers its rows', 2, count($call('GET', 'listings', null, $key, [], ['limit' => 5, 'page' => 3])->body()['data'] ?? []));
$setCap(0);
$meta = $list(['limit' => 5, 'count' => 'true']);
pin('setting 0: exact and not capped', [12, false], [$meta['total'] ?? null, $meta['total_capped'] ?? null]);
pin('without count there is no total_capped', false, array_key_exists('total_capped', $list(['limit' => 5])));

harness_section('new installs and upgrades');
check('a new install starts at 1000', str_contains((string) file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/basic_data.sql'), "('osclass', 'search_count_cap', '1000', 'INTEGER')"));
$migrate = static function (): void {
    (require ABS_PATH . 'oc-includes/osclass/installer/migrations/0084_search_count_cap.php')->up(\mindstellar\database\Connection::getInstance());
};
$row = static fn (): array => $admin->query("SELECT s_value, e_type FROM {$p}t_preference WHERE s_section = 'osclass' AND s_name = 'search_count_cap'")->fetch_all(MYSQLI_NUM);
$admin->query("DELETE FROM {$p}t_preference WHERE s_name = 'search_count_cap'");
$migrate();
pin('an upgrade adds 0, exact as before', [['0', 'INTEGER']], $row());
$migrate();
pin('runs again without a second row', [['0', 'INTEGER']], $row());
$admin->query("UPDATE {$p}t_preference SET s_value = '500' WHERE s_name = 'search_count_cap'");
$migrate();
pin('keeps a value the admin chose', [['500', 'INTEGER']], $row());

exit(harness_result());
