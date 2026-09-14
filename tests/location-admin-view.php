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

// ---- Data tab: catalogRows() / catalogFilter() / catalogMatches() -------------------
$status = array(
    array('code' => 'mt', 'name' => 'Malta', 'file' => 'json/MT.json', 'ndjson' => 'data/MT.ndjson', 'installed' => true, 'current' => true, 'rows' => 121, 'regions' => 69),
    array('code' => 'IN', 'name' => 'India', 'file' => '', 'ndjson' => 'data/IN.ndjson', 'installed' => true, 'current' => false, 'rows' => 85898, 'regions' => 37),
    array('code' => 'DE', 'name' => 'Germany', 'file' => 'json/DE.json', 'ndjson' => '', 'installed' => false, 'current' => false, 'rows' => 123644),
    array('code' => 'AL', 'name' => 'Albania', 'file' => 'json/AL.json', 'ndjson' => '', 'installed' => false, 'current' => false, 'rows' => 3302, 'regions' => 12),
    array('code' => 'XX', 'name' => 'Nowhere', 'file' => '', 'ndjson' => '', 'installed' => false, 'current' => false, 'rows' => 0),
);
$catalog = LocationAdminView::catalogRows($status);
pin('catalog: updates, then up to date, then not installed, by name', array('IN', 'MT', 'AL', 'DE'), array_column($catalog, 'code'));
pin('catalog: states', array('update', 'current', 'available', 'available'), array_column($catalog, 'state'));
pin('catalog: a row with no file to import is left out', false, in_array('XX', array_column($catalog, 'code'), true));
pin('catalog: codes upper-cased, cities from rows, missing regions read as 0', array('code' => 'DE', 'name' => 'Germany', 'cities' => 123644, 'regions' => 0, 'state' => 'available'), $catalog[3]);
pin('catalog: counts per filter', array('all' => 4, 'installed' => 2, 'update' => 1, 'available' => 2), LocationAdminView::catalogCounts($catalog));

pin('filter: installed by default when something is installed', 'installed', LocationAdminView::catalogFilter('', '', 2)['show']);
pin('filter: all by default on a fresh site', 'all', LocationAdminView::catalogFilter('', '', 0)['show']);
pin('filter: an unknown filter falls back to the default', 'installed', LocationAdminView::catalogFilter('', 'everything', 1)['show']);
pin('filter: a known filter is kept, the text trimmed', array('find' => 'ger', 'show' => 'available'), LocationAdminView::catalogFilter('  ger ', 'available', 3));
$shown = static fn (string $find, string $show): array => array_column(array_values(array_filter(
    $catalog,
    static fn (array $row): bool => LocationAdminView::catalogMatches($row, $find, $show)
)), 'code');
pin('matches: installed means up to date or waiting for an update', array('IN', 'MT'), $shown('', 'installed'));
pin('matches: updates only', array('IN'), $shown('', 'update'));
pin('matches: not installed', array('AL', 'DE'), $shown('', 'available'));
pin('matches: name contains the text, any case', array('DE'), $shown('MAN', 'all'));
pin('matches: an exact code', array('MT'), $shown('mt', 'all'));
pin('matches: text and filter together', array(), $shown('ger', 'installed'));

// ---- importOffer() -----------------------------------------------------------------
pin('offer: a catalog code, typed in any case', array('code' => 'AL', 'name' => 'Albania', 'regions' => 12, 'cities' => 3302, 'installed' => false, 'current' => false), LocationAdminView::importOffer(' al ', $status));
pin('offer: an installed country says so', array(true, false), array_values(array_intersect_key(LocationAdminView::importOffer('IN', $status), array('installed' => 1, 'current' => 1))));
pin('offer: none for a code the catalog lacks', null, LocationAdminView::importOffer('QQ', $status));
pin('offer: none for a catalog row with nothing to import', null, LocationAdminView::importOffer('XX', $status));
pin('offer: none for a malformed code', null, LocationAdminView::importOffer('M1', $status));
pin('offer: none for a three-letter code, even one the catalog lists', null, LocationAdminView::importOffer('MLT', array(
    array('code' => 'MLT', 'name' => 'Malta', 'file' => 'json/MLT.json', 'ndjson' => '', 'installed' => false, 'current' => false, 'rows' => 1),
)));
pin('offer: none when the catalog could not be read', null, LocationAdminView::importOffer('MT', array()));

// ---- catalogEntry() / importInsteadRefusal() ------------------------------------------
pin('entry: found by code', 'IN', LocationAdminView::catalogEntry('in', $status)['code'] ?? null);
pin('entry: found by the exact published file name', 'AL', LocationAdminView::catalogEntry('json/AL.json', $status)['code'] ?? null);
pin('entry: a path the catalog does not publish is refused', null, LocationAdminView::catalogEntry('../../config.php', $status));
pin('entry: a file name with a path prefix is refused', null, LocationAdminView::catalogEntry('/tmp/json/AL.json', $status));
pin('entry: a code the catalog lacks is refused', null, LocationAdminView::catalogEntry('QQ', $status));
pin('entry: nothing when the catalog could not be read', null, LocationAdminView::catalogEntry('IN', array()));
pin('import instead: allowed for a country not installed', null, LocationAdminView::importInsteadRefusal('al', $status));
pin('import instead: refused for an installed country', 'installed', LocationAdminView::importInsteadRefusal('IN', $status));
pin('import instead: refused for an up-to-date country', 'installed', LocationAdminView::importInsteadRefusal('MT', $status));
pin('import instead: refused for a code the catalog lacks', 'unknown', LocationAdminView::importInsteadRefusal('QQ', $status));
pin('import instead: refused for a malformed code', 'malformed', LocationAdminView::importInsteadRefusal('M', $status));

// ---- previewReport() ----------------------------------------------------------------
$counters = static fn (array $set): array => $set + array('inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'renamed' => 0, 'adopted' => 0, 'reparented' => 0, 'deactivated' => 0, 'kept_stale' => 0, 'skipped_empty' => 0, 'skipped_long' => 0);
$renames = array();
for ($i = 1; $i <= 13; $i++) {
    $renames[] = array('type' => $i === 1 ? 'REGION' : 'CITY', 'id' => $i, 'from' => 'old-' . $i, 'to' => 'new-' . $i);
}
$report = array(
    'country' => 'IN', 'dry_run' => true, 'country_inserted' => false, 'country_renamed' => false,
    'regions' => $counters(array('updated' => 2, 'renamed' => 1, 'unchanged' => 35, 'kept_stale' => 1, 'skipped_empty' => 3)),
    'cities'  => $counters(array('inserted' => 4, 'updated' => 952, 'renamed' => 50, 'deactivated' => 759, 'unchanged' => 84180, 'skipped_long' => 766)),
    'renames' => $renames,
);
$preview = LocationAdminView::previewReport($report, array('REGION' => array(1 => 'Goa'), 'CITY' => array(2 => 'Panaji')));
pin('preview: counts per level, deactivated read as hidden', array('inserted' => 4, 'updated' => 952, 'renamed' => 50, 'hidden' => 759, 'unchanged' => 84180, 'kept' => 0, 'skipped' => 766), $preview['levels']['cities']);
pin('preview: skipped adds empty and long', 3, $preview['levels']['regions']['skipped']);
pin('preview: the first ten renames only', LocationAdminView::PREVIEW_RENAMES, count($preview['renames']));
pin('preview: a rename carries its level and current name', array('level' => 'region', 'id' => 1, 'name' => 'Goa', 'from' => 'old-1', 'to' => 'new-1'), $preview['renames'][0]);
pin('preview: a rename whose name was not found has none', null, $preview['renames'][2]['name']);
pin('preview: the rest are counted from the totals, not the sample', 41, $preview['renamesMore']);
pin('preview: kept adds both levels', 1, $preview['kept']);
check('preview: changes when anything is added, changed or hidden', $preview['changes'] === true);
check('preview: no fallback noted', $preview['fellBack'] === false);
$same = LocationAdminView::previewReport(array('country' => 'MT', 'regions' => $counters(array('unchanged' => 69)), 'cities' => $counters(array('unchanged' => 121, 'kept_stale' => 2)), 'renames' => array()));
check('preview: nothing to change when every row is unchanged or kept', $same['changes'] === false && $same['kept'] === 2);
$renamedOnly = LocationAdminView::previewReport(array(
    'country' => 'MT', 'country_renamed' => true,
    'regions' => $counters(array('unchanged' => 69)), 'cities' => $counters(array('unchanged' => 121)), 'renames' => array(),
));
check('preview: a renamed country alone is a change', $renamedOnly['changes'] === true && $renamedOnly['countryRenamed'] === true);
pin('preview: an import that could not run reports only its error', array('error' => 'could not download json/IN.json'), LocationAdminView::previewReport(array('error' => 'could not download json/IN.json', 'regions' => array())));
check('preview: a fallback is noted', LocationAdminView::previewReport($report + array('fell_back' => 'data/IN.ndjson'))['fellBack']);

// ---- releaseDate() / recalcProgress() ----------------------------------------------
pin('release: date of a release name', '2026-08-22', LocationAdminView::releaseDate('2026-08-22T0451Z'));
pin('release: a version hash has no date', '', LocationAdminView::releaseDate('f31dafd51acc843f'));
pin('release: an impossible date has none', '', LocationAdminView::releaseDate('2026-13-40'));
pin('recalc: progress from the queue left', array('pending' => 8120, 'total' => 19300, 'done' => 11180, 'percent' => 57), LocationAdminView::recalcProgress(8120, 19300));
pin('recount budget: 20 s when there is no time limit', 20.0, LocationAdminView::recountBudget(0, 0.0));
pin('recount budget: time already spent counts against it', 12.5, LocationAdminView::recountBudget(0, 7.5));
pin('recount budget: stops five seconds short of max_execution_time', 3.0, LocationAdminView::recountBudget(10, 2.0));
pin('recount budget: never negative', 0.0, LocationAdminView::recountBudget(30, 40.0));
pin('recalc: an empty queue is complete', 100, LocationAdminView::recalcProgress(0, 0)['percent']);
pin('recalc: a stale total never goes below what is left', array('pending' => 500, 'total' => 500, 'done' => 0, 'percent' => 0), LocationAdminView::recalcProgress(500, 20));

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
require_once __DIR__ . '/lib/stubs.php';
function _e($s)
{
    echo $s;
}
function _n($one, $many, $n)
{
    return (int) $n === 1 ? $one : $many;
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

function osc_admin_base_url($withIndex = false)
{
    return 'index.php';
}
function osc_admin_date($date, $dateOnly = false)
{
    return '<time datetime="' . $date . '">' . $date . '</time>';
}
function osc_csrf_token_form()
{
    return '<input type="hidden" name="CSRFName" value="t"/>';
}
function osc_current_admin_theme_path($file)
{
    include ABS_PATH . 'oc-admin/themes/modern/' . $file;
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

// ---- Data tab, rendered ------------------------------------------------------------
$dataModel = static fn (array $over = array()): array => $over + array(
    'base' => 'index.php?page=settings&action=locations', 'url' => 'index.php?page=settings&action=locations&tab=data',
    'reachable' => true, 'release' => '2026-08-22', 'rows' => $catalog, 'counts' => LocationAdminView::catalogCounts($catalog),
    'find' => '', 'show' => 'installed', 'preview' => null,
    'recalc' => LocationAdminView::recalcProgress(0, 0),
);
$x = $render('data', array('locationData' => $dataModel()));
pin('data: every catalog row is in the table', 4, $x->query('//table[contains(@class,"loc-catalog-table")]//tr[@data-code]')->length);
pin('data: rows outside the filter are hidden, so no-JS filtering works', array('AL', 'DE'), array_map(static fn ($n) => $n->getAttribute('data-code'), iterator_to_array($x->query('//tr[@data-code][@hidden]'))));
pin('data: the filter radio is checked', 'installed', $x->query('//input[@name="show"][@checked]')->item(0)?->getAttribute('value'));
pin('data: an update offers preview and update', array('loc-preview-form', 'loc-import-form'), array_map(static fn ($n) => $n->getAttribute('form'), iterator_to_array($x->query('//tr[@data-code="IN"]//button'))));
pin('data: an up-to-date country offers nothing', 0, $x->query('//tr[@data-code="MT"]//button')->length);
pin('data: a missing country offers install, posting its code', 'AL', $x->query('//tr[@data-code="AL"]//button[@form="loc-import-form"]')->item(0)?->getAttribute('value'));
pin('data: row buttons post through two shared forms', 2, $x->query('//form[contains(@class,"loc-catalog-form")]')->length);
pin('data: no empty row while something shows', 1, $x->query('//tr[@data-loc-catalog-empty][@hidden]')->length);
pin('data: progress hidden with nothing queued', 1, $x->query('//*[@data-loc-recalc-progress][@hidden]')->length);
pin('data: the release date is shown', 1, $x->query('//*[contains(@class,"loc-release")]//time[@datetime="2026-08-22"]')->length);

$x = $render('data', array('locationData' => $dataModel(array('find' => 'zzz', 'show' => 'all'))));
pin('data: the empty row shows when nothing matches', 0, $x->query('//tr[@data-loc-catalog-empty][@hidden]')->length);

$x = $render('data', array('locationData' => $dataModel(array('reachable' => false, 'rows' => array()))));
pin('data: an unreachable catalog shows the notice', 1, $x->query('//*[contains(@class,"loc-outage")][@role="status"]')->length);
pin('data: with a retry and the manual add', array('index.php?page=settings&action=locations&tab=data&refresh=1', 'index.php?page=settings&action=locations&form=add'), array_map(static fn ($n) => $n->getAttribute('href'), iterator_to_array($x->query('//*[contains(@class,"loc-outage-actions")]//a'))));
pin('data: and no table', 0, $x->query('//table')->length);
pin('data: counts still offered', 1, $x->query('//form[contains(@class,"loc-recalc-form")]')->length);

$x = $render('data', array('locationData' => $dataModel(array('recalc' => LocationAdminView::recalcProgress(8120, 19300)))));
pin('data: a queued recount shows its progress', '11180', $x->query('//progress')->item(0)?->getAttribute('value'));
pin('data: and offers to continue', 'Continue counting', trim((string) $x->query('//form[contains(@class,"loc-recalc-form")]//button')->item(0)?->textContent));

$previewVars = $preview + array('code' => 'IN', 'name' => 'India', 'regions' => 37, 'cities' => 85898, 'installed' => true, 'current' => false);
$x = $render('preview', array('locationPreview' => $previewVars));
pin('preview view: one row per level, four counts each', array('4', '952', '50', '759'), array_map(static fn ($n) => trim($n->textContent), iterator_to_array($x->query('//table//tr[2]/td'))));
pin('preview view: ten renames listed', 10, $x->query('//ol[contains(@class,"loc-renames-list")]/li')->length);
pin('preview view: carries its own CSRF token', 1, $x->query('//input[@name="CSRFName"]')->length);
pin('preview view: offers the update', 'Update India', trim((string) $x->query('//button[@type="submit"]')->item(0)?->textContent));
pin('preview view: kept rows are named', 1, $x->query('//ul[contains(@class,"loc-preview-notes")]/li[contains(., "holds listings")]')->length);

$x = $render('preview', array('locationPreview' => $same + array('code' => 'MT', 'name' => 'Malta', 'regions' => 69, 'cities' => 121, 'installed' => true, 'current' => false)));
pin('preview view: nothing to change says so, without a table', array(1, 0), array($x->query('//*[contains(@class,"loc-preview-same")]')->length, $x->query('//table')->length));

// ---- Add country, rendered -----------------------------------------------------------
$x = $render('form', array('locations' => $list(array('level' => 'country', 'country' => null)), 'locationForm' => array('kind' => 'add', 'level' => 'country', 'error' => null)));
$submits = $x->query('//form//button[@type="submit"]');
pin('add country: the first submit button adds, so Enter never imports', array('', 'import_instead'), array($submits->item(0)?->getAttribute('name'), $submits->item(1)?->getAttribute('name')));
check('add country: the import offer skips validation, so no name is needed', $submits->item(1)?->hasAttribute('formnovalidate') === true);
$x = $render('form', array('locations' => $list(), 'locationForm' => array('kind' => 'add', 'level' => 'region', 'error' => null)));
pin('add region: no import offer', 0, $x->query('//*[@data-loc-offer]')->length);

// ---- Controller contracts (source scan: the controllers need a live admin session) --
$tools  = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminTools.php');
preg_match("/case \('locations_post'\):(.*?)case \('upgrade'\):/s", $tools, $post);
check('locations_post is found', isset($post[1]));
$csrfAt  = isset($post[1]) ? strpos($post[1], 'osc_csrf_check();') : false;
$workAt  = isset($post[1]) ? strpos($post[1], 'osc_update_location_stats(') : false;
check('locations_post checks CSRF before it counts anything', $csrfAt !== false && $workAt !== false && $csrfAt < $workAt);

$locCtl = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsLocations.php');
preg_match('/private function runImport\(.*?\n    \}\n/s', $locCtl, $run);
check('runImport is found', isset($run[0]));
$guardAt  = isset($run[0]) ? strpos($run[0], 'if ($entry === null)') : false;
$importAt = isset($run[0]) ? strpos($run[0], 'osc_install_json_locations(') : false;
check('runImport refuses what the catalog does not list before importing', $guardAt !== false && $importAt !== false && $guardAt < $importAt);
check('runImport imports by the catalog code, not the posted value', isset($run[0]) && strpos($run[0], "osc_install_json_locations((string) \$entry['code'])") !== false);
preg_match("/import_instead'\) === '1'\) \{(.*?)\\\$this->runImport/s", $locCtl, $instead);
check('import instead is refused for an installed country', isset($instead[1]) && strpos($instead[1], "case 'installed':") !== false);

exit(harness_result());
