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
 * A search condition can carry its own count text: the page statement runs the page text, the
 * count runs the count text, and toJson() and the filter list keep the page text.
 * Usage: php tests/search-count-split.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\search\query\SearchCompiler;
use mindstellar\search\query\SearchParts;
use mindstellar\search\query\SearchRecord;

$admin = api_admin_boot('osc_search_count_split');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Things', null, $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'rewriteEnabled' => '0',
    'defaultResultsPerPage@search' => '50', 'maxResultsPerPage@search' => '50'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_COOKIE['oc_userLocale'] = 'en_US';

$ids = [];
for ($i = 1; $i <= 20; $i++) {
    $ids[] = seed_item($admin, $cat, $sue, 'Item ' . $i, 10.0);
}
$first = min($ids);

$parts = static function (Search $s): SearchParts {
    return (new ReflectionProperty(Search::class, 'parts'))->getValue($s);
};
$sql = static function (Search $s, bool $count) use ($parts): string {
    return SearchCompiler::results($parts($s), null, $count)[0];
};
$fresh = static function () use ($cat): Search {
    $s = new Search();
    $s->addCategory($cat);

    return $s;
};

harness_section('page and count statements');
$s = $fresh();
$s->addCondition("{$p}t_item.pk_i_id > ?", [0], "{$p}t_item.pk_i_id >= ?");
$s->addConditions("{$p}t_item.i_price > 0");
check('page SQL holds the page text', str_contains($sql($s, false), "{$p}t_item.pk_i_id > 0"));
check('page SQL does not hold the count text', !str_contains($sql($s, false), "{$p}t_item.pk_i_id >= 0"));
check('count SQL holds the count text', str_contains($sql($s, true), "{$p}t_item.pk_i_id >= 0"));
check('count SQL does not hold the page text', !str_contains($sql($s, true), "{$p}t_item.pk_i_id > 0"));
check('a plain condition is in the page', str_contains($sql($s, false), "{$p}t_item.i_price > 0"));
check('a plain condition is in the count', str_contains($sql($s, true), "{$p}t_item.i_price > 0"));
check('toJson() keeps the page text', str_contains($s->toJson(), "pk_i_id > 0") && !str_contains($s->toJson(), "pk_i_id >= 0"));

$s = $fresh();
$s->addFieldFilter(7, ['type' => 'CHECKBOX', 'op' => 'eq', 'value' => '1'], "{$p}t_item.pk_i_id > 1", "{$p}t_item.pk_i_id > 2");
check('field filter: page text on the page', str_contains($sql($s, false), "pk_i_id > 1") && !str_contains($sql($s, false), "pk_i_id > 2"));
check('field filter: count text on the count', str_contains($sql($s, true), "pk_i_id > 2") && !str_contains($sql($s, true), "pk_i_id > 1"));
pin('field filter: the backend spec keeps it', ['type' => 'CHECKBOX', 'op' => 'eq', 'value' => '1'], $s->filters()->meta[7] ?? null);

harness_section('the sql_search_conditions filter');
$seen  = [];
$added = static function ($conditions) use (&$seen, $p) {
    $seen[]       = $conditions;
    $conditions[] = "{$p}t_item.b_premium = 0";

    return $conditions;
};
osc_add_filter('sql_search_conditions', $added);
$s = $fresh();
$s->addCondition("{$p}t_item.pk_i_id > ?", [0], "{$p}t_item.pk_i_id >= ?");
$page  = $sql($s, false);
$count = $sql($s, true);
osc_remove_filter('sql_search_conditions', $added);
check('a filter condition is in the page', str_contains($page, "{$p}t_item.b_premium = 0"));
check('a filter condition is in the count', str_contains($count, "{$p}t_item.b_premium = 0"));
pin('the filter sees the page list, then the count list', [["{$p}t_item.pk_i_id > 0"], ["{$p}t_item.pk_i_id >= 0"]], $seen);

harness_section('the count runs its own text');
$s = $fresh();
$s->addCondition("{$p}t_item.pk_i_id >= ?", [$first], '1 = 0');
$s->limit(0, 50);
pin('the page has rows', 20, count((array) $s->doSearch(true, false)));
pin('countMatches() uses the count text', 0, $s->countMatches());
$s = $fresh();
pin('no condition: 20', 20, $s->countMatches());
$s = $fresh();
$s->addCondition("{$p}t_item.pk_i_id < ?", [$first + 5]);
pin('a plain condition counts with its page text', 5, $s->countMatches());
$s = $fresh();
$s->addCondition("{$p}t_item.pk_i_id < ?", [$first + 5], "{$p}t_item.pk_i_id < ? + 1");
pin('the count text gets the same params', 6, $s->countMatches());

harness_section('the record');
$a = $fresh();
$a->addCondition("{$p}t_item.pk_i_id > ?", [0], "{$p}t_item.pk_i_id >= ?");
$b = $fresh();
$b->addCondition("{$p}t_item.pk_i_id > ?", [0]);
pin('toJson() is the same with or without a count text', $b->toJson(), $a->toJson());
check('the cached total keys on the count text', SearchRecord::encodeMatching($parts($a)) !== SearchRecord::encodeMatching($parts($b)));
pin('no count text: the key is the record of what matches', json_decode(SearchRecord::encodeMatching($parts($b)), true)['no_catched_conditions'], ["{$p}t_item.pk_i_id > 0"]);
pin('the key holds the pair', ["{$p}t_item.pk_i_id > 0" => "{$p}t_item.pk_i_id >= 0"], json_decode(SearchRecord::encodeMatching($parts($a)), true)['count_conditions'] ?? null);
$a->setJsonAlert(['price_min' => 0, 'price_max' => 0, 'aCategories' => [$cat]]);
pin('an old-format alert clears the count text too', [], $parts($a)->plugin->countConditions());
pin('and its page text', [], $parts($a)->plugin->conditions());
pin('the restored search counts every listing', 20, $a->countMatches());

harness_section('bad count text');
$threw = static function (callable $fn): bool {
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return true;
    }

    return false;
};
check('more ? in the count text throws', $threw(static fn () => $fresh()->addCondition('pk_i_id > ?', [1], 'pk_i_id > ? AND pk_i_id < ?')));
check('fewer ? in the count text throws', $threw(static fn () => $fresh()->addCondition('pk_i_id > ? AND pk_i_id < ?', [1, 9], 'pk_i_id > ?')));
check('a count text with no ? takes no params', !$threw(static fn () => $fresh()->addCondition('pk_i_id > ?', [1], '1 = 0')));
check('a quote in the count text throws', $threw(static fn () => $fresh()->addCondition('pk_i_id > ?', [1], "s_title = 'x' AND pk_i_id > ?")));
$s = $fresh();
$s->addCondition("{$p}t_item.pk_i_id > ?", [0], "{$p}t_item.pk_i_id > ?");
pin('a count text equal to the page text is no count text', [], $parts($s)->plugin->countConditions());

exit(harness_result());
