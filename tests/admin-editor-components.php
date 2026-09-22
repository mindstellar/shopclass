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
 *  - a Save button inside the status panel is a second primary on one screen;
 *  - a picker that stops emitting its hidden field, or emits it under another name, posts
 *    nothing where the save reads a category, a place or a seller.
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
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/ui/Picker.php';
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
// Not Bootstrap's .btn-outline-danger: that class paints from a colour nothing re-points
// per theme, and measures 2.86:1 on a dark card.
emits('and are drawn on the theme\'s own danger colour', $panel,
    '<a class="btn btn-sm osc-btn-danger" href="https://example.test/block">Block</a>');
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
 * The pickers.
 * ------------------------------------------------------------------------- */

harness_section('the category picker');

$catRows = array(
    array('pk_i_id' => 4, 'fk_i_parent_id' => null, 's_name' => 'Vehicles'),
    array('pk_i_id' => 7, 'fk_i_parent_id' => 4, 's_name' => 'Bicycles'),
    array('pk_i_id' => 9, 'fk_i_parent_id' => null, 's_name' => 'Property'),
);

$catpick = render(static function () use ($catRows) {
    osc_admin_category_picker(array(
        'name'       => 'catId',
        'value'      => 7,
        'label'      => 'Category',
        'required'   => true,
        'categories' => $catRows,
        'help'       => 'Changing the category changes which details apply below.',
    ));
});

emits('posts the name the save reads, from a hidden field on its own id', $catpick,
    '<input type="hidden" id="catId" name="catId" value="7" />');
emits('the control on screen is a button over that field', $catpick,
    '<button type="button" class="input-text osc-catpick-value" id="catId-picker"');
emits('which says it opens a list', $catpick, 'aria-haspopup="listbox" aria-expanded="false"');
emits('the button shows the whole path, not just the leaf', $catpick,
    "Vehicles<span class=\"osc-catpick-sep\" aria-hidden=\"true\">\u{203a}</span>Bicycles");
emits('every category is an option carrying its value and path', $catpick,
    "data-value=\"7\" data-path=\"Vehicles \u{203a} Bicycles\"");
emits('a child is indented by its depth', $catpick, 'style="--osc-catpick-depth: 1"');
emits('the chosen one is marked', $catpick, 'aria-selected="true"');
emits('and the root it sits under is named beside it', $catpick,
    '<span class="osc-catpick-under">Vehicles</span>');
emits('the list is searchable', $catpick, '<input type="text" class="form-control osc-catpick-search"');
emits('and says when nothing matches', $catpick, '<p class="osc-catpick-empty" hidden>');
emits('the label points at the control, not the hidden field', $catpick, 'for="catId-picker"');
emits('required is said on the label', $catpick, 'class="form-label osc-field-required"');
emits('and the hint is the shared help box', $catpick, '<div class="help-box">Changing the category');
divs_balance('every div the picker opens is closed', $catpick);

$catpickEmpty = render(static function () use ($catRows) {
    osc_admin_category_picker(array('value' => '', 'categories' => $catRows));
});
emits('nothing chosen yet reads as a prompt', $catpickEmpty,
    '<span class="osc-catpick-placeholder">Choose a category</span>');
emits('and still posts the field, empty', $catpickEmpty, 'name="catId" value=""');

$catpickBad = render(static function () {
    osc_admin_category_picker(array(
        'value'      => 1,
        'error'      => 'Choose one category.',
        'categories' => array(array('pk_i_id' => 1, 'fk_i_parent_id' => 0, 's_name' => '<b>x</b>')),
    ));
});
emits('a rejected pick says why, under the control', $catpickBad,
    '<p class="field-error" id="catId-picker-error">Choose one category.</p>');
emits('and the control points at the message', $catpickBad, 'aria-describedby="catId-picker-error"');
omits('a category name is escaped', $catpickBad, '<b>x</b>');

harness_section('the location picker');

$loc = render(static function () {
    osc_admin_location_picker(array(
        'countries' => array(
            array('pk_c_code' => 'US', 's_name' => 'United States'),
            array('pk_c_code' => 'ES', 's_name' => 'Spain'),
        ),
        'value'     => array(
            'countryId' => 'US',
            'region'    => 'California',
            'regionId'  => 12,
            'city'      => 'San Jose',
            'cityId'    => 340,
            'cityArea'  => 'Downtown',
            'zip'       => '95110',
            'address'   => '1 Market Street',
        ),
    ));
});

// Every id here is one the shipped location autocomplete binds to by name.
emits('the country is a select on its own id', $loc, '<select id="countryId" name="countryId"');
emits('with the stored country chosen', $loc, '<option value="US" selected>United States</option>');
emits('the region is a text field', $loc, 'id="region" name="region"');
emits('with its id beside it, hidden', $loc, '<input type="hidden" id="regionId" name="regionId" value="12" />');
emits('the city is a text field', $loc, 'id="city" name="city"');
emits('with its id beside it, hidden', $loc, '<input type="hidden" id="cityId" name="cityId" value="340" />');
emits('neither offers the browser its own history over the suggestions', $loc, 'autocomplete="off"');
emits('the rest of the address is behind a disclosure', $loc, '<details class="osc-disclosure"');
emits('which says what is in it', $loc, '<span class="osc-disclosure-hint">City area, ZIP, street</span>');
emits('and holds the city area', $loc, 'id="cityArea" name="cityArea"');
emits('the ZIP', $loc, 'id="zip" name="zip"');
emits('and the street', $loc, 'id="address" name="address"');
check('a filled address opens the disclosure', strpos($loc, '<details class="osc-disclosure" open>') !== false);

$locEmpty = render(static function () {
    osc_admin_location_picker(array('countries' => array(), 'value' => array(), 'detail' => 'none'));
});
emits('a site with no countries loaded types the country instead', $locEmpty, 'id="country" name="country"');
omits("and 'none' draws no address detail at all", $locEmpty, 'name="zip"');

harness_section('the user picker');

$userPick = render(static function () {
    osc_admin_user_picker(array(
        'user'   => array('name' => 'Ada Lovelace', 'email' => 'ada@example.test',
                          'url' => 'https://example.test/u/3'),
        'source' => 'https://example.test/oc-admin/index.php?page=ajax&action=userajax',
        'fields' => array('name' => 'contactName', 'email' => 'contactEmail'),
    ));
});

emits('a matched account is a card', $userPick, '<div class="osc-user-card">');
emits('naming the user', $userPick, '<span class="osc-user-card-name">Ada Lovelace</span>');
emits('and the e-mail the save matches on', $userPick,
    '<span class="osc-user-card-mail">ada@example.test</span>');
emits('with a way through to the account', $userPick, 'href="https://example.test/u/3"');
emits('the search is a field like the ones it fills', $userPick,
    '<input type="text" id="userPicker" class="input-text field-text osc-user-search"');
emits('bound to the shared autocomplete', $userPick, 'data-osc-user-search="1"');
emits('carrying its endpoint', $userPick, 'data-osc-user-source="https://example.test/oc-admin/index.php?page=ajax&amp;action=userajax"');
emits('and the fields a pick fills', $userPick,
    'data-osc-user-fields="{&quot;name&quot;:&quot;contactName&quot;,&quot;email&quot;:&quot;contactEmail&quot;}"');
omits('the search itself posts nothing', $userPick, 'name="userPicker"');
divs_balance('every div the picker opens is closed', $userPick);

$noUser = render(static function () {
    osc_admin_user_picker(array('user' => null, 'source' => 'https://example.test/u'));
});
omits('no matching account draws no card', $noUser, 'osc-user-card');
emits('but still offers the search', $noUser, 'data-osc-user-search');

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
