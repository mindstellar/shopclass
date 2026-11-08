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
 * The admin header a screen declares with osc_admin_page(): with a 'heading' it draws one
 * title row (the h1, then buttons, then icon actions), without one it keeps the section h1.
 * A later level-2 osc_admin_page_head() does not draw a second title, and the top bar shows
 * the "Section › Page" path.
 *
 * Usage:  php tests/admin-page-header.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}

$GLOBALS['hooks'] = array();
function osc_add_hook($hook, $fn, $priority = 5)
{
    $GLOBALS['hooks'][$hook][] = $fn;
}
function osc_run_hook($hook, ...$args)
{
    foreach ($GLOBALS['hooks'][$hook] ?? array() as $fn) {
        $fn(...$args);
    }
}
function osc_add_filter($hook, $fn, $priority = 5)
{
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

    public static function getParamString(string $key): string
    {
        return '';
    }
}

class AdminMenu
{
    public static function getInstance(): self
    {
        return new self();
    }

    public function currentIds(): array
    {
        return array('pages', 'pages_manage');
    }

    public function get_array_menu(): array
    {
        return array('pages' => array('Pages', 'https://example.test/oc-admin/index.php?page=pages', 'pages'));
    }

    public function settingsGroupTabs($entry, $moderator): array
    {
        return array();
    }
}

function osc_is_moderator()
{
    return false;
}

function osc_admin_base_url($index = false)
{
    return 'https://example.test/oc-admin/index.php';
}

require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
require_once ABS_PATH . 'oc-includes/classes/admin/ui/Field.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

function render(callable $fn): string
{
    ob_start();
    $fn();

    return (string)ob_get_clean();
}

$add = array('icon' => 'bi-plus-circle-fill', 'url' => 'https://example.test/add', 'title' => 'Create page');

// The header title is recorded once per request, so the screen with no heading goes first.
harness_section('no heading');
$old = render(static fn () => osc_admin_page_header('Pages', array('actions' => array($add), 'help' => true)));
check('the section is the h1', (bool)preg_match('~<h1>Pages\s*<a~', $old), $old);
check('...with the icon actions inside it, help last', preg_match('~Create page.*bi-question-circle.*</a>\s*</h1>~s', $old) === 1, $old);
check('...and no title row', !str_contains($old, 'page-head'), $old);
check('a level-2 page head still draws the page title', str_contains(render(static fn () => osc_admin_page_head('Manage pages')), '<h2 class="render-title osc-page-title">Manage pages</h2>'));

osc_admin_page(array('section' => 'Pages'));
$crumb = render(static fn () => osc_admin_breadcrumb());
check('with no heading the path is only the section', str_contains($crumb, '<a href="https://example.test/oc-admin/index.php?page=pages">Pages</a>') && !str_contains($crumb, 'aria-current'), $crumb);

harness_section('declared heading');
$GLOBALS['hooks'] = array();
osc_admin_page(array(
    'section'      => 'Pages',
    'heading'      => static fn () => 'Manage pages',
    'buttons'      => array(array('label' => 'Export', 'url' => 'https://example.test/export')),
    'buttons_html' => static function () {
        echo '<span class="seg">Toggle</span>';
    },
    'actions'      => array($add),
    'help'         => 'Help text',
));
$row = render(static fn () => osc_run_hook('admin_page_header'));
check('one h1, the page title', substr_count($row, '<h1') === 1 && str_contains($row, '<h1 class="render-title osc-page-title">Manage pages</h1>'), $row);
check('the section name is not printed', !str_contains($row, '>Pages<'), $row);
check('the title sits in a title row', (bool)preg_match('~<div class="page-head">\s*<h1~', $row), $row);
$order = array(strpos($row, 'Export'), strpos($row, 'class="seg"'), strpos($row, 'Create page'), strpos($row, 'bi-question-circle'));
check('buttons, then the custom control, then icon actions, help last', $order === array_values(array_filter($order)) && $order[0] < $order[1] && $order[1] < $order[2] && $order[2] < $order[3], json_encode($order));
check('the icon actions keep their look', str_contains($row, 'class="osc-head-action osc-head-add"') && str_contains($row, 'class="osc-head-action bi bi-question-circle"'), $row);
check('the header recorded the title', osc_admin_header_title() === 'Manage pages' && osc_admin_page_titled());

$crumb = render(static fn () => osc_admin_breadcrumb());
check('the path is a breadcrumb nav with an ordered list', str_contains($crumb, '<nav class="admin-breadcrumb" aria-label="Breadcrumb"><ol>'), $crumb);
check('...the section links to its main screen', str_contains($crumb, '<li class="admin-breadcrumb-section"><a href="https://example.test/oc-admin/index.php?page=pages">Pages</a></li>'), $crumb);
check('...and the page is plain text marked current', str_contains($crumb, '<li class="admin-breadcrumb-page" aria-current="page">Manage pages</li>'), $crumb);

harness_section('no second title');
check('a level-2 call with the same title prints nothing', render(static fn () => osc_admin_page_head('Manage pages')) === '');
$same = render(static fn () => osc_admin_page_head('Manage pages', array(array('label' => 'Settings', 'url' => 'http://x.test/s'))));
check('with actions it keeps them and draws no second title', str_contains($same, 'page-head-actions') && str_contains($same, 'Settings') && !str_contains($same, 'Manage pages'));
$other = render(static fn () => osc_admin_page_head('Other themes'));
check('one with other text is a section heading, an h2 under the header\'s h1', $other === '<h2 class="render-title osc-section-title">Other themes</h2>', $other);
check('a level-3 section is an h2 under the header\'s h1', render(static fn () => osc_admin_page_head('Rates', array(), array('level' => 3))) === '<h2 class="render-title osc-section-title">Rates</h2>');
$form = render(static fn () => \mindstellar\admin\ui\Form::heading('Notes', 'section', 'n') . \mindstellar\admin\ui\Form::heading('More', 'subsection', 'm'));
check('form sections follow it: h2, sub-sections h3', $form === '<h2 id="n" class="render-title osc-section-title">Notes</h2><h3 id="m" class="render-title osc-subsection-title">More</h3>', $form);

$settingsView = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/gui/admin/settings-page.php');
check('a registered settings screen declares its title as the header title row', preg_match('/osc_admin_page\(array\([^;]*\'heading\'\s*=>\s*\$page\[\'title\'\]/s', $settingsView) === 1);

exit(harness_result());
