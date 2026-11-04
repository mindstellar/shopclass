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
 * Pages > Edit blocks: ?page=pages&action=blocks&id=N opens the Customise screen for one Page
 * builder page, and blocks_draft_post, blocks_publish_post and blocks_discard_post keep that
 * page's own draft. A missing CSRF token is refused, a page that is not a builder page is sent
 * back (404 in JSON), a moderator without Pages access never reaches it, and the theme's
 * Customise actions answer exactly as before.
 *
 * DB-backed.  Usage:  php tests/admin-page-blocks-screen.php
 */

// Buffered from the start, so the JSON answers can still set their status code.
ob_start();

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

const SCRATCH = 'osc_admin_page_blocks';

$admin = scratchdb_session(SCRATCH);
harness_reset_container();
define('OC_ADMIN', true);

/** A request without the token stops here, as the real check stops the request. */
final class CsrfRefused extends RuntimeException
{
}
function osc_csrf_check()
{
    $GLOBALS['csrfChecks'][] = Params::getParam('action');
    if (Params::getParam('octoken') !== 't') {
        throw new CsrfRefused();
    }
}

$GLOBALS['themesDir'] = sys_get_temp_dir() . '/osc_page_blocks_themes_' . getmypid() . '/';
@mkdir($GLOBALS['themesDir'] . 'aisle', 0777, true);
file_put_contents($GLOBALS['themesDir'] . 'aisle/index.php', "<?php\n/*\nTheme Name: aisle\n*/\n");
register_shutdown_function(static function () {
    @unlink($GLOBALS['themesDir'] . 'aisle/index.php');
    @rmdir($GLOBALS['themesDir'] . 'aisle');
    @rmdir($GLOBALS['themesDir']);
});
function osc_themes_path()
{
    return $GLOBALS['themesDir'];
}

require_once __DIR__ . '/lib/customiser-kit.php';
customiser_kit_declare();
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPageTemplates.php';
require_once ABS_PATH . 'oc-includes/osclass/formatting.php';
if (!function_exists('osc_core_url')) {
    function osc_core_url($name, $args = array())
    {
        return \mindstellar\routing\CoreRoutes::url($name, $args);
    }
}

/** The real gate stops the request after its redirect; here that is an exception. */
final class GateStopped extends RuntimeException
{
}

/** The base controller, stubbed: the moderator gate as the real one has it, redirects and the view recorded. */
class AdminSecBaseModel
{
    protected $action;

    protected $page;

    public function __construct()
    {
        $this->action = Params::getParam('action');
        $this->page   = Params::getParam('page');
        if (osc_is_moderator() && !in_array($this->page, \mindstellar\admin\ModeratorAccess::pages(), false)) {
            $this->redirectTo(osc_admin_base_url());

            throw new GateStopped();
        }
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
        $GLOBALS['exported'][$key] = $value;
    }

    public function doView($view)
    {
        $GLOBALS['views'][] = $view;
    }

    protected function refuseOnDemo($redirectUrl = null)
    {
        return false;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminPages.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminAppearance.php';

$table  = DB_TABLE_PREFIX . 't_widget';
$adminA = seed_exec($admin, 'INSERT INTO ' . DB_TABLE_PREFIX . "t_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES ('A', 'a', 'x', 'a@x.test', 0)", '', array());
$GLOBALS['adminId'] = $adminA;
Preference::getInstance()->replace('theme', 'aisle', 'osclass', 'STRING');

/** One admin request: the controller's JSON, status, redirects, view, exports and flashes. */
function drive(string $controller, array $get, array $post = array()): array
{
    $_GET  = $get;
    $_POST = $post;
    Params::init();
    $GLOBALS['redirects']  = array();
    $GLOBALS['views']      = array();
    $GLOBALS['exported']   = array();
    $GLOBALS['flashes']    = array();
    $GLOBALS['csrfChecks'] = array();
    unset($GLOBALS['osc_page_cache_purge']);
    http_response_code(200);
    $stopped = '';
    ob_start();
    try {
        (new $controller())->doModel();
    } catch (CsrfRefused $e) {
        $stopped = 'csrf';
    } catch (GateStopped $e) {
        $stopped = 'gate';
    }
    $out = (string) ob_get_clean();

    return array(
        'status'    => http_response_code(),
        'json'      => json_decode($out, true),
        'raw'       => $out,
        'stopped'   => $stopped,
        'redirects' => $GLOBALS['redirects'],
        'views'     => $GLOBALS['views'],
        'screen'    => $GLOBALS['exported']['customiser'] ?? null,
        'flashes'   => $GLOBALS['flashes'],
        'purges'    => $GLOBALS['osc_page_cache_purge'] ?? array(),
    );
}

/** A request to the pages controller for page $id, with the token unless $token is false. */
function blocks(string $action, int $id, array $post = array(), bool $token = true): array
{
    $get = array('page' => 'pages', 'action' => $action, 'id' => (string) $id);
    if ($token) {
        $get['octoken'] = 't';
    }

    return drive('CAdminPages', $get, $post);
}

/** A request to the theme's Customise actions, with the token. */
function customise(string $action, array $post = array()): array
{
    return drive('CAdminAppearance', array('page' => 'appearance', 'action' => $action, 'octoken' => 't'), $post);
}

$pagesT = DB_TABLE_PREFIX . 't_pages';
$p7     = seed_page($admin, 'seven', 'Seven');
$p8     = seed_page($admin, 'eight', 'Eight');
$plain  = seed_page($admin, 'plain', 'Plain');
foreach (array($p7, $p8) as $id) {
    $admin->query("UPDATE $pagesT SET s_meta = '{\"template\":\"core.page_builder\"}' WHERE pk_i_id = $id");
}
$l7       = 'page.' . $p7;
$pageForm = 'https://admin.shop.test/oc-admin/index.php?page=pages&action=edit&id=';
$kv       = static fn (string $scope) => osc_kv_get('customiser', $scope . ':' . $adminA);
$live     = static function (string $location) use ($admin, $table): array {
    $out = array();
    $res = $admin->query("SELECT s_type, s_config FROM $table WHERE s_location = '$location' ORDER BY i_order, pk_i_id");
    while ($r = $res->fetch_assoc()) {
        $out[] = $r;
    }

    return $out;
};

harness_section('the Edit blocks screen');

$r = blocks('blocks', $p7, array(), false);
pin('it draws the Customise view', array('appearance/customise.php'), $r['views']);
$screen = (array) $r['screen'];
pin('...in page mode', 'page', $screen['mode'] ?? null);
pin('...for this page', array('id' => $p7, 'title' => 'Seven', 'edit_url' => $pageForm . $p7), $screen['target'] ?? null);
pin('...with its one location', array($l7), array_keys($screen['locations'] ?? array()));
$preview = (string) ($screen['locations'][$l7]['preview_url'] ?? '');
check('...previewing the page with osc_preview=1 and osc_blocks=1', str_starts_with($preview, WEB_PATH) && str_contains($preview, (string) $p7) && str_ends_with($preview, 'osc_preview=1&osc_blocks=1'));
pin('...no look fields', array(), $screen['look'] ?? null);
pin('...an administrator is not a moderator here', false, $screen['moderator'] ?? null);
pin('...nothing is stale with no draft', false, $screen['stale'] ?? null);

$r = blocks('blocks', $plain);
pin('a page that is not a builder page goes back to its form', array($pageForm . $plain), $r['redirects']);
check('...saying why', count($r['flashes']) === 1 && str_starts_with($r['flashes'][0], 'info:'));
pin('...and draws nothing', array(), $r['views']);
pin('a missing page goes back to the pages list', array('https://admin.shop.test/oc-admin/index.php?page=pages'), blocks('blocks', 999999)['redirects']);
pin('a page id that is not a number goes back to the pages list', array('https://admin.shop.test/oc-admin/index.php?page=pages'), drive('CAdminPages', array('page' => 'pages', 'action' => 'blocks', 'id' => array('7')))['redirects']);

harness_section('the JSON actions check the token first');

foreach (array('blocks_draft_post', 'blocks_publish_post', 'blocks_discard_post') as $action) {
    $r = blocks($action, $p7, array('locations' => array($l7 => array(array('type' => 'core.heading', 'config' => array('text' => 'x'))))), false);
    pin("$action without the token is refused", 'csrf', $r['stopped']);
    pin('...before anything is answered', '', $r['raw']);
}
pin('...and no draft was stored', null, $kv($l7));
foreach (array('blocks_draft_post', 'blocks_publish_post', 'blocks_discard_post') as $action) {
    $r = blocks($action, $plain);
    pin("$action on a page that is not a builder page is 404", 404, $r['status']);
    pin('...with an error', 1, $r['json']['error'] ?? null);
    pin("$action on a missing page is 404", 404, blocks($action, 999999)['status']);
}

harness_section('draft, publish and discard for one page');

pin('a theme draft to leave alone is 200', 200, customise('customise_draft_post', array('settings' => array('brand' => 'raspberry')))['status']);
$themeDraft = $kv('aisle');

$r = blocks('blocks_draft_post', $p7, array('locations' => array($l7 => array(array('type' => 'core.heading', 'description' => 'Top', 'config' => array('text' => 'Hello'))))));
pin('a page draft is 200', 200, $r['status']);
pin('...with no error', 0, $r['json']['error'] ?? null);
check('...and a save time', ($r['json']['saved_at'] ?? 0) > 0);
pin('...and the page preview URL', $preview, $r['json']['preview_url'] ?? null);
pin('...stored in the page draft', array('core.heading'), array_column($kv($l7)['locations'][$l7] ?? array(), 'type'));
pin('...the theme draft is untouched', $themeDraft, $kv('aisle'));
pin('...page 8 has no draft', null, $kv('page.' . $p8));
pin('...nothing is live yet', array(), $live($l7));

$r = blocks('blocks', $p7);
pin('the screen then shows the drafted block', array('core.heading'), array_column($r['screen']['locations'][$l7]['rows'] ?? array(), 'type'));

$r = blocks('blocks_draft_post', $p7, array('locations' => array('page.' . $p8 => array(array('type' => 'core.heading')))));
pin('a draft for another page\'s location is 422', 422, $r['status']);

$r = blocks('blocks_publish_post', $p7);
pin('publish is 200', 200, $r['status']);
pin('...answered as the theme publish is', array('error' => 0, 'errors' => array(), 'stale' => false), $r['json']);
pin('...the block is live', array('core.heading'), array_column($live($l7), 's_type'));
pin('...the page cache purge is for widgets', array('widget'), $r['purges']);
pin('...the page draft is gone', null, $kv($l7));
pin('...the theme draft is still there', $themeDraft, $kv('aisle'));
pin('publishing again has nothing to publish', 409, blocks('blocks_publish_post', $p7)['status']);

blocks('blocks_draft_post', $p7, array('locations' => array($l7 => array())));
$r = blocks('blocks_discard_post', $p7);
pin('discard is 200', 200, $r['status']);
pin('...answered as the theme discard is', array('error' => 0), $r['json']);
pin('...the page draft is gone', null, $kv($l7));
pin('...the live block stays', array('core.heading'), array_column($live($l7), 's_type'));
pin('...the theme draft is still there', $themeDraft, $kv('aisle'));

blocks('blocks_draft_post', $p7, array('locations' => array($l7 => array())));
$admin->query("UPDATE $table SET s_description = 'Changed elsewhere' WHERE s_location = '$l7'");
pin('the screen marks a draft older than the live blocks as stale', true, blocks('blocks', $p7)['screen']['stale'] ?? null);
$r = blocks('blocks_publish_post', $p7);
pin('...and publish refuses it', 409, $r['status']);
pin('...saying it is stale', true, $r['json']['stale'] ?? null);
pin('...unless forced', 200, blocks('blocks_publish_post', $p7, array('force' => '1'))['status']);

harness_section('legacy and locked rows on the screen');

$l8  = 'page.' . $p8;
$raw = \mindstellar\widgets\WidgetStore::add(array('s_location' => $l8, 'e_kind' => 'html', 's_description' => 'Old HTML', 's_content' => '<p>raw</p>', 'i_order' => 0));
$cc  = \mindstellar\widgets\WidgetStore::add(array('s_location' => $l8, 'e_kind' => 'html', 's_description' => 'Tracker', 's_content' => '', 's_type' => 'core.custom_code', 's_config' => '{"code":"x"}', 'i_order' => 1));
$rowFlags = static fn (array $screen): array => array_map(
    static fn (array $row): array => array($row['id'], $row['legacy'], $row['locked']),
    $screen['locations'][$l8]['rows'] ?? array()
);
$r = blocks('blocks', $p8);
pin('an admin sees a legacy row, locked, and Custom Code unlocked', array(array($raw, true, true), array($cc, false, false)), $rowFlags((array) $r['screen']));
pin('...nothing is counted as other', 0, $r['screen']['locations'][$l8]['other'] ?? null);
pin('...the Custom Code tile is not locked', false, $r['screen']['types']['core.custom_code']['locked'] ?? null);

harness_section('moderators');

$GLOBALS['moderator'] = true;
foreach (array('blocks', 'blocks_draft_post', 'blocks_publish_post', 'blocks_discard_post') as $action) {
    $r = blocks($action, $p7, array('locations' => array($l7 => array(array('type' => 'core.heading', 'config' => array('text' => 'Mod'))))));
    pin("a moderator without Pages access is stopped at $action", 'gate', $r['stopped']);
    pin('...before the token is checked', array(), $GLOBALS['csrfChecks']);
}
pin('...and no draft was stored', null, $kv($l7));

$access = static fn (array $pages): array => array_merge($pages, array('pages'));
osc_add_filter('moderator_access', $access);
$r = blocks('blocks', $p7);
pin('a moderator with Pages access gets the screen', array('appearance/customise.php'), $r['views']);
pin('...marked as a moderator', true, $r['screen']['moderator'] ?? null);
$r8 = blocks('blocks', $p8);
pin('...a Custom Code row is locked for them', array(array($raw, true, true), array($cc, false, true)), $rowFlags((array) $r8['screen']));
pin('...and so is its tile', true, $r8['screen']['types']['core.custom_code']['locked'] ?? null);
$r = blocks('blocks_draft_post', $p7, array('locations' => array($l7 => array(array('type' => 'core.custom_code', 'config' => array('code' => '<p>x</p>'))))));
pin('...but cannot add Custom Code', 422, $r['status']);
$r = blocks('blocks_draft_post', $p7, array('locations' => array($l7 => array(array('type' => 'core.heading', 'config' => array('text' => 'Mod'))))));
pin('...and can draft a heading', 200, $r['status']);
blocks('blocks_discard_post', $p7);
pin('the theme Customise actions stay out of reach of a moderator', 'gate', customise('customise_draft_post')['stopped']);
osc_remove_filter('moderator_access', $access);
$GLOBALS['moderator'] = false;

harness_section('the page form summary');

blocks('blocks_draft_post', $p7, array('locations' => array($l7 => array())));
$r   = drive('CAdminPages', array('page' => 'pages', 'action' => 'edit', 'id' => (string) $p7));
$sum = $GLOBALS['exported']['pageBlocks'] ?? null;
pin('the edit form of a builder page exports its summary', array('pages/frm.php', 'https://admin.shop.test/oc-admin/index.php?page=pages&action=blocks&id=' . $p7), array($r['views'][0] ?? null, $sum['edit_url'] ?? null));
pin('...with this admin\'s draft flagged', true, $sum['draft'] ?? null);

$block = \mindstellar\widgets\WidgetStore::add(array('s_location' => $l7, 'e_kind' => 'html', 's_description' => 'Intro', 's_content' => '', 's_type' => 'core.heading', 's_config' => '{}', 'i_order' => 0));
drive('CAdminPages', array('page' => 'pages', 'action' => 'edit', 'id' => (string) $p7));
$sum = $GLOBALS['exported']['pageBlocks'] ?? null;
pin('...counting its live blocks, by name', array(1, array('Intro')), array($sum['count'] ?? null, array_column($sum['blocks'] ?? array(), 'name')));
$r   = drive('CAdminPages', array('page' => 'pages', 'action' => 'edit_post', 'id' => (string) $p7, 'octoken' => 't'), array('id' => (string) $p7, 's_internal_name' => '', 'meta' => array('template' => 'core.page_builder')));
$sum = $GLOBALS['exported']['pageBlocks'] ?? null;
pin('a refused save redraws the form with the summary', array(array('pages/frm.php'), 1, array('Intro'), true), array($r['views'], $sum['count'] ?? null, array_column($sum['blocks'] ?? array(), 'name'), $sum['draft'] ?? null));
$admin->query("DELETE FROM $table WHERE pk_i_id = " . (int) $block);
blocks('blocks_discard_post', $p7);
drive('CAdminPages', array('page' => 'pages', 'action' => 'edit', 'id' => (string) $p7));
pin('...and not flagged once the draft is gone', false, $GLOBALS['exported']['pageBlocks']['draft'] ?? null);
drive('CAdminPages', array('page' => 'pages', 'action' => 'edit', 'id' => (string) $plain));
pin('a classic page exports no summary', true, array_key_exists('pageBlocks', $GLOBALS['exported']) && $GLOBALS['exported']['pageBlocks'] === null);
drive('CAdminPages', array('page' => 'pages', 'action' => 'add'));
pin('the new page form exports no summary', true, array_key_exists('pageBlocks', $GLOBALS['exported']) && $GLOBALS['exported']['pageBlocks'] === null);

harness_section('deleting a page drops its drafts');

$pd = seed_page($admin, 'doomed', 'Doomed');
$admin->query("UPDATE $pagesT SET s_meta = '{\"template\":\"core.page_builder\"}' WHERE pk_i_id = $pd");
blocks('blocks_draft_post', $pd, array('locations' => array('page.' . $pd => array())));
blocks('blocks_draft_post', $p7, array('locations' => array($l7 => array())));
pin('the page has a draft', true, osc_kv_get('customiser', 'page.' . $pd . ':' . $adminA) !== null);
drive('CAdminPages', array('page' => 'pages', 'action' => 'delete', 'id' => (string) $pd, 'octoken' => 't'));
pin('...gone with the page', null, osc_kv_get('customiser', 'page.' . $pd . ':' . $adminA));
pin('...another page\'s draft is kept', true, $kv($l7) !== null);
blocks('blocks_discard_post', $p7);

harness_section('the theme Customise actions answer as before');

$r = customise('customise_draft_post', array('settings' => array('brand' => 'ocean')));
pin('draft: 200 with the keys it has always had', array('error', 'saved_at', 'preview_url'), array_keys((array) $r['json']));
pin('...previewing the theme home page', WEB_PATH . '?osc_preview=1', $r['json']['preview_url'] ?? null);
pin('...the token was checked', array('customise_draft_post'), $GLOBALS['csrfChecks']);
pin('a refused draft: 422 and its errors', array(422, array('error' => 1, 'errors' => array('other.thing is not a section this page can hold.'))), (static function () {
    $r = customise('customise_draft_post', array('locations' => array('home' => array(array('type' => 'other.thing')))));

    return array($r['status'], $r['json']);
})());
$r = customise('customise_publish_post');
pin('publish: 200 as before', array(200, array('error' => 0, 'errors' => array(), 'stale' => false)), array($r['status'], $r['json']));
pin('...purging widgets', array('widget'), $r['purges']);
pin('publish with nothing drafted: 409 as before', array(409, array('error' => 1, 'errors' => array('There are no changes to publish.'), 'stale' => false)), (static function () {
    $r = customise('customise_publish_post');

    return array($r['status'], $r['json']);
})());
$r = customise('customise_discard_post');
pin('discard: 200 as before', array(200, array('error' => 0)), array($r['status'], $r['json']));
$GLOBALS['moderator'] = true;
$appearance = static fn (array $pages): array => array_merge($pages, array('appearance'));
osc_add_filter('moderator_access', $appearance);
$r = customise('customise_publish_post');
osc_remove_filter('moderator_access', $appearance);
pin('a moderator let into Appearance: 403 as before', array(403, array('error' => 1, 'errors' => array('Only an administrator can customise the theme.'))), array($r['status'], $r['json']));
$GLOBALS['moderator'] = false;
osc_remove_theme_support('customiser');
$r = customise('customise_discard_post');
pin('a theme with no customiser: 404 as before', array(404, array('error' => 1, 'errors' => array('This theme has nothing to customise.'))), array($r['status'], $r['json']));
pin('...while a page still opens its blocks', array('appearance/customise.php'), blocks('blocks', $p7)['views']);
customiser_kit_declare();
$r = drive('CAdminAppearance', array('page' => 'appearance', 'action' => 'customise'));
pin('the theme screen is in theme mode', 'theme', $r['screen']['mode'] ?? null);

$code = harness_result();
ob_end_flush();
exit($code);
