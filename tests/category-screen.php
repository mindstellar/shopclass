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
 * The admin Categories page markup: table shape, rows, tabs, bulk bar, parent template, empty
 * state, escaping and size. No database. Usage: php tests/category-screen.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/categories-page.php';

/** @return array<string,mixed> */
function cs_node(int $id, string $name, array $kids = [], array $over = []): array
{
    return $over + [
        'pk_i_id' => $id, 's_name' => $name, 'b_enabled' => 1, 'i_num_items' => 0,
        'i_expiration_days' => 0, 'b_price_enabled' => 0, 'categories' => $kids,
    ];
}

$xss  = '<img src=x onerror=alert(1)>';
$tree = [
    cs_node(1, 'Vehicles', [
        cs_node(2, 'Cars', [
            cs_node(3, 'Car Parts', [], ['i_num_items' => 4]),
        ], ['i_num_items' => 19, 'i_expiration_days' => 60]),
    ], ['i_num_items' => 30]),
    cs_node(4, 'Real estate', [], ['b_enabled' => 0]),
    cs_node(5, $xss),
];

$html = categories_page_html($tree);
$dom  = new DOMDocument();
@$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
$xp = new DOMXPath($dom);

harness_section('table shape');
$heads = [];
foreach ($xp->query('//table[contains(@class,"cat-table")]/thead//th') as $th) {
    $heads[] = trim($th->textContent) === '' ? 'checkbox' : trim($th->textContent);
}
pin('six header cells in order', ['checkbox', 'Status', 'Name', 'Subcategories', 'Listings', 'Listings expire'], $heads);
pin('one row per category', 5, $xp->query('//tbody[@id="cat-rows"]/tr')->length);
pin('only top level rows are shown', 3, $xp->query('//tbody[@id="cat-rows"]/tr[not(@hidden)]')->length);
$r3 = $xp->query('//tr[@id="cat-3"]')->item(0);
pin('level 3 row depth', '3', $r3->getAttribute('data-depth'));
pin('level 3 row parent', '2', $r3->getAttribute('data-parent'));
pin('level 3 row is hidden', true, $r3->hasAttribute('hidden'));
pin('level 3 indent step', '--cat-depth:2', trim(explode(';', $r3->getAttribute('style'))[0]));
check('check-all has its own id', $xp->query('//input[@id="cat-check-all"]')->length === 1);
check('no global check_all id', !str_contains($html, 'id="check_all"'));
check('no datatablesForm', !str_contains($html, 'datatablesForm'));

harness_section('rows');
$off = $xp->query('//tr[@id="cat-4"]')->item(0);
check('disabled row has is-disabled', str_contains($off->getAttribute('class'), 'is-disabled'));
$on = $xp->query('//tr[@id="cat-1"]')->item(0);
check('enabled row has only the hidden word', $xp->query('.//td[contains(@class,"col-status")]/span[@class="visually-hidden"]', $on)->length === 1
    && $xp->query('.//span[@class="cat-off"]', $on)->length === 0);
check('listings column shows the count as is', trim($xp->query('.//td[contains(@class,"col-listings")]', $on)->item(0)->textContent) === '30');
check('leaf has no disclosure button', $xp->query('//tr[@id="cat-5"]//button[contains(@class,"cat-disclosure")]')->length === 0);
check('parent has a collapsed disclosure', $xp->query('//tr[@id="cat-1"]//button[contains(@class,"cat-disclosure") and @aria-expanded="false"]')->length === 1);
check('handle is a focusable button', $xp->query('//tr[@id="cat-1"]//button[contains(@class,"cat-handle")]')->length === 1);
check('row actions use the Plugins pattern', $xp->query('//tr[@id="cat-1"]//div[contains(@class,"actions")]/ul/li')->length === 6
    && $xp->query('//tr[@id="cat-1"]//button[contains(@class,"row-action-danger")]')->length === 1);

harness_section('tabs and bulk bar');
$tabs = [];
foreach ($xp->query('//ul[contains(@class,"cat-tabs")]/li/a') as $a) {
    $tabs[] = preg_replace('/\s+/', ' ', trim($a->textContent));
}
pin('tab counts', ['All 5', 'Enabled 4', 'Disabled 1'], $tabs);
pin('no tab active on all', 'All 5', preg_replace('/\s+/', ' ', trim($xp->query('//ul[contains(@class,"cat-tabs")]/li/a[@aria-current]')->item(0)->textContent)));
$offHtml = categories_page_html($tree, ['status' => 'off']);
check('status=off marks Disabled active', (bool) preg_match('~class="is-active" aria-current="page">Disabled~', $offHtml));
check('data-status carries the tab', str_contains($offHtml, 'data-status="off"'));
$bulk = [];
foreach ($xp->query('//select[@id="cat-bulk"]/option') as $o) {
    $bulk[] = $o->getAttribute('value');
}
pin('bulk options', ['', 'enable', 'disable', 'move', 'expiry', 'delete'], $bulk);
check('bulk bar posts to the hidden form', $xp->query('//form[@id="cat-bulk-form"]')->length === 1);

harness_section('urls');
$app = $xp->query('//div[contains(@class,"categories-app")]')->item(0);
foreach (['edit', 'enable', 'delete', 'move', 'bulk', 'preview'] as $k) {
    check("data-$k-url is set", $app->getAttribute("data-$k-url") !== '');
}
foreach (['enable', 'delete', 'move', 'bulk'] as $k) {
    check("data-$k-url carries the token", str_contains($app->getAttribute("data-$k-url"), 'CSRFToken='));
}
check('move url names category_move', str_contains($app->getAttribute('data-move-url'), 'action=category_move'));
check('bulk url names category_bulk', str_contains($app->getAttribute('data-bulk-url'), 'action=category_bulk'));
check('preview url names category_delete_preview', str_contains($app->getAttribute('data-preview-url'), 'action=category_delete_preview'));
$i18n = json_decode($app->getAttribute('data-i18n'), true);
check('data-i18n is valid JSON with plural pairs', is_array($i18n) && count($i18n['subcategoriesN']) === 2);

harness_section('depth limit');
$lim = categories_page_html($tree, ['depthLimit' => 2]);
$d = new DOMDocument();
@$d->loadHTML('<?xml encoding="utf-8"?>' . $lim);
$lx = new DOMXPath($d);
check('Add subcategory is disabled at the limit', $lx->query('//tr[@id="cat-2"]//button[@data-cat-act="addsub" and @disabled]')->length === 1);
check('...and enabled above it', $lx->query('//tr[@id="cat-1"]//button[@data-cat-act="addsub" and @disabled]')->length === 0);
check('a row below the limit is marked deep', $lx->query('//tr[@id="cat-3" and contains(@class,"is-deep")]')->length === 1);
pin('data-depth-limit', '2', $lx->query('//div[contains(@class,"categories-app")]')->item(0)->getAttribute('data-depth-limit'));
pin('no limit prints empty', '', $app->getAttribute('data-depth-limit'));

harness_section('parent template');
$opts = $xp->query('//template[@id="catParentOptions"]/option');
pin('one option per row plus Top level', 6, $opts->length);
pin('first option is Top level', '0', $opts->item(0)->getAttribute('value'));
check('template options carry depth and height', $opts->item(3)->getAttribute('data-depth') === '3' && $opts->item(3)->getAttribute('data-height') === '1');
check('dialogs are present', $xp->query('//dialog[@id="catConfirm"] | //dialog[@id="catMove"] | //dialog[@id="catExpiry"]')->length === 3);
check('drawer has a sub line and no eyebrow', $xp->query('//*[@id="catDrawerSub"]')->length === 1 && !str_contains($html, 'cat-drawer-eyebrow'));

harness_section('escaping');
check('name is escaped in the row', str_contains($html, '&lt;img src=x onerror=alert(1)&gt;') && !str_contains($html, '<img src=x'));
check('no raw angle bracket name anywhere', substr_count($html, '<img src=x') === 0);
check('the template option is escaped', str_contains((string) $xp->query('//template[@id="catParentOptions"]')->item(0)->ownerDocument->saveHTML(), '&lt;img'));
check('no HTML comments reach the browser', !str_contains($html, '<!--'));
$escaped = categories_page_html([cs_node(9, '"><script>x</script>')]);
check('quotes cannot break out of attributes', !str_contains($escaped, '<script>x'));

harness_section('empty tree');
$empty = categories_page_html([]);
check('no table, tabs or footer', !str_contains($empty, '<table') && !str_contains($empty, 'cat-tabs') && !str_contains($empty, 'cat-foot'));
pin('one add button', 1, substr_count($empty, 'js-cat-add'));
pin('one empty line', 1, substr_count($empty, 'class="cat-empty"'));
check('drawer still there', str_contains($empty, 'id="catDrawer"'));
check('no HTML comments', !str_contains($empty, '<!--'));

harness_section('size');
function cs_big(int $n): array
{
    $id = 0;
    $make = function (int $depth) use (&$make, &$id, $n): array {
        $kids = [];
        if ($depth < 5) {
            for ($i = 0; $i < 4 && $id < $n; $i++) {
                $id++;
                $kids[] = cs_node($id, 'Category ' . $id, $make($depth + 1), ['i_num_items' => $id % 7]);
            }
        }

        return $kids;
    };
    $top = [];
    while ($id < $n) {
        $id++;
        $top[] = cs_node($id, 'Group ' . $id, $make(2));
    }

    return $top;
}
$big = cs_big(1500);
$t0  = microtime(true);
$out = categories_page_html($big);
$ms  = (microtime(true) - $t0) * 1000;
$kb  = strlen($out) / 1024;
$gz = strlen((string) gzencode($out, 6)) / 1024;
echo sprintf("  1500 rows: %d rows, %.0f ms, %.0f KB raw, %.0f KB gzipped\n", substr_count($out, '<tr id="cat-'), $ms, $kb, $gz);
// Six text actions and the phone's ⋯ per row put the raw size near 1.7 KB a row; gzip is what travels.
check('1500 rows stay under 2.7 MB raw and 150 KB gzipped', $kb < 2700 && $gz < 150);

exit(harness_result());
