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
 * The search page's meta[id][] list for a dropdown or radio field: any value matches, a scalar
 * is unchanged, and the filter a search backend sees is `eq` for one value and `in` for several.
 * Usage: php tests/search-meta-list.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\search\SearchBuilder;
use mindstellar\search\SearchCriteria;

$admin = api_admin_boot('osc_search_meta_list');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Shirts', seed_category($admin, 'Clothes', null, $locale), $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'rewriteEnabled' => '0',
    'defaultResultsPerPage@search' => '50', 'maxResultsPerPage@search' => '50', 'defaultOrderField@search' => 'dt_pub_date', 'defaultOrderType@search' => '1'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_COOKIE['oc_userLocale'] = 'en_US';

$field = static fn (string $name, string $type): int => seed_meta_field($admin, $name, $type, $cat);
$material = $field('Material', 'DROPDOWN');
$fit      = $field('Fit', 'RADIO');
$note     = $field('Note', 'TEXT');
$ids      = [];
foreach (['Cotton' => ['Cotton', 'Slim', 'soft'], 'Linen' => ['Linen', 'Loose', 'cool'], 'Wool' => ['Wool', 'Slim', 'warm'], 'Plain' => [null, null, null]] as $title => [$m, $f, $n]) {
    $ids[$title] = seed_item($admin, $cat, $sue, $title, 10.0);
    foreach ([$material => $m, $fit => $f, $note => $n] as $fieldId => $value) {
        if ($value !== null) {
            seed_exec($admin, "INSERT INTO {$p}t_item_meta (fk_i_item_id, fk_i_field_id, s_value, s_multi) VALUES (?, ?, ?, '')", 'iis', [$ids[$title], $fieldId, $value]);
        }
    }
}

$search = static function (array $meta) use ($cat): array {
    $s = new Search();
    SearchBuilder::apply(SearchCriteria::fromRequest(['sCategory' => [(string) $cat], 'meta' => $meta]), $s);
    $titles = array_map(static fn (array $row): string => (string) $row['s_title'], (array) $s->doSearch());
    sort($titles);

    return [$titles, $s->filters()->meta];
};
$titles = static fn (array $meta): array => $search($meta)[0];

harness_section('dropdown and radio lists');
pin('a scalar is unchanged', ['Cotton'], $titles([$material => 'Cotton']));
pin('a list is OR', ['Cotton', 'Linen'], $titles([$material => ['Cotton', 'Linen']]));
pin('a list indexed by position is the same', ['Cotton', 'Linen'], $titles([$material => [0 => 'Cotton', 1 => 'Linen']]));
pin('a list on a radio field', ['Cotton', 'Wool'], $titles([$fit => ['Slim', 'Tight']]));
pin('fields are AND', ['Cotton'], $titles([$material => ['Cotton', 'Linen'], $fit => ['Slim']]));
pin('a list of one is the scalar', ['Wool'], $titles([$material => ['Wool']]));
pin('empty values are ignored', ['Linen'], $titles([$material => ['', 'Linen']]));
pin('an empty list is no filter', ['Cotton', 'Linen', 'Plain', 'Wool'], $titles([$material => ['']]));
pin('a quote and a % are values, not SQL or wildcards', [], $titles([$material => ["Cot'ton", 'C%']]));
pin('a nested array is dropped', ['Cotton'], $titles([$material => [['x'], 'Cotton']]));
pin('past 20 values the rest are dropped', [], $titles([$material => array_merge(array_map(static fn (int $i): string => 'v' . $i, range(1, 20)), ['Cotton'])]));
pin('a text field still takes one value', ['Cotton'], $titles([$note => 'soft']));

harness_section('what a search backend sees');
pin('one value is eq', [$material => ['type' => 'DROPDOWN', 'op' => 'eq', 'value' => 'Cotton']], $search([$material => ['Cotton']])[1]);
pin('several values are in', [$material => ['type' => 'DROPDOWN', 'op' => 'in', 'values' => ['Cotton', 'Linen']]], $search([$material => ['Cotton', 'Linen', 'Cotton']])[1]);

exit(harness_result());
