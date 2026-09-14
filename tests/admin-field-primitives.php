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
 * Pins what osc_admin_field() and its per-type sugar emit.
 *
 * These are the primitives a plugin builds an admin settings page from, so the
 * markup is a contract in both directions and every break here is silent:
 *
 *  - an unescaped value is stored XSS on a page only an administrator sees, which
 *    is exactly where it does the most damage;
 *  - a dropped field-num/field-text/field-key and width stops following the field's
 *    meaning -- the number goes back to being a full-width block that orphans the
 *    words after it;
 *  - a suffix emitted outside .field-inline and the sentence breaks again, which is
 *    the whole reason the slot exists;
 *  - a lost id and the row's <label> points at nothing.
 *
 * DB-free: the helpers are pure markup over the spec array they are handed.
 *
 * Usage:  php tests/admin-field-primitives.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';

if (!function_exists('__')) {
    function __($key, $domain = 'core')
    {
        return $key;
    }
}

if (!class_exists('Params')) {
    class Params
    {
        public static function getParam($key, $a = false, $b = true)
        {
            return $GLOBALS['params'][$key] ?? '';
        }
    }
}

function osc_admin_base_url($index = false)
{
    return 'https://example.test/oc-admin/index.php';
}

require_once ABS_PATH . 'oc-includes/osclass/classes/admin/ui/Field.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/ui/Form.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

/** Capture what a helper prints. */
function render(callable $fn): string
{
    ob_start();
    $fn();

    return (string)ob_get_clean();
}

/** Assert $needle appears in $html, reporting the markup when it does not. */
function emits(string $label, string $html, string $needle): void
{
    check($label, strpos($html, $needle) !== false, $needle . ' not in: ' . $html);
}

harness_section('the row every field sits in');
$html = render(static function () {
    osc_admin_text(array('name' => 'site_title', 'label' => 'Site title', 'value' => 'Shopclass'));
});
emits('opens a form-row', $html, '<div class="form-row">');
emits('labels the row', $html, '<div class="form-label"><label for="field-site_title">Site title</label>');
emits('puts the control in form-controls', $html, '<div class="form-controls">');
emits('derives an id from the name', $html, 'id="field-site_title"');
emits('carries the value', $html, 'value="Shopclass"');
check('closes both wrappers', substr($html, -12) === '</div></div>', $html);

// A row label that has to carry markup -- the "(required)" the admin screens italicise, or
// a link -- reaches the row through the field spec, the same slot osc_admin_form_row_open()
// has always taken. Without it a declared field could only ever have a plain-text label,
// and a screen wanting one would have to write the row by hand again.
$marked = render(static function () {
    osc_admin_text(array('name' => 's_name', 'label' => 'Name', 'label_html' => 'Name <em>(required)</em>'));
});
emits(
    'label_html on a field is the row label, wrapped once and pointed at the control',
    $marked,
    '<div class="form-label"><label for="field-s_name">Name <em>(required)</em></label></div>'
);
check('and it is raw markup, not escaped', strpos($marked, '&lt;em&gt;') === false, $marked);
check(
    'the plain label is not printed beside it',
    substr_count($marked, 'Name') === 1 || strpos($marked, '>Name</label>') === false,
    $marked
);

// osc_admin_text() used to force 'text' over whatever the caller asked for, so a caller
// that named a type got a plain text box and no sign that it had been ignored. The list is
// the renderer's own, so the helper cannot promise a type that gets downgraded a layer down.
foreach (array('text', 'email', 'url', 'tel') as $type) {
    $typed = render(static function () use ($type) {
        osc_admin_text(array('name' => 't', 'label' => 'T', 'type' => $type));
    });
    emits('osc_admin_text() honours an explicit ' . $type, $typed, '<input type="' . $type . '"');
}
$defaulted = render(static function () {
    osc_admin_text(array('name' => 't', 'label' => 'T'));
});
emits('and still defaults to text when none is named', $defaulted, '<input type="text"');
// It is the helper that draws a line of text, so it does not become another control
// because a caller said so. Every type below is one the renderer draws differently, so each
// would be a visibly different control if the helper let it through -- 'secret' worst of
// all, which turns a line of text into a password box with a reveal button beside it. A
// type the renderer does not know ('search', a typo, anything invented) is deliberately not
// on this list: the renderer falls back to text for those on its own, so pinning one would
// pass with no allowlist here at all.
foreach (array('select', 'checkbox', 'textarea', 'number', 'color', 'file', 'radio', 'secret') as $type) {
    $typed = render(static function () use ($type) {
        osc_admin_text(array('name' => 't', 'label' => 'T', 'type' => $type, 'options' => array('a' => 'A')));
    });
    emits('a ' . $type . ' asked of osc_admin_text() is still a text box', $typed, '<input type="text"');
    // The class carries the width, so a type that got through the input-type check and not
    // this one is still the wrong control in the sentence it sits in.
    emits('and still classed as one (' . $type . ')', $typed, 'class="input-text field-text"');
}

// A caller composing its own row (a plugin inside an existing screen) needs the
// control alone, or it nests a form-row inside a form-row.
$bare = render(static function () {
    osc_admin_text(array('name' => 'x', 'label' => 'X', 'row' => false));
});
check("'row' => false emits no row", strpos($bare, 'form-row') === false, $bare);

harness_section('width follows the field type');
$widths = array(
    'text'     => 'field-text',
    'number'   => 'field-num',
    'secret'   => 'field-key',
    'textarea' => 'field-text',
    'select'   => 'field-select',
);
foreach ($widths as $type => $class) {
    $out = render(static function () use ($type) {
        osc_admin_field(array('type' => $type, 'name' => 'f', 'label' => 'F', 'options' => array()));
    });
    emits("a {$type} field is {$class}", $out, $class);
}
// The override exists for the screen that genuinely needs something else.
$wide = render(static function () {
    osc_admin_field(array('type' => 'number', 'name' => 'f', 'width' => 'text'));
});
check("'width' overrides the type's default", strpos($wide, 'field-text') !== false
    && strpos($wide, 'field-num') === false, $wide);

harness_section('a suffix is a slot, not concatenation');
$html = render(static function () {
    osc_admin_number(array(
        'name'   => 'comments_per_page',
        'label'  => 'Other comment settings',
        'value'  => 10,
        'suffix' => 'comments per page',
        'min'    => 0,
    ));
});
emits('wraps control and suffix together', $html, '<div class="field-inline">');
emits('renders the suffix in its own label', $html, '<label class="field-suffix" for="field-comments_per_page">comments per page</label>');
emits('passes min through', $html, 'min="0"');
check('the suffix follows the input', strpos($html, 'field-suffix') > strpos($html, '<input'), $html);
// The words in front of a field are a slot too: the sentence they came from is never
// split around a %s the translator cannot move the field within.
$both = render(static function () {
    osc_admin_number(array(
        'name'   => 'comments_per_page',
        'value'  => 10,
        'prefix' => 'Break comments into pages with',
        'suffix' => 'comments per page',
    ));
});
emits('renders a prefix', $both, '<label class="field-prefix" for="field-comments_per_page">Break comments into pages with</label>');
check('the prefix comes before the input', strpos($both, 'field-prefix') < strpos($both, '<input'), $both);
// Without a suffix there is nothing to keep on one line, so no wrapper.
$plain = render(static function () {
    osc_admin_number(array('name' => 'n', 'label' => 'N'));
});
check('no wrapper without a suffix', strpos($plain, 'field-inline') === false, $plain);

harness_section('choice lists');
$html = render(static function () {
    osc_admin_radio_group(array(
        'name'     => 'date_format',
        'label'    => 'Date format',
        'selected' => 'F j, Y',
        'options'  => array(
            'F j, Y' => 'August 25, 2026',
            'custom' => array('label' => 'Custom', 'custom_html' => '<input class="field-inline-text" />'),
        ),
    ));
});
emits('opens a choices list', $html, '<div class="field-choices"');
emits('each option is its own label', $html, '<label class="field-choice">');
// The id counts options: a value is free text, so slugging it can produce a space,
// or two options that slug to the same id.
emits('each radio has its own id', $html, 'id="field-date_format-1"');
emits('the second option too', $html, 'id="field-date_format-2"');
emits('the group is named', $html, 'role="group" aria-label="Date format"');
check('the row label points at no control', strpos($html, '<label for=') === false, $html);
emits('marks the selected option', $html, 'value="F j, Y" checked');
emits('renders per-option markup', $html, '<input class="field-inline-text" />');
check('only one option is checked', substr_count($html, ' checked') === 1, $html);

harness_section('escaping');
$html = render(static function () {
    osc_admin_text(array(
        'name'  => 'evil',
        'label' => '<script>alert(1)</script>',
        'value' => '" onfocus="alert(1)',
        'help'  => '<b>hi</b>',
        'attrs' => array('data-x' => '"><script>'),
    ));
});
check('no raw script tag survives', strpos($html, '<script') === false, $html);
emits('escapes the value out of its attribute', $html, 'value="&quot; onfocus=&quot;alert(1)"');
emits('escapes extra attributes', $html, 'data-x="&quot;&gt;&lt;script&gt;"');
emits('escapes help text', $html, '<div class="help-box">&lt;b&gt;hi&lt;/b&gt;</div>');
// The _html suffix is the whole warning system: only those keys are raw sinks.
$raw = render(static function () {
    osc_admin_text(array('name' => 'h', 'help_html' => '<b>bold</b>'));
});
emits('help_html is a markup slot', $raw, '<div class="help-box"><b>bold</b></div>');

harness_section('select');
$html = render(static function () {
    osc_admin_select(array(
        'name'        => 'mode',
        'label'       => 'Mode',
        'selected'    => 'test',
        'placeholder' => 'Choose…',
        'options'     => array('live' => 'Live', 'test' => 'Test'),
    ));
});
emits('renders a select', $html, '<select id="field-mode" name="mode"');
emits('a placeholder is an empty first option', $html, '<option value="">Choose…</option>');
emits('marks the selected option', $html, '<option value="test" selected>Test</option>');
check('the placeholder is not an input attribute', strpos($html, 'placeholder="') === false, $html);

harness_section('secret');
$html = render(static function () {
    osc_admin_secret(array('name' => 'api_key', 'label' => 'API key', 'value' => 's3cret', 'reveal' => true));
});
emits('is a password field', $html, '<input type="password"');
emits('does not autocomplete', $html, 'autocomplete="off"');
emits('the reveal button names its input', $html, 'data-osc-reveal="field-api_key"');
emits('and reports its state', $html, 'aria-pressed="false"');
check('the reveal button sits on the input line', strpos($html, 'field-inline') !== false, $html);
// masked keeps a stored secret out of the DOM entirely.
$masked = render(static function () {
    osc_admin_secret(array('name' => 'api_key', 'value' => 's3cret', 'masked' => true));
});
check('masked never echoes the secret', strpos($masked, 's3cret') === false, $masked);
emits('masked shows bullets instead', $masked, 'placeholder="••••••••"');

harness_section('colour and file');
$html = render(static function () {
    osc_admin_field(array('type' => 'color', 'name' => 'tint', 'label' => 'Tint', 'value' => '#112233'));
});
emits('a colour field is a colour well', $html, '<input type="color" id="field-tint" name="tint" class="field-color"');
check('and is not styled as a text control', strpos($html, 'input-text') === false, $html);
$html = render(static function () {
    osc_admin_field(array('type' => 'file', 'name' => 'logo', 'label' => 'Logo', 'value' => '/etc/passwd'));
});
emits('a file field is a file picker', $html, '<input type="file"');
// A file input's value cannot be set from markup, and echoing one back would be a
// path disclosure for nothing.
check('a file field carries no value', strpos($html, 'value=') === false, $html);

harness_section('an option can be disabled or keep its own id');
$html = render(static function () {
    osc_admin_radio_group(array(
        'name'     => 'watermark_type',
        'selected' => 'none',
        'options'  => array(
            'none' => array('label' => 'None', 'id' => 'watermark_none'),
            'text' => array('label' => 'Text', 'id' => 'watermark_text', 'disabled' => true),
        ),
    ));
});
emits('an option keeps the id a script already reaches for', $html, 'id="watermark_none"');
emits('and one option can be disabled alone', $html, 'id="watermark_text" name="watermark_type" value="text" disabled');
check('without disabling the rest', substr_count($html, 'disabled') === 1, $html);

harness_section('checkbox keeps its published shape');
$html = render(static function () {
    osc_admin_field(array(
        'type'      => 'checkbox',
        'name'      => 'enabled',
        'label'     => 'Enable the thing',
        'row_label' => 'Comments',
        'checked'   => true,
    ));
});
emits('labels the row separately', $html, '<div class="form-label"><label for="field-enabled">Comments</label>');
emits('the control label sits beside the box', $html, 'class="form-label-checkbox"');
emits('is checked', $html, 'checked="checked"');
check('the label is printed once', substr_count($html, 'Enable the thing') === 1, $html);
// A checkbox label sometimes has to carry a link; label_html is that slot, named so the
// _html suffix still marks every raw sink.
$html = render(static function () {
    osc_admin_field(array(
        'type'       => 'checkbox',
        'name'       => 'cron',
        'label_html' => 'Run <a href="#">cron</a>',
    ));
});
emits('label_html is a markup slot', $html, 'Run <a href="#">cron</a>');

harness_section('a labelled row of several fields');
$html = render(static function () {
    osc_admin_field_row('Default comment settings', array(
        array('type' => 'checkbox', 'name' => 'enabled', 'label' => 'Allow comments', 'checked' => true),
        array('type' => 'checkbox', 'name' => 'reg_only', 'label' => 'Registered only'),
        array('type' => 'number', 'name' => 'per_page', 'value' => 10, 'suffix' => 'per page'),
    ));
});
emits('opens one row', $html, '<div class="form-row"><div class="form-label">Default comment settings</div>');
check('with one controls column', substr_count($html, 'class="form-controls"') === 1, $html);
// The fields share the row, so none of them may draw one of its own.
check('and no field draws a row of its own', substr_count($html, 'class="form-row"') === 1, $html);
emits('the checkboxes are in it', $html, 'name="enabled"');
emits('and so is the number field', $html, 'name="per_page"');
emits('which keeps its suffix', $html, '<label class="field-suffix"');
// A row that has to drop to markup for one part should not hand-write the row around it.
$mixed = render(static function () {
    osc_admin_field_row('Mixed', array(
        array('type' => 'checkbox', 'name' => 'a', 'label' => 'A'),
        static function () {
            echo '<p>anything</p>';
        },
    ));
});
emits('a callable emits into the same column', $mixed, '<p>anything</p>');

harness_section('a row a script has to address');
// A row shown and hidden by the page's own script needs its id and its hidden state; without
// somewhere to put them the view has to hand-write the row, which is what these replaced.
$html = render(static function () {
    osc_admin_form_row_open('Option map', array(
        'id'             => 'cf_cascade_map_row',
        'style'          => 'display:none;',
        'controls_class' => 'cf-rule-condition',
    ));
    osc_admin_form_row_close();
});
emits('the row carries its id', $html, '<div class="form-row" id="cf_cascade_map_row"');
emits('and its inline state', $html, 'style="display:none;"');
emits('the controls column can be classed', $html, '<div class="form-controls cf-rule-condition">');
$plain = render(static function () {
    osc_admin_form_row_open('');
    osc_admin_form_row_close();
});
// A row with nothing to label must not emit an empty label column, which would indent its
// contents past a heading that is not there.
check('an unlabelled row has no label column', strpos($plain, 'form-label') === false, $plain);

harness_section('a row with a data attribute, a stacked layout, or a raw label');
$html = render(static function () {
    osc_admin_form_row_open('', array('data' => array('cfg-key' => 'placeholder', 'a b' => 'nope')));
    osc_admin_form_row_close();
});
emits('a data opt becomes a data-* attribute', $html, '<div class="form-row" data-cfg-key="placeholder">');
check(
    'a data key with an illegal character is dropped, not just its value',
    strpos($html, 'a b') === false && strpos($html, 'nope') === false,
    $html
);
$html = render(static function () {
    osc_admin_form_row_open('', array('data' => array('x' => '"><b>')));
    osc_admin_form_row_close();
});
check('a data value is escaped, not raw', strpos($html, '"><b>') === false, $html);
emits('escaped as an attribute', $html, 'data-x="&quot;&gt;&lt;b&gt;"');
$html = render(static function () {
    osc_admin_form_row_open('', array('layout' => 'stacked'));
    osc_admin_form_row_close();
});
emits('a stacked layout adds its class', $html, 'class="form-row form-row-stacked"');
$html = render(static function () {
    osc_admin_form_row_open('', array('label_html' => '<em>x</em>'));
    osc_admin_form_row_close();
});
emits('a label column is emitted even though $label is empty', $html, '<div class="form-label">');
emits('label_html is raw markup', $html, '<em>x</em>');
check('label_html is not escaped', strpos($html, '&lt;em&gt;') === false, $html);
$html = render(static function () {
    osc_admin_form_row_open('', array('for' => 'field-x', 'label_html' => '<em>x</em>'));
    osc_admin_form_row_close();
});
emits(
    'label_html respects for, wrapping in one label',
    $html,
    '<div class="form-label"><label for="field-x"><em>x</em></label></div>'
);
$html = render(static function () {
    osc_admin_form_row_open('', array(
        'layout'     => 'stacked',
        'for'        => 'field-y',
        'label_html' => '<span class="form-sublabel">Y</span>',
    ));
    osc_admin_form_row_close();
});
emits(
    'stacked + label_html + for still wraps once',
    $html,
    '<div class="form-row form-row-stacked"><div class="form-label">'
    . '<label for="field-y"><span class="form-sublabel">Y</span></label></div>'
);
check('only one label element wraps it', substr_count($html, '<label') === 1, $html);

harness_section('the form and section scaffolding');
$html = render(static function () {
    osc_admin_form_open(array('action' => 'comments_post', 'page' => 'settings', 'name' => 'comments_form'));
    osc_admin_form_close(array());
});
emits('opens a post form', $html, '<form action="https://example.test/oc-admin/index.php" method="post" name="comments_form">');
// The route a form posts to was a pair of hidden fields every screen wrote for itself.
emits('carries the page it posts to', $html, '<input type="hidden" name="page" value="settings"/>');
emits('and the action', $html, '<input type="hidden" name="action" value="comments_post"/>');
emits('wraps the rows for the label column', $html, '<fieldset><div class="form-horizontal">');
emits('an empty actions array is the Save row', $html, '<div class="form-actions">');
check('and everything closes', substr($html, -22) === '</div></div></fieldset></form>'
    || strpos($html, '</div></fieldset></form>') !== false, $html);

// A form whose buttons live elsewhere must not have one invented for it.
$bare = render(static function () {
    osc_admin_form_open(array('action' => 'x'));
    osc_admin_form_close();
});
check('no actions row unless asked for', strpos($bare, 'form-actions') === false, $bare);

$upload = render(static function () {
    osc_admin_form_open(array('action' => 'import', 'upload' => true, 'horizontal' => false, 'csrf' => false));
    osc_admin_form_close(null, array('horizontal' => false));
});
emits('a file form is multipart', $upload, 'enctype="multipart/form-data"');
// nocsrf is only ever right for a form that changes nothing; it is opt-in and explicit.
emits('csrf can be opted out of', $upload, 'nocsrf');
check('a stacked form skips the row wrappers', strpos($upload, 'form-horizontal') === false, $upload);

$section = render(static function () {
    osc_admin_form_section('Notifications', array('intro' => 'Who hears about what.'));
});
// A section is inside the page head, not a second one: h3, not h2.
emits('a section is an h3', $section, '<h3 class="render-title">Notifications</h3>');
emits('with its intro under it', $section, '<p class="form-intro">Who hears about what.</p>');
emits('and can be spaced from the block above', render(static function () {
    osc_admin_form_section('Later', array('spaced' => true));
}), '<h3 class="render-title separate-top">Later</h3>');

harness_section('custom');
$html = render(static function () {
    osc_admin_field(array(
        'type'   => 'custom',
        'name'   => 'thing',
        'label'  => 'Thing',
        'render' => static function (array $spec) {
            echo '<em>' . $spec['name'] . '</em>';
        },
    ));
});
emits('a custom field renders into the normal row', $html, '<div class="form-row">');
emits('and its callable owns the control', $html, '<em>thing</em>');
// What the callable draws is the declaration's business -- a pair of radio groups, a button,
// nothing at all -- so there is no one control for the row label to point at. A `for`
// naming an id nothing carries reaches nothing when it is clicked.
emits('its row label is plain text', $html, '<div class="form-label">Thing</div>');
check('with no for at all', strpos($html, 'for="thing"') === false && strpos($html, 'for="field-thing"') === false);

harness_section('required is only said out loud where it can be obeyed');
$html = render(static function () {
    osc_admin_field(array('type' => 'text', 'name' => 'a', 'label' => 'A', 'required' => true));
});
emits('a plain required field carries the attribute', $html, 'value="" required />');
$html = render(static function () {
    osc_admin_field(array('type' => 'hidden', 'name' => 'b', 'label' => 'B', 'required' => true));
});
check('a hidden one does not, being barred from validation anyway', strpos($html, 'required') === false);
// A browser will not submit a form holding a required control it cannot show, and it
// refuses silently: no submit event, so the page's own validator never runs and never
// reports anything, and the save button looks dead. A dependent row is safe, because the
// shared script lifts the flag while the row is off and puts it back with the row.
$html = render(static function () {
    osc_admin_field(array(
        'type'     => 'number',
        'name'     => 'c',
        'label'    => 'C',
        'required' => true,
        'depends'  => 'master',
    ));
});
emits('a dependent row declares what it hangs on', $html, 'data-osc-depends="master"');
emits('and keeps the flag, which the shared script lifts while the row is off', $html, 'value="" required');
// The script finds the control through that attribute, and the attribute is on the row. A
// dependent field drawing no row of its own is hidden by whatever page put it there, and
// nothing would ever give the flag back.
$html = render(static function () {
    osc_admin_field(array(
        'type'     => 'number',
        'name'     => 'd',
        'label'    => 'D',
        'required' => true,
        'depends'  => 'master',
        'row'      => false,
    ));
});
check('one with no row of its own carries no data-osc-depends', strpos($html, 'data-osc-depends') === false);
check('and so says nothing the browser could hold it to', strpos($html, 'required') === false);

harness_section('hidden');
// The value a page's own script computes from the controls beside it: collected, validated
// and stored like any other field, and drawn as nothing at all. A row around it would put
// an empty label column and a gap where no control is.
$html = render(static function () {
    osc_admin_field(array(
        'type'  => 'hidden',
        'name'  => 'dateFormat',
        'label' => 'Date format',
        'help'  => 'Never shown.',
        'value' => 'Y/m/d',
    ));
});
pin(
    'a hidden field is the input and nothing else',
    '<input type="hidden" id="field-dateFormat" name="dateFormat" value="Y/m/d" />',
    $html
);
$html = render(static function () {
    osc_admin_field(array('type' => 'hidden', 'name' => 'q', 'value' => '<b>&"'));
});
emits('and its value is escaped like any other', $html, 'value="&lt;b&gt;&amp;&quot;"');

harness_section('a row that is labelled one thing and named another');
// The field whose row says "Other comment settings" and whose errors have to say "Comments
// per page must be 0 or more". Without this the two are one string and one of them is wrong.
$html = render(static function () {
    osc_admin_field(array(
        'type'      => 'number',
        'name'      => 'comments_per_page',
        'label'     => 'Comments per page',
        'row_label' => 'Other comment settings',
    ));
});
emits(
    'the row carries the row label',
    $html,
    '<div class="form-label"><label for="field-comments_per_page">Other comment settings</label></div>'
);
check('and the field label is nowhere in the markup', strpos($html, 'Comments per page') === false);
$html = render(static function () {
    osc_admin_field(array('type' => 'number', 'name' => 'n', 'label' => 'Count'));
});
emits('a field with no row label still labels the row with its own', $html, '<label for="field-n">Count</label>');

harness_section('the length hint, and the mirror it depends on');
// Field keeps its own copy of the purified-type list so a markup primitive need not load
// the settings registry. The copy is only safe while it stays a copy.
require_once ABS_PATH . 'oc-includes/osclass/classes/settings/SettingsPageRegistry.php';
$mirror = new ReflectionClassConstant('mindstellar\\admin\\ui\\Field', 'PURIFIED_TYPES');
pin(
    'Field\'s purified-type list still matches the registry it mirrors',
    mindstellar\settings\SettingsPageRegistry::PURIFIED_TYPES,
    $mirror->getValue()
);

// Escaping lengthens -- one typed "&" reaches the column as five characters -- so on a
// purified type the attribute would promise a cap the save does not apply.
foreach (array('text', 'tel', 'textarea') as $type) {
    $html = render(static function () use ($type) {
        osc_admin_field(array('type' => $type, 'name' => 'f', 'label' => 'F', 'maxlength' => 20));
    });
    check("a purified {$type} carries no maxlength", strpos($html, 'maxlength') === false);
    $html = render(static function () use ($type) {
        osc_admin_field(array(
            'type' => $type, 'name' => 'f', 'label' => 'F', 'maxlength' => 20, 'purify' => false,
        ));
    });
    check("and takes one once it is not purified", strpos($html, 'maxlength="20"') !== false);
}
foreach (array('email', 'url', 'secret') as $type) {
    $html = render(static function () use ($type) {
        osc_admin_field(array('type' => $type, 'name' => 'f', 'label' => 'F', 'maxlength' => 20));
    });
    check("an unpurified {$type} carries the cap it was given", strpos($html, 'maxlength="20"') !== false);
}

exit(harness_result());
