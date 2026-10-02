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
 * Pins the two schema features a declared settings page gets instead of per-page
 * JavaScript and per-screen multilang loops: 'depends' and 'translate'.
 *
 * The dependent half is security-relevant, and each way it breaks is silent:
 *
 *  - a hidden field's posted value being stored anyway, which turns a row the browser
 *    never showed into a value anyone can set by hand -- the client script is UX and
 *    nothing more, so the server has to re-evaluate the relationship itself;
 *  - a hidden field still counting as required, which is a page that cannot be saved at
 *    all and no visible field to blame;
 *  - a chain resolving one level deep, so a grandchild survives its grandparent being
 *    switched off;
 *  - the data attribute going missing, or the shared script never being wired to run,
 *    which leaves every dependent row permanently on screen and permanently required --
 *    a page that cannot be saved at all. Nothing about that shows in the source, so the
 *    real script and the committed stylesheet are driven in a browser where one is here.
 *
 * The translated half breaks quietly too: a control per locale that reads or writes the
 * wrong key silently drops what was typed, and a single-locale install growing a tab strip
 * puts a fake choice in front of the only option there is.
 *
 * DB-free: the locale list is stubbed, the same way the preference store is.
 *
 * Usage: php tests/admin-form-depends-translate.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/settings/SettingsPageRegistry.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/form/store/Store.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/form/store/PreferenceStore.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/form/store/TableStore.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/form/store/StoreFactory.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/utility/Validate.php';

use mindstellar\settings\SettingsPageRegistry;
use mindstellar\utility\Validate;

// --- the slice of core the helpers lean on -----------------------------------------
$GLOBALS['filters']     = array();
$GLOBALS['params']      = array();
$GLOBALS['preferences'] = array();
$GLOBALS['viewVars']    = array();
$GLOBALS['locales']     = array(
    array('pk_c_code' => 'en_US', 's_name' => 'English'),
    array('pk_c_code' => 'es_ES', 's_name' => 'Español'),
);

class Params
{
    public static function getParam($key, $a = false, $b = true)
    {
        return $GLOBALS['params'][$key] ?? '';
    }

    // The save path purifies declared text through this. Left as a pass-through: what it
    // strips is pinned against the real one in tests/admin-form-text-purify.php, and these
    // tests are about what reaches it.
    public static function purifyText($value)
    {
        return $value;
    }
}

class Preference
{
    public static function newInstance()
    {
        return new self();
    }

    public function get($key, $section = 'osclass')
    {
        return $GLOBALS['preferences'][$section . '/' . $key] ?? null;
    }

    /** Mirrors the real Preference: the whole section, keyed by name. */
    public function getSection($section = 'osclass')
    {
        $out    = array();
        $prefix = $section . '/';
        foreach (($GLOBALS['preferences'] ?? array()) as $k => $v) {
            if (strpos($k, $prefix) === 0) {
                $out[substr($k, strlen($prefix))] = $v;
            }
        }

        return $out;
    }
}

/** Read at call time, so a test can change which locales are enabled. */
class OSCLocale
{
    public static function newInstance()
    {
        return new self();
    }

    public function listAllEnabled($isBo = false, $indexedByPk = false)
    {
        return $GLOBALS['locales'];
    }
}

function osc_set_preference($key, $value = '', $section = 'osclass', $type = 'STRING')
{
    $k   = $section . '/' . $key;
    $was = $GLOBALS['preferences'][$k] ?? null;
    $GLOBALS['preferences'][$k] = $value;

    return (int)((string)$was !== (string)$value);
}

function osc_add_hook($hook, $fn, $priority = 5)
{
}

/** The real filter runner in miniature: listeners chain and the last return wins. */
function osc_apply_filter($hook, $content = '', ...$args)
{
    foreach ($GLOBALS['filters'][$hook] ?? array() as $fn) {
        $content = $fn($content, ...$args);
    }

    return $content;
}

/** The admin's own language, which a locale strip opens on. */
function osc_current_admin_locale()
{
    return $GLOBALS['__adminLocale'] ?? 'en_US';
}
require_once __DIR__ . '/lib/stubs.php';

/** Register a filter listener for the duration of one assertion, and drop it after. */
function listen(string $hook, callable $fn): void
{
    $GLOBALS['filters'][$hook][] = $fn;
}

function unlisten(): void
{
    $GLOBALS['filters'] = array();
}

/** Validate::localeCode() checks membership of the enabled list, not only its width. */
function osc_get_locales()
{
    return $GLOBALS['locales'];
}

function __get($key)
{
    return $GLOBALS['viewVars'][$key] ?? null;
}

function osc_admin_page(array $opts = array())
{
}

function osc_current_admin_theme_path($path)
{
}

function osc_admin_base_url($index = false)
{
    return 'https://example.test/oc-admin/index.php';
}

function osc_add_admin_submenu_page($menu, $title, $url, $id, $capability = 'administrator')
{
}

function osc_validate_email($email, $required = true)
{
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function osc_validate_url($value, $required = false, $headers = false)
{
    return (bool)filter_var($value, FILTER_VALIDATE_URL);
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/ui/Field.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/ui/Form.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/ui/SettingsForm.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

/** Draw a declared page through the view core owns, and hand back the markup. */
function render_settings_page(string $pageId): string
{
    $GLOBALS['viewVars'] = array(
        'settings_page'   => osc_settings_page($pageId),
        'settings_values' => osc_settings_values($pageId),
    );
    ob_start();
    require ABS_PATH . 'oc-includes/osclass/gui/admin/settings-page.php';

    return (string)ob_get_clean();
}

/** Assert $needle appears in $html, reporting the markup when it does not. */
function emits(string $label, string $html, string $needle): void
{
    check($label, strpos($html, $needle) !== false, $needle . ' not in: ' . $html);
}

/**
 * The translated fields in $html, each as its tab hrefs in order and its panels as
 * id => visible. Split on the widget wrapper, so one field's panels cannot be read as
 * another's.
 *
 * @return array<int,array{tabs:array<int,string>,panels:array<string,bool>}>
 */
function translate_widgets(string $html): array
{
    $widgets = array();
    foreach (array_slice(explode('<div class="field-translate">', $html), 1) as $chunk) {
        preg_match_all('#<li[^>]*><a href="([^"]*)">#', $chunk, $tabs);
        preg_match_all('#<div class="field-translate-panel" id="([^"]*)"( hidden)?>#', $chunk, $panels);
        $visible = array();
        foreach ($panels[1] as $i => $id) {
            $visible[$id] = ($panels[2][$i] ?? '') === '';
        }
        $widgets[] = array('tabs' => $tabs[1], 'panels' => $visible);
    }

    return $widgets;
}

/** Change which locales are enabled, dropping the list the helpers cache per request. */
function set_locales(array $locales): void
{
    $GLOBALS['locales'] = $locales;
    osc_settings_locales(true);
}

/** A Chrome or Chromium binary this machine has, or '' when it has none. */
function test_browser(): string
{
    if (!function_exists('shell_exec')) {
        return '';
    }

    $candidates = array((string)getenv('CHROME'), 'google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser');
    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }
        if (strpos($candidate, '/') !== false) {
            if (is_executable($candidate)) {
                return $candidate;
            }
            continue;
        }
        $found = trim((string)shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
        if ($found !== '' && is_executable($found)) {
            return $found;
        }
    }

    return '';
}

/**
 * Load a rendered settings page in a real browser, with the real ui-osc.js and the
 * committed stylesheet beside it, run one of the drivers in tests/lib/ over it, and hand
 * back whatever the driver reported.
 *
 * @return array|null the driver's reading, or null when the browser produced nothing
 */
function drive_page(string $chrome, string $body, string $driver): ?array
{
    $dir = sys_get_temp_dir() . '/osc-depends-' . getmypid();
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        return null;
    }
    $fixture = $dir . '/page.html';
    file_put_contents($fixture, '<!doctype html><html><head><meta charset="utf-8">'
        . '<link rel="stylesheet" href="file://' . ABS_PATH . 'oc-admin/themes/modern/css/main.css">'
        . '</head><body>' . $body
        . '<pre id="osc-out"></pre>'
        . '<script src="file://' . ABS_PATH . 'oc-admin/themes/modern/js/ui-osc.js"></script>'
        . '<script>' . file_get_contents(__DIR__ . '/lib/' . $driver) . '</script>'
        . '</body></html>');

    $out = (string)shell_exec(escapeshellarg($chrome)
        . ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage'
        . ' --user-data-dir=' . escapeshellarg($dir . '/profile')
        . ' --virtual-time-budget=5000 --dump-dom ' . escapeshellarg('file://' . $fixture) . ' 2>/dev/null');

    shell_exec('rm -rf ' . escapeshellarg($dir));
    if (!preg_match('#<pre id="osc-out">([A-Za-z0-9+/=]*)</pre>#', $out, $m) || $m[1] === '') {
        return null;
    }

    $decoded = json_decode((string)base64_decode($m[1], true), true);

    return is_array($decoded) ? $decoded : null;
}

/** Submit $params to a declared page and hand back the save result. */
function submit(string $pageId, array $params): array
{
    $GLOBALS['params'] = $params;

    return osc_settings_save($pageId);
}

/** The preference keys a page's section holds, sorted so a comparison is stable. */
function stored(string $section): array
{
    $keys = array();
    foreach ($GLOBALS['preferences'] as $key => $value) {
        if (strpos($key, $section . '/') === 0) {
            $keys[] = substr($key, strlen($section) + 1);
        }
    }
    sort($keys);

    return $keys;
}

SettingsPageRegistry::instance()->register('cond', array(
    'title'  => 'Conditional',
    'fields' => array(
        array('type' => 'checkbox', 'name' => 'b_enabled', 'label' => 'Enable it'),
        array('type' => 'text', 'name' => 's_key', 'label' => 'Key', 'required' => true, 'depends' => 'b_enabled'),
        array('type' => 'text', 'name' => 's_always', 'label' => 'Always'),
    ),
));

harness_section('a dependent field whose master is off');

$GLOBALS['preferences'] = array();
$result = submit('cond', array('s_key' => 'sk-live-9f2a', 's_always' => 'kept'));
// The row was hidden, so the browser should not have sent this at all -- but a request is
// whatever the sender says it is, and the client script is not what decides.
check(
    'its posted value is not stored',
    !array_key_exists('cond/s_key', $GLOBALS['preferences']),
    var_export($GLOBALS['preferences'], true)
);
pin('and nothing else is stored in its place', array('b_enabled', 's_always'), stored('cond'));
pin('the submission is not rejected for having discarded it', 0, count($result['errors']));
check(
    'the value never reaches the caller either',
    !array_key_exists('s_key', $result['values']),
    var_export(array_keys($result['values']), true)
);
pin('a field with no depends at all is untouched by any of this', 'kept', $GLOBALS['preferences']['cond/s_always'] ?? null);

// The field is declared required. Left empty while its master is off it still has to
// save: otherwise the administrator is told to fill in a field that is not on screen,
// and the page cannot be saved at all until the master is switched on.
$GLOBALS['preferences'] = array();
$result = submit('cond', array('s_key' => '', 's_always' => 'kept'));
pin('an empty required field is not required while its master is off', 0, count($result['errors']));
check('so the rest of the page still saves', array_key_exists('cond/s_always', $GLOBALS['preferences']));

harness_section('a dependent field whose master is on');

$GLOBALS['preferences'] = array();
$result = submit('cond', array('b_enabled' => '1', 's_key' => 'sk-live-9f2a', 's_always' => 'kept'));
pin('the save succeeds with the dependency met', 0, count($result['errors']));
pin('and the value is stored', 'sk-live-9f2a', $GLOBALS['preferences']['cond/s_key'] ?? null);

$GLOBALS['preferences'] = array();
$result = submit('cond', array('b_enabled' => '1', 's_key' => '', 's_always' => 'kept'));
pin('an empty value is now rejected, because the field is required again', 1, count($result['errors']));
pin('and the error names the field', 'Key cannot be left empty', $result['errors'][0] ?? '');
// The all-or-nothing guarantee still holds over a rejected dependent field.
pin('a rejected submission writes nothing at all', array(), stored('cond'));

harness_section('what a master value has to look like to count as on');

// Exercised directly, not only through a save: everything reaching it that way has been
// through osc_settings_sanitize() first, so a field carrying its own 'sanitize' callback
// is the case where this has to answer on its own.
check('nothing submitted is off', osc_settings_master_on('') === false);
check('so is the "0" an unticked checkbox stores', osc_settings_master_on('0') === false);
check('and whitespace, which is not an answer either', osc_settings_master_on("  \t\n ") === false);
check('a missing value is off rather than an error', osc_settings_master_on(null) === false);
check('"1" is on', osc_settings_master_on('1') === true);
check('and so is any other text', osc_settings_master_on('0.0') === true);
// A multi-select or a checkbox group arrives as an array.
check('an array is on when any member is', osc_settings_master_on(array('', '0', 'x')) === true);
check('and off when none is', osc_settings_master_on(array('', '0')) === false);
check('an empty array is off', osc_settings_master_on(array()) === false);

harness_section('what counts as "off" for a master that is not a checkbox');

SettingsPageRegistry::instance()->register('masters', array(
    'title'  => 'Masters',
    'fields' => array(
        array('type' => 'select', 'name' => 'mode', 'label' => 'Mode',
              'options' => array('0' => 'Off', '1' => 'On')),
        array('type' => 'text', 'name' => 's_word', 'label' => 'Word'),
        array('type' => 'text', 'name' => 's_by_mode', 'label' => 'By mode', 'depends' => 'mode'),
        array('type' => 'text', 'name' => 's_by_word', 'label' => 'By word', 'depends' => 's_word'),
    ),
));

// A checkbox is read by presence, so its master value is already a bool. Everything else
// arrives as a string, and '0' is the string an off/on select stores -- read as "not
// empty, therefore on", a switch turned off would keep feeding its dependent field.
$GLOBALS['preferences'] = array();
submit('masters', array('mode' => '0', 's_word' => '', 's_by_mode' => 'x', 's_by_word' => 'y'));
check('a select master submitting "0" is off', !array_key_exists('masters/s_by_mode', $GLOBALS['preferences']));
check('a text master left empty is off', !array_key_exists('masters/s_by_word', $GLOBALS['preferences']));
$GLOBALS['preferences'] = array();
submit('masters', array('mode' => '0', 's_word' => '   ', 's_by_mode' => 'x', 's_by_word' => 'y'));
// Whitespace is sanitised away before the rule is reached, so what this pins is the rule
// applied to what actually arrives: an empty string is off.
check('a text master sanitised down to nothing is off', !array_key_exists('masters/s_by_word', $GLOBALS['preferences']));
$GLOBALS['preferences'] = array();
submit('masters', array('mode' => '1', 's_word' => 'set', 's_by_mode' => 'x', 's_by_word' => 'y'));
pin('a select master on any other option is on', 'x', $GLOBALS['preferences']['masters/s_by_mode'] ?? null);
pin('and a text master with anything in it', 'y', $GLOBALS['preferences']['masters/s_by_word'] ?? null);

harness_section('a chain of dependencies');

SettingsPageRegistry::instance()->register('chain', array(
    'title'  => 'Chain',
    'fields' => array(
        array('type' => 'checkbox', 'name' => 'b_one', 'label' => 'One'),
        array('type' => 'checkbox', 'name' => 'b_two', 'label' => 'Two', 'depends' => 'b_one'),
        array('type' => 'text', 'name' => 's_three', 'label' => 'Three', 'depends' => 'b_two'),
    ),
));

$GLOBALS['preferences'] = array();
submit('chain', array('b_one' => '1', 'b_two' => '1', 's_three' => 'deep'));
pin('everything is stored while the whole chain is on', 'deep', $GLOBALS['preferences']['chain/s_three'] ?? null);

$GLOBALS['preferences'] = array();
submit('chain', array('b_two' => '1', 's_three' => 'deep'));
// The middle field is hidden and its own posted value is discarded, so taking that value
// at face value would leave the grandchild alive under a master that is off.
check(
    'a grandchild is discarded when the grandparent is off',
    !array_key_exists('chain/s_three', $GLOBALS['preferences']),
    var_export($GLOBALS['preferences'], true)
);
check('and so is the middle field it depends on', !array_key_exists('chain/b_two', $GLOBALS['preferences']));
pin('while the master itself is still written', array('b_one'), stored('chain'));

// The same chain, declared bottom-up. Every answer has to be worked out against the
// submission as it arrived: resolve and discard field by field and this page keeps the
// grandchild, because by the time it is reached its master has already been dropped for
// an unrelated reason.
SettingsPageRegistry::instance()->register('chainup', array(
    'title'  => 'Chain, declared bottom-up',
    'fields' => array(
        array('type' => 'text', 'name' => 's_three', 'label' => 'Three', 'depends' => 'b_two'),
        array('type' => 'checkbox', 'name' => 'b_two', 'label' => 'Two', 'depends' => 'b_one'),
        array('type' => 'checkbox', 'name' => 'b_one', 'label' => 'One'),
    ),
));
$GLOBALS['preferences'] = array();
submit('chainup', array('b_two' => '1', 's_three' => 'deep'));
pin('declaration order does not change what is discarded', array('b_one'), stored('chainup'));

harness_section('a dependent field that follows one value of its master');

SettingsPageRegistry::instance()->register('byvalue', array(
    'title'  => 'By value',
    'fields' => array(
        array('type' => 'checkbox', 'name' => 'b_wm', 'label' => 'Watermark on'),
        array('type' => 'select', 'name' => 'wm_type', 'label' => 'Watermark', 'depends' => 'b_wm',
              'options' => array('none' => 'None', 'text' => 'Text', 'image' => 'Image')),
        array('type' => 'text', 'name' => 's_wm_text', 'label' => 'Text', 'required' => true,
              'depends' => 'wm_type', 'depends_value' => 'text'),
        array('type' => 'text', 'name' => 's_wm_image', 'label' => 'Image',
              'depends' => 'wm_type', 'depends_value' => array('image')),
        array('type' => 'text', 'name' => 's_wm_any', 'label' => 'Either',
              'depends' => 'wm_type', 'depends_value' => array('text', 'image')),
        array('type' => 'text', 'name' => 's_font', 'label' => 'Font', 'depends' => 's_wm_text'),
        array('type' => 'select', 'name' => 'wm_pos', 'label' => 'Position',
              'depends' => 'wm_type', 'depends_value' => 'text',
              'options' => array('corner' => 'Corner', 'custom' => 'Custom')),
        array('type' => 'text', 'name' => 's_offset', 'label' => 'Offset', 'depends' => 'wm_pos', 'depends_value' => 'custom'),
        array('type' => 'radio', 'name' => 'r_plan', 'label' => 'Plan',
              'options' => array('free' => 'Free', 'paid' => 'Paid', 'a&b' => 'Both', '0' => 'Zero')),
        array('type' => 'text', 'name' => 's_card', 'label' => 'Card', 'required' => true,
              'depends' => 'r_plan', 'depends_value' => 'paid'),
        array('type' => 'text', 'name' => 's_amp', 'label' => 'Amp', 'depends' => 'r_plan', 'depends_value' => 'a&b'),
        array('type' => 'text', 'name' => 's_zero', 'label' => 'Zero', 'depends' => 'r_plan', 'depends_value' => '0'),
    ),
));

// Every dependent is posted with something in it, so what is stored is exactly the set
// whose step is met. A required field is filled only where the case says so.
$posted = array(
    's_wm_image' => 'logo.png', 's_wm_any' => 'any', 's_font' => 'serif', 's_offset' => '12', 's_amp' => 'amp',
    's_zero'     => 'zero', 'b_wm' => '1', 'wm_pos' => 'corner',
);
foreach (array(
    'the master on a value no dependent names' => array(
        array('wm_type' => 'none', 'r_plan' => 'free', 's_wm_text' => '', 's_card' => ''),
        0,
        array('b_wm', 'r_plan', 'wm_type'),
    ),
    // '0' is off under the on/off rule; named as a value it is simply a value.
    'a "0" option named as the value' => array(
        array('wm_type' => 'none', 'r_plan' => '0', 's_wm_text' => '', 's_card' => ''),
        0,
        array('b_wm', 'r_plan', 's_zero', 'wm_type'),
    ),
    'the select on "text"' => array(
        array('wm_type' => 'text', 'r_plan' => 'free', 's_wm_text' => '(c) me', 's_card' => ''),
        0,
        array('b_wm', 'r_plan', 's_font', 's_wm_any', 's_wm_text', 'wm_pos', 'wm_type'),
    ),
    // The required text field is off here, so it is neither required nor stored -- and the
    // font following it goes with it, although its own master still holds a value.
    'the select on "image"' => array(
        array('wm_type' => 'image', 'r_plan' => 'free', 's_wm_text' => '(c) me', 's_card' => ''),
        0,
        array('b_wm', 'r_plan', 's_wm_any', 's_wm_image', 'wm_type'),
    ),
    // A value step whose master is switched off by an on/off step above it: the select
    // still says "text", and nothing under it survives.
    'a value step under a checkbox that is off' => array(
        array('b_wm' => '', 'wm_type' => 'text', 'wm_pos' => 'custom', 'r_plan' => 'free', 's_wm_text' => '(c) me', 's_card' => ''),
        0,
        array('b_wm', 'r_plan'),
    ),
    'a value step under a value step, both met' => array(
        array('wm_type' => 'text', 'wm_pos' => 'custom', 'r_plan' => 'free', 's_wm_text' => '(c) me', 's_card' => ''),
        0,
        array('b_wm', 'r_plan', 's_font', 's_offset', 's_wm_any', 's_wm_text', 'wm_pos', 'wm_type'),
    ),
    // The position still says "custom", but its own step is not met, so the offset goes too.
    'a value step under a value step whose own step is not met' => array(
        array('wm_type' => 'image', 'wm_pos' => 'custom', 'r_plan' => 'free', 's_wm_text' => '', 's_card' => ''),
        0,
        array('b_wm', 'r_plan', 's_wm_any', 's_wm_image', 'wm_type'),
    ),
    'the radio on "paid", with its required field left empty' => array(
        array('wm_type' => 'none', 'r_plan' => 'paid', 's_wm_text' => '', 's_card' => ''),
        1,
        array(),
    ),
    'the radio on "paid", filled in' => array(
        array('wm_type' => 'none', 'r_plan' => 'paid', 's_wm_text' => '', 's_card' => '4242'),
        0,
        array('b_wm', 'r_plan', 's_card', 'wm_type'),
    ),
    'a value holding an ampersand' => array(
        array('wm_type' => 'none', 'r_plan' => 'a&b', 's_wm_text' => '', 's_card' => ''),
        0,
        array('b_wm', 'r_plan', 's_amp', 'wm_type'),
    ),
    // A value a hand-made request invents is refused by the select itself, and no
    // dependent is kept alive by it in the meantime.
    'a master value the page never offered' => array(
        array('wm_type' => 'TEXT', 'r_plan' => 'free', 's_wm_text' => 'x', 's_card' => ''),
        1,
        array(),
    ),
) as $label => [$params, $errorCount, $keys]) {
    $GLOBALS['preferences'] = array();
    $result = submit('byvalue', $params + $posted);
    pin($label . ': errors', $errorCount, count($result['errors']));
    pin($label . ': what is stored', $keys, stored('byvalue'));
}

$byValueFields = SettingsPageRegistry::instance()->fields('byvalue');
// Straight at the rule, past the select's own validation: a string compare, not a loose one.
check('"text" is met with the chain above it on', osc_settings_depends_met($byValueFields, 's_wm_text', array('b_wm' => true, 'wm_type' => 'text')) === true);
check('"text " is not "text"', osc_settings_depends_met($byValueFields, 's_wm_text', array('b_wm' => true, 'wm_type' => 'text ')) === false);
check('an int master value compares as its string', osc_settings_depends_met($byValueFields, 's_zero', array('r_plan' => 0)) === true);
check('a missing master matches no value', osc_settings_depends_met($byValueFields, 's_wm_text', array('b_wm' => true)) === false);

harness_section('the relationship reaches the markup');

$html = render_settings_page('cond');
// One shared script reads this. A page that has to ship its own JavaScript to hide a row
// is exactly what this replaces.
emits('the dependent row carries the master it follows', $html, 'data-osc-depends="b_enabled"');
pin('and only the dependent row carries it', 1, substr_count($html, 'data-osc-depends='));
emits('the row is still a form-row', $html, '<div class="form-row" data-osc-depends="b_enabled">');
emits('and the control itself is unchanged', $html, 'id="field-s_key"');
check('a row with no value to match carries no value list', strpos($html, 'data-osc-depends-value') === false, $html);

$GLOBALS['preferences'] = array(
    'byvalue/b_wm' => true, 'byvalue/wm_type' => 'none', 'byvalue/r_plan' => 'free', 'byvalue/s_wm_text' => '(c) me',
);
$byValueHtml = render_settings_page('byvalue');
emits(
    'a value is emitted beside the master, as a JSON list',
    $byValueHtml,
    '<div class="form-row" data-osc-depends="wm_type" data-osc-depends-value="[&quot;text&quot;]">'
);
emits('a list stays a list', $byValueHtml, 'data-osc-depends-value="[&quot;text&quot;,&quot;image&quot;]"');
// Hex-escaped inside the JSON, so osc_esc_html() never meets an "&" it might take for an
// entity and leave alone.
emits('an ampersand reaches the attribute as a JSON escape', $byValueHtml, 'data-osc-depends-value="[&quot;a\\u0026b&quot;]"');
pin('every value row carries one, and no on/off row does', 8, substr_count($byValueHtml, 'data-osc-depends-value='));

$sharedScript = (string)file_get_contents(ABS_PATH . 'oc-admin/themes/modern/js/ui-osc.js');
// The needles below live inside oscSyncDepends and its two helpers, which is a function
// nothing calls unless these registrations exist -- dead code that reads exactly like
// working code, and leaves every dependent row on screen and required for ever.
preg_match_all('/document\\.addEventListener\\(\\s*\'([A-Za-z]+)\'[\\s\\S]{0,200}?oscSyncDepends/', $sharedScript, $m);
pin('the sync runs at load and on every edit', array('DOMContentLoaded', 'change', 'input'), $m[1]);

harness_section('the shared script, driven in a browser');

// Source greps cannot tell a script that runs from one that is merely present, and the
// failure that matters is not a missing string: it is a row that stays visible and stays
// required, which is a page that cannot be saved at all. So the real file is loaded, with
// the committed stylesheet beside it, and asked what it does.
$chrome = test_browser();
// A contributor without Chrome is not blocked, but a CI run that skips this is green with
// every browser assertion below missing and nothing in the log says so.
if ($chrome === '' && (string)getenv('CI') !== '') {
    check(
        'a browser is available to drive the shared script',
        false,
        'no Chrome or Chromium on PATH, so the browser-driven assertions below did not run'
    );
}
if ($chrome === '') {
    echo "  (skipped: no Chrome or Chromium on this machine)\n";
} else {
    $snaps = drive_page($chrome, render_settings_page('cond'), 'depends-driver.js');
    check('the depends browser run produced a reading', is_array($snaps) && count($snaps) === 4, var_export($snaps, true));

    $atLoad = $snaps[0] ?? array();
    check('the dependent row starts hidden, its master being off', ($atLoad['hidden'] ?? null) === true, var_export($atLoad, true));
    // Hiding a .form-row means [hidden] beating the display:flex it is given as a member of
    // .form-horizontal. Committed CSS is what ships -- a release runs no build -- so the
    // question is what the stylesheet in the tree does, not what the source says.
    pin('and the committed stylesheet really takes it off the page', 'none', $atLoad['display'] ?? null);
    // A hidden control that is still `required` blocks submission with a browser message
    // pointing at a field nobody can see.
    check('the control it holds is not required while hidden', ($atLoad['required'] ?? null) === false, var_export($atLoad, true));

    $on = $snaps[1] ?? array();
    check('ticking the master shows the row', ($on['hidden'] ?? null) === false, var_export($on, true));
    pin('and it takes up space again', 'flex', $on['display'] ?? null);
    check('and the required flag it was declared with comes back', ($on['required'] ?? null) === true, var_export($on, true));

    $off = $snaps[2] ?? array();
    check('unticking it hides the row again', ($off['hidden'] ?? null) === true, var_export($off, true));
    check('and lifts required a second time', ($off['required'] ?? null) === false, var_export($off, true));

    // The action row counts what has changed since the page loaded, so Save is quiet on a
    // form nobody has touched and says how much is pending on one somebody has.
    $dirt = drive_page($chrome, render_settings_page('cond'), 'dirty-driver.js');
    check('the dirty-bar browser run produced a reading', is_array($dirt) && count($dirt) === 4, var_export($dirt, true));

    $start = $dirt[0] ?? array();
    check('an untouched form is not dirty', ($start['dirty'] ?? null) === false, var_export($start, true));
    pin('and says nothing', '', $start['text'] ?? null);
    pin('the row is sticky, so it stays reachable down a long page', 'sticky', $start['position'] ?? null);

    pin('one edit counts as one', '1 unsaved change', ($dirt[1]['text'] ?? null));
    check('and the row goes loud', ($dirt[1]['dirty'] ?? null) === true, var_export($dirt[1] ?? null, true));
    pin('a second, on a different control, counts as two', '2 unsaved changes', ($dirt[2]['text'] ?? null));

    // The count is measured against the baseline, not tallied per event, so undoing an edit
    // takes it back down rather than counting the undo as another change.
    pin('putting both back is no change at all', '', ($dirt[3]['text'] ?? null));
    check('and the row goes quiet again', ($dirt[3]['dirty'] ?? null) === false, var_export($dirt[3] ?? null, true));

    // Every keystroke used to re-scan every dependent row on the page; on Permalinks that
    // is 39 of them for a box none of them follows.
    $counted = $snaps[3] ?? array();
    pin('typing in a box no row follows runs no sweep', 0, $counted['afterDependent'] ?? null);
    pin('and touching the master still runs exactly one', 1, $counted['afterMaster'] ?? null);
    check('the row it governs is still shown', ($counted['hidden'] ?? null) === false, var_export($counted, true));

    // The same rows the server matrix above decides, read back from the browser: what the
    // script shows has to be what the save keeps.
    $valued = drive_page($chrome, $byValueHtml, 'depends-value-driver.js');
    check('the depends-value browser run produced a reading', is_array($valued) && count($valued) === 10, var_export($valued, true));
    $shown = static fn (int $i) => $valued[$i]['shown'] ?? null;
    $flag  = static fn (int $i, string $key) => $valued[$i][$key] ?? null;
    $full  = array('s_font', 's_offset', 's_wm_any', 's_wm_text', 'wm_pos');
    pin('at load, on "none", no value row is shown', array(), $shown(0));
    pin('and the required ones it hides are not required', array(false, false), array($flag(0, 'textRequired'), $flag(0, 'cardRequired')));
    pin('the select on "text" shows its rows and the chain under them', array('s_font', 's_wm_any', 's_wm_text', 'wm_pos'), $shown(1));
    pin('and gives the required flag back', true, $flag(1, 'textRequired'));
    pin('a value step under a value step shows once both are met', $full, $shown(2));
    pin('switching the checkbox above off hides every value step under it', array(), $shown(3));
    pin('and lifts required again', false, $flag(3, 'textRequired'));
    pin('switching it back on brings the whole chain back', $full, $shown(4));
    pin('on "image" the text rows go, and every step under them with them', array('s_wm_any', 's_wm_image'), $shown(5));
    pin('the radio on "paid" shows its row', array('s_card', 's_wm_any', 's_wm_image'), $shown(6));
    pin('and its required flag comes back', true, $flag(6, 'cardRequired'));
    pin('a value holding an ampersand survives the attribute', array('s_amp', 's_wm_any', 's_wm_image'), $shown(7));
    pin('and the card row it replaced is not required', false, $flag(7, 'cardRequired'));
    pin('a "0" value shows its row, although "0" is off under the on/off rule', array('s_wm_any', 's_wm_image', 's_zero'), $shown(8));
    pin('a select master still counts as a master: one sweep', 1, $valued[9]['afterMaster'] ?? null);
    pin('and a box nothing follows still runs none', 0, $valued[9]['afterDependent'] ?? null);
}

harness_section('the surface the render path added');

// The locale list is handed in, never fetched: drawing a field must not query anything,
// and render and store have to expand a translated field over the very same list.
pin(
    'localesFor() takes the spec and nothing else',
    'public static localesFor(array $spec)',
    harness_method_signature(mindstellar\admin\ui\Field::class, 'localesFor')
);
pin(
    'translated() is handed the locales rather than looking them up',
    'public static translated($id, array $spec, array $locales)',
    harness_method_signature(mindstellar\admin\ui\Field::class, 'translated')
);
pin(
    'and a panel id is derived, not invented per call site',
    'public static localePanelId($id, $code)',
    harness_method_signature(mindstellar\admin\ui\Field::class, 'localePanelId')
);

harness_section('the locale code is fixed width, and the key spelling rests on it');

// A translated value is stored under the field name with the locale code appended, and
// nothing separates the two -- so 'de' beside 'de_DE' would make the key ambiguous. The
// stubs below stand in for real locales only while they are the same width as real ones.
foreach (osc_settings_locales(true) as $code => $localeName) {
    pin('the stubbed locale "' . $code . '" is five characters, as every real one is', 5, strlen($code));
}
$validate = new Validate();
check('an enabled five-character code is a locale code', $validate->localeCode('en_US') === true);
check('one that is not enabled is not', $validate->localeCode('fr_FR') === false);
// Enabled and still refused: what rules a short code out is its width, not its absence
// from the list, and only the width keeps 'de' from colliding with 'de_DE' in a key.
$GLOBALS['locales'][] = array('pk_c_code' => 'de', 's_name' => 'Deutsch');
check('a shorter code is refused even while it is enabled', $validate->localeCode('de') === false);
$GLOBALS['locales'][count($GLOBALS['locales']) - 1] = array('pk_c_code' => 'en_USXX', 's_name' => 'Long');
check('and so is a longer one', $validate->localeCode('en_USXX') === false);
array_pop($GLOBALS['locales']);
set_locales($GLOBALS['locales']);

harness_section('a translated field, two enabled locales');

SettingsPageRegistry::instance()->register('multi', array(
    'title'  => 'Multi',
    'fields' => array(
        array('type' => 'text', 'name' => 's_title', 'label' => 'Title', 'translate' => true),
        array('type' => 'textarea', 'name' => 's_body', 'label' => 'Body', 'translate' => true),
        array('type' => 'text', 'name' => 's_plain', 'label' => 'Plain'),
    ),
));

$fields = SettingsPageRegistry::instance()->fields('multi');
pin(
    'a translated field expands over every enabled locale',
    array('en_US' => 'English', 'es_ES' => 'Español'),
    osc_settings_field_locales($fields['s_title'])
);
pin('a field that did not ask for it expands over none', array(), osc_settings_field_locales($fields['s_plain']));

$GLOBALS['preferences'] = array();
$result = submit('multi', array(
    's_titleen_US' => 'Hello',
    's_titlees_ES' => 'Hola',
    's_bodyen_US'  => 'Body',
    's_bodyes_ES'  => 'Cuerpo',
    's_plain'      => 'one',
));
pin('the save succeeds across every locale', 0, count($result['errors']));
// The key is the field name with the locale code appended -- read the other way round and
// every locale but one silently loses what was typed into it.
pin(
    'every locale is stored under its own key',
    array('s_bodyen_US', 's_bodyes_ES', 's_plain', 's_titleen_US', 's_titlees_ES'),
    stored('multi')
);
pin('with the value that locale was given', 'Hola', $GLOBALS['preferences']['multi/s_titlees_ES'] ?? null);
check('and nothing is stored under the bare field name', !array_key_exists('multi/s_title', $GLOBALS['preferences']));
pin(
    'reading back hands every locale over, keyed by code',
    array('en_US' => 'Hello', 'es_ES' => 'Hola'),
    osc_settings_value('multi', 's_title')
);
pin('a plain field on the same page still reads back as a scalar', 'one', osc_settings_value('multi', 's_plain'));
pin(
    'and the whole-page read is the same shape',
    array('en_US' => 'Body', 'es_ES' => 'Cuerpo'),
    osc_settings_values('multi')['s_body']
);

$html = render_settings_page('multi');
emits('the tab strip is the shared widget, not a private one', $html, '<div class="osc-tab">');
emits('one tab per locale: the first', $html, '>English <span class="visually-hidden">');
emits('and the second', $html, '>Español</a>');
emits('one control per locale: the first', $html, 'name="s_titleen_US"');
emits('and the second', $html, 'name="s_titlees_ES"');
emits('each carrying that locale\'s stored value', $html, 'value="Hola"');
emits('a translated textarea expands the same way', $html, 'name="s_bodyes_ES"');
emits('its stored value is the element body, not an attribute', $html, '>Cuerpo</textarea>');
// Two translated fields on the page, two locales each.
pin('and every locale gets a panel to sit in', 4, substr_count($html, 'class="field-translate-panel"'));

// A tab strip is a set of links and a set of panels agreeing on ids, and nothing on the
// page announces a disagreement: a tab pointing at an id nothing carries does nothing at
// all, and an id used twice focuses the wrong control.
$widgets = translate_widgets($html);
pin('every translated field gets its own widget', 2, count($widgets));
foreach ($widgets as $index => $widget) {
    pin(
        'widget ' . $index . ': each tab points at the panel next to it',
        array_map(static function ($id) {
            return '#' . $id;
        }, array_keys($widget['panels'])),
        $widget['tabs']
    );
    // All of them open at once is a stack of controls with a tab strip on top of it,
    // which is the widget doing nothing.
    pin(
        'widget ' . $index . ': the first panel is open and the rest are not',
        array(true, false),
        array_values($widget['panels'])
    );
}

// The strip opens on the admin's own language, wherever it sits in the list, and marks it.
$GLOBALS['__adminLocale'] = 'es_ES';
$mine = render_settings_page('multi');
$GLOBALS['__adminLocale'] = 'fr_FR';
$other = render_settings_page('multi');
unset($GLOBALS['__adminLocale']);
foreach (translate_widgets($mine) as $index => $widget) {
    pin(
        'widget ' . $index . ': the admin\'s own language opens, not the first',
        array(false, true),
        array_values($widget['panels'])
    );
}
emits(
    'and its tab is the one marked',
    $mine,
    '<li class="ui-tabs-active ui-state-active" data-osc-tab-mine title="Your admin language"><a href="#field-s_title-es_ES">'
);
pin('only one tab per strip is marked', 2, substr_count($mine, 'data-osc-tab-mine'));
// A locale's name and a panel id are printed into markup, so both are escaped.
$tab = \mindstellar\admin\ui\Field::localeTab('p"x', '<b>De</b>&"', 'de_DE', 'de_DE');
pin(
    'a tab escapes the name it shows and the panel it points at',
    array(false, true, true),
    array(
        strpos($tab, '<b>') !== false,
        strpos($tab, '&lt;b&gt;De&lt;/b&gt;&amp;&quot;') !== false,
        strpos($tab, 'href="#p&quot;x"') !== false,
    )
);
pin(
    'a language the field does not offer opens the first tab and marks none',
    array(0, array(true, false)),
    array(substr_count($other, 'data-osc-tab-mine'), array_values(translate_widgets($other)[0]['panels']))
);

$panelIds = array_merge(...array_map(static function ($widget) {
    return array_keys($widget['panels']);
}, $widgets));
pin('and no two panels on the page share an id', 4, count(array_unique($panelIds)));
// Only the tab strip says which locale a control belongs to, and a tab is not a label.
emits('each control names its locale for assistive tech', $html, 'aria-label="Title (Español)"');
// A `for` pointing at an id nothing carries reaches nothing: the base id does not exist
// on a translated field.
emits('the row label points at the first locale\'s control', $html, '<label for="field-s_titleen_US">Title</label>');
check('there is no control under the bare field name', strpos($html, 'name="s_title"') === false, $html);

harness_section('the tab strip, driven in a browser');

// The markup above only says the ids agree. Whether a click on a tab actually swaps one
// field's panels -- and only that field's -- is the shared widget's job, and a strip of
// tabs that changes nothing looks exactly like one that works.
if ($chrome === '') {
    echo "  (skipped: no Chrome or Chromium on this machine)\n";
} else {
    $tabbed = drive_page($chrome, render_settings_page('multi'), 'translate-driver.js');
    check('the locale-tab browser run produced a reading', is_array($tabbed) && count($tabbed) === 3, var_export($tabbed, true));
    pin('two translated fields, two locales each', 4, $tabbed['tabCount'] ?? null);
    pin(
        'each field opens on its first locale and no other',
        array('field-s_title-en_US', 'field-s_body-en_US'),
        $tabbed['atLoad'] ?? null
    );
    // The second field is untouched: two widgets sharing a panel id would move together.
    pin(
        'clicking a tab swaps that field\'s panel and leaves the other alone',
        array('field-s_title-es_ES', 'field-s_body-en_US'),
        $tabbed['afterClick'] ?? null
    );
}

harness_section('a translated field whose value has not been set yet');

SettingsPageRegistry::instance()->register('transdefault', array(
    'title'  => 'Translated with a default',
    'fields' => array(
        array('type' => 'text', 'name' => 's_motto', 'label' => 'Motto', 'translate' => true, 'default' => 'Nothing yet'),
    ),
));

$GLOBALS['preferences'] = array();
// A default belongs to the field, not to one locale: a fresh install has nothing stored
// under any of the per-locale keys.
pin(
    'every locale falls back to the declared default',
    array('en_US' => 'Nothing yet', 'es_ES' => 'Nothing yet'),
    osc_settings_value('transdefault', 's_motto')
);
$GLOBALS['preferences']['transdefault/s_mottoes_ES'] = 'Hola';
pin(
    'and only the locales that still have nothing',
    array('en_US' => 'Nothing yet', 'es_ES' => 'Hola'),
    osc_settings_value('transdefault', 's_motto')
);
$html = render_settings_page('transdefault');
emits('the empty locale draws the default', $html, 'name="s_mottoen_US" class="input-text field-text" value="Nothing yet"');
emits('the other draws what it holds', $html, 'name="s_mottoes_ES" class="input-text field-text" value="Hola"');

harness_section('a before_save listener that hands back the wrong shape');

// The filter runs after validation and before the write, so what it returns is what the
// store sees. A translated field is one value per locale; a scalar has nothing to spread
// over them, and the bare name is a key this page never reads back -- so the field is
// left alone rather than half-written under keys nothing will find again.
$GLOBALS['preferences'] = array();
listen('admin_form_before_save', static function (array $values) {
    $values['s_title'] = 'one value for every language';

    return $values;
});
$result = submit('multi', array(
    's_titleen_US' => 'Hello',
    's_titlees_ES' => 'Hola',
    's_bodyen_US'  => 'Body',
    's_bodyes_ES'  => 'Cuerpo',
    's_plain'      => 'one',
));
unlisten();
pin('the save still succeeds', 0, count($result['errors']));
pin(
    'the flattened field is not written at all',
    array('s_bodyen_US', 's_bodyes_ES', 's_plain'),
    stored('multi')
);
check(
    'least of all under the bare name',
    !array_key_exists('multi/s_title', $GLOBALS['preferences']),
    var_export($GLOBALS['preferences'], true)
);

harness_section('a translated field, one enabled locale');

set_locales(array(array('pk_c_code' => 'en_US', 's_name' => 'English')));
$GLOBALS['preferences'] = array();
submit('multi', array('s_titleen_US' => 'Hello', 's_bodyen_US' => 'Body', 's_plain' => 'one'));
// Still the per-locale key: were it stored bare, enabling a second locale later would
// strand everything already written.
pin('the one locale still stores under its own key', 'Hello', $GLOBALS['preferences']['multi/s_titleen_US'] ?? null);

$html = render_settings_page('multi');
emits('the control is there', $html, 'name="s_titleen_US"');
// A single tab is a label pretending to be a choice.
check('but a single locale sprouts no tab strip', strpos($html, 'osc-tab') === false, $html);
check('and no panels either', strpos($html, 'field-translate-panel') === false, $html);
emits('the row label still points at the control', $html, '<label for="field-s_titleen_US">Title</label>');

set_locales(array(
    array('pk_c_code' => 'en_US', 's_name' => 'English'),
    array('pk_c_code' => 'es_ES', 's_name' => 'Español'),
));

harness_section('translated and required');

SettingsPageRegistry::instance()->register('reqtrans', array(
    'title'  => 'Required translation',
    'fields' => array(
        array('type' => 'text', 'name' => 's_name', 'label' => 'Name', 'translate' => true, 'required' => true),
    ),
));

$GLOBALS['preferences'] = array();
$result = submit('reqtrans', array('s_nameen_US' => 'Hello', 's_namees_ES' => ''));
pin('an empty locale is rejected', 1, count($result['errors']));
// "Name cannot be left empty" on a page with two tabs does not say which tab to open.
pin('and the error names the locale', 'Name (Español) cannot be left empty', $result['errors'][0] ?? '');
pin('nothing is written, including the locale that was filled in', array(), stored('reqtrans'));

$result = submit('reqtrans', array('s_nameen_US' => 'Hello', 's_namees_ES' => 'Hola'));
pin('filling every locale saves', 0, count($result['errors']));
pin('and writes both', 2, count(stored('reqtrans')));

harness_section('the two features on one field');

SettingsPageRegistry::instance()->register('both', array(
    'title'  => 'Both',
    'fields' => array(
        array('type' => 'checkbox', 'name' => 'b_on', 'label' => 'On'),
        array('type' => 'text', 'name' => 's_tag', 'label' => 'Tag', 'translate' => true, 'depends' => 'b_on'),
    ),
));

$GLOBALS['preferences'] = array();
submit('both', array('s_tagen_US' => 'Hello', 's_tages_ES' => 'Hola'));
pin('a hidden translated field stores no locale at all', array('b_on'), stored('both'));
$GLOBALS['preferences'] = array();
submit('both', array('b_on' => '1', 's_tagen_US' => 'Hello', 's_tages_ES' => 'Hola'));
pin('and every locale once the master is on', array('b_on', 's_tagen_US', 's_tages_ES'), stored('both'));

exit(harness_result());
