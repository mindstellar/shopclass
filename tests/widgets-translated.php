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
 * A widget or section field declared 'translate' => true: the editors draw one input per
 * enabled locale, a save stores each locale beside the plain name, render reads the visitor's
 * locale, then the default one, then a single value saved before the field was translated, and
 * a Customise draft and publish of a section keep each locale.
 *
 * DB-backed.  Usage:  php tests/widgets-translated.php
 */

ob_start();

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

const SCRATCH = 'osc_widgets_translated';

$admin = scratchdb_session(SCRATCH);
harness_reset_container();
define('OC_ADMIN', true);
require_once __DIR__ . '/lib/customiser-kit.php';

const DECLARE_BANNER = array('locations' => array('home' => array('label' => 'Home page', 'types' => array('aisle.banner', 'aisle.hero'), 'preview' => WEB_PATH)));
customiser_kit_declare(DECLARE_BANNER);

use mindstellar\widgets\CustomiserDraft;
use mindstellar\widgets\CustomiserScreen;
use mindstellar\widgets\WidgetConfig;
use mindstellar\widgets\WidgetRegistry;
use mindstellar\widgets\WidgetStore;

// Stubbed: the real path, URL and CSRF helpers redeclare the ones customiser-kit already installs.
foreach (array(
    'osc_themes_path'                => 'return sys_get_temp_dir() . "/";',
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

/** The base controller is stubbed: the real one needs a full admin request. */
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

if ((int) $admin->query('SELECT COUNT(*) FROM ' . DB_TABLE_PREFIX . "t_locale WHERE pk_c_code = 'en_US'")->fetch_row()[0] === 0) {
    seed_locale($admin);
}
seed_locale($admin, 'hi_IN', 'Hindi');
osc_settings_locales(true);
Preference::getInstance()->replace('language', 'en_US', 'osclass', 'STRING');
Preference::getInstance()->replace('theme', 'aisle', 'osclass', 'STRING');
$GLOBALS['adminId'] = seed_exec($admin, 'INSERT INTO ' . DB_TABLE_PREFIX . "t_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES ('A', 'a', 'x', 'a@x.test', 0)", '', array());

/** One admin request to the appearance controller: status, JSON and the HTML of a screen. */
function drive(string $action, array $post = array(), array $get = array()): array
{
    $_GET  = array('page' => 'appearance', 'action' => $action) + $get;
    $_POST = $post;
    Params::init();
    $GLOBALS['views'] = array();
    http_response_code(200);
    ob_start();
    (new CAdminAppearance())->doModel();
    $out  = (string) ob_get_clean();
    $html = '';
    foreach (array('appearance/customise.php', 'appearance/add_widget.php') as $view) {
        if ($GLOBALS['views'] === array($view)) {
            ob_start();
            require ABS_PATH . 'oc-admin/themes/modern/' . $view;
            $html = (string) ob_get_clean();
        }
    }

    return array('status' => http_response_code(), 'json' => json_decode($out, true), 'html' => $html);
}

/** The home rows a public request renders in one locale, in its own process. */
function publicPage(string $locale, bool $preview = false): string
{
    $args = array('get' => $preview ? array('osc_preview' => '1') : array(), 'adminId' => $GLOBALS['adminId'], 'declare' => DECLARE_BANNER, 'locale' => $locale);
    $cmd  = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/lib/customiser-preview-child.php') . ' '
        . escapeshellarg(SCRATCH) . ' ' . escapeshellarg((string) json_encode($args));
    $out = (string) shell_exec($cmd . ' 2>&1');
    $res = json_decode($out, true);
    if (!is_array($res)) {
        fwrite(STDERR, $out . "\n");

        return '';
    }

    return (string) ($res['page'] ?? '');
}

$banner = WidgetRegistry::getInstance()->get('aisle.banner');
$fields = array_column($banner['fields'], null, 'name');
$liveConfig = static fn (int $id): array => json_decode((string) (WidgetStore::find($id)['s_config'] ?? ''), true) ?: array();

harness_section('declare');

pin('a translated text field is edited in every enabled locale', array('en_US' => 'English', 'hi_IN' => 'Hindi'), WidgetConfig::locales($fields['heading']));
pin('...and so is a translated richtext field', array('en_US', 'hi_IN'), array_keys(WidgetConfig::locales($fields['text'])));
pin('a field without translate has none', array(), WidgetConfig::locales($fields['button_url']));
pin('translate on a number field is ignored', array(), WidgetConfig::locales($fields['count']));

harness_section('save');

$saved = WidgetConfig::fromPosted(
    $banner,
    array('headingen_US' => 'Hello', 'headinghi_IN' => 'Namaste', 'headingfr_FR' => 'Bonjour', 'button_url' => '/sale', 'count' => '3', 'count' . 'hi_IN' => '9'),
    array('texten_US' => '<p>Hi</p><script>x()</script>', 'texthi_IN' => '<p>Namaste</p>')
);
pin('each locale is stored under the name plus its code', array('Hello', 'Namaste'), array($saved['headingen_US'] ?? null, $saved['headinghi_IN'] ?? null));
pin('...the plain name keeps the default language\'s text', 'Hello', $saved['heading'] ?? null);
check('a locale the site does not have is dropped', !array_key_exists('headingfr_FR', $saved));
check('richtext is cleaned per locale', !str_contains((string) ($saved['texten_US'] ?? ''), 'script') && ($saved['texthi_IN'] ?? '') === '<p>Namaste</p>');
pin('an untranslated field stays one value', array('/sale', 3), array($saved['button_url'], $saved['count']));
check('...with no per-locale keys', !array_key_exists('counthi_IN', $saved) && !array_key_exists('button_urlen_US', $saved));
pin('only the plain name posted is kept as it is', 'Old', WidgetConfig::fromPosted($banner, array('heading' => 'Old'), array())['heading']);
pin('an empty default language falls back to the field default', 'Sale', WidgetConfig::fromPosted($banner, array('headinghi_IN' => 'Namaste'), array())['heading']);

harness_section('render');

$config = array('heading' => 'Old', 'headingen_US' => 'Hello', 'headinghi_IN' => 'Namaste');
pin('the visitor\'s locale', 'Namaste', WidgetConfig::resolve($banner, $config, 'hi_IN')['heading']);
pin('...the default locale for another', 'Hello', WidgetConfig::resolve($banner, $config, 'en_US')['heading']);
pin('an empty locale falls back to the default one', 'Hello', WidgetConfig::resolve($banner, array('headinghi_IN' => ' ') + $config, 'hi_IN')['heading']);
pin('with no locale text the single value is read', 'Old', WidgetConfig::resolve($banner, array('heading' => 'Old', 'headingen_US' => ''), 'hi_IN')['heading']);
pin('an old single value reads in every locale', array('Old', 'Old'), array(
    WidgetConfig::resolve($banner, array('heading' => 'Old'), 'hi_IN')['heading'],
    WidgetConfig::resolve($banner, array('heading' => 'Old'), 'en_US')['heading'],
));
pin('an untranslated field is untouched', '/sale', WidgetConfig::resolve($banner, array('button_url' => '/sale'), 'hi_IN')['button_url']);

$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . 't_widget');
$old = WidgetStore::add(array('s_location' => 'home', 'e_kind' => 'html', 's_description' => 'Banner', 's_content' => '', 's_type' => 'aisle.banner', 's_config' => '{"heading":"Old","button_url":"/sale"}', 'i_order' => 0));
pin('a public page renders an old single value in Hindi', '<banner>Old|</banner>', publicPage('hi_IN'));

harness_section('the widgets screen');

pin('an old single value opens in the default language', array('button_url' => '/sale', 'headingen_US' => 'Old'), WidgetConfig::forEditor($banner, array('heading' => 'Old', 'button_url' => '/sale')));
pin('...and a saved locale is not overwritten by it', array('headingen_US' => ''), WidgetConfig::forEditor($banner, array('heading' => 'Old', 'headingen_US' => '')));

View::getInstance()->_exportVariableToView('widget', WidgetStore::find($old));
$html = drive('edit_widget', array(), array('id' => (string) $old, 'location' => 'home'))['html'];
if ($html === '') {
    ob_start();
    $widget = WidgetStore::find($old);
    $_GET['action'] = 'edit_widget';
    $_GET['location'] = 'home';
    Params::init();
    require ABS_PATH . 'oc-admin/themes/modern/appearance/add_widget.php';
    $html = (string) ob_get_clean();
}
pin('the edit form has one heading input per locale', 2, preg_match_all('#<input[^>]+name="config\[heading(en_US|hi_IN)\]"#', $html));
check('...the default one holding the old value', (bool) preg_match('#name="config\[headingen_US\]"[^>]*value="Old"#', $html));
check('...the other one empty', (bool) preg_match('#name="config\[headinghi_IN\]"[^>]*value=""#', $html));
check('...in the language tabs', str_contains($html, 'field-translate-panel') && str_contains($html, 'data-osc-translated'));
$bannerSet = preg_match('#data-type-id="aisle\.banner"(?:(?!data-type-id=).)*#s', $html, $m) ? $m[0] : '';
check('...with no single heading input', $bannerSet !== '' && !str_contains($bannerSet, 'name="config[heading]"'));
check('an untranslated field keeps its single input', str_contains($html, 'name="config[button_url]"'));

drive('edit_widget_post', array('id' => (string) $old, 'description' => 'Banner', 's_type' => 'aisle.banner', 'config' => array('headingen_US' => 'Old', 'headinghi_IN' => 'Namaste', 'button_url' => '/sale')));
$cfg = $liveConfig($old);
pin('saving keeps the old value in the default language and adds Hindi', array('Old', 'Namaste', 'Old'), array($cfg['headingen_US'] ?? null, $cfg['headinghi_IN'] ?? null, $cfg['heading'] ?? null));
pin('the public page reads Hindi', '<banner>Namaste|</banner>', publicPage('hi_IN'));
pin('...and English', '<banner>Old|</banner>', publicPage('en_US'));

harness_section('Customise');

WidgetStore::update($old, array('s_config' => '{"heading":"Old"}'));
$built = CustomiserScreen::build((array) osc_customiser_spec(), null);
pin('the screen opens an old single value in the default language', array('headingen_US' => 'Old'), $built['locations']['home']['rows'][0]['config'] ?? null);
$html = drive('customise')['html'];
pin('the section editor has one input per locale', 2, preg_match_all('#<input[^>]+name="config\[heading(en_US|hi_IN)\]"#', $html));

$r = drive('customise_draft_post', array('locations' => array('home' => array(
    array('id' => (string) $old, 'type' => 'aisle.banner', 'config' => array('headingen_US' => 'Hello', 'headinghi_IN' => 'Namaste <b>sab</b>')),
))));
pin('a draft with two locales', 200, $r['status']);
$drafted = (new CustomiserDraft('aisle', $GLOBALS['adminId']))->read()['locations']['home'][0]['config'] ?? array();
pin('...keeps each locale, purified', array('Hello', 'Namaste sab'), array($drafted['headingen_US'] ?? null, $drafted['headinghi_IN'] ?? null));
pin('...and leaves the live row alone', array('heading' => 'Old'), $liveConfig($old));
pin('the preview shows the drafted Hindi', '<banner>Namaste sab|</banner>', publicPage('hi_IN', true));
pin('...and the drafted English', '<banner>Hello|</banner>', publicPage('en_US', true));
pin('the public page still shows the live text', '<banner>Old|</banner>', publicPage('hi_IN'));

pin('publish', 200, drive('customise_publish_post')['status']);
$cfg = $liveConfig($old);
pin('...stores each locale on the row', array('Hello', 'Namaste sab', 'Hello'), array($cfg['headingen_US'] ?? null, $cfg['headinghi_IN'] ?? null, $cfg['heading'] ?? null));
pin('the public page reads the published Hindi', '<banner>Namaste sab|</banner>', publicPage('hi_IN'));

$code = harness_result();
ob_end_flush();
exit($code);
