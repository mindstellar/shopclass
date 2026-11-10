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
 * The builder, market and Categories screens sit in the shared body and sections.
 *
 * Usage:  php tests/admin-builders-structure.php
 */

declare(strict_types=1);

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

$theme = ABS_PATH . 'oc-admin/themes/modern/';
$src   = static fn (string $path): string => (string) file_get_contents($theme . $path);

$screens = array(
    'appearance/add_widget.php', 'appearance/menus.php', 'appearance/widgets.php',
    'categories/index.php', 'fields/index.php', 'plugins/index.php',
);
foreach ($screens as $file) {
    $text = $src($file);
    check($file . ' opens one body', substr_count($text, 'osc_admin_body_open(') === 1);
    check($file . ' closes it', substr_count($text, 'osc_admin_body_close()') === 1);
    check($file . ' has no col-title heading', strpos($text, 'col-title') === false);
    check($file . ' has no col-head wrapper', strpos($text, 'col-head') === false);
}

check('add_widget.php has no mb-3', strpos($src('appearance/add_widget.php'), 'mb-3') === false);

$ids = array(
    'appearance/add_widget.php' => array('widgets-page', 'item-form', 'error_list'),
    'appearance/menus.php'      => array('menus-page', 'menu-form', 'menu-state', 'menu-add-category'),
    'appearance/widgets.php'    => array('appearance-page', 'widgets-live', 'deleteModal'),
    'categories/index.php'      => array('cat-bulk-form', 'cat-filter-line', 'cat-announce'),
    'fields/index.php'          => array('forms-list', 'palette-list', 'add-form-button', 'add-field-button', 'cf-live'),
    'plugins/index.php'         => array('market-tab-installed', 'market-tab-browse', 'market-tab-updates'),
);
foreach ($ids as $file => $list) {
    foreach ($list as $id) {
        check($file . ' keeps #' . $id, strpos($src($file), $id) !== false);
    }
}

$classes = array(
    'categories/index.php' => 'categories-app',
    'plugins/index.php'    => 'market-app',
    'appearance/menus.php' => 'menus-page',
);
foreach ($classes as $file => $class) {
    check($file . ' keeps .' . $class . ' on the body', strpos($src($file), "'class' => '" . $class . "'") !== false);
}

$view = $src('appearance/view.php');
check('appearance/view.php declares a heading', strpos($view, "'heading'") !== false);
check('appearance/view.php opens no body', strpos($view, 'osc_admin_body_open') === false);
check('appearance/view.php keeps the block-flow wrapper', strpos($view, '<div class="theme-files">') !== false);

// Raw values in 'attrs' are escaped once, JSON included.
ob_start();
osc_admin_body_open(array('class' => 'categories-app', 'attrs' => array(
    'data-edit-url' => 'https://x.test/?a=1&b=2',
    'data-i18n'     => json_encode(array('t' => 'He said "hi"')),
)));
osc_admin_body_close();
$html = (string) ob_get_clean();
check('body keeps the app class', strpos($html, 'class="osc-body categories-app"') !== false);
check('url ampersand is escaped once', strpos($html, 'data-edit-url="https://x.test/?a=1&amp;b=2"') !== false);
check('json quotes are escaped once', strpos($html, 'data-i18n="{&quot;t&quot;:&quot;He said \\&quot;hi\\&quot;&quot;}"') !== false);

// A builder column is a section: heading, intro, action in the head row.
ob_start();
osc_admin_section('Forms', array(
    'class'   => 'forms-col',
    'intro'   => 'Intro',
    'actions' => array(array('label' => 'New form', 'variant' => 'outline-primary', 'attrs' => array('id' => 'add-form-button'))),
));
osc_admin_section_close();
$html = (string) ob_get_clean();
check('section keeps the column class', strpos($html, 'osc-section forms-col') !== false);
check('section button keeps its id', strpos($html, 'id="add-form-button"') !== false);
check('section heading is not a col-title', strpos($html, 'col-title') === false);

$scss = (string) file_get_contents($theme . 'scss/categories.scss') . (string) file_get_contents($theme . 'scss/widget-box.scss')
    . (string) file_get_contents($theme . 'scss/catcards.scss');
check('palette-group margin rule is gone', strpos($scss, '.palette-group + .palette-group') === false);
check('help-box margin rule is gone', strpos($scss, '.help-box + .osc-catcards-bar') === false);
check('.cat-tabs margin rule is gone', strpos($scss, ".cat-tabs {\n  margin") === false);
check('.col-title stays defined', strpos($scss, '.col-title {') !== false);

exit(harness_result());
