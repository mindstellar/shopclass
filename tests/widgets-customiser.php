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
 * none), uncached and noindex.
 *
 * DB-backed.  Usage:  php tests/widgets-customiser.php
 */

// Buffered from the start, so the JSON answers can still set their status code.
ob_start();

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

const SCRATCH = 'osc_widgets_customiser';

$admin = scratchdb_session(SCRATCH);
define('OC_ADMIN', true);
require_once __DIR__ . '/lib/customiser-kit.php';
customiser_kit_declare();

use mindstellar\widgets\CustomiserDraft;
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

$live = preview(array('osc_preview' => '1'), $adminA);
pin('with the admin session and a draft it renders the draft rows', '<hero ' . $hero . '>Draft title</hero><promise>Free returns</promise>', $live['page'] ?? null);
pin('osc_customiser_draft_value is the drafted value', 'ocean', $live['brand'] ?? null);
check('...and null for another settings page', array_key_exists('other', $live) && $live['other'] === null);
pin('the response is noindex', array('X-Robots-Tag: noindex'), $live['headers'] ?? null);
pin('...and uncacheable', false, $live['cacheable'] ?? true);
pin('osc_head prints the admin origin', '<meta name="osc-customiser" content="https://admin.shop.test">', $live['head'] ?? null);

pin('without osc_preview the admin sees the published page', '<hero ' . $hero . '>Live</hero>', preview(array(), $adminA)['page'] ?? null);
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

$code = harness_result();
ob_end_flush();
exit($code);
