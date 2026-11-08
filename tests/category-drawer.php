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
 * The Categories drawer markup: one heading, Parent, expiry control, apply box, escaping.
 *
 * DB-free.  Usage: php tests/category-drawer.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/categories-drawer.php';

$row = static fn (array $over = []): array => $over + [
    'pk_i_id' => 7, 'fk_i_parent_id' => 2, 'i_expiration_days' => 60, 'b_price_enabled' => 1,
    'locale' => ['en_US' => ['s_name' => 'Cars', 's_slug' => 'cars', 's_description' => '']],
];

harness_section('Heading and parent');
$html = categories_drawer_html($row(), ['parent' => ['id' => 2, 'name' => 'Vehicles'], 'subcategory_count' => 3, 'has_subcategories' => true]);
check('no <h3> naming the category', !preg_match('~<h3>(New|Edit) category</h3>~', $html));
check('the section heading is printed', str_contains($html, '<h3 class="cat-drawer-section">Listings in this category</h3>'));
check('the Parent select carries data-self', str_contains($html, 'name="parent_id"') && str_contains($html, 'data-self="7"'));
check('the current parent is selected', (bool) preg_match('~<option value="2" selected>Vehicles</option>~', $html));
check('the hint carries the count below', str_contains($html, 'id="cat-parent-hint" data-below="3"'));
check('the web address field is relabelled', str_contains($html, 'Web address name') && !str_contains($html, '>Slug<'));

$new = categories_drawer_html($row(['pk_i_id' => '', 'fk_i_parent_id' => null]), ['parent' => ['id' => 5, 'name' => 'Real estate']]);
check('a new subcategory shows its parent', (bool) preg_match('~<option value="5" selected>Real estate</option>~', $new));
check('a new category has data-self 0', str_contains($new, 'data-self="0"'));
$top = categories_drawer_html($row(['fk_i_parent_id' => null]));
check('a top-level category shows Top level', (bool) preg_match('~<option value="0" selected>Top level</option>~', $top));

harness_section('Expiry control');
$chips = static fn (string $h): array => (function () use ($h) {
    preg_match_all('~<input type="radio" name="cat-f-choice" value="([a-z0-9]+)"( checked)?>~', $h, $m, PREG_SET_ORDER);
    return $m;
})();
$c = $chips($html);
check('five chips are printed', count($c) === 5);
check('60 days is the checked chip', array_values(array_column(array_filter($c, static fn ($x) => !empty($x[2])), 1)) === ['60']);
$odd = categories_drawer_html($row(['i_expiration_days' => 45]));
check('45 days checks Other', array_values(array_column(array_filter($chips($odd), static fn ($x) => !empty($x[2])), 1)) === ['other']);
check('Other shows the number', (bool) preg_match('~id="cat-f-days"[^>]*value="45"(?![^>]*hidden)~', $odd));
check('a preset hides the number field', (bool) preg_match('~id="cat-f-days"[^>]*value="60"[^>]*hidden~', $html));
$never = categories_drawer_html($row(['i_expiration_days' => 0]));
check('0 checks Never', array_values(array_column(array_filter($chips($never), static fn ($x) => !empty($x[2])), 1)) === ['never']);
check('the saved value is on the control', str_contains($html, 'data-saved="60"'));
check('Keep their end dates is checked', (bool) preg_match('~name="expiry_listings" value="keep" checked~', $html));
check('the posted group starts hidden', (bool) preg_match('~<fieldset class="cat-exp-posted" id="cat-f-posted" hidden>~', $html));

harness_section('Apply box');
check('a category with subcategories shows the apply box', str_contains($html, 'id="apply_changes_to_subcategories"'));
check('the box is unticked', !preg_match('~id="apply_changes_to_subcategories"[^>]*checked~', $html));
check('the label counts every level below', str_contains($html, 'Also apply to its 3 subcategories'));
$leaf = categories_drawer_html($row());
check('a leaf has no apply box', !str_contains($leaf, 'apply_changes_to_subcategories'));

harness_section('Escaping');
$evil = '<img src=x onerror=alert(1)>';
$bad  = categories_drawer_html(
    $row(['locale' => ['en_US' => ['s_name' => $evil, 's_slug' => $evil, 's_description' => $evil]]]),
    ['parent' => ['id' => 2, 'name' => $evil], 'subcategory_count' => 1]
);
check('no raw tag from a name', !str_contains($bad, '<img'));
check('no HTML comment reaches the browser', !str_contains($html, '<!--') && !str_contains($bad, '<!--'));

exit(harness_result());
