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
 * Custom-field filters give the same listings and the same backend spec whether they read the
 * filter index (facet_ready on) or t_item_meta (off). Usage: php tests/search-meta-facet.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\search\FacetIndex;
use mindstellar\search\SearchBuilder;
use mindstellar\search\SearchCriteria;

$admin = api_admin_boot('osc_search_meta_facet');
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

$field = static function (string $name, string $type) use ($admin, $p, $cat): int {
    $id = seed_exec($admin, "INSERT INTO {$p}t_meta_fields (s_name, s_slug, e_type, b_required, b_searchable, i_position) VALUES (?, ?, ?, 0, 1, 0)", 'sss', [$name, strtolower($name), $type]);
    seed_exec($admin, "INSERT INTO {$p}t_meta_categories (fk_i_category_id, fk_i_field_id) VALUES (?, ?)", 'ii', [$cat, $id]);

    return $id;
};
$material = $field('Material', 'DROPDOWN');
$fit      = $field('Fit', 'RADIO');
$organic  = $field('Organic', 'CHECKBOX');
$weight   = $field('Weight', 'NUMBER');
$made     = $field('Made', 'DATE');
$season   = $field('Season', 'DATEINTERVAL');
$note     = $field('Note', 'TEXT');

$day = 86400;
$t0  = (int) gmmktime(12, 0, 0, 6, 15, 2026);
$ids = [];
$rows = [
    'A' => [$material => 'Cotton', $fit => 'Slim', $organic => '1', $weight => '12.5', $made => (string) $t0, $note => 'soft', 'span' => [$t0 - 10 * $day, $t0 + 10 * $day]],
    'B' => [$material => 'Linen', $fit => 'Loose', $organic => '0', $weight => '9', $made => (string) ($t0 + 5 * $day), $note => 'cool', 'span' => [$t0 + 2 * $day, $t0 + 4 * $day]],
    'C' => [$material => 'Wool', $fit => 'Slim', $organic => '1', $weight => '20', $made => (string) ($t0 - 30 * $day), $note => 'warm', 'span' => [$t0 - 20 * $day, $t0 - 15 * $day]],
    'D' => [],
];
foreach ($rows as $title => $values) {
    $ids[$title] = seed_item($admin, $cat, $sue, $title, 10.0);
    $span        = $values['span'] ?? null;
    unset($values['span']);
    foreach ($values as $fieldId => $value) {
        seed_exec($admin, "INSERT INTO {$p}t_item_meta (fk_i_item_id, fk_i_field_id, s_value, s_multi) VALUES (?, ?, ?, '')", 'iis', [$ids[$title], $fieldId, $value]);
    }
    if ($span !== null) {
        foreach (['from' => $span[0], 'to' => $span[1]] as $multi => $value) {
            seed_exec($admin, "INSERT INTO {$p}t_item_meta (fk_i_item_id, fk_i_field_id, s_value, s_multi) VALUES (?, ?, ?, ?)", 'iiss', [$ids[$title], $season, (string) $value, $multi]);
        }
    }
}
$after = 0;
do {
    $after = FacetIndex::buildStep($after, FacetIndex::BATCH);
} while ($after !== null);

$ready = static function (bool $on): void {
    Preference::getInstance()->set('facet_ready', $on ? '1' : '0');
    scratchdb_forget_cache();
    osc_reset_preferences();
};

$search = static function (array $meta) use ($cat): array {
    $s = new Search();
    SearchBuilder::apply(SearchCriteria::fromRequest(['sCategory' => [(string) $cat], 'meta' => $meta]), $s);
    $titles = array_map(static fn (array $row): string => (string) $row['s_title'], (array) $s->doSearch());
    sort($titles);

    return [$titles, $s->filters()->meta, str_contains($s->toJson(), 't_item_facet')];
};

$cases = [
    'dropdown eq'       => [[$material => 'Cotton'], ['A']],
    'dropdown in'       => [[$material => ['Cotton', 'Wool']], ['A', 'C']],
    'radio'             => [[$fit => 'Slim'], ['A', 'C']],
    'checkbox'          => [[$organic => '1'], ['A', 'C']],
    'number between'    => [[$weight => ['from' => '10', 'to' => '20']], ['A', 'C']],
    'number keeps 12.5' => [[$weight => ['from' => '12', 'to' => '13']], ['A']],
    'number drops 9'    => [[$weight => ['from' => '10', 'to' => '11']], []],
    'date on a day'     => [[$made => (string) $t0], ['A']],
    'interval covers'   => [[$season => ['from' => $t0, 'to' => $t0 + $day]], ['A']],
    'interval around the ask' => [[$season => ['from' => $t0 + 3 * $day, 'to' => $t0 + 3 * $day + 3600]], ['A', 'B']],
    'interval inside the ask' => [[$season => ['from' => $t0 + 3 * $day, 'to' => $t0 + 8 * $day]], ['A']],
    'two fields'        => [[$material => 'Wool', $organic => '1'], ['C']],
    'text contains'     => [[$note => 'soft'], ['A']],
];

harness_section('same listings and same spec, flag off and on');
foreach ($cases as $label => [$meta, $expect]) {
    $ready(false);
    [$offTitles, $offSpec, $offFacet] = $search($meta);
    $ready(true);
    [$onTitles, $onSpec, $onFacet] = $search($meta);
    pin("$label: listings, off", $expect, $offTitles);
    pin("$label: listings, on", $expect, $onTitles);
    pin("$label: spec is the same", $offSpec, $onSpec);
    pin("$label: index read only when ready", [false, $label !== 'text contains'], [$offFacet, $onFacet]);
}

exit(harness_result());
