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
 * Pins what the editor shell, the status panel and the disclosure emit.
 *
 * These are the composites an editing screen is assembled from, and both core editors plus
 * any plugin add/edit screen stand on them, so the markup is a contract in both directions:
 *
 *  - a dropped .osc-editor-side and the rail stops being sticky on a wide screen and stops
 *    coming first on a narrow one, which is the whole reason a moderator can reach a
 *    record's state on a phone;
 *  - a lost data-osc-dirty-bar and the save bar goes back to being an ordinary row that
 *    counts nothing and scrolls away;
 *  - an unbalanced open/close pair and every element after the editor is nested inside the
 *    form, which submits things the screen never meant to submit;
 *  - an unescaped title or status word is stored XSS on a page only an administrator sees;
 *  - a Save button inside the status panel is a second primary on one screen.
 *
 * DB-free: the helpers are pure markup over the options array they are handed.
 *
 * Usage:  php tests/admin-editor-components.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';

require_once __DIR__ . '/lib/stubs.php';

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
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/ui/Editor.php';
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

/** Assert $needle does not appear in $html. */
function omits(string $label, string $html, string $needle): void
{
    check($label, strpos($html, $needle) === false, $needle . ' unexpectedly in: ' . $html);
}

/** Every <div> a fragment opens is closed again. */
function divs_balance(string $label, string $html): void
{
    pin($label, substr_count($html, '</div>'), preg_match_all('/<div\b/', $html));
}

/* ----------------------------------------------------------------------------
 * The shell.
 * ------------------------------------------------------------------------- */

harness_section('the editor shell');

$shell = render(static function () {
    osc_admin_editor_open(array(
        'id'         => 'item-form',
        'class'      => 'page-editor',
        'page'       => 'pages',
        'action'     => 'edit_post',
        'main_id'    => 'left-side',
        'main_class' => 'page-mode-classic',
    ));
    echo '<p>body</p>';
    osc_admin_editor_rail(array('id' => 'right-side'));
    echo '<p>rail</p>';
    osc_admin_editor_close(array(
        array('label' => 'Save changes', 'type' => 'submit', 'variant' => 'primary'),
        array('label' => 'Back to pages', 'url' => 'https://example.test/back', 'variant' => 'dim'),
    ));
});

emits('opens the form with its id and class', $shell, '<form action="https://example.test/oc-admin/index.php"'
    . ' method="post" id="item-form" class="page-editor">');
emits('posts the page it routes to', $shell, '<input type="hidden" name="page" value="pages"/>');
emits('posts the action it routes to', $shell, '<input type="hidden" name="action" value="edit_post"/>');
omits('never wraps the editor in the horizontal form layout', $shell, '<fieldset>');
emits('opens the grid', $shell, '<div class="osc-editor">');
emits('opens the main column with its class and id', $shell,
    '<div class="osc-editor-main page-mode-classic" id="left-side">');
emits('the rail closes the main column and opens beside it', $shell,
    '<p>body</p></div><div class="osc-editor-side" id="right-side">');
emits('closes the rail, then the grid, before the save bar', $shell,
    '<p>rail</p></div></div><div class="osc-editor-actions">');
emits('ends on the form', $shell, '</form>');
divs_balance('every div the shell opens is closed', $shell);

harness_section('the save bar the shell closes on');

emits('is the shared action row, counting changes', $shell, '<div class="form-actions" data-osc-dirty-bar');
emits('names the one-change wording', $shell, 'data-osc-dirty-one="1 unsaved change"');
emits('names the many-change wording', $shell, 'data-osc-dirty-many="%d unsaved changes"');
emits('carries the live region the count is written into', $shell,
    '<p class="form-actions-status" role="status" aria-live="polite"></p>');
emits('the primary is a submit', $shell, '<button type="submit" class="btn btn-sm btn-submit">Save changes</button>');
emits('and the way out is a link, not history.go', $shell,
    '<a class="btn btn-sm btn-dim" href="https://example.test/back">Back to pages</a>');

$plain = render(static function () {
    osc_admin_editor_open(array('page' => 'pages', 'action' => 'edit_post'));
    echo '<p>body</p>';
    osc_admin_editor_close(null);
});
harness_section('a screen that wants no rail');
omits('gets one column', $plain, 'osc-editor-side');
omits('and no action row it did not ask for', $plain, 'form-actions');
divs_balance('and still balances', $plain);
emits('and still closes its form', $plain, '</p></div></div></form>');

harness_section('the error summary');

$clean = render(static function () {
    osc_admin_editor_open(array('page' => 'pages', 'action' => 'edit_post'));
    osc_admin_editor_close(null);
});
omits('is absent when nothing was rejected', $clean, 'error_list');

$rejected = render(static function () {
    osc_admin_editor_open(array(
        'page'         => 'pages',
        'action'       => 'edit_post',
        'errors'       => array(
            'hi_IN#s_title'   => 'This page has a Hindi body, so it needs a Hindi title too.',
            's_internal_name' => 'Letters, numbers and dashes only.',
        ),
        'error_labels' => array('hi_IN#s_title' => 'Title (Hindi)', 's_internal_name' => 'Internal name'),
    ));
    osc_admin_editor_close(null);
});
emits('fills the surface the client-side validator already writes to', $rejected,
    '<ul id="error_list" role="alert" style="display: block">');
emits('counts what needs fixing', $rejected, '<li><strong>2 things need fixing before this can be saved.</strong></li>');
emits('links each field by its label and message', $rejected,
    '<li><a href="#hi_IN#s_title">Title (Hindi): This page has a Hindi body,'
    . ' so it needs a Hindi title too.</a></li>');
emits('and falls back to the posted name when no label is given', $rejected, '>Internal name: ');

$oneError = render(static function () {
    osc_admin_editor_open(array('page' => 'pages', 'errors' => array('x' => 'Nope.')));
    osc_admin_editor_close(null);
});
emits('one rejected field reads as one', $oneError, '<strong>1 thing needs fixing before this can be saved.</strong>');

/* ----------------------------------------------------------------------------
 * The status panel.
 * ------------------------------------------------------------------------- */

harness_section('the status panel');

$panel = render(static function () {
    osc_admin_publish_panel(array(
        'status'    => array(array('active', 'Active'), array('state' => 'premium', 'word' => 'Premium')),
        'rows'      => array(
            array('label' => 'Published', 'value' => '3 Sep 2026'),
            array('label' => 'Address', 'value' => '/page/about-us', 'mono' => true),
        ),
        'body_html' => '<div class="osc-publish-expiry">expiry</div>',
        'actions'   => array(array('label' => 'Deactivate', 'url' => 'https://example.test/off')),
        'danger'    => array(array('label' => 'Block', 'url' => 'https://example.test/block')),
    ));
});

emits('is a widget-box carrying the publish variant', $panel, '<div class="widget-box osc-publish">');
emits('titles itself Status by default', $panel, '<div class="widget-box-title"><h3>Status</h3></div>');
emits('opens the panel body', $panel, '<div class="widget-box-content">');
emits('groups the state pills', $panel, '<div class="osc-publish-status">');
emits('draws each pill through the shared status component', $panel,
    '<span class="osc-status status-active">Active</span>');
emits('and takes a pill given by key as well as by position', $panel,
    '<span class="osc-status status-premium">Premium</span>');
emits('draws the facts as definition rows', $panel,
    '<div class="osc-deflist-row"><dt>Published</dt><dd>3 Sep 2026</dd></div>');
emits('a mono row says so', $panel, '<dd class="osc-mono">/page/about-us</dd>');
emits('the body slot lands between the rows and the actions', $panel,
    '</dl><div class="osc-publish-expiry">expiry</div><div class="osc-publish-actions">');
emits('routine actions are secondary', $panel,
    '<a class="btn btn-sm btn-secondary" href="https://example.test/off">Deactivate</a>');
emits('destructive ones sit apart, after the rule', $panel, '<div class="osc-publish-danger">');
emits('and are drawn as danger', $panel,
    '<a class="btn btn-sm btn-outline-danger" href="https://example.test/block">Block</a>');
omits('the panel never carries a second Save', $panel, 'type="submit"');
divs_balance('every div the panel opens is closed', $panel);

$bare = render(static function () {
    osc_admin_publish_panel(array('title' => 'State'));
});
emits('a panel with nothing but a title still renders', $bare, '<h3>State</h3>');
omits('and draws no empty pill row', $bare, 'osc-publish-status');
omits('no empty definition list', $bare, 'osc-deflist');
omits('and no empty action rows', $bare, 'osc-publish-actions');

$escaped = render(static function () {
    osc_admin_publish_panel(array(
        'title'  => '<script>alert(1)</script>',
        'status' => array(array('"><script>', '<b>bad</b>')),
        'rows'   => array(array('label' => '<i>l</i>', 'value' => '<i>v</i>')),
    ));
});
omits('the title is escaped', $escaped, '<script>alert(1)</script>');
omits('the status state and word are escaped', $escaped, '<b>bad</b>');
omits('and a row escapes unless it asks not to', $escaped, '<i>v</i>');
emits('a row that asks for markup gets it', render(static function () {
    osc_admin_publish_panel(array('rows' => array(array('label' => 'x', 'value' => '<a href="#">y</a>', 'html' => true))));
}), '<dd><a href="#">y</a></dd>');

/* ----------------------------------------------------------------------------
 * The disclosure.
 * ------------------------------------------------------------------------- */

harness_section('the disclosure');

$closed = render(static function () {
    osc_admin_disclosure_open('Advanced', array('summary_hint' => 'Internal name'));
    echo '<p>inside</p>';
    osc_admin_disclosure_close();
});

emits('is a details element, so it works with no JavaScript', $closed, '<details class="osc-disclosure">');
emits('its summary names the group', $closed, '<summary>Advanced');
emits('and says what is inside without opening it', $closed,
    '<span class="osc-disclosure-hint">Internal name</span></summary>');
emits('the body is its own block', $closed, '<div class="osc-disclosure-body"><p>inside</p></div>');
emits('and the element closes', $closed, '</details>');
omits('closed is the default', $closed, ' open>');

$open = render(static function () {
    osc_admin_disclosure_open('Advanced', array('open' => true, 'id' => 'adv', 'class' => 'page-advanced'));
    osc_admin_disclosure_close();
});
emits('a group holding something to see starts expanded', $open,
    '<details class="osc-disclosure page-advanced" id="adv" open>');

$dirty = render(static function () {
    osc_admin_disclosure_open('<script>x</script>', array('summary_hint' => '<i>h</i>'));
    osc_admin_disclosure_close();
});
omits('the title is escaped', $dirty, '<script>x</script>');
omits('and so is the hint', $dirty, '<i>h</i>');

exit(harness_result());

/* file end: ./tests/admin-editor-components.php */
