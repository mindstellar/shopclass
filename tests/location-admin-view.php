<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The decisions the location admin screen makes before it renders.
 *
 * What would go wrong here is quiet: a delete that removes listings asking for no typed
 * confirmation, a confirm pattern that a name with brackets or dots makes invalid (browsers
 * then skip the check entirely), a search hit linking to a list whose parent is gone, and an
 * everywhere search with no text that renders an empty result instead of the list.
 *
 * No database. Usage:  php tests/location-admin-view.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/location/LocationAdminView.php';

use mindstellar\location\LocationAdminView;

// ---- search() ----------------------------------------------------------------
pin('search: trims and folds spaces', array('q' => 'San Jose', 'scope' => 'level'), LocationAdminView::search("  San \t Jose ", ''));
pin('search: everywhere kept with text', array('q' => 'ban', 'scope' => 'all'), LocationAdminView::search('ban', 'all'));
pin('search: everywhere dropped without text', array('q' => '', 'scope' => 'level'), LocationAdminView::search('   ', 'all'));
pin('search: unknown scope means this level', 'level', LocationAdminView::search('ban', 'everything')['scope']);
$long = LocationAdminView::search(str_repeat('é', LocationAdminView::MAX_QUERY + 20), '');
pin('search: capped in characters, not bytes', LocationAdminView::MAX_QUERY, mb_strlen($long['q']));

// ---- showAlphabet() ----------------------------------------------------------
check('A–Z: hidden at the threshold', !LocationAdminView::showAlphabet(LocationAdminView::ALPHABET_MIN, 'level'));
check('A–Z: shown above it', LocationAdminView::showAlphabet(LocationAdminView::ALPHABET_MIN + 1, 'level'));
check('A–Z: hidden while searching everywhere', !LocationAdminView::showAlphabet(50000, 'all'));

// ---- hitLinks() --------------------------------------------------------------
pin('hit: country opens its regions', array(
    'open' => array('country' => 'IN'),
    'edit' => array('form' => 'edit', 'id' => 'IN'),
    'path' => array(),
), LocationAdminView::hitLinks('country', array('code' => 'IN', 'name' => 'India', 'slug' => 'india')));

pin('hit: region opens its cities', array(
    'open' => array('country' => 'IN', 'region' => 12),
    'edit' => array('country' => 'IN', 'form' => 'edit', 'id' => 12),
    'path' => array('India'),
), LocationAdminView::hitLinks('region', array('id' => 12, 'name' => 'Goa', 'country' => 'IN', 'country_name' => 'India')));

pin('hit: city opens its region filtered to its name', array(
    'open' => array('country' => 'IN', 'region' => 12, 'q' => 'Panaji'),
    'edit' => array('country' => 'IN', 'region' => 12, 'form' => 'edit', 'id' => 99),
    'path' => array('India', 'Goa'),
), LocationAdminView::hitLinks('city', array(
    'id' => 99, 'name' => 'Panaji', 'region' => 12, 'region_name' => 'Goa', 'country' => 'IN', 'country_name' => 'India',
)));

$orphanCity = LocationAdminView::hitLinks('city', array(
    'id' => 5, 'name' => 'Lost', 'region' => 404, 'region_name' => null, 'country' => null, 'country_name' => null,
));
check('hit: city whose region is gone has no links', $orphanCity['open'] === null && $orphanCity['edit'] === null);
$orphanRegion = LocationAdminView::hitLinks('region', array('id' => 7, 'name' => 'Lost', 'country' => 'QQ', 'country_name' => null));
check('hit: region whose country is gone has no links', $orphanRegion['open'] === null && $orphanRegion['edit'] === null);

// ---- confirmPhrase() ---------------------------------------------------------
pin('confirm: none when no listing is deleted', null, LocationAdminView::confirmPhrase(array('listings' => 0), 'Goa'));
pin('confirm: the name for one row', 'Goa', LocationAdminView::confirmPhrase(array('listings' => 3), ' Goa '));
pin('confirm: the listing count for a selection', '3481', LocationAdminView::confirmPhrase(array('listings' => 3481), null));

// ---- confirmPattern() --------------------------------------------------------
// Browsers compile the attribute as ^(?:pattern)$ with the v flag; PCRE with /u stands in.
$matches = static function (string $pattern, string $value): bool {
    $compiled = '/^(?:' . str_replace('/', '\/', str_replace('\/', '/', $pattern)) . ')$/u';

    return @preg_match($compiled, $value) === 1;
};
foreach (array('Goa', 'Saint-Denis (Réunion)', 'St. John\'s', 'a+b*c?', '[Old] {town} | new', 'Ürümqi/乌鲁木齐', '$5 ^ \\ path', '3481') as $name) {
    $pattern = LocationAdminView::confirmPattern($name);
    check('pattern matches its own text: ' . $name, $matches($pattern, $name), $pattern);
    check('pattern refuses a near miss: ' . $name, !$matches($pattern, $name . 'x') && !$matches($pattern, 'x' . $name));
    // Outside a class the v flag refuses identity escapes of anything but syntax characters.
    preg_match_all('/\\\\(.)/su', $pattern, $escaped);
    $syntax = str_split('^$\\.*+?()[]{}|/');
    check('pattern escapes only syntax characters: ' . $name, array_diff($escaped[1], $syntax) === array(), $pattern);
}
check('pattern: a dot is literal', !$matches(LocationAdminView::confirmPattern('St. Ives'), 'StX Ives'));

// ---- The views, rendered ------------------------------------------------------
// The theme helpers are stubbed to plain markup, so the partials run without a request.
define('OC_ADMIN', true);
$GLOBALS['viewVars'] = array();
$GLOBALS['hooks']    = array();
$GLOBALS['filters']  = array();
function __get($key)
{
    return $GLOBALS['viewVars'][$key] ?? null;
}
function __($s)
{
    return $s;
}
function _e($s)
{
    echo $s;
}
function _n($one, $many, $n)
{
    return (int) $n === 1 ? $one : $many;
}
function osc_esc_html($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function osc_run_hook($name, ...$args)
{
    $GLOBALS['hooks'][] = array($name, $args);
}
function osc_apply_filter($name, $content, ...$args)
{
    return isset($GLOBALS['filters'][$name]) ? $GLOBALS['filters'][$name]($content, ...$args) : $content;
}
function osc_admin_form_open(array $o)
{
    echo '<form class="' . osc_esc_html($o['class'] ?? '') . '">';
}
function osc_admin_form_close($a = null, array $o = array())
{
    echo '</form>';
}
function osc_admin_status($state, $word)
{
    echo '<span class="osc-status status-' . $state . '">' . $word . '</span>';
}
function osc_admin_definition(array $rows)
{
    foreach ($rows as $r) {
        echo '<dt>' . osc_esc_html($r['label']) . '</dt><dd>' . (empty($r['html']) ? osc_esc_html($r['value']) : $r['value']) . '</dd>';
    }
}
function osc_admin_action_button(array $a)
{
    echo '<a class="btn" href="' . osc_esc_html($a['url'] ?? '') . '">' . osc_esc_html($a['label']) . '</a>';
}
function osc_admin_empty(array $o)
{
    echo '<div class="osc-empty">' . osc_esc_html($o['title'] ?? '') . '</div>';
}
function osc_admin_table_empty($colspan, array $o)
{
    echo '<tr><td>';
    osc_admin_empty($o);
    echo '</td></tr>';
}
function osc_admin_bulk_actions(array $o)
{
    echo '<div id="bulk-actions">';
    if (!empty($o['options_html'])) {
        ($o['options_html'])();
    }
    echo '</div>';
}
function osc_admin_pager(array $o)
{
}

$render = static function (string $partial, array $vars): DOMXPath {
    $GLOBALS['viewVars'] = $vars;
    $GLOBALS['hooks']    = array();
    ob_start();
    include ABS_PATH . 'oc-admin/themes/modern/settings/locations/' . $partial . '.php';
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8"?><div>' . ob_get_clean() . '</div>');

    return new DOMXPath($doc);
};
$list = static fn (array $over = array()): array => $over + array(
    'base' => 'index.php?page=settings&action=locations', 'level' => 'region', 'found' => true, 'missing' => null,
    'country' => array('code' => 'ZZ', 'name' => 'Zedland'), 'region' => null, 'q' => '', 'scope' => 'level',
    'hits' => null, 'hitsMore' => null, 'levelTotal' => 2, 'initials' => null,
    'rows' => array(array('id' => 7, 'country' => 'ZZ', 'name' => 'Omega', 'slug' => 'omega', 'active' => true, 'listings' => 2, 'cities' => 1)),
    'total' => 1, 'page' => 1, 'per' => 50,
);
$impact = static fn (int $listings): array => array('level' => 'region', 'requested' => 1, 'found' => 1, 'regions' => 0, 'cities' => 1, 'children' => 1, 'listings' => $listings, 'users' => 0);
$deleteForm = static function (int $listings, ?string $name, int $count = 1) use ($impact): array {
    return array('kind' => 'delete', 'level' => 'region', 'error' => null, 'impact' => $impact($listings),
        'ids' => range(1, $count), 'record' => $name === null ? null : array('name' => $name),
        'confirm' => LocationAdminView::confirmPhrase($impact($listings), $name));
};

$x     = $render('form', array('locations' => $list(), 'locationForm' => $deleteForm(2, 'Omega (Old)')));
$input = $x->query('//input[@data-loc-confirm]')->item(0);
check('delete with listings renders the typed confirm', $input !== null);
pin('its pattern is the escaped name', 'Omega \(Old\)', $input ? $input->getAttribute('pattern') : null);
check('the confirm is required', $input !== null && $input->hasAttribute('required'));

$x = $render('form', array('locations' => $list(), 'locationForm' => $deleteForm(0, 'Omega')));
pin('delete without listings has no typed confirm', 0, $x->query('//input[@data-loc-confirm]')->length);

$x     = $render('form', array('locations' => $list(), 'locationForm' => $deleteForm(3481, null, 3)));
$input = $x->query('//input[@data-loc-confirm]')->item(0);
pin('a selection types the listing count', '3481', $input ? $input->getAttribute('data-loc-confirm') : null);
pin('the prompt shows the count as the consequence line does', '3,481', trim((string) $x->query('//*[@class="loc-confirm-phrase"]')->item(0)?->textContent));
$pattern = $input ? $input->getAttribute('pattern') : '';
foreach (array('3481' => true, '3,481' => true, '3.481' => true, '34,81' => false, '3481x' => false) as $typed => $ok) {
    pin('count pattern ' . ($ok ? 'accepts ' : 'refuses ') . $typed, $ok, preg_match('/^(?:' . $pattern . ')$/u', (string) $typed) === 1);
}

$record = array('level' => 'region', 'id' => 7, 'name' => 'Omega', 'slug' => 'omega', 'active' => false, 'source_id' => null,
    'lat' => null, 'long' => null, 'country' => array('code' => 'ZZ', 'name' => 'Zedland'), 'region' => null, 'counts' => null);
$x = $render('form', array('locations' => $list(), 'locationForm' => array('kind' => 'edit', 'level' => 'region', 'error' => null, 'record' => $record)));
pin('edit fires admin_locations_drawer_fields with level and record', array(array('admin_locations_drawer_fields', array('region', $record))), $GLOBALS['hooks']);
pin('counts left for the script are marked pending', 1, $x->query('//*[@data-loc-counts-pending]')->length);
$render('form', array('locations' => $list(), 'locationForm' => array('kind' => 'add', 'level' => 'region', 'error' => null)));
pin('add fires admin_locations_drawer_fields with no record', array(array('admin_locations_drawer_fields', array('region', null))), $GLOBALS['hooks']);

$GLOBALS['filters']['admin_locations_row_actions'] = static function (array $actions, string $level, array $row): array {
    return $actions + array('extra' => '<a class="plugin-action">' . $level . ':' . $row['id'] . '</a>');
};
$x = $render('list', array('locations' => $list()));
pin('row actions pass through the filter with level and row', 'region:7', trim((string) $x->query('//a[@class="plugin-action"]')->item(0)?->textContent));
pin('no initials, no strip', 0, $x->query('//nav[contains(@class,"loc-az")]')->length);
unset($GLOBALS['filters']['admin_locations_row_actions']);

$x = $render('list', array('locations' => $list(array('levelTotal' => 500, 'initials' => array('1', 'Ł', 'Б')))));
$letters = array();
foreach ($x->query('//nav[contains(@class,"loc-az")]//a') as $a) {
    $letters[] = trim($a->textContent);
}
pin('the strip lists the level\'s own initials', array('All', '1', 'Ł', 'Б'), $letters);

$hitsList = static fn (bool $more): array => $list(array('scope' => 'all', 'q' => 'Om', 'hits' => array(
    'countries' => array(), 'regions' => array(array('id' => 7, 'name' => 'Omega', 'slug' => 'omega', 'active' => true, 'country' => 'ZZ', 'country_name' => 'Zedland')), 'cities' => array(),
), 'hitsMore' => array('countries' => false, 'regions' => $more, 'cities' => false)));
pin('"only the first" note when a level had more', 1, $render('list', array('locations' => $hitsList(true)))->query('//*[@class="loc-hits-more"]')->length);
pin('no note when it did not', 0, $render('list', array('locations' => $hitsList(false)))->query('//*[@class="loc-hits-more"]')->length);

// ---- splitHits() -------------------------------------------------------------
$eleven = array_fill(0, LocationAdminView::HITS_PER_LEVEL + 1, array('id' => 1));
$ten    = array_fill(0, LocationAdminView::HITS_PER_LEVEL, array('id' => 1));
$split  = LocationAdminView::splitHits(array('countries' => array(), 'regions' => $ten, 'cities' => $eleven));
pin('split: exactly the limit is not "more"', array('countries' => false, 'regions' => false, 'cities' => true), $split['more']);
pin('split: trimmed to the limit', LocationAdminView::HITS_PER_LEVEL, count($split['hits']['cities']));

exit(harness_result());
