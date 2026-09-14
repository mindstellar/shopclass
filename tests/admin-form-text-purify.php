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
 * Pins what a declared text field stores, and the length cap its control tells the browser.
 *
 * The declared save path reads the request with the XSS check switched off, so a declared
 * text or textarea stored markup that the hand-written controller it replaces -- reading
 * the same field through Params::getParam() -- had always stripped. A screen that stores
 * more after being migrated than before it inverts the reason to migrate, and nothing on
 * the page shows it, because core escapes on the way out either way.
 *
 * What is taken out is therefore measured against Params::getParam()'s live output rather
 * than a transcribed constant, so the pin cannot rot if the purifier's configuration ever
 * moves. Storing the escaped form is deliberate and is pinned as such: osc_esc_html() is
 * built to carry an entity through untouched, so a pre-escaped row prints correctly, while
 * a bare "&" in the same row prints as a bare "&".
 *
 * The escaping also means the stored value can be longer than the typed one, so maxlength
 * is emitted only on a control whose value is not purified. The cap itself is enforced on
 * the stored value either way -- the column is what it protects.
 *
 * Usage:  php tests/admin-form-text-purify.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/Params.php';

// --- the slice of core the helpers lean on ------------------------------------------
$GLOBALS['preferences'] = array();
$GLOBALS['locales']     = array(
    array('pk_c_code' => 'en_US', 's_name' => 'English'),
    array('pk_c_code' => 'es_ES', 's_name' => 'Español'),
);

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

class OSCLocale
{
    public static function newInstance()
    {
        return new self();
    }

    public function listAllEnabled()
    {
        return $GLOBALS['locales'];
    }
}

function osc_set_preference($key, $value = '', $section = 'osclass', $type = 'STRING')
{
    $GLOBALS['preferences'][$section . '/' . $key] = $value;

    return 1;
}

function osc_add_hook($hook, $fn, $priority = 5)
{
}

function osc_run_hook($hook, ...$args)
{
}

function osc_apply_filter($hook, $content = '', ...$args)
{
    return $content;
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

function __($key, $domain = 'core')
{
    return $key;
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

use mindstellar\settings\SettingsPageRegistry;

/** Put a submission in front of the helpers, exactly as a request would. */
function submit(array $params): void
{
    $_GET  = $params;
    $_POST = array();
    Params::init();
}

/** Capture what a helper prints. */
function render(callable $fn): string
{
    ob_start();
    $fn();

    return (string)ob_get_clean();
}

// The value the measurement was taken on: a tag, what it contains, and space either side.
$dirty = '<script>alert(1)</script> spam  ';

harness_section('osc_sanitize_text() takes out what the request path takes out');

// Both sides live. A constant here would let the two drift apart silently the day the
// purifier's configuration moves, which is the one thing this helper exists to prevent.
submit(array('x' => $dirty, 'plain' => 'nothing to strip'));
pin('the helper does to a value what getParam() does to the request', Params::getParam('x'), osc_sanitize_text($dirty));
pin('a value with nothing to strip comes back as it was', 'nothing to strip', osc_sanitize_text('nothing to strip'));
check('and the tag is gone, contents and all', strpos(osc_sanitize_text($dirty), 'alert(1)') === false);
// A per-locale map is an array of values, and dropping one would store a locale's text
// unfiltered while its neighbour was cleaned.
pin(
    'an array is walked rather than dropped',
    array('en_US' => ' spam  ', 'es_ES' => ' correo  '),
    osc_sanitize_text(array('en_US' => $dirty, 'es_ES' => '<script>alert(1)</script> correo  '))
);
pin('a non-string is handed straight back', 42, osc_sanitize_text(42));
pin('and so is null', null, osc_sanitize_text(null));

harness_section('what it leaves behind is escaped, and stays that way on the page');

// The purifier escapes what it kept, so the stored value is inert wherever it is printed --
// including by a plugin that forgets to escape it. osc_esc_html() carries an entity through
// untouched, so storing the escaped form is what makes the round trip come out as typed;
// storing the bare character puts a raw "&" on the page instead.
submit(array('amp' => 'Q&A; Session'));
pin('the request path escapes an ampersand', 'Q&amp;A; Session', Params::getParam('amp'));
pin('and so does the helper', 'Q&amp;A; Session', osc_sanitize_text('Q&A; Session'));
pin('escaping an already-escaped value changes nothing', 'Q&amp;A; Session', osc_esc_html('Q&amp;A; Session'));
check(
    'while the bare character does not survive the same trip',
    osc_esc_html('Q&A; Session') === 'Q&A; Session',
    osc_esc_html('Q&A; Session')
);
pin('so the stored value prints as the admin typed it', 'Q&amp;A; Session', osc_esc_html(osc_sanitize_text('Q&A; Session')));

// Escaping lengthens, which is why no maxlength is promised on a purified control: twelve
// typed characters reach the column as sixteen.
pin('one typed character can reach the column as five', 16, mb_strlen((string)osc_sanitize_text('Q&A; Session')));

$nasty = array(
    '<script>alert(1)</script>',
    '&lt;script&gt;alert(1)&lt;/script&gt;',
    '&#60;script&#62;alert(1)&#60;/script&#62;',
    '&amp;lt;script&amp;gt;',
    '<img src=x onerror=alert(1)>',
    '"><svg/onload=alert(1)>',
    '< script>alert(1)</script>',
    '<scr<x>ipt>alert(1)</scr<x>ipt>',
);
foreach ($nasty as $value) {
    $plain = osc_sanitize_text($value);
    check(
        'nothing that opens a tag survives ' . $value,
        preg_match('#<[a-zA-Z/!?]#', $plain) === 0,
        var_export($plain, true)
    );
    // A screen that re-saves what it loaded must store the same thing again, or every visit
    // to the page rewrites the value.
    pin('and re-saving it changes nothing more: ' . $value, $plain, osc_sanitize_text($plain));
}

// Nor does printing it: osc_esc_html() carries an entity through untouched, so a stored
// value does not grow an escape on every render. A bare quote is the one character it adds
// -- the purifier leaves that alone in text -- so this is a first escape, not a second.
foreach (array('<script>alert(1)</script>', '&lt;script&gt;', 'Tom & Jerry', '5 > 3 & 2 < 4') as $value) {
    $plain = osc_sanitize_text($value);
    pin('printing the stored value changes nothing: ' . $value, $plain, osc_esc_html($plain));
}

harness_section('what a declared text field stores');

SettingsPageRegistry::instance()->register('purify.test', array(
    'title'  => 'Purify',
    'fields' => array(
        array('type' => 'text', 'name' => 'title', 'label' => 'Title'),
        array('type' => 'textarea', 'name' => 'body', 'label' => 'Body'),
        array('type' => 'text', 'name' => 'slug', 'label' => 'Slug',
              'sanitize' => static fn ($v) => strtolower($v)),
        array('type' => 'text', 'name' => 'snippet', 'label' => 'Snippet', 'purify' => false),
        array('type' => 'textarea', 'name' => 'template', 'label' => 'Template', 'purify' => false),
        array('type' => 'secret', 'name' => 'api_key', 'label' => 'API key', 'write_only' => false),
    ),
));
$fields = SettingsPageRegistry::instance()->fields('purify.test');

submit(array(
    'title'    => $dirty,
    'body'     => $dirty,
    'slug'     => '<b>MY</b> SLUG',
    'snippet'  => $dirty,
    'template' => $dirty,
    'api_key'  => $dirty,
));

// The hand-written half of the comparison, taken now rather than written down: this is
// what the controller this layer replaces read out of the same request. There is nothing
// here for the purifier to escape, so the two agree character for character; where they do
// not is pinned in the section above.
$handWritten = trim((string)Params::getParam('title'));
pin('a text field stores what the hand-written path stored', $handWritten, osc_settings_sanitize($fields['title']));
pin('a textarea does the same', $handWritten, osc_settings_sanitize($fields['body']));
check(
    'so the script tag reaches no row',
    strpos((string)osc_settings_sanitize($fields['title']), '<script>') === false,
    var_export(osc_settings_sanitize($fields['title']), true)
);
// The order matters: a field's own sanitiser is handed text, so a screen no longer has to
// strip tags itself before doing anything else with the value.
pin('a field sanitiser is handed the purified value', 'my slug', osc_settings_sanitize($fields['slug']));

// Some settings hold markup or code on purpose -- an e-mail template, a snippet, a page
// body. Purifying those would empty them, so a field says so and is read raw.
pin('a field declaring purify => false keeps its markup', trim($dirty), osc_settings_sanitize($fields['snippet']));
pin('and so does a textarea declaring it', trim($dirty), osc_settings_sanitize($fields['template']));
// A secret may legitimately contain anything, and always could. It is the one type that
// keeps its surrounding whitespace too: a password with a space at either end is the
// password, and the sign-in form reads what was typed rather than a trimmed copy.
pin('a secret is still read raw, and untrimmed with it', $dirty, osc_settings_sanitize($fields['api_key']));

harness_section('a translated field, whose value is one per locale');

SettingsPageRegistry::instance()->register('purify.trans', array(
    'title'  => 'Translated',
    'fields' => array(
        array('type' => 'text', 'name' => 's_title', 'label' => 'Title', 'translate' => true),
        array('type' => 'textarea', 'name' => 's_body', 'label' => 'Body', 'translate' => true, 'purify' => false),
    ),
));
submit(array(
    's_titleen_US' => $dirty,
    's_titlees_ES' => $dirty,
    's_bodyen_US'  => $dirty,
    's_bodyes_ES'  => $dirty,
));
$saved = osc_settings_save('purify.trans');
pin('the save was accepted', array(), $saved['errors']);
// Every locale, not just the first: the tab that is not open when the form is submitted is
// exactly the one nobody would notice storing markup.
pin(
    'every locale of a translated field is purified',
    array('en_US' => $handWritten, 'es_ES' => $handWritten),
    $saved['values']['s_title']
);
pin(
    'and purify => false covers every locale too',
    array('en_US' => trim($dirty), 'es_ES' => trim($dirty)),
    $saved['values']['s_body']
);

harness_section('the whole save path, not just the sanitiser');

submit(array('title' => $dirty, 'body' => $dirty, 'slug' => 'x', 'snippet' => $dirty,
             'template' => $dirty, 'api_key' => 'k'));
$GLOBALS['preferences'] = array();
$saved                  = osc_settings_save('purify.test');
pin('nothing was rejected', array(), $saved['errors']);
pin('what is stored is what the old path stored', $handWritten, $GLOBALS['preferences']['purify.test/title'] ?? null);
pin('and the raw field kept its markup', trim($dirty), $GLOBALS['preferences']['purify.test/snippet'] ?? null);

harness_section('the cap the control tells the browser about');

// The attribute is a promise the server has to keep, and on a purified field it cannot:
// the purifier escapes what it keeps, so one typed "&" costs the column five characters and
// a value sent at exactly the cap comes back refused. Rather
// than hand out a number that is wrong, a purified control is given none.
$html = render(static fn () => osc_admin_text(array('name' => 's_name', 'label' => 'Name', 'maxlength' => 250)));
check('a text field is given no maxlength', strpos($html, 'maxlength') === false, $html);
$html = render(static fn () => osc_admin_textarea(array('name' => 's_body', 'label' => 'Body', 'maxlength' => 40)));
check('nor is a textarea', strpos($html, 'maxlength') === false, $html);
// The list is asked of the registry rather than written down here: the two would otherwise
// drift the day a type joins it, and the drift is a control promising what the save refuses.
foreach (SettingsPageRegistry::PURIFIED_TYPES as $type) {
    $html = render(static fn () => osc_admin_field(array('type' => $type, 'name' => 'f', 'maxlength' => 20)));
    check('nor any purified type: ' . $type, strpos($html, 'maxlength') === false, $html);
}

// Where the value is stored as typed the number is honest, so the admin is told before
// being rejected instead of after, with a screen full of typing to shorten by hand.
$html = render(static fn () => osc_admin_text(array('name' => 's', 'maxlength' => 250, 'purify' => false)));
check('a text field that opts out of purification carries it', strpos($html, ' maxlength="250"') !== false, $html);
$html = render(static fn () => osc_admin_textarea(array('name' => 'b', 'maxlength' => 40, 'purify' => false)));
check('and so does a textarea', strpos($html, ' maxlength="40"') !== false, $html);
$html = render(static fn () => osc_admin_field(array('type' => 'email', 'name' => 'e', 'maxlength' => 60)));
check('an email field is never purified, so it carries it too', strpos($html, ' maxlength="60"') !== false, $html);
$html = render(static fn () => osc_admin_field(array('type' => 'url', 'name' => 'u', 'maxlength' => 300)));
check('so does a url field', strpos($html, ' maxlength="300"') !== false, $html);
$html = render(static fn () => osc_admin_field(array('type' => 'secret', 'name' => 'k', 'maxlength' => 64)));
check('and a secret, which is stored exactly as submitted', strpos($html, ' maxlength="64"') !== false, $html);
$html = render(static fn () => osc_admin_field(array('type' => 'email', 'name' => 'e')));
check('a field that declares none emits none', strpos($html, 'maxlength') === false, $html);
// The attribute means nothing on these, and a browser given it on a number input ignores
// it: emitting it would be markup that lies about what is enforced.
$html = render(static fn () => osc_admin_field(array('type' => 'number', 'name' => 'n', 'maxlength' => 5)));
check('a number field does not pretend to carry one', strpos($html, 'maxlength') === false, $html);
$html = render(static fn () => osc_admin_field(array(
    'type' => 'select', 'name' => 's', 'maxlength' => 5, 'options' => array('a' => 'A'),
)));
check('nor does a select', strpos($html, 'maxlength') === false, $html);
// A cap of zero would stop the field accepting anything at all, which is never what a
// declaration meant.
$html = render(static fn () => osc_admin_field(array('type' => 'email', 'name' => 'e', 'maxlength' => 0)));
check('a zero cap is not emitted', strpos($html, 'maxlength') === false, $html);

// Two spellings reached the same attribute, and only the declared one was enforced on save.
$html = render(static fn () => osc_admin_field(array(
    'type' => 'email', 'name' => 'e', 'maxlength' => 5, 'attrs' => array('maxlength' => 10),
)));
pin('a field spelling the cap both ways emits it once', 1, substr_count($html, 'maxlength='));
check('and the number shown is the one the save applies', strpos($html, ' maxlength="5"') !== false, $html);
pin(
    'while the attrs spelling alone is enforced too',
    'Currency must be 3 characters or fewer',
    osc_settings_validate(
        array('type' => 'text', 'name' => 'c', 'label' => 'Currency', 'attrs' => array('maxlength' => 3)),
        'USDX'
    )
);

harness_section('the cap the save applies, measured where it matters');

// STRICT_TRANS_TABLES is off on a default install, so a value the column cannot hold is
// stored as much of itself as fits and nothing is said. The rule therefore counts the
// stored value -- after purification, not before -- which is the only count the column
// agrees with.
$capped = array('type' => 'text', 'name' => 's_name', 'label' => 'Name', 'maxlength' => 250);
submit(array('s_name' => str_repeat('a', 250)));
pin('exactly the cap is taken', null, osc_settings_validate($capped, osc_settings_sanitize($capped)));
submit(array('s_name' => str_repeat('a', 251)));
pin(
    'one character over is refused',
    'Name must be 250 characters or fewer',
    osc_settings_validate($capped, osc_settings_sanitize($capped))
);
// 249 a's and an ampersand is 250 characters typed and 254 stored. Counting the typed value
// would let four characters past the column and MySQL would quietly take them off the end.
submit(array('s_name' => str_repeat('a', 249) . '&'));
pin('the escaped value is what is counted', 254, mb_strlen((string)osc_settings_sanitize($capped)));
pin(
    'so a value that only overflows once escaped is refused too',
    'Name must be 250 characters or fewer',
    osc_settings_validate($capped, osc_settings_sanitize($capped))
);

// A field read raw is not rewritten, so the count is the one the browser made -- which is
// why this is the only kind of field the control hands a maxlength to.
$raw = array('type' => 'text', 'name' => 's_raw', 'label' => 'Raw', 'maxlength' => 250, 'purify' => false);
submit(array('s_raw' => str_repeat('a', 249) . '&'));
pin('an unpurified value is stored at the length it was typed', 250, mb_strlen((string)osc_settings_sanitize($raw)));
pin('and the save takes it', null, osc_settings_validate($raw, osc_settings_sanitize($raw)));
submit(array('s_raw' => str_repeat('a', 251)));
pin(
    'while the column is still what stops it',
    'Raw must be 250 characters or fewer',
    osc_settings_validate($raw, osc_settings_sanitize($raw))
);

exit(harness_result());
