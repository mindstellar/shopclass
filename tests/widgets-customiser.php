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
 * The customiser core: osc_customiser_spec() drops what a theme may not declare,
 * WidgetStore::replaceLocation() rewrites one location in one transaction, the draft refuses
 * what the spec does not allow, publish and discard keep settings images right, the
 * widgets screen keeps a hidden row hidden, moderators are refused, and ?osc_preview=1
 * renders the draft only for the admin who saved it (the published rows for an admin with
 * none), uncached and noindex. Page mode: a page spec on any theme, a draft per page, and
 * locked rows that only move.
 *
 * DB-backed.  Usage:  php tests/widgets-customiser.php
 */

// Buffered from the start, so the JSON answers can still set their status code.
ob_start();

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

const SCRATCH = 'osc_widgets_customiser';

$admin = scratchdb_session(SCRATCH);
harness_reset_container();
define('OC_ADMIN', true);
require_once __DIR__ . '/lib/customiser-kit.php';
customiser_kit_declare();

use mindstellar\widgets\CustomiserDraft;
use mindstellar\widgets\PageBlocks;
use mindstellar\widgets\WidgetStore;

/** The base controller, stubbed: redirects are recorded and nothing is drawn. */
class AdminSecBaseModel
{
    protected $action;

    public function __construct()
    {
        $this->action = Params::getParam('action');
    }

    public function doModel()
    {
    }

    public function redirectTo($url, $code = null)
    {
        $GLOBALS['redirects'][] = $url;
    }

    public function _exportVariableToView($key, $value)
    {
    }

    public function doView($view)
    {
    }

    protected function refuseOnDemo($redirectUrl = null)
    {
        return false;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminAppearance.php';

$table = DB_TABLE_PREFIX . 't_widget';
$adminA = seed_exec($admin, 'INSERT INTO ' . DB_TABLE_PREFIX . "t_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES ('A', 'a', 'x', 'a@x.test', 0)", '', array());
$GLOBALS['adminId'] = $adminA;
Preference::getInstance()->replace('theme', 'aisle', 'osclass', 'STRING');

/** One admin request to the appearance controller; the JSON it answered and its status. */
function drive(string $action, array $post = array(), array $files = array()): array
{
    $_GET   = array('page' => 'appearance', 'action' => $action);
    $_POST  = $post;
    $_FILES = $files;
    Params::init();
    $GLOBALS['redirects'] = array();
    unset($GLOBALS['osc_page_cache_purge']);
    http_response_code(200);
    ob_start();
    (new CAdminAppearance())->doModel();
    $out = (string) ob_get_clean();

    return array('status' => http_response_code(), 'json' => json_decode($out, true), 'redirects' => $GLOBALS['redirects'], 'purges' => $GLOBALS['osc_page_cache_purge'] ?? array());
}

/** A public request in its own process. */
function preview(array $get, int $adminId, bool $api = false, array $extra = array()): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/lib/customiser-preview-child.php') . ' '
        . escapeshellarg(SCRATCH) . ' ' . escapeshellarg((string) json_encode(array('get' => $get, 'adminId' => $adminId, 'api' => $api) + $extra));
    $out = (string) shell_exec($cmd . ' 2>&1');
    $res = json_decode($out, true);
    if (!is_array($res)) {
        fwrite(STDERR, $out . "\n");

        return array();
    }

    return $res;
}

$rowsAt = static function (string $location) use ($admin, $table): array {
    $out = array();
    $res = $admin->query("SELECT pk_i_id, s_type, s_config, i_order FROM $table WHERE s_location = '$location' ORDER BY i_order, pk_i_id");
    while ($r = $res->fetch_assoc()) {
        $out[] = $r;
    }

    return $out;
};
$resourceCount = static fn (int $id): int => (int) $admin->query('SELECT COUNT(*) FROM ' . DB_TABLE_PREFIX . "t_resource WHERE pk_i_id = $id")->fetch_row()[0];

harness_section('osc_customiser_spec');

$spec = osc_customiser_spec();
pin('an undeclared location is dropped', array('home'), array_keys($spec['locations']));
pin('an unregistered type is dropped', array('aisle.hero', 'aisle.promise'), $spec['locations']['home']['types']);
pin('a secret and a missing field are dropped', array('brand', 'footer_note', 'logo'), $spec['settings']['fields']);
pin('a non-hex swatch is dropped, a hex one kept lower case', array('brand' => array('raspberry' => '#c81f5e')), $spec['swatches']);
pin('a live attribute must be data-*', array('brand' => 'data-brand'), $spec['live']);
pin('the preview URL is kept on this site', WEB_PATH, $spec['locations']['home']['preview']);
customiser_kit_declare(array('locations' => array('home' => array('types' => array('aisle.hero'), 'preview' => 'https://evil.test/'))));
pin('a preview on another origin falls back to the home page', WEB_PATH, osc_customiser_spec()['locations']['home']['preview']);
pin('the preview URL helper adds osc_preview=1', WEB_PATH . '?osc_preview=1', osc_customiser_preview_url('home'));
pin('and is empty for a location the theme does not offer', '', osc_customiser_preview_url('footer'));
customiser_kit_declare(array('locations' => array()));
pin('a theme with settings only keeps them', array('brand', 'footer_note', 'logo'), osc_customiser_spec()['settings']['fields'] ?? null);
pin('and previews its home page', WEB_PATH . '?osc_preview=1', osc_customiser_preview_url());
customiser_kit_declare(array('locations' => array(), 'settings' => array()));
pin('nothing usable reads as no customiser', null, osc_customiser_spec());
osc_remove_theme_support('customiser');
pin('no declaration reads as no customiser', null, osc_customiser_spec());
customiser_kit_declare();

harness_section('WidgetStore::replaceLocation');

$admin->query("DELETE FROM $table");
$h1 = WidgetStore::add(array('s_location' => 'home', 'e_kind' => 'html', 's_description' => 'Hero', 's_content' => '', 's_type' => 'aisle.hero', 's_config' => '{}', 'i_order' => 0));
$h2 = WidgetStore::add(array('s_location' => 'home', 'e_kind' => 'html', 's_description' => 'Promise', 's_content' => '', 's_type' => 'aisle.promise', 's_config' => '{}', 'i_order' => 1));
$h3 = WidgetStore::add(array('s_location' => 'home', 'e_kind' => 'html', 's_description' => 'Gone', 's_content' => '', 's_type' => 'aisle.promise', 's_config' => '{}', 'i_order' => 2));
$f1 = WidgetStore::add(array('s_location' => 'footer', 'e_kind' => 'html', 's_description' => 'Foot', 's_content' => '<p>f</p>', 'i_order' => 0));
$footerBefore = $rowsAt('footer');

WidgetStore::replaceLocation('home', array(
    array('id' => $h2, 'type' => 'aisle.promise', 'config' => array('text' => 'Fast'), 'description' => 'Promise'),
    array('id' => null, 'type' => 'aisle.hero', 'config' => array('title' => 'New'), 'description' => 'Hero 2'),
    array('id' => $h1, 'type' => 'aisle.hero', 'config' => array('title' => 'Old', '_enabled' => false), 'description' => 'Hero'),
    array('id' => $f1, 'type' => 'aisle.hero', 'config' => array(), 'description' => 'Not moved'),
));
$home = $rowsAt('home');
pin('the order is as given', array((string) $h2, 'new', (string) $h1, 'new'), array_map(static fn ($r) => in_array((int) $r['pk_i_id'], array($h1, $h2), true) ? $r['pk_i_id'] : 'new', $home));
pin('i_order is the index', array('0', '1', '2', '3'), array_column($home, 'i_order'));
pin('the dropped row is gone', 0, (int) $admin->query("SELECT COUNT(*) FROM $table WHERE pk_i_id = $h3")->fetch_row()[0]);
pin('kept rows are updated in place', '{"title":"Old","_enabled":false}', $home[2]['s_config']);
pin('an id from another location is not moved: the footer is untouched', $footerBefore, $rowsAt('footer'));

$before = $rowsAt('home');
$threw  = false;
try {
    WidgetStore::replaceLocation('home', array(
        array('id' => $h2, 'type' => 'aisle.promise', 'config' => array('text' => 'Changed'), 'description' => 'Promise'),
        array('id' => null, 'type' => 'aisle.hero', 'config' => array('title' => "\xB1\x31"), 'description' => 'Not UTF-8'),
    ));
} catch (Throwable $e) {
    $threw = true;
}
check('a failure mid-way throws', $threw);
pin('...and leaves the old rows exactly', $before, $rowsAt('home'));

harness_section('the draft refuses what the spec does not allow');

$admin->query("DELETE FROM $table");
$hero = WidgetStore::add(array('s_location' => 'home', 'e_kind' => 'html', 's_description' => 'Hero', 's_content' => '', 's_type' => 'aisle.hero', 's_config' => '{"title":"Live"}', 'i_order' => 0));
$draftKv = static fn (): ?array => (new CustomiserDraft('aisle', $GLOBALS['adminId']))->read();

$r = drive('customise_draft_post', array('locations' => array('home' => array(array('type' => 'other.thing')))));
pin('a type outside the location\'s types is 422', 422, $r['status']);
pin('...and stores nothing', null, $draftKv());
$r = drive('customise_draft_post', array('locations' => array('footer' => array(array('type' => 'aisle.hero')))));
pin('a location outside locations is 422', 422, $r['status']);
$r = drive('customise_draft_post', array('settings' => array('api_key' => 'x')));
pin('a field outside settings.fields is 422', 422, $r['status']);
$r = drive('customise_draft_post', array('settings' => array('brand' => 'evil')));
pin('a look select outside its options is 422', 422, $r['status']);
$r = drive('customise_draft_post', array('settings' => array('footer_note' => str_repeat('a', 21))));
pin('a look value that fails its field\'s rules is 422', 422, $r['status']);
$r = drive('customise_draft_post', array('locations' => array('home' => array(array('type' => 'aisle.hero', 'config' => array('title' => str_repeat('a', 300 * 1024)))))));
pin('a 300 KB draft is 422', 422, $r['status']);
pin('...and stores nothing', null, $draftKv());

$GLOBALS['moderator'] = true;
foreach (array('customise_draft_post', 'customise_publish_post', 'customise_discard_post') as $action) {
    pin('a moderator gets 403 from ' . $action, 403, drive($action, array('settings' => array('brand' => 'ocean')))['status']);
}
$GLOBALS['moderator'] = false;
pin('...and nothing was drafted', null, $draftKv());

harness_section('a good draft');

$r = drive('customise_draft_post', array(
    'locations' => array('home' => array(
        array('id' => (string) $hero, 'type' => 'aisle.hero', 'config' => array('title' => '<b>Big</b> *sale*', 'layout' => 'evil', 'image' => 'x'), 'enabled' => '0'),
        array('id' => '', 'key' => 'new-1', 'type' => 'aisle.promise', 'config' => array('text' => 'Free returns', 'stray' => 'x')),
        array('id' => '999999', 'type' => 'aisle.promise', 'config' => array('text' => 'Forged id')),
    )),
    'settings'  => array('brand' => 'ocean', 'footer_note' => ' Hi <i>there</i> '),
));
pin('a good draft is 200', 200, $r['status']);
check('...answers saved_at and the preview URL', ($r['json']['saved_at'] ?? 0) > 0 && $r['json']['preview_url'] === WEB_PATH . '?osc_preview=1');
$d = $draftKv();
$rows = $d['locations']['home'];
pin('the hero keeps its id', $hero, $rows[0]['id']);
pin('...is hidden', false, $rows[0]['config']['_enabled']);
pin('...a select outside its options fell to the default', 'grid', $rows[0]['config']['layout']);
pin('...a non-URL image was dropped', '', $rows[0]['config']['image']);
check('...the title lost its markup', strpos($rows[0]['config']['title'], '<b>') === false);
pin('an undeclared config key is dropped', array('text'), array_keys($rows[1]['config']));
pin('a new row keeps its draft key', array(null, 'new-1'), array($rows[1]['id'], $rows[1]['key']));
pin('an id not in the location becomes a new row', null, $rows[2]['id']);
pin('look values are drafted, sanitised', array('brand' => 'ocean', 'footer_note' => 'Hi there'), $d['settings']);
pin('the live rows are untouched by a draft', '{"title":"Live"}', $rowsAt('home')[0]['s_config']);

$r = drive('customise_draft_post', array('locations' => array('home' => array(
    array('id' => (string) $hero, 'type' => 'aisle.hero', 'config' => array('image' => 'https://cdn.test/a.jpg')),
))));
pin('a later change keeps the look values', 'ocean', $draftKv()['settings']['brand']);
pin('...and an image URL from the library is kept', 'https://cdn.test/a.jpg', $draftKv()['locations']['home'][0]['config']['image']);
drive('customise_draft_post', array('locations' => array('home' => array(
    array('id' => (string) $hero, 'type' => 'aisle.hero', 'config' => array('image' => 'javascript:alert(1)')),
))));
pin('a javascript: image URL is dropped', '', $draftKv()['locations']['home'][0]['config']['image']);

drive('customise_draft_post', array('locations' => array('home' => array(
    array('id' => (string) $hero, 'type' => 'aisle.hero', 'config' => array('title' => 'Draft title'), 'enabled' => '1'),
    array('id' => '', 'key' => 'new-1', 'type' => 'aisle.promise', 'config' => array('text' => 'Free returns')),
))));

harness_section('the preview');

$plain = preview(array('osc_preview' => '1'), 0);
pin('without an admin session ?osc_preview=1 renders the published rows', '<hero ' . $hero . '>Live</hero>', $plain['page'] ?? null);
check('...is no preview', ($plain['preview'] ?? true) === false && array_key_exists('brand', $plain) && $plain['brand'] === null);
check('...sends no preview headers and stays cacheable', ($plain['headers'] ?? null) === array() && ($plain['cacheable'] ?? false) === true);
pin('...and prints no customiser meta', '', $plain['head'] ?? null);
check('...and loads no frame script or style', !str_contains((string) ($plain['assets'] ?? 'x'), 'customiser-preview'));

$live = preview(array('osc_preview' => '1'), $adminA);
pin('with the admin session and a draft it renders the draft rows', '<hero ' . $hero . '>Draft title</hero><promise>Free returns</promise>', $live['page'] ?? null);
pin('osc_customiser_draft_value is the drafted value', 'ocean', $live['brand'] ?? null);
check('...and null for another settings page', array_key_exists('other', $live) && $live['other'] === null);
pin('the response is noindex', array('X-Robots-Tag: noindex'), $live['headers'] ?? null);
pin('...and uncacheable', false, $live['cacheable'] ?? true);
pin('osc_head prints the admin origin', '<meta name="osc-customiser" content="https://admin.shop.test">', $live['head'] ?? null);
pin('...and a theme-mode preview loads no core frame script', 0, substr_count((string) ($live['assets'] ?? ''), 'osclass/customiser-preview.'));
pin('...while a page-mode one (osc_blocks=1) loads script and style', 2, substr_count((string) (preview(array('osc_preview' => '1', 'osc_blocks' => '1'), $adminA)['assets'] ?? ''), 'osclass/customiser-preview.'));

$noFlag = preview(array(), $adminA);
pin('without osc_preview the admin sees the published page', '<hero ' . $hero . '>Live</hero>', $noFlag['page'] ?? null);
check('...with no frame script or style', !str_contains((string) ($noFlag['assets'] ?? 'x'), 'customiser-preview'));
pin('an API request ignores it', '<hero ' . $hero . '>Live</hero>', preview(array('osc_preview' => '1'), $adminA, true)['page'] ?? null);
$adminB = seed_exec($admin, 'INSERT INTO ' . DB_TABLE_PREFIX . "t_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES ('B', 'b', 'x', 'b@x.test', 0)", '', array());
$noDraft = preview(array('osc_preview' => '1'), $adminB);
pin('another admin with no draft sees the published rows', '<hero ' . $hero . '>Live</hero>', $noDraft['page'] ?? null);
check('...still as a preview, so the frame answers the customiser', ($noDraft['preview'] ?? false) === true && array_key_exists('brand', $noDraft) && $noDraft['brand'] === null);

$inAdmin = preview(array('osc_preview' => '1'), $adminA, false, array('ocAdmin' => true));
check('inside the admin ?osc_preview=1 is no preview', ($inAdmin['preview'] ?? true) === false);
pin('...and the published rows render', '<hero ' . $hero . '>Live</hero>', $inAdmin['page'] ?? null);
$noSession = preview(array('osc_preview' => '1'), $adminA, false, array('loggedIn' => false));
check('an admin id without a signed-in admin session is no preview', ($noSession['preview'] ?? true) === false);
pin('...and the published rows render', '<hero ' . $hero . '>Live</hero>', $noSession['page'] ?? null);
(new CustomiserDraft('other', $adminB))->save(array('locations' => array('home' => array(array('id' => null, 'key' => 'o', 'type' => 'aisle.promise', 'config' => array('text' => 'Other theme'))))));
pin('a draft saved for another theme does not show on the active one', '<hero ' . $hero . '>Live</hero>', preview(array('osc_preview' => '1'), $adminB)['page'] ?? null);
pin('...and shows when that theme is active', '<promise>Other theme</promise>', preview(array('osc_preview' => '1'), $adminB, false, array('theme' => 'other'))['page'] ?? null);
(new CustomiserDraft('other', $adminB))->discard();

harness_section('publish');

$publishedThemes = array();
osc_add_hook('customiser_published', static function ($theme) use (&$publishedThemes) {
    $publishedThemes[] = $theme;
});
$savedPages = array();
osc_add_hook('settings_page_saved', static function ($page) use (&$savedPages) {
    $savedPages[] = $page;
});
$r = drive('customise_publish_post');
pin('publish runs customiser_published once with the theme', array('aisle'), $publishedThemes);
pin('...and settings_page_saved for the look page', array('aisle'), $savedPages);
pin('publish is 200', 200, $r['status']);
pin('...purges the page cache', array('widget'), $r['purges']);
$home = $rowsAt('home');
pin('the location now holds the drafted rows', array('aisle.hero', 'aisle.promise'), array_column($home, 's_type'));
pin('...the hero kept its id', (string) $hero, $home[0]['pk_i_id']);
pin('the look values are stored', array('ocean', 'Hi there'), array(osc_get_preference('brand', 'aisle'), osc_get_preference('footer_note', 'aisle')));
pin('the draft is gone', null, $draftKv());
pin('publishing again has nothing to publish', 409, drive('customise_publish_post')['status']);
drive('customise_draft_post', array('settings' => array('brand' => 'raspberry')));
drive('customise_discard_post');
pin('neither the 409 nor a discard runs customiser_published', array('aisle'), $publishedThemes);

harness_section('publish after the live rows changed');

$live = $rowsAt('home');
$draftRows = array_map(static fn ($r) => array('id' => $r['pk_i_id'], 'type' => $r['s_type'], 'config' => json_decode($r['s_config'], true)), array_reverse($live));
drive('customise_draft_post', array('locations' => array('home' => $draftRows)));
pin('the toggle on the Widgets screen still works', 200, drive('widget_toggle_post', array('id' => $live[0]['pk_i_id']))['status']);
$toggled = $rowsAt('home');
$r = drive('customise_publish_post');
pin('publish refuses a draft whose location changed since it began', array(409, true), array($r['status'], $r['json']['stale'] ?? null));
pin('...and writes nothing', $toggled, $rowsAt('home'));
check('...and keeps the draft', $draftKv() !== null);
pin('publish with force replaces them', 200, drive('customise_publish_post', array('force' => '1'))['status']);
pin('...in the drafted order', array_column(array_reverse($live), 'pk_i_id'), array_column($rowsAt('home'), 'pk_i_id'));
drive('customise_draft_post', array('settings' => array('footer_note' => 'Look only')));
drive('widget_toggle_post', array('id' => $live[0]['pk_i_id']));
pin('a draft of look values alone is not refused', 200, drive('customise_publish_post')['status']);
$live = $rowsAt('home');
drive('widget_toggle_post', array('id' => $live[0]['pk_i_id']));
drive('customise_draft_post', array('locations' => array('home' => array_map(static fn ($r) => array('id' => $r['pk_i_id'], 'type' => $r['s_type'], 'config' => array()), $live))));
pin('a change made before the draft began does not refuse it', 200, drive('customise_publish_post')['status']);

harness_section('settings images');

$png = tempnam(sys_get_temp_dir(), 'osccust');
$im  = imagecreatetruecolor(4, 4);
imagepng($im, $png);
$file = static fn (): array => array('logo' => array('name' => 'logo.png', 'type' => 'image/png', 'tmp_name' => $png, 'error' => UPLOAD_ERR_OK, 'size' => filesize($png)));

pin('an image posted with the draft is 200', 200, drive('customise_draft_post', array(), $file())['status']);
$first = (int) $draftKv()['settings']['logo'];
check('...stores a setting resource', $first > 0 && $resourceCount($first) === 1);
drive('customise_draft_post', array(), $file());
$second = (int) $draftKv()['settings']['logo'];
check('a second upload replaces the first draft-only image', $second !== $first && $resourceCount($first) === 0 && $resourceCount($second) === 1);
pin('discard is 200', 200, drive('customise_discard_post')['status']);
pin('...deletes the draft-only image', 0, $resourceCount($second));
pin('...and the draft', null, $draftKv());

drive('customise_draft_post', array(), $file());
$published = (int) $draftKv()['settings']['logo'];
drive('customise_publish_post');
pin('a published image is the stored preference', (string) $published, (string) osc_get_preference('logo', 'aisle'));
drive('customise_draft_post', array('settings' => array('brand' => 'raspberry')));
drive('customise_discard_post');
pin('discard keeps the image the published preference holds', 1, $resourceCount($published));
drive('customise_draft_post', array(), $file());
$replacement = (int) $draftKv()['settings']['logo'];
drive('customise_publish_post');
check('publishing a new image deletes the old published one', $resourceCount($published) === 0 && $resourceCount($replacement) === 1);
@unlink($png);

harness_section('the widgets screen keeps a hidden row hidden');

$hidden = WidgetStore::add(array('s_location' => 'home', 'e_kind' => 'html', 's_description' => 'Hidden', 's_content' => '', 's_type' => 'aisle.promise', 's_config' => '{"text":"a","_enabled":false}', 'i_order' => 5));
drive('edit_widget_post', array('id' => (string) $hidden, 'description' => 'Hidden', 's_type' => 'aisle.promise', 'config' => array('text' => 'b')));
pin('editing it keeps _enabled false', '{"text":"b","_enabled":false}', WidgetStore::find($hidden)['s_config']);
$shown = WidgetStore::add(array('s_location' => 'home', 'e_kind' => 'html', 's_description' => 'Shown', 's_content' => '', 's_type' => 'aisle.promise', 's_config' => '{"text":"a"}', 'i_order' => 6));
drive('edit_widget_post', array('id' => (string) $shown, 'description' => 'Shown', 's_type' => 'aisle.promise', 'config' => array('text' => 'b')));
pin('a row with no key gains none', '{"text":"b"}', WidgetStore::find($shown)['s_config']);

harness_section('a database error is not "no draft"');

drive('customise_draft_post', array('settings' => array('brand' => 'ocean')));
$before = $draftKv();
$kv     = DB_TABLE_PREFIX . 't_key_value';
$admin->query("RENAME TABLE $kv TO {$kv}_away");
$threw = false;
try {
    $draftKv();
} catch (\mindstellar\database\DbException $e) {
    $threw = true;
}
check('CustomiserDraft::read() throws instead of answering null', $threw);
$r = drive('customise_draft_post', array('settings' => array('footer_note' => 'Hi')));
pin('a draft save answers 500', 500, $r['status']);
$r = drive('customise_publish_post');
pin('a publish answers 500', 500, $r['status']);
$admin->query("RENAME TABLE {$kv}_away TO $kv");
pin('...and the stored draft is untouched', $before, $draftKv());

$away = WidgetStore::add(array('s_location' => 'home', 'e_kind' => 'html', 's_description' => 'Away', 's_content' => '', 's_type' => 'aisle.promise', 's_config' => '{}', 'i_order' => 9));
$wt   = DB_TABLE_PREFIX . 't_widget';
$admin->query("RENAME TABLE $wt TO {$wt}_away");
$threw = false;
try {
    (new \mindstellar\widgets\Customiser(osc_customiser_spec(), new CustomiserDraft('aisle', $GLOBALS['adminId'])))
        ->saveDraft(array('home' => array(array('id' => (string) $away, 'type' => 'aisle.promise', 'config' => array()))), array(), array());
} catch (\Throwable $e) {
    $threw = true;
}
$admin->query("RENAME TABLE {$wt}_away TO $wt");
check('a failed lookup of the live ids is thrown, not read as "all rows are new"', $threw);
drive('customise_discard_post');

harness_section('the orphan sweep and settings images');

require_once ABS_PATH . 'oc-includes/osclass/helpers/hResources.php';
$rt     = DB_TABLE_PREFIX . 't_resource';
$png2   = tempnam(sys_get_temp_dir(), 'osccust');
imagepng(imagecreatetruecolor(4, 4), $png2);
$upload = static fn (): array => array('logo' => array('name' => 'logo.png', 'type' => 'image/png', 'tmp_name' => $png2, 'error' => UPLOAD_ERR_OK, 'size' => filesize($png2)));
$old    = static function (int $id) use ($admin, $rt): void {
    $admin->query("UPDATE $rt SET dt_created = '2020-01-01 00:00:00' WHERE pk_i_id = $id");
};
$sweep = static function () use ($admin, $rt): void {
    osc_set_preference('resource_sweep_offset', 0, 'osclass', 'INTEGER');
    osc_sweep_orphan_resources();
};

drive('customise_draft_post', array(), $upload());
$drafted = (int) $draftKv()['settings']['logo'];
$old($drafted);
$sweep();
pin('a settings image a live draft names is kept', 1, $resourceCount($drafted));
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . "t_key_value WHERE s_group = 'customiser'");
$sweep();
pin('...and swept once the draft has expired', 0, $resourceCount($drafted));

drive('customise_draft_post', array(), $upload());
$kept = (int) $draftKv()['settings']['logo'];
drive('customise_publish_post');
$old($kept);
$sweep();
pin('a settings image a preference points to is kept', 1, $resourceCount($kept));
@unlink($png2);

harness_section('a widget type that names the sections it fits');

use mindstellar\widgets\WidgetRegistry;

$registry = WidgetRegistry::getInstance();
$render   = static function () {
    echo '<x>';
};
$registry->register('only.home', array('label' => 'Home only', 'render' => $render, 'locations' => array('home')));
$registry->register('only.footer', array('label' => 'Footer only', 'render' => $render, 'locations' => array('footer', 'footer')));
pin('locations are stored, duplicates dropped', array('footer'), $registry->get('only.footer')['locations']);
pin('no locations key means everywhere', array(), $registry->get('aisle.hero')['locations']);
check('a listed section fits', $registry->fits('only.home', 'home'));
check('another section does not fit', !$registry->fits('only.home', 'footer'));
check('an unlimited type fits any section', $registry->fits('aisle.hero', 'footer'));
check('an unknown type fits nowhere', !$registry->fits('no.such', 'home'));
foreach (array(array(), 'home', array('Home'), array(''), array(5), array(str_repeat('a', 41)), array('home page')) as $bad) {
    $threw = false;
    try {
        $registry->register('bad.type', array('label' => 'Bad', 'render' => $render, 'locations' => $bad));
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    check('locations ' . json_encode($bad) . ' is refused', $threw);
}
check('a refused type is not registered', $registry->get('bad.type') === null);

$admin->query("DELETE FROM $table");
$post = array('location' => 'footer', 's_type' => 'only.home', 'description' => 'Hero');
$r    = drive('add_widget_post', $post);
pin('add: a type in a section it does not fit adds nothing', array(), $rowsAt('footer'));
check('...and flashes an error', in_array('error:Home only cannot be placed in this section.', $GLOBALS['flashes'] ?? array(), true));
$r = drive('add_widget_post', array('location' => 'home') + $post);
pin('add: the right section still works', 1, count($rowsAt('home')));

$r = drive('widget_create_post', $post);
pin('create: a misfit is error 1', 1, $r['json']['error'] ?? null);
pin('...with a message', 'Home only cannot be placed in this section.', $r['json']['message'] ?? null);
pin('...and adds nothing', array(), $rowsAt('footer'));
$r = drive('widget_create_post', array('location' => 'home') + $post);
pin('create: the right section works', 0, $r['json']['error'] ?? null);
$r = drive('widget_create_post', array('location' => 'footer', 's_type' => 'aisle.hero'));
pin('create: an unlimited type works anywhere', 0, $r['json']['error'] ?? null);

$typed  = $rowsAt('home')[0]['pk_i_id'];
$legacy = WidgetStore::add(array('s_location' => 'home', 'e_kind' => 'html', 's_description' => 'Raw', 's_content' => '<p>x</p>', 'i_order' => 5));
$r      = drive('widget_move_post', array('location' => 'footer', 'id' => $typed, 'ids' => array($typed)));
pin('move: a typed widget cannot go to a section it does not fit', 1, $r['json']['error'] ?? null);
pin('...with a message', 'Home only cannot be placed in this section.', $r['json']['message'] ?? null);
pin('...and stays where it was', 'home', $admin->query("SELECT s_location FROM $table WHERE pk_i_id = $typed")->fetch_row()[0]);
$r = drive('widget_move_post', array('location' => 'footer', 'id' => $legacy, 'ids' => array($legacy)));
pin('move: a legacy HTML widget is never limited', 0, $r['json']['error'] ?? null);

$heroAt = WidgetStore::add(array('s_location' => 'footer', 'e_kind' => 'html', 's_description' => 'Hero', 's_content' => '', 's_type' => 'aisle.hero', 's_config' => '{}', 'i_order' => 9));
$GLOBALS['flashes'] = array();
$r = drive('edit_widget_post', array('id' => $heroAt, 's_type' => 'only.home', 'description' => 'Hero'));
pin('edit: a type that does not fit the stored section is refused', 'aisle.hero', $admin->query("SELECT s_type FROM $table WHERE pk_i_id = $heroAt")->fetch_row()[0]);
check('...and flashes an error', in_array('error:Home only cannot be placed in this section.', $GLOBALS['flashes'] ?? array(), true));
$r = drive('edit_widget_post', array('id' => $heroAt, 's_type' => 'only.footer', 'description' => 'Hero'));
pin('edit: a type that fits is saved', 'only.footer', $admin->query("SELECT s_type FROM $table WHERE pk_i_id = $heroAt")->fetch_row()[0]);

harness_section('Widget actions return to the Widgets screen, even with page_builder_id');
$widgetsUrl = osc_admin_base_url(true) . '?page=appearance&action=widgets';
$returns    = static function (string $action, array $post, string $flash) use ($widgetsUrl): void {
    $GLOBALS['flashes'] = array();
    $r = drive($action, $post + array('page_builder_id' => '7'));
    pin("$action returns to the Widgets screen", array($widgetsUrl), $r['redirects']);
    pin("...and flashes \"$flash\"", array('ok:' . $flash), $GLOBALS['flashes']);
};
$returns('add_widget_post', array('location' => 'home', 'description' => 'Plain', 'content' => '<p>a</p>'), 'Widget added correctly');
$plain = (int) $admin->query("SELECT MAX(pk_i_id) FROM $table")->fetch_row()[0];
$returns('edit_widget_post', array('id' => (string) $plain, 'description' => 'Plain', 'content' => '<p>b</p>'), 'Widget updated correctly');
$returns('delete_widget', array('id' => (string) $plain), 'Widget removed correctly');
check('the old page block undo is gone', !class_exists('mindstellar\widgets\PageBlockUndo'));

$admin->query("DELETE FROM $table");
customiser_kit_declare(array('locations' => array(
    'home'   => array('label' => 'Home page', 'types' => array('only.home', 'only.footer'), 'preview' => WEB_PATH),
    'footer' => array('label' => 'Footer', 'types' => array('only.home', 'only.footer'), 'preview' => WEB_PATH),
)));
$r = drive('customise_draft_post', array('locations' => array('footer' => array(array('type' => 'only.home')))));
pin('customiser: a type that does not fit the section is 422', 422, $r['status']);
check('...with the existing refusal message', strpos(json_encode($r['json']), 'is not a section this page can hold') !== false);
$r = drive('customise_draft_post', array('locations' => array('footer' => array(array('type' => 'only.footer')))));
pin('customiser: a fitting type is saved', 200, $r['status']);
$offered = \mindstellar\widgets\CustomiserScreen::build(osc_customiser_spec(), null);
pin('the screen offers only the types that fit each section', array('home' => array('only.home'), 'footer' => array('only.footer')), array_map(static fn ($l) => $l['types'], $offered['locations']));
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . "t_key_value WHERE s_group = 'customiser'");
customiser_kit_declare();

harness_section('page mode: the page spec');

use mindstellar\widgets\Customiser;

require_once ABS_PATH . 'oc-includes/osclass/helpers/hPageTemplates.php';
if (!function_exists('osc_core_url')) {
    function osc_core_url($name, $args = array())
    {
        return \mindstellar\routing\CoreRoutes::url($name, $args);
    }
}
$pagesT  = DB_TABLE_PREFIX . 't_pages';
$builder = static function (int $id) use ($admin, $pagesT): void {
    $admin->query("UPDATE $pagesT SET s_meta = '{\"template\":\"core.page_builder\"}' WHERE pk_i_id = $id");
};
$p7    = seed_page($admin, 'seven', 'Seven');
$p8    = seed_page($admin, 'eight', 'Eight');
$plain = seed_page($admin, 'plain', 'Plain');
$builder($p7);
$builder($p8);
$l7 = 'page.' . $p7;
$l8 = 'page.' . $p8;
$admin->query("DELETE FROM $table");

osc_remove_theme_support('customiser');
$ps     = PageBlocks::spec($p7);
$pTypes = $ps['locations'][$l7]['types'] ?? array();
pin('a theme with no customiser still gets a page spec with one location', array($l7), array_keys($ps['locations'] ?? array()));
pin('...labelled with the page title', 'Seven', $ps['locations'][$l7]['label'] ?? null);
pin('...listing builder and content types', 4, count(array_intersect(array('core.columns', 'core.heading', 'core.rich_text', 'core.custom_code'), $pTypes)));
check('...and no type limited to other sections', !in_array('only.home', $pTypes, true));
pin('...and no look fields', array('page' => '', 'fields' => array()), $ps['settings'] ?? null);
pin('...swatches and live are empty', array(array(), array()), array($ps['swatches'] ?? null, $ps['live'] ?? null));
check('...the preview is the page on this site', str_starts_with((string) ($ps['locations'][$l7]['preview'] ?? ''), WEB_PATH) && str_contains((string) $ps['locations'][$l7]['preview'], (string) $p7));
pin('a page that is not a builder page has no page spec', null, PageBlocks::spec($plain));
pin('a missing page has no page spec', null, PageBlocks::spec(999999));
pin('...and no page customiser', null, Customiser::forPage(999999));
pin('a classic page is a plain page', true, PageBlocks::isPlainPage($plain));
pin('a builder page is not a plain page', false, PageBlocks::isPlainPage($p7));
pin('a missing page is not a plain page', false, PageBlocks::isPlainPage(999999));

$p9 = seed_page($admin, 'nine', 'Nine');
$builder($p9);
pin('a plain page gets no Page text block', false, PageBlocks::seedPageText($plain));
pin('a builder page with text and no blocks gets a Page text block', true, PageBlocks::seedPageText($p9));
pin('...as its only block', array('core.page_text'), array_values(array_column(WidgetStore::rowsAt('page.' . $p9), 's_type')));
pin('...and only once', false, PageBlocks::seedPageText($p9));
$admin->query('UPDATE ' . DB_TABLE_PREFIX . "t_pages_description SET s_text = '<p> </p>' WHERE fk_i_pages_id = $p8");
pin('a builder page with no text gets none', false, PageBlocks::seedPageText($p8));
$admin->query("DELETE FROM $table WHERE s_location = 'page.$p9'");
customiser_kit_declare();

harness_section('page mode: drafts are per page');

$GLOBALS['moderator'] = false;
drive('customise_draft_post', array('settings' => array('brand' => 'ocean')));
$themeDraft = $draftKv();
$c7 = Customiser::forPage($p7);
$r  = $c7->saveDraft(array($l7 => array(array('type' => 'core.heading', 'config' => array('text' => 'Hello')))), array(), array());
pin('a page draft is 200', 200, $r['status']);
$kv7 = osc_kv_get('customiser', $l7 . ':' . $adminA);
pin('...stored under page.N:<admin>', array('core.heading'), array_column($kv7['locations'][$l7] ?? array(), 'type'));
pin('...the theme draft is untouched', $themeDraft, $draftKv());
pin('...page 8 has no draft', null, Customiser::forPage($p8)->draft()->read());
pin('a page customiser refuses another page\'s location', 422, $c7->saveDraft(array($l8 => array(array('type' => 'core.heading'))), array(), array())['status']);
pin('...and look values', 422, $c7->saveDraft(array(), array(), array('brand' => 'ocean'))['status']);

harness_section('page mode: publish');

$h8   = WidgetStore::add(array('s_location' => $l8, 'e_kind' => 'html', 's_description' => 'P8', 's_content' => '', 's_type' => 'core.heading', 's_config' => '{"text":"Eight"}', 'i_order' => 0));
WidgetStore::add(array('s_location' => 'home', 'e_kind' => 'html', 's_description' => 'Hero', 's_content' => '', 's_type' => 'aisle.hero', 's_config' => '{}', 'i_order' => 0));
$was8 = $rowsAt($l8);
$wasH = $rowsAt('home');
$publishedThemes = array();
$r = $c7->publish();
pin('a page publish is 200', 200, $r['status']);
pin('...writes the drafted rows to page.N', array('core.heading'), array_column($rowsAt($l7), 's_type'));
pin('...leaves page 8 alone', $was8, $rowsAt($l8));
pin('...and the theme location', $wasH, $rowsAt('home'));
pin('...runs no customiser_published', array(), $publishedThemes);
pin('...forgets the page draft', null, $c7->draft()->read());
pin('...and keeps the theme draft', $themeDraft, $draftKv());
drive('customise_discard_post');

harness_section('page mode: stale is per page');

$live7 = $rowsAt($l7);
$c7->saveDraft(array($l7 => array(array('id' => $live7[0]['pk_i_id'], 'type' => 'core.heading', 'config' => array('text' => 'Seven again')))), array(), array());
$c8 = Customiser::forPage($p8);
$c8->saveDraft(array($l8 => array(array('id' => (string) $h8, 'type' => 'core.heading', 'config' => array('text' => 'Eight again')))), array(), array());
WidgetStore::update($h8, array('s_description' => 'Changed elsewhere'));
$r = $c8->publish();
pin('a page whose live blocks changed is stale', array(409, true), array($r['status'], $r['stale'] ?? null));
pin('...another page is not', 200, $c7->publish()['status']);
$c8->discard();

harness_section('page mode: locked rows');

pin('locked: an untyped row', true, Customiser::locked(null, false));
pin('locked: a super_admin type for a moderator', true, Customiser::locked(array('capability' => 'super_admin'), true));
pin('not locked: a super_admin type for an admin', false, Customiser::locked(array('capability' => 'super_admin'), false));
pin('not locked: an admin type for a moderator', false, Customiser::locked(array('capability' => 'admin'), true));

$admin->query("DELETE FROM $table");
$cc   = WidgetStore::add(array('s_location' => $l7, 'e_kind' => 'html', 's_description' => 'Tracker', 's_content' => '', 's_type' => 'core.custom_code', 's_config' => '{"code":"<script>ok()</script>"}', 'i_order' => 0));
$raw  = WidgetStore::add(array('s_location' => $l7, 'e_kind' => 'html', 's_description' => 'Old HTML', 's_content' => '<p>raw</p>', 'i_order' => 1));
$head = WidgetStore::add(array('s_location' => $l7, 'e_kind' => 'html', 's_description' => 'Heading', 's_content' => '', 's_type' => 'core.heading', 's_config' => '{"text":"Hi"}', 'i_order' => 2));
$lockedCols = static fn (): array => $admin->query("SELECT s_description, s_content, s_type, s_config FROM $table WHERE pk_i_id IN ($cc, $raw) ORDER BY pk_i_id")->fetch_all(MYSQLI_ASSOC);
$liveBefore = $lockedCols();

$GLOBALS['moderator'] = true;
$m7  = Customiser::forPage($p7);
$all = static fn (array $over = array()): array => array_values(array_filter(array_replace(array(
    'head' => array('id' => (string) $head, 'type' => 'core.heading', 'config' => array('text' => 'Moved')),
    'code' => array('id' => (string) $cc, 'type' => 'core.custom_code', 'description' => 'Renamed', 'enabled' => '0', 'config' => array('code' => '<script>evil()</script>')),
    'raw'  => array('id' => (string) $raw, 'type' => '', 'config' => array('x' => 'y')),
), $over)));
$r = $m7->saveDraft(array($l7 => $all()), array($l7 => $all()), array());
pin('moderator: moving a Custom Code and an untyped row is 200', 200, $r['status']);
$drafted = $m7->draft()->read()['locations'][$l7] ?? array();
pin('...in the posted order', array($head, $cc, $raw), array_map('intval', array_column($drafted, 'id')));
pin('...the Custom Code row keeps its live config', array('code' => '<script>ok()</script>'), $drafted[1]['config'] ?? null);
pin('...and its live name', 'Tracker', $drafted[1]['description'] ?? null);
pin('...the untyped row stays untyped with no config', array('', array()), array($drafted[2]['type'] ?? null, $drafted[2]['config'] ?? null));
pin('moderator: dropping a Custom Code row is 422', 422, $m7->saveDraft(array($l7 => $all(array('code' => null))), array(), array())['status']);
pin('moderator: dropping an untyped row is 422', 422, $m7->saveDraft(array($l7 => $all(array('raw' => null))), array(), array())['status']);
$added   = $all();
$added[] = array('type' => 'core.custom_code', 'config' => array('code' => '<script>new()</script>'));
pin('moderator: adding a Custom Code row is 422', 422, $m7->saveDraft(array($l7 => $added), array($l7 => $added), array())['status']);
pin('...and the draft is as it was', $drafted, $m7->draft()->read()['locations'][$l7] ?? null);

$r = $m7->publish();
pin('moderator: publish is 200', 200, $r['status']);
pin('...the rows are in the drafted order', array((string) $head, (string) $cc, (string) $raw), array_column($rowsAt($l7), 'pk_i_id'));
pin('...the locked rows keep every live column', $liveBefore, $lockedCols());

$m7->saveDraft(array($l7 => $all()), array($l7 => $all()), array());
$draft = $m7->draft()->read();
$draft['locations'][$l7] = array_values(array_filter($draft['locations'][$l7], static fn ($r) => (int) $r['id'] !== $cc));
$m7->draft()->save($draft);
pin('moderator: a forced publish of a draft without a live Custom Code row is 422', 422, $m7->publish(true)['status']);
check('...and the row is still there', WidgetStore::find($cc) !== null);
$m7->discard();
$GLOBALS['moderator'] = false;

$a7 = Customiser::forPage($p7);
$r  = $a7->saveDraft(array($l7 => array(
    array('id' => (string) $raw, 'type' => '', 'config' => array('x' => 'y')),
    array('id' => (string) $cc, 'type' => 'core.custom_code', 'config' => array('code' => '<b>admin</b>')),
)), array($l7 => array(array(), array('config' => array('code' => '<b>admin</b>')))), array());
pin('admin: dropping a row and editing Custom Code is 200', 200, $r['status']);
pin('admin: an untyped row is still not editable', array(), $a7->draft()->read()['locations'][$l7][0]['config'] ?? null);
$a7->publish();
pin('admin: the untyped row\'s s_content survives publish', '<p>raw</p>', WidgetStore::find($raw)['s_content'] ?? null);
pin('...it moved first', '0', WidgetStore::find($raw)['i_order'] ?? null);
pin('admin: Custom Code was saved', '{"code":"<b>admin<\/b>"}', WidgetStore::find($cc)['s_config'] ?? null);
$a7->saveDraft(array($l7 => array(
    array('key' => 'n1', 'type' => 'core.heading', 'config' => array('text' => 'Drafted')),
    array('id' => (string) $raw, 'type' => '', 'config' => array()),
)), array(), array());
drive('customise_draft_post', array('locations' => array('home' => array(array('type' => 'aisle.promise', 'config' => array('text' => 'Theme draft'))))));
$pagePreview = (string) (preview(array('osc_preview' => '1', 'osc_blocks' => '1'), $adminA, false, array('location' => $l7))['page'] ?? '');
check('the page preview shows the drafted block', str_contains($pagePreview, 'Drafted'));
check('...read from the page draft, not the theme draft', !str_contains($pagePreview, 'Theme draft'));
check('...marked for the picker', str_contains($pagePreview, 'data-osc-block="n1"'));
$themeMode = preview(array('osc_preview' => '1'), $adminA, false, array('location' => $l7));
check('a theme-mode preview of a page marks no blocks', !str_contains((string) $themeMode['page'], 'data-osc-block'));
check('...and loads no frame script', !str_contains((string) $themeMode['assets'], 'osclass/customiser-preview.'));
check('...but still shows the page draft', str_contains((string) $themeMode['page'], 'Drafted'));
check('a page-mode preview loads the frame script and style', str_contains((string) preview(array('osc_preview' => '1', 'osc_blocks' => '1'), $adminA, false, array('location' => $l7))['assets'], 'osclass/customiser-preview.'));
foreach (array('a visitor' => array(array('osc_preview' => '1', 'osc_blocks' => '1'), 0), 'the admin without osc_preview' => array(array(), $adminA)) as $who => $as) {
    $seen = (string) (preview($as[0], $as[1], false, array('location' => $l7))['page'] ?? 'x');
    check("$who sees the live page, not the draft", str_contains($seen, '<b>admin</b>') && !str_contains($seen, 'Drafted'));
    check('...with no block markers', !str_contains($seen, 'data-osc-block'));
}
drive('customise_discard_post');
check('...and the legacy row kept in the draft, after it', str_contains($pagePreview, '<p>raw</p>') && strpos($pagePreview, 'Drafted') < strpos($pagePreview, '<p>raw</p>'));
check('...but not the row the draft left out', !str_contains($pagePreview, '<b>admin</b>'));
$a7->discard();
$a7->saveDraft(array($l7 => array(array('id' => (string) $cc, 'type' => 'core.custom_code', 'config' => array()))), array(), array());
$a7->publish();
pin('admin: an untyped row may be removed', null, WidgetStore::find($raw));
pin('admin: a new untyped row is refused', 422, $a7->saveDraft(array($l7 => array(array('type' => ''))), array(), array())['status']);
$a7->discard();
$admin->query("DELETE FROM $table");
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . "t_key_value WHERE s_group = 'customiser'");

harness_section('PageBlocks: summary and drafts');

$row = static fn (string $loc, string $type, string $desc, array $cfg = array(), int $order = 0): int => WidgetStore::add(array('s_location' => $loc, 'e_kind' => 'html', 's_description' => $desc, 's_content' => '', 's_type' => $type, 's_config' => json_encode($cfg), 'i_order' => $order));
$row($l7, 'core.heading', 'Intro', array(), 0);
$row($l7, '', '', array(), 1);
$row($l7, 'gone.type', '', array(), 2);
$row($l7, 'core.rich_text', '', array('_enabled' => false), 3);
$sum = PageBlocks::summary($p7, $adminA);
pin('summary counts the live rows', 4, $sum['count'] ?? null);
pin('...in order, named by description, a legacy row as Custom HTML like the block list', array('Intro', 'Custom HTML', 'Custom HTML'), array_slice(array_column($sum['blocks'] ?? array(), 'name'), 0, 3));
pin('...an untyped row is Custom HTML', 'Custom HTML', $sum['blocks'][1]['name'] ?? null);
pin('...a row with no description takes its type label', WidgetRegistry::getInstance()->get('core.rich_text')['label'], $sum['blocks'][3]['name'] ?? null);
pin('...an unregistered type is hidden', true, $sum['blocks'][2]['hidden'] ?? null);
pin('...a disabled row is hidden', true, $sum['blocks'][3]['hidden'] ?? null);
pin('...a plain row is not', false, $sum['blocks'][0]['hidden'] ?? null);
pin('...the icon is the type icon', WidgetRegistry::getInstance()->get('core.heading')['icon'], $sum['blocks'][0]['icon'] ?? null);
pin('...the edit link', osc_admin_base_url(true) . '?page=pages&action=blocks&id=' . $p7, $sum['edit_url'] ?? null);
pin('no draft yet', false, $sum['draft'] ?? null);
pin('a classic page has no summary', null, PageBlocks::summary($plain, $adminA));
pin('a missing page has no summary', null, PageBlocks::summary(999999, $adminA));

$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . "t_key_value WHERE s_group = 'customiser'");
$mkDraft = static function (string $slug, int $who, string $theme = '') {
    osc_kv_set('customiser', $slug . ':' . $who, array('theme' => $theme === '' ? $slug : $theme, 'locations' => array(), 'settings' => array(), 'base' => array(), 'updated_at' => time()), 600);
};
$mkDraft($l8, $adminA);
$mkDraft('storefront', $adminA);
pin('page 8\'s draft and the theme draft are not page 7\'s', false, PageBlocks::summary($p7, $adminA)['draft']);
$mkDraft($l7, $adminA + 1);
pin('another admin\'s draft is not this admin\'s', false, PageBlocks::summary($p7, $adminA)['draft']);
$mkDraft($l7, $adminA);
pin('this admin\'s page draft is reported', true, PageBlocks::summary($p7, $adminA)['draft']);

$mkDraft('page.' . $p7 . '0', $adminA);
$mkDraft($l7, $adminA + 1);
$mkDraft($l7, $adminA + 2);
$left = static fn (): array => array_column(osc_kv_list('customiser', 100), 'key');
pin('forgetDrafts removes every draft of the page', 3, PageBlocks::forgetDrafts($p7));
$kept = array_values(array_filter($left(), static fn ($k) => !str_starts_with($k, 'page.' . $p7 . ':')));
sort($kept);
$want = array($l8 . ':' . $adminA, 'page.' . $p7 . '0:' . $adminA, 'storefront:' . $adminA);
sort($want);
pin('...and keeps the other pages and the theme', $want, $kept);
pin('...a page whose id starts with the same digits keeps its draft', true, osc_kv_get('customiser', 'page.' . $p7 . '0:' . $adminA) !== null);
pin('...none of page 7 is left', 0, count(array_filter($left(), static fn ($k) => str_starts_with($k, 'page.' . $p7 . ':'))));
pin('forgetDrafts on a page with none', 0, PageBlocks::forgetDrafts($p7));

$code = harness_result();
ob_end_flush();
exit($code);
