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
 * The shared body and section helpers every admin screen sits in, and what the older
 * section helpers print next to them.
 *
 * Usage:  php tests/admin-structure.php
 */

declare(strict_types=1);

use mindstellar\admin\ui\Form;
use mindstellar\admin\ui\PageState;

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}

require_once dirname(__DIR__) . '/oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';

class Params
{
    public static function getParam($key, $a = false, $b = true)
    {
        return '';
    }
}

function osc_admin_base_url($index = false)
{
    return 'https://example.test/oc-admin/index.php';
}

require_once ABS_PATH . 'oc-includes/classes/admin/ui/Field.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

function render(callable $fn): string
{
    ob_start();
    $fn();

    return (string)ob_get_clean();
}

function same(string $name, string $actual, string $expected): void
{
    check($name, $actual === $expected, $actual);
}

// The header title is recorded once per request, so the sections with no header title go first.
harness_section('sections without a header title');
same('a section is an h3, the section carries the id', render(static function () {
    osc_admin_section('Server rules (nginx)');
    osc_admin_section_close();
}), '<section class="osc-section" id="s-server-rules-nginx"><h3 class="render-title osc-section-title">Server rules (nginx)</h3><div class="osc-section-body"></div></section>');
same('a sub-section is an h4', render(static function () {
    osc_admin_section('Later', array('level' => 'subsection', 'id' => 'later'));
    osc_admin_section_close();
}), '<section class="osc-section" id="later"><h4 class="render-title osc-subsection-title">Later</h4><div class="osc-section-body"></div></section>');

PageState::setHeaderTitle('Page');

harness_section('body');
same('a stack is the default', render(static function () {
    osc_admin_body_open();
    echo 'x';
    osc_admin_body_close();
}), '<div class="osc-body">x</div>');
same('a grid, with id, class and attrs', render(static function () {
    osc_admin_body_open(array('layout' => 'grid', 'id' => 'a"b', 'class' => 'theme-files', 'attrs' => array('data-x' => '1')));
    osc_admin_body_close();
}), '<div class="osc-body osc-body-grid theme-files" id="a&quot;b" data-x="1"></div>');
same('an unknown layout is a stack', render(static function () {
    osc_admin_body_open(array('layout' => 'nope'));
    osc_admin_body_close();
}), '<div class="osc-body"></div>');
same('a split has a main column, then the rail', render(static function () {
    osc_admin_body_open(array('layout' => 'split'));
    echo 'main';
    osc_admin_body_rail(array('class' => 'side', 'id' => 'r'));
    echo 'rail';
    osc_admin_body_close();
}), '<div class="osc-body osc-body-split"><div>main</div><aside class="osc-body-rail side" id="r">rail</aside></div>');
same('a split with no rail still closes its main column', render(static function () {
    osc_admin_body_open(array('layout' => 'split'));
    osc_admin_body_close();
}), '<div class="osc-body osc-body-split"><div></div></div>');
same('a second rail prints nothing', render(static function () {
    osc_admin_body_open(array('layout' => 'split'));
    osc_admin_body_rail();
    osc_admin_body_rail();
    osc_admin_body_close();
}), '<div class="osc-body osc-body-split"><div></div><aside class="osc-body-rail"></aside></div>');
same('a rail in a stack prints nothing', render(static function () {
    osc_admin_body_open();
    osc_admin_body_rail();
    osc_admin_body_close();
}), '<div class="osc-body"></div>');
same('bodies nest and close innermost first', render(static function () {
    osc_admin_body_open(array('layout' => 'split'));
    osc_admin_body_open();
    osc_admin_body_close();
    osc_admin_body_rail();
    osc_admin_body_close();
}), '<div class="osc-body osc-body-split"><div><div class="osc-body"></div></div><aside class="osc-body-rail"></aside></div>');

harness_section('section');
$head = render(static function () {
    osc_admin_section('Notes', array(
        'intro'   => 'A <b> intro',
        'actions' => array(array('label' => 'Add', 'url' => 'https://example.test/add')),
        'class'   => 'sysinfo-group',
        'attrs'   => array('data-x' => '1'),
    ));
    echo 'body';
    osc_admin_section_close();
});
same('title, actions, intro and body', $head, '<section class="osc-section sysinfo-group" id="s-notes" data-x="1">'
    . '<div class="osc-section-head"><h2 class="render-title osc-section-title">Notes</h2>'
    . '<span class="osc-section-actions"><a class="btn btn-sm btn-secondary" href="https://example.test/add">Add</a></span></div>'
    . '<p class="form-intro osc-section-intro">A &lt;b&gt; intro</p>'
    . '<div class="osc-section-body">body</div></section>');
check('the id is printed once', substr_count($head, 'id="s-notes"') === 1, $head);
$sub = render(static function () {
    osc_admin_section('Sub', array('level' => 'subsection'));
    osc_admin_section_close();
});
check('a sub-section is an h3 under the header title', str_contains($sub, '<h3 class="render-title osc-subsection-title">Sub</h3>'), $sub);
$html = render(static function () {
    osc_admin_section('Rich', array('intro_html' => 'See <a href="#">this</a>', 'intro' => 'ignored'));
    osc_admin_section_close();
});
check('intro_html is printed as markup and wins over intro', str_contains($html, '<p class="form-intro osc-section-intro">See <a href="#">this</a></p>') && !str_contains($html, 'ignored'), $html);
same('no title: no heading and no id', render(static function () {
    osc_admin_section('');
    osc_admin_section_close();
}), '<section class="osc-section"><div class="osc-section-body"></div></section>');
same('no title with actions keeps the head row', render(static function () {
    osc_admin_section('', array('actions' => array(array('label' => 'Go', 'url' => 'https://example.test/'))));
    osc_admin_section_close();
}), '<section class="osc-section"><div class="osc-section-head"><span class="osc-section-actions"><a class="btn btn-sm btn-secondary" href="https://example.test/">Go</a></span></div><div class="osc-section-body"></div></section>');
$escaped = render(static function () {
    osc_admin_section('<b>T</b>', array('id' => 'x"y', 'class' => 'c"d'));
    osc_admin_section_close();
});
check('title, id and class are escaped', str_contains($escaped, '&lt;b&gt;T&lt;/b&gt;') && str_contains($escaped, 'id="x&quot;y"') && str_contains($escaped, 'c&quot;d') && !str_contains($escaped, '<b>'), $escaped);
$icon = render(static function () {
    osc_admin_section('Plain', array('icon' => 'bi-clock-history', 'intro' => 'Line'));
    osc_admin_section_close();
});
check('an icon option draws no icon', !str_contains($icon, '<i') && !str_contains($icon, 'bi-clock'), $icon);

harness_section('unbalanced closes');
same('closing a section that is not open prints nothing', render(static fn () => osc_admin_section_close()), '');
same('closing a body that is not open prints nothing', render(static fn () => osc_admin_body_close()), '');
same('a rail with no body prints nothing', render(static fn () => osc_admin_body_rail()), '');
same('the extra close leaves a later pair balanced', render(static function () {
    osc_admin_section_close();
    osc_admin_section('');
    osc_admin_section_close();
    osc_admin_section_close();
}), '<section class="osc-section"><div class="osc-section-body"></div></section>');

harness_section('older helpers');
same('osc_admin_form_section ignores spaced', render(static fn () => osc_admin_form_section('Later', array('spaced' => true))), '<h2 id="s-later" class="render-title osc-section-title">Later</h2>');
same('...and so does its level 2', render(static fn () => osc_admin_form_section('Top', array('spaced' => true, 'level' => 2))), '<h2 id="s-top" class="render-title">Top</h2>');
same('osc_admin_section_heading draws the same heading', render(static fn () => osc_admin_section_heading('Later')), '<h2 id="s-later" class="render-title osc-section-title">Later</h2>');
same('Form::heading ignores spaced', render(static fn () => Form::heading('Later', 'section', 'l', true)), '<h2 id="l" class="render-title osc-section-title">Later</h2>');
$action = render(static fn () => osc_admin_action_section(array('title' => 'Queue', 'spaced' => true, 'intro' => 'Runs', 'actions' => array(array('label' => 'Run', 'url' => 'https://example.test/run')))));
same('an action section is a section too, same inner markup', $action, '<section class="osc-section settings-actions">'
    . '<h2 id="s-queue" class="render-title osc-section-title">Queue</h2><p class="form-intro">Runs</p>'
    . '<div class="settings-action"><a class="btn btn-sm btn-secondary" href="https://example.test/run">Run</a></div></section>');

harness_section('forms');
$open = render(static fn () => osc_admin_form_open(array('action' => 'x', 'stack' => true)));
check('stack adds osc-stack to the form body', str_contains($open, '<fieldset><div class="form-horizontal osc-stack">'), $open);
$plain = render(static fn () => osc_admin_form_open(array('action' => 'x')));
check('without it the wrapper is as before', str_contains($plain, '<fieldset><div class="form-horizontal">'), $plain);
$bar = render(static fn () => osc_admin_form_actions(array(), array('sticky' => false)));
check('sticky false adds is-static', str_contains($bar, '<div class="form-actions is-static">'), $bar);
$default = render(static fn () => osc_admin_form_actions(array(array('label' => 'Save', 'type' => 'submit'))));
same('the default bar is unchanged', $default, '<div class="form-actions"><button type="submit" class="btn btn-sm btn-submit">Save</button></div>');
$dirty = render(static fn () => osc_admin_form_actions(array(), array('sticky' => false, 'dirty' => true)));
check('the dirty bar can be static too', str_contains($dirty, '<div class="form-actions is-static" data-osc-dirty-bar') && str_contains($dirty, 'form-actions-status'), $dirty);
$close = render(static fn () => osc_admin_form_close(array(), array('sticky' => false)));
check('osc_admin_form_close passes sticky on', str_contains($close, 'form-actions is-static') && str_ends_with($close, '</div></fieldset></form>'), $close);

exit(harness_result());
