<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The shared admin components: tab strips, the locale strip, confirm dialogs, empty states,
 * callouts, status pills, counts and list filter fields. Exact markup, because scripts and
 * plugins bind to it.
 *
 * Usage:  php tests/admin-components.php
 */

declare(strict_types=1);

use mindstellar\admin\ui\Field;
use mindstellar\admin\ui\Form;

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}

require_once __DIR__ . '/lib/admin-ui-stubs.php';

function osc_run_hook($hook, ...$args)
{
}
function osc_add_filter($hook, $fn, $priority = 5)
{
}

require_once dirname(__DIR__) . '/oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';

require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

/** Collapses the indentation of a PHP template so markup can be compared exactly. */
function squeezed(string $html): string
{
    return trim((string)preg_replace('~\s+~', ' ', (string)preg_replace('~>\s+<~', '><', $html)));
}

$hostile = '"><script>alert(1)</script>';
$escaped = '&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;';

harness_section('tab nav: links');
check(
    'a plain link, an active link and a count keep their markup',
    html_of(static fn () => osc_admin_tab_nav(array(
        array('label' => 'All', 'url' => '/all', 'active' => true),
        array('label' => 'Held', 'url' => '/held', 'count' => 1200),
    ))) === '<nav class="osc-tabnav-scroll"><ul class="osc-tabnav">'
        . '<li><a href="/all" class="is-active" aria-current="page">All</a></li>'
        . '<li><a href="/held">Held <span class="osc-count">1,200</span></a></li></ul></nav>'
);
check(
    'the class argument stays on the list',
    str_contains(html_of(static fn () => osc_admin_tab_nav(array(), 'osc-tabnav-group')), '<ul class="osc-tabnav osc-tabnav-group">')
);
check(
    'attrs, count_id, nav_class and aria_label',
    ($nav = html_of(static fn () => osc_admin_tab_nav(
        array(array('label' => 'Data', 'url' => '#d', 'count' => 0, 'count_id' => 'c1', 'attrs' => array('data-loc-tab' => 'data'))),
        '',
        array('nav_class' => 'loc-tabs', 'aria_label' => 'Location views')
    ))) === '<nav class="osc-tabnav-scroll loc-tabs" aria-label="Location views"><ul class="osc-tabnav">'
        . '<li><a href="#d" data-loc-tab="data">Data <span class="osc-count is-zero" id="c1">0</span></a></li></ul></nav>',
    $nav
);
check(
    'an active link merges a class passed in attrs',
    str_contains(html_of(static fn () => osc_admin_tab_nav(array(
        array('label' => 'A', 'url' => '/a', 'active' => true, 'attrs' => array('class' => 'mine', 'data-x' => '1')),
    ))), '<a href="/a" class="is-active mine" aria-current="page" data-x="1">')
);
check(
    'a hostile label, url, attr and aria_label are escaped',
    !str_contains($h = html_of(static fn () => osc_admin_tab_nav(
        array(array('label' => $hostile, 'url' => $hostile, 'attrs' => array('title' => $hostile))),
        '',
        array('aria_label' => $hostile, 'nav_class' => $hostile)
    )), '<script>') && substr_count($h, $escaped) === 5,
    $h
);

harness_section('tab nav: panels');
check(
    'panels draw the markup oscInitTabs() reads, with no nav and no is-active',
    ($panels = html_of(static fn () => osc_admin_tab_nav(array(
        array('label' => 'Themes', 'url' => '#market-tab-installed'),
        array('label' => 'Browse', 'url' => '#market-tab-browse', 'active' => true),
        array('label' => 'Updates', 'url' => '#market-tab-updates', 'count' => 3, 'count_id' => 'market-updates-count', 'attrs' => array('data-x' => '1')),
    ), '', array('panels' => true, 'nav_class' => 'ignored', 'aria_label' => 'ignored')))) === '<div class="ui-osc-tabs osc-tab"><ul>'
        . '<li><a href="#market-tab-installed">Themes</a></li>'
        . '<li class="ui-tabs-active"><a href="#market-tab-browse">Browse</a></li>'
        . '<li><a href="#market-tab-updates" data-x="1">Updates <span class="osc-count" id="market-updates-count">3</span></a></li>'
        . '</ul></div>',
    $panels
);
check(
    'the class argument goes on the wrapper in panels mode',
    str_starts_with(html_of(static fn () => osc_admin_tab_nav(array(), 'mt-3', array('panels' => true))), '<div class="ui-osc-tabs osc-tab mt-3"><ul>')
);

harness_section('locale strip');
$li = '<li><a href="#en">English</a></li>';
check('the default wrapper', Field::localeStrip(array($li)) === '<div class="ui-osc-tabs osc-tab"><ul>' . $li . '</ul></div>');
check(
    'id and class',
    Field::localeStrip(array($li), array('id' => 'language-tab', 'class' => 'ui-osc-tabs osc-tab mt-3'))
        === '<div id="language-tab" class="ui-osc-tabs osc-tab mt-3"><ul>' . $li . '</ul></div>'
);
check(
    'panels print inside the wrapper, after the list',
    Field::localeStrip(array($li), array('panels' => '<div id="en"></div>'))
        === '<div class="ui-osc-tabs osc-tab"><ul>' . $li . '</ul><div id="en"></div></div>'
);
check(
    'no tabs print no list but keep the panels',
    Field::localeStrip(array(), array('id' => 'language-tab', 'panels' => '<div id="en"></div>'))
        === '<div id="language-tab" class="ui-osc-tabs osc-tab"><div id="en"></div></div>'
);
check('a hostile id is escaped', str_contains(Field::localeStrip(array(), array('id' => $hostile)), 'id="' . $escaped . '"'));

harness_section('confirm dialog');
$danger = squeezed(html_of(static fn () => osc_admin_confirm_dialog(array(
    'id'     => 'd1',
    'title'  => 'Delete it?',
    'text'   => 'Gone for good.',
    'url'    => '/go',
    'fields' => array('page' => 'items'),
))));
check(
    'the default danger dialog is unchanged',
    $danger === '<dialog id="d1" class="osc-dialog osc-dialog-danger">'
        . '<form method="post" action="/go"><input type="hidden" name="page" value="items" />'
        . '<div class="osc-dialog-body"><p class="osc-dialog-title"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> Delete it? </p>'
        . '<p class="osc-dialog-text">Gone for good.</p></div>'
        . '<div class="osc-dialog-actions"><button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close>Cancel</button>'
        . '<button type="submit" class="btn btn-danger btn-sm"> Delete </button></div>'
        . '</form></dialog>',
    $danger
);
$plain = squeezed(html_of(static fn () => osc_admin_confirm_dialog(array(
    'id'         => 'catConfirm',
    'tone'       => 'neutral',
    'form'       => false,
    'url'        => '/ignored',
    'method'     => 'get',
    'fields'     => array('ignored' => '1'),
    'title_id'   => 'catConfirmTitle',
    'cancel'     => 'Keep editing',
    'cancel_id'  => 'catConfirmCancel',
    'confirm'    => '',
    'confirm_id' => 'catConfirmOk',
    'text_slot'  => true,
    'body_html'  => '<ul id="catConfirmFacts"></ul>',
    'attrs'      => array('aria-labelledby' => 'catConfirmTitle', 'data-field-id' => '7'),
))));
check(
    'a script-driven dialog has no form, a button-type confirm and every id',
    $plain === '<dialog id="catConfirm" class="osc-dialog" aria-labelledby="catConfirmTitle" data-field-id="7">'
        . '<div class="osc-dialog-body"><p class="osc-dialog-title" id="catConfirmTitle"></p>'
        . '<p class="osc-dialog-text"></p><ul id="catConfirmFacts"></ul></div>'
        . '<div class="osc-dialog-actions"><button type="button" class="btn btn-dim btn-sm" id="catConfirmCancel" data-osc-dialog-close>Keep editing</button>'
        . '<button type="button" id="catConfirmOk" class="btn btn-submit btn-sm"></button></div></dialog>',
    $plain
);
check(
    'text wins over text_slot, so there is one text line',
    substr_count(html_of(static fn () => osc_admin_confirm_dialog(array('title' => 't', 'text' => 'x', 'text_slot' => true))), 'osc-dialog-text') === 1
);
check(
    'no text and no text_slot print no text line',
    !str_contains(html_of(static fn () => osc_admin_confirm_dialog(array('title' => 't'))), 'osc-dialog-text')
);
check(
    'a hostile title, text, cancel, attr and ids are escaped',
    !str_contains($h = html_of(static fn () => osc_admin_confirm_dialog(array(
        'title'     => $hostile,
        'text'      => $hostile,
        'cancel'    => $hostile,
        'title_id'  => $hostile,
        'cancel_id' => $hostile,
        'attrs'     => array('data-x' => $hostile),
    ))), '<script>') && substr_count($h, $escaped) === 6,
    $h
);

harness_section('empty state');
$empty = squeezed(html_of(static fn () => osc_admin_empty(array('icon' => 'bi-inbox', 'title' => 'No rules', 'text' => 'Add one.'))));
check(
    'one quiet line: no icon even when one is passed',
    $empty === '<div class="osc-empty"><p class="osc-empty-title">No rules</p><p class="osc-empty-text">Add one.</p></div>',
    $empty
);
$li = squeezed(html_of(static fn () => osc_admin_empty(array('title' => 'None', 'id' => 'palette-empty', 'class' => 'x', 'tag' => 'li'))));
check(
    'id, class and tag',
    $li === '<li id="palette-empty" class="osc-empty x"><p class="osc-empty-title">None</p></li>',
    $li
);
check(
    'an unknown tag falls back to a div',
    str_starts_with(trim(html_of(static fn () => osc_admin_empty(array('title' => 'x', 'tag' => 'script')))), '<div class="osc-empty">')
);
check(
    'the action still renders',
    str_contains(html_of(static fn () => osc_admin_empty(array('title' => 'x', 'action' => array('label' => 'Add', 'url' => '/add')))), 'osc-empty-action')
);
$row = html_of(static fn () => osc_admin_table_empty(4));
check('a table empty row has no icon', !str_contains($row, '<i') && str_contains($row, 'Nothing here yet') && str_contains($row, 'colspan="4"'), $row);

harness_section('callout');
foreach (array('danger', 'warning', 'info', 'success') as $tone) {
    check(
        $tone . ' tone',
        html_of(static fn () => osc_admin_callout($tone, 'Note')) === '<div class="callout-' . $tone . ' callout-block">Note</div>'
    );
}
check('text is escaped by default', Form::callout('info', $hostile) === '<div class="callout-info callout-block">' . $escaped . '</div>');
check('html passes through when asked', Form::callout('info', '<strong>x</strong>', array('html' => true)) === '<div class="callout-info callout-block"><strong>x</strong></div>');
check('block false', Form::callout('info', 'x', array('block' => false)) === '<div class="callout-info">x</div>');
check(
    'id, class and role',
    Form::callout('danger', 'x', array('id' => 'n1', 'class' => 'api-webhook-paused', 'role' => 'alert'))
        === '<div class="callout-danger callout-block api-webhook-paused" id="n1" role="alert">x</div>'
);
check('a hostile tone cannot leave the attribute', !str_contains(Form::callout($hostile, 'x'), '<script>'));

harness_section('status and count');
check('status keeps its markup without a title', html_of(static fn () => osc_admin_status('paid', 'Paid')) === '<span class="osc-status status-paid">Paid</span>');
check(
    'status carries a title',
    html_of(static fn () => osc_admin_status('spam', 'Spam', 'Marked by a <rule>')) === '<span class="osc-status status-spam" title="Marked by a &lt;rule&gt;">Spam</span>'
);
check('statusBadge carries a title', Form::statusBadge('', 'x', 'why') === '<span class="osc-status" title="why">x</span>');
check('count keeps its markup without a title', osc_admin_count(3, 'c') === ' <span class="osc-count" id="c">3</span>');
check('count carries a title', osc_admin_count(3, '', '3 reports') === ' <span class="osc-count" title="3 reports">3</span>');
check('a hostile count title is escaped', !str_contains(osc_admin_count(1, '', $hostile), '<script>'));

harness_section('list filter');
$filter = html_of(static fn () => osc_admin_list_filter(array(
    'class'  => 'media-search',
    'fields' => array(array('type' => 'date', 'label' => 'From', 'id' => 'fFrom', 'name' => 'from', 'value' => '2026-01-02')),
)));
check('the form takes an extra class', str_contains($filter, 'class="osc-filter nocsrf media-search"'), $filter);
check(
    'a date field keeps the labelled shape',
    str_contains(squeezed($filter), '<span class="osc-filter-field"><label for="fFrom">From</label>'
        . '<input type="date" id="fFrom" name="from" class="form-control form-control-sm" value="2026-01-02"/></span>'),
    $filter
);
check('no class leaves the form as it was', str_contains(html_of(static fn () => osc_admin_list_filter(array())), 'class="osc-filter nocsrf"'));

exit(harness_result());
