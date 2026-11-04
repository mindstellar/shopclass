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
 * Appearance > Customise: the screen shows the declared location's sections (draft first),
 * hidden ones marked, only the allowed types and look fields, swatches only for a checked
 * hex, and a preview frame on osc_preview=1. A moderator gets 403 and a theme with no
 * customiser goes back to the themes screen.
 *
 * DB-backed.  Usage:  php tests/admin-customise-page.php
 */

ob_start();

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

const SCRATCH = 'osc_admin_customise_page';

$admin = scratchdb_session(SCRATCH);
define('OC_ADMIN', true);
require_once __DIR__ . '/lib/customiser-kit.php';
customiser_kit_declare();

use mindstellar\widgets\Customiser;
use mindstellar\widgets\CustomiserDraft;
use mindstellar\widgets\CustomiserScreen;
use mindstellar\widgets\PageBlocks;
use mindstellar\widgets\WidgetStore;

$GLOBALS['themesDir'] = sys_get_temp_dir() . '/osc_customise_themes_' . getmypid() . '/';
@mkdir($GLOBALS['themesDir'] . 'aisle', 0777, true);
file_put_contents($GLOBALS['themesDir'] . 'aisle/index.php', "<?php\n/*\nTheme Name: aisle\n*/\n");
register_shutdown_function(static function () {
    @unlink($GLOBALS['themesDir'] . 'aisle/index.php');
    @rmdir($GLOBALS['themesDir'] . 'aisle');
    @rmdir($GLOBALS['themesDir']);
});
foreach (array(
    'osc_themes_path'                => 'return $GLOBALS["themesDir"];',
    'osc_current_admin_theme_path'   => 'return null;',
    'osc_current_admin_theme_js_url' => 'return "https://admin.shop.test/js/" . func_get_arg(0);',
    'osc_csrf_token_url'             => 'return "octoken=t";',
    'osc_asset_url_versioned'        => 'return func_get_arg(0);',
) as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '(...$args) { ' . $body . ' }');
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
require_once ABS_PATH . 'oc-admin/themes/modern/functions.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

/** The base controller, stubbed: redirects and the view are recorded, nothing is drawn. */
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
        View::getInstance()->_exportVariableToView($key, $value);
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
require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminAppearance.php';

$adminId = seed_exec($admin, 'INSERT INTO ' . DB_TABLE_PREFIX . "t_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES ('A', 'a', 'x', 'a@x.test', 0)", '', array());
$GLOBALS['adminId'] = $adminId;
Preference::getInstance()->replace('theme', 'aisle', 'osclass', 'STRING');

/** One GET of the customise screen: status, redirects, the view it asked for, and the HTML. */
function screen(): array
{
    $_GET  = array('page' => 'appearance', 'action' => 'customise');
    $_POST = array();
    Params::init();
    $GLOBALS['redirects'] = array();
    $GLOBALS['views']     = array();
    http_response_code(200);
    (new CAdminAppearance())->doModel();
    $html = '';
    if ($GLOBALS['views'] === array('appearance/customise.php')) {
        ob_start();
        require ABS_PATH . 'oc-admin/themes/modern/appearance/customise.php';
        $html = (string) ob_get_clean();
    }

    return array('status' => http_response_code(), 'redirects' => $GLOBALS['redirects'], 'html' => $html);
}

$hero    = WidgetStore::add(array('s_location' => 'home', 'e_kind' => 'html', 's_description' => 'Big hero', 's_content' => '', 's_type' => 'aisle.hero', 's_config' => '{"title":"Hi <b>there</b>","layout":"rail"}', 'i_order' => 0));
$promise = WidgetStore::add(array('s_location' => 'home', 'e_kind' => 'html', 's_description' => 'Promise', 's_content' => '', 's_type' => 'aisle.promise', 's_config' => '{"text":"x","_enabled":false}', 'i_order' => 1));
WidgetStore::add(array('s_location' => 'home', 'e_kind' => 'html', 's_description' => 'Other', 's_content' => '', 's_type' => 'other.thing', 's_config' => '{}', 'i_order' => 2));
WidgetStore::add(array('s_location' => 'home', 'e_kind' => 'html', 's_description' => 'Legacy', 's_content' => '<p>old</p>', 'i_order' => 3));
WidgetStore::add(array('s_location' => 'footer', 'e_kind' => 'html', 's_description' => 'Foot', 's_content' => '', 's_type' => 'aisle.hero', 's_config' => '{}', 'i_order' => 0));
Preference::getInstance()->replace('brand', 'ocean', 'aisle', 'STRING');
Preference::getInstance()->replace('footer_note', 'Made here', 'aisle', 'STRING');

harness_section('what the screen holds');

$built = CustomiserScreen::build((array) osc_customiser_spec(), null);
pin('only the declared location', array('home'), array_keys($built['locations']));
pin('its rows in order, of the allowed types only', array((string) $hero, (string) $promise), array_column($built['locations']['home']['rows'], 'ref'));
pin('a hidden row reads as not enabled', array(true, false), array_column($built['locations']['home']['rows'], 'enabled'));
check('...and _enabled is not shown as a field', !array_key_exists('_enabled', $built['locations']['home']['rows'][1]['config']));
pin('rows it cannot show are counted', 2, $built['locations']['home']['other']);
pin('the allowed types and no other', array('aisle.hero', 'aisle.promise'), array_keys($built['types']));
pin('the look fields and no other', array('brand', 'footer_note', 'logo'), array_keys($built['look']));
pin('a look field shows the published value', 'ocean', $built['look']['brand']['value']);
pin('swatches only for a checked hex', array('brand' => array('raspberry' => '#c81f5e')), $built['swatches']);
pin('theme-mode previewUrl is unchanged', 'https://x.test/?a=1&osc_preview=1', CustomiserScreen::previewUrl('https://x.test/?a=1'));
pin('the preview frame opens on osc_preview=1', WEB_PATH . '?osc_preview=1', $built['locations']['home']['preview_url']);

(new CustomiserDraft('aisle', $adminId))->save(array(
    'locations' => array('home' => array(
        array('id' => $promise, 'key' => '', 'type' => 'aisle.promise', 'description' => 'Promise', 'config' => array('text' => 'y')),
        array('id' => null, 'key' => 'n1', 'type' => 'aisle.hero', 'description' => 'New hero', 'config' => array('title' => 'New', '_enabled' => false)),
    )),
    'settings'  => array('brand' => 'raspberry'),
));
$drafted = CustomiserScreen::build((array) osc_customiser_spec(), (new CustomiserDraft('aisle', $adminId))->read());
pin('a drafted location shows the draft rows', array((string) $promise, 'n1'), array_column($drafted['locations']['home']['rows'], 'ref'));
pin('...with the draft hidden flags', array(true, false), array_column($drafted['locations']['home']['rows'], 'enabled'));
pin('a drafted look value wins over the published one', 'raspberry', $drafted['look']['brand']['value']);
pin('...and one not drafted shows the published one', 'Made here', $drafted['look']['footer_note']['value']);
check('the draft time is passed on', $drafted['updated_at'] > 0);

harness_section('the page');

$page = screen();
pin('an administrator gets the screen', 200, $page['status']);
$html  = $page['html'];
$state = preg_match('#<script type="application/json" id="cz-state">(.*?)</script>#s', $html, $m) ? json_decode($m[1], true) : null;
check('the state is valid JSON', is_array($state));
pin('...listing the draft rows', array((string) $promise, 'n1'), array_column($state['locations']['home']['rows'] ?? array(), 'ref'));
check('...with no raw markup that could close the script', strpos($m[1] ?? '', '<') === false);
check('the heading names the theme', strpos($html, 'Customise Aisle</h1>') !== false);
check('the page select names the location', strpos($html, '<option value="home">Home page</option>') !== false);
check('the iframe opens the preview URL', strpos($html, 'src="' . WEB_PATH . '?osc_preview=1"') !== false);
check('...same-origin referrer', strpos($html, 'referrerpolicy="same-origin"') !== false);
check('the add list offers the allowed types', strpos($html, 'data-add="aisle.hero"') !== false && strpos($html, 'data-add="aisle.promise"') !== false);
check('...and no other', strpos($html, 'data-add="other.thing"') === false && strpos($html, 'data-type-id="other.thing"') === false);
check('the field templates come from the shared part', strpos($html, 'class="widget-type-fieldset" data-type-id="aisle.hero"') !== false);
foreach (array('brand', 'footer_note', 'logo') as $name) {
    check("look field $name is drawn", strpos($html, 'data-look="' . $name . '"') !== false);
}
check('a secret field is not', strpos($html, 'api_key') === false);
check('...nor a field the theme did not name', strpos($html, 'data-look="hero_title"') === false);
check('a swatch is drawn for the checked hex', strpos($html, 'data-value="raspberry"') !== false && strpos($html, 'background-color: #c81f5e') !== false);
check('...and none for the refused one', strpos($html, 'data-value="ocean"') === false);
check('the select stays the form control', strpos($html, '<select id="cz-look-brand" name="brand"') !== false);
check('the drafted swatch is pressed', (bool) preg_match('#data-value="raspberry"\s+aria-pressed="true"#', $html));
check('the posts carry the CSRF token', strpos($state['urls']['draft'] ?? '', 'action=customise_draft_post&octoken=t') !== false);

harness_section('who may open it');

$GLOBALS['moderator'] = true;
$page = screen();
pin('a moderator gets 403', 403, $page['status']);
check('...and no customiser', strpos($page['html'], 'id="cz-state"') === false);
unset($GLOBALS['moderator']);

customiser_kit_declare(array('locations' => array(), 'settings' => array()));
$page = screen();
pin('a theme with no customiser goes back to the themes screen', array('https://admin.shop.test/oc-admin/index.php?page=appearance'), $page['redirects']);
customiser_kit_declare();

harness_section('theme mode data keeps its shape');

pin('theme mode is the default', 'theme', $built['mode']);
check('...with no page target and no moderator', $built['target'] === null && $built['moderator'] === false);
pin('a row carries locked and legacy flags, both false in theme mode', array(false, false), array($built['locations']['home']['rows'][0]['locked'], $built['locations']['home']['rows'][0]['legacy']));
pin('a theme row\'s preview_url is what osc_customiser_preview_url gives', osc_customiser_preview_url('home'), $built['locations']['home']['preview_url']);
pin('a type carries icon, group and locked', array('', 'content', false), array($built['types']['aisle.hero']['icon'], $built['types']['aisle.hero']['group'], $built['types']['aisle.hero']['locked']));
pin('an untyped row in a theme location is still not listed', 2, $built['locations']['home']['other']);

harness_section('page mode data');

require_once ABS_PATH . 'oc-includes/osclass/helpers/hPageTemplates.php';
if (!function_exists('osc_core_url')) {
    function osc_core_url($name, $args = array())
    {
        return \mindstellar\routing\CoreRoutes::url($name, $args);
    }
}
$pagesT = DB_TABLE_PREFIX . 't_pages';
$pg     = seed_page($admin, 'seven', 'Seven');
$admin->query("UPDATE $pagesT SET s_meta = '{\"template\":\"core.page_builder\"}' WHERE pk_i_id = $pg");
$slug = 'page.' . $pg;
$wT   = DB_TABLE_PREFIX . 't_widget';
$admin->query("DELETE FROM $wT WHERE s_location = '$slug'");
$cc   = WidgetStore::add(array('s_location' => $slug, 'e_kind' => 'html', 's_description' => 'Tracker', 's_content' => '', 's_type' => 'core.custom_code', 's_config' => '{"code":"x"}', 'i_order' => 0));
$head = WidgetStore::add(array('s_location' => $slug, 'e_kind' => 'html', 's_description' => 'Intro', 's_content' => '', 's_type' => 'core.heading', 's_config' => '{"text":"Hi"}', 'i_order' => 1));
$old  = WidgetStore::add(array('s_location' => $slug, 'e_kind' => 'html', 's_description' => 'Old HTML', 's_content' => '<p>raw</p>', 'i_order' => 2));

$pspec = PageBlocks::spec($pg);
$pageScreen = static fn (bool $moderator, ?array $draft = null): array => CustomiserScreen::build($pspec, $draft, $moderator, $pg);

$admScreen = $pageScreen(false);
pin('page mode names itself', 'page', $admScreen['mode']);
pin('...with the page as target', array('id' => $pg, 'title' => 'Seven'), array_intersect_key((array) $admScreen['target'], array('id' => 1, 'title' => 1)));
check('...and an edit link back to the page form', str_contains((string) $admScreen['target']['edit_url'], 'page=pages&action=edit&id=' . $pg));
pin('...one location', array($slug), array_keys($admScreen['locations']));
pin('...rows in order, with the untyped one listed', array((string) $cc, (string) $head, (string) $old), array_column($admScreen['locations'][$slug]['rows'], 'ref'));
pin('...an admin may edit Custom Code', array(false, false, true), array_column($admScreen['locations'][$slug]['rows'], 'locked'));
pin('...the untyped row is legacy', array(false, false, true), array_column($admScreen['locations'][$slug]['rows'], 'legacy'));
pin('...and none counted as removed on publish', 0, $admScreen['locations'][$slug]['other']);
pin('...the preview is the page with osc_preview=1&osc_blocks=1', $pspec['locations'][$slug]['preview'] . (str_contains($pspec['locations'][$slug]['preview'], '?') ? '&' : '?') . 'osc_preview=1&osc_blocks=1', $admScreen['locations'][$slug]['preview_url']);
pin('...no look fields', array(), $admScreen['look']);
pin('...an admin has no moderator flag', false, $admScreen['moderator']);
pin('...Custom Code is advanced', 'advanced', $admScreen['types']['core.custom_code']['group']);
pin('...a layout block is layout', 'layout', $admScreen['types']['core.heading']['group']);
pin('...and has an icon', 'type-h1', $admScreen['types']['core.heading']['icon']);
check('...every type is offered to an admin', $admScreen['types']['core.custom_code']['locked'] === false);

$modScreen = $pageScreen(true);
pin('a moderator sees Custom Code locked', array(true, false, true), array_column($modScreen['locations'][$slug]['rows'], 'locked'));
pin('...with the moderator flag', true, $modScreen['moderator']);
check('...and its type marked locked, so no tile', $modScreen['types']['core.custom_code']['locked'] === true);

$stored = array(
    array('id' => $head, 'key' => '', 'type' => 'core.heading', 'description' => 'Drafted', 'config' => array('text' => 'D')),
    array('id' => null, 'key' => 'n1', 'type' => 'core.heading', 'description' => 'New', 'config' => array('text' => 'N', '_enabled' => false)),
    array('id' => $old, 'key' => '', 'type' => '', 'description' => 'Old HTML', 'config' => array()),
);
$dr = $pageScreen(false, array('locations' => array($slug => $stored), 'settings' => array(), 'updated_at' => 5));
pin('a page draft wins over the live blocks', array((string) $head, 'n1', (string) $old), array_column($dr['locations'][$slug]['rows'], 'ref'));
pin('...a drafted hidden block reads as not enabled', array(true, false, true), array_column($dr['locations'][$slug]['rows'], 'enabled'));
pin('...an untyped drafted row stays legacy and locked', array(true, true), array($dr['locations'][$slug]['rows'][2]['legacy'], $dr['locations'][$slug]['rows'][2]['locked']));

$admin->query("UPDATE $wT SET s_type = 'gone.type', s_content = '' WHERE pk_i_id = $old");
$gone = $pageScreen(false);
pin('a block of a type no longer registered is listed as legacy and locked', array(true, true), array($gone['locations'][$slug]['rows'][2]['legacy'], $gone['locations'][$slug]['rows'][2]['locked']));

harness_section('page mode markup');

/** The customise view with the given screen data, as the controller would hand it over. */
function render_screen(array $screen): string
{
    View::getInstance()->_exportVariableToView('customiser', $screen);
    ob_start();
    require ABS_PATH . 'oc-admin/themes/modern/appearance/customise.php';

    return (string) ob_get_clean();
}
$GLOBALS['moderator'] = false;
$pageHtml = render_screen($admScreen + array('stale' => false, 'theme_name' => 'Aisle'));
$ids = array('cz-list-view', 'cz-tiles', 'cz-field-view', 'cz-back', 'cz-section-title', 'cz-section-type', 'cz-section-desc', 'cz-lock-note', 'cz-name', 'cz-section-fields', 'cz-remove', 'cz-rows', 'cz-add', 'cz-status', 'cz-publish', 'cz-discard', 'cz-preview', 'cz-type-fields');
foreach ($ids as $id) {
    check("page mode has #$id", str_contains($pageHtml, 'id="' . $id . '"'));
}
check('the heading names the page', str_contains($pageHtml, 'Edit blocks: Seven</h1>'));
check('...with a link back to the page', str_contains($pageHtml, 'Back to page') && str_contains($pageHtml, 'action=edit&amp;id=' . $pg));
check('...no page select', !str_contains($pageHtml, 'id="cz-page"'));
check('...and no Look', !str_contains($pageHtml, 'id="cz-look-open"') && !str_contains($pageHtml, 'id="cz-look"'));
check('tiles are grouped Layout, Content and Advanced', (bool) preg_match('#data-group="layout".*data-group="content".*data-group="advanced"#s', $pageHtml));
check('...an admin gets the Custom Code tile and its warning', str_contains($pageHtml, 'data-add="core.custom_code"') && str_contains($pageHtml, 'id="cz-code-warn"'));
check('the iframe opens the page preview', str_contains($pageHtml, 'src="' . osc_esc_html($admScreen['locations'][$slug]['preview_url']) . '"'));
$pst = preg_match('#<script type="application/json" id="cz-state">(.*?)</script>#s', $pageHtml, $pm) ? json_decode($pm[1], true) : null;
check('the state is valid JSON', is_array($pst));
pin('...says it is page mode', 'page', $pst['mode'] ?? null);
check('...with page endpoints', str_contains($pst['urls']['draft'] ?? '', 'page=pages&action=blocks_draft_post&id=' . $pg) && str_contains($pst['urls']['publish'] ?? '', 'blocks_publish_post') && str_contains($pst['urls']['discard'] ?? '', 'blocks_discard_post'));
check('...with no raw markup that could close the script', !str_contains($pm[1] ?? '', '<'));

$modHtml = render_screen($modScreen + array('stale' => false, 'theme_name' => 'Aisle'));
check('a moderator gets no Custom Code tile, no warning', !str_contains($modHtml, 'data-add="core.custom_code"') && !str_contains($modHtml, 'id="cz-code-warn"'));
check('...but still the Layout tiles', str_contains($modHtml, 'data-add="core.heading"'));

$staleHtml = render_screen($admScreen + array('stale' => true, 'theme_name' => 'Aisle'));
check('a stale page draft has its own wording', str_contains($staleHtml, 'id="cz-stale"') && !str_contains($staleHtml, 'Widgets screen, after your draft began. Publishing replaces those changes; Discard'));

$themeScreen = array_replace(CustomiserScreen::build((array) osc_customiser_spec(), null), array('stale' => true, 'theme_name' => 'Aisle'));
$themeHtml   = render_screen($themeScreen);
check('theme mode still has the page select and Look', str_contains($themeHtml, 'id="cz-page"') && str_contains($themeHtml, 'id="cz-look-open"'));
check('...the stale note is the old text, byte for byte', str_contains($themeHtml, 'These sections were changed elsewhere, such as on the Widgets screen, after your draft began. Publishing replaces those changes; Discard starts again from what is live.'));
check('...and no Custom Code warning', !str_contains($themeHtml, 'id="cz-code-warn"'));
check('...theme heading unchanged', str_contains($themeHtml, 'Customise Aisle</h1>'));

harness_section('the widget editor still draws the same fields');

$add = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/appearance/add_widget.php');
check('add_widget.php includes the shared part', strpos($add, "require __DIR__ . '/parts/widget-fields.php';") !== false);
$widgetTypes         = array('aisle.hero' => osc_widget_types()['aisle.hero']);
$currentTypeId       = 'aisle.hero';
$currentWidgetConfig = array('title' => 'Saved', 'layout' => 'rail');
ob_start();
require ABS_PATH . 'oc-admin/themes/modern/appearance/parts/widget-fields.php';
$part = (string) ob_get_clean();
check('the current type shows its saved values', strpos($part, 'value="Saved"') !== false && (bool) preg_match('#<option value="rail" selected>#', $part));
check('...and is not hidden', !preg_match('#data-type-id="aisle.hero"\s+hidden#', $part));

harness_section('on a phone the preview comes first');

$scss = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/scss/_customise.scss');
check(
    'the stacked layout puts the preview and its Publish bar above the settings',
    (bool) preg_match('/@media \(max-width: 991\.98px\) \{.*?\.cz-main \{\s*order: -1;/s', $scss)
);

$code = harness_result();
ob_end_flush();
exit($code);
