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
 * A translated look field in Appearance > Customise: the screen draws one input per enabled
 * locale, the draft keeps each locale apart, publish writes every locale through the settings
 * store without blanking the ones not drafted, discard writes nothing, and the preview answers
 * the visitor's locale.
 *
 * DB-backed.  Usage:  php tests/customise-translated.php
 */

ob_start();

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

const SCRATCH = 'osc_customise_translated';

$admin = scratchdb_session(SCRATCH);
harness_reset_container();
define('OC_ADMIN', true);
require_once __DIR__ . '/lib/customiser-kit.php';

const DECLARE_LOOK = array('settings' => array('page' => 'aisle', 'fields' => array('brand', 'announcement')));
customiser_kit_declare(DECLARE_LOOK);

use mindstellar\widgets\CustomiserDraft;
use mindstellar\widgets\CustomiserScreen;

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

$adminId = seed_exec($admin, 'INSERT INTO ' . DB_TABLE_PREFIX . "t_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES ('A', 'a', 'x', 'a@x.test', 0)", '', array());
$GLOBALS['adminId'] = $adminId;
Preference::getInstance()->replace('theme', 'aisle', 'osclass', 'STRING');
Preference::getInstance()->replace('announcementen_US', 'Hello', 'aisle', 'STRING');

/** One admin request to the appearance controller: status, JSON and the HTML of a screen. */
function drive(string $action, array $post = array()): array
{
    $_GET  = array('page' => 'appearance', 'action' => $action);
    $_POST = $post;
    Params::init();
    $GLOBALS['views'] = array();
    http_response_code(200);
    ob_start();
    (new CAdminAppearance())->doModel();
    $out  = (string) ob_get_clean();
    $html = '';
    if ($GLOBALS['views'] === array('appearance/customise.php')) {
        ob_start();
        require ABS_PATH . 'oc-admin/themes/modern/appearance/customise.php';
        $html = (string) ob_get_clean();
    }

    return array('status' => http_response_code(), 'json' => json_decode($out, true), 'html' => $html);
}

/** The drafted value the preview of one locale reads, from a public request in its own process. */
function previewValue(string $locale, ?string $asked = null): array
{
    $args = array(
        'get'        => array('osc_preview' => '1'),
        'adminId'    => $GLOBALS['adminId'],
        'declare'    => DECLARE_LOOK,
        'locale'     => $locale,
        'draftValue' => $asked === null ? array('aisle', 'announcement') : array('aisle', 'announcement', $asked),
    );
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/lib/customiser-preview-child.php') . ' '
        . escapeshellarg(SCRATCH) . ' ' . escapeshellarg((string) json_encode($args));
    $out = (string) shell_exec($cmd . ' 2>&1');
    $res = json_decode($out, true);
    if (!is_array($res)) {
        fwrite(STDERR, $out . "\n");

        return array();
    }

    return $res;
}

$stored = static function (string $code) use ($admin): ?string {
    $row = $admin->query('SELECT s_value FROM ' . DB_TABLE_PREFIX . "t_preference WHERE s_section = 'aisle' AND s_name = 'announcement$code'")->fetch_row();

    return $row === null ? null : (string) $row[0];
};
$draft = static fn (): ?array => (new CustomiserDraft('aisle', $GLOBALS['adminId']))->read();

harness_section('the screen');

$built = CustomiserScreen::build((array) osc_customiser_spec(), null);
pin('a translated look field shows each locale\'s published text', array('en_US' => 'Hello', 'hi_IN' => ''), $built['look']['announcement']['value'] ?? null);
pin('...and names the locales', array('en_US' => 'English', 'hi_IN' => 'Hindi'), $built['look']['announcement']['locales'] ?? null);
pin('a plain look field has none', array(), $built['look']['brand']['locales'] ?? null);

$html = drive('customise')['html'];
pin('one input per locale', 2, preg_match_all('#<input[^>]+name="announcement\[(en_US|hi_IN)\]"#', $html));
check('...holding the published text', (bool) preg_match('#name="announcement\[en_US\]"[^>]*value="Hello"#', $html));
check('...each labelled with its locale', str_contains($html, 'Announcement (English)') && str_contains($html, 'Announcement (Hindi)'));
check('...in the locale tabs the settings screens use', str_contains($html, 'field-translate-panel'));
check('the field is marked for the script', str_contains($html, 'data-look="announcement" data-look-type="text" data-look-translate'));
check('no single input for it', !str_contains($html, 'name="announcement"'));

harness_section('the draft');

$r = drive('customise_draft_post', array('settings' => array('announcement' => array('hi_IN' => ' Namaste <b>sab</b> '))));
pin('a locale saves into the draft', 200, $r['status']);
pin('...sanitised, and only that locale', array('hi_IN' => 'Namaste sab'), $draft()['settings']['announcement'] ?? null);
pin('...nothing is stored yet', null, $stored('hi_IN'));
drive('customise_draft_post', array('settings' => array('brand' => 'ocean')));
pin('a later change keeps the drafted locale', array('hi_IN' => 'Namaste sab'), $draft()['settings']['announcement'] ?? null);

$r = drive('customise_draft_post', array('settings' => array('announcement' => array('fr_FR' => 'Bonjour'))));
pin('a locale the site does not have is 422', 422, $r['status']);
$r = drive('customise_draft_post', array('settings' => array('announcement' => array('hi_IN' => str_repeat('a', 21)))));
pin('a locale over the limit is 422', 422, $r['status']);
check('...naming the locale', str_contains((string) ($r['json']['errors'][0] ?? ''), 'Announcement (Hindi)'));
pin('a single value for a translated field is 422', 422, drive('customise_draft_post', array('settings' => array('announcement' => 'Hi')))['status']);
pin('...and none of these touched the draft', array('hi_IN' => 'Namaste sab'), $draft()['settings']['announcement'] ?? null);

$html = drive('customise')['html'];
check('the screen shows the drafted locale', (bool) preg_match('#name="announcement\[hi_IN\]"[^>]*value="Namaste sab"#', $html));
check('...and the published one for the rest', (bool) preg_match('#name="announcement\[en_US\]"[^>]*value="Hello"#', $html));

harness_section('the preview');

pin('the preview reads the visitor\'s locale from the draft', 'Namaste sab', previewValue('hi_IN')['drafted'] ?? 'missing');
$notDrafted = previewValue('en_US');
check('...null for a locale not drafted, so the theme reads the stored text', array_key_exists('drafted', $notDrafted) && $notDrafted['drafted'] === null && ($notDrafted['preview'] ?? false) === true);
pin('...or the locale asked for', 'Namaste sab', previewValue('en_US', 'hi_IN')['drafted'] ?? 'missing');

harness_section('discard and publish');

pin('discard', 200, drive('customise_discard_post')['status']);
pin('...writes nothing', array(null, 'Hello'), array($stored('hi_IN'), $stored('en_US')));

drive('customise_draft_post', array('settings' => array('announcement' => array('hi_IN' => 'Namaste'))));
$r = drive('customise_publish_post');
pin('publish', 200, $r['status']);
pin('...stores the drafted locale and keeps the one not drafted', array('Namaste', 'Hello'), array($stored('hi_IN'), $stored('en_US')));
pin('...and forgets the draft', null, $draft());
osc_reset_preferences();
pin('the settings store reads it back per locale', array('en_US' => 'Hello', 'hi_IN' => 'Namaste'), osc_settings_values('aisle')['announcement'] ?? null);

drive('customise_draft_post', array('settings' => array('announcement' => array('en_US' => ''))));
drive('customise_publish_post');
pin('clearing one locale clears only that one', array('', 'Namaste'), array($stored('en_US'), $stored('hi_IN')));

$code = harness_result();
ob_end_flush();
exit($code);
