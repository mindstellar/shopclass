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
 * osc_menu() and the core.menu widget: the helper returns the same links the service
 * resolves, the widget prints nested escaped lists inside <nav class="osc-menu"> with an
 * optional heading, prints nothing for an empty menu, and offers main, footer and the saved
 * menus as choices.
 * DB-backed. Usage: php tests/menu-widget.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/api-boot.php';
require_once __DIR__ . '/lib/stubs.php';

$admin = scratchdb_session('osc_menu_widget');
function osc_current_user_locale()
{
    return 'en_US';
}
function osc_search_url($params = array())
{
    return '/c/' . (int) ($params['sCategory'] ?? 0);
}
function osc_core_url($name, $params = array())
{
    return '/page/' . (int) ($params['id'] ?? 0);
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hWidgets.php';

use mindstellar\menu\MenuService;

seed_locale($admin, 'en_US', 'English');
Preference::getInstance()->replace('language', 'en_US');
Preference::getInstance()->replace('rewriteEnabled', '0');
$motors = seed_category($admin, 'Motors');
$cars   = seed_category($admin, 'Cars', $motors);

$render = static function (array $config): string {
    ob_start();
    osc_render_widget(array('s_type' => 'core.menu', 's_config' => json_encode($config), 's_content' => ''));

    return (string) ob_get_clean();
};

harness_section('osc_menu');
pin('main with nothing saved: the top categories', [['label' => 'Motors', 'url' => '/c/' . $motors]], osc_menu('main'));
pin('an unknown menu is empty', [], osc_menu('nope'));

MenuService::getInstance()->save('side', [
    ['type' => 'category', 'ref' => $motors, 'children' => [['type' => 'category', 'ref' => $cars]]],
    ['type' => 'link', 'url' => 'https://example.test/?a=1&b=2', 'label' => ['en_US' => 'Tom & "Jerry"']],
], 'Side links');
pin('a saved menu resolves with children', ['Motors', 'Tom & "Jerry"'], array_column(osc_menu('side'), 'label'));

harness_section('core.menu');
check('the type is registered', osc_widget_types()['core.menu']['label'] === 'Menu');
pin('choices: main, footer, then saved menus by their label', ['main' => 'Main menu', 'footer' => 'Footer menu', 'side' => 'Side links'], osc_menu_choices());
$support = \mindstellar\theme\ThemeSupports::getInstance();
$support->add('menus', ['main' => ['label' => 'Top bar', 'depth' => 1], 'strip' => ['label' => 'Strip']]);
pin('choices: a declared place is named, an unsaved one is listed', ['main' => 'Main menu — Top bar', 'footer' => 'Footer menu', 'side' => 'Side links', 'strip' => 'Strip'], osc_menu_choices());
$support->reset();
pin('the menu select offers the same ids', ['main', 'footer', 'side'], \mindstellar\widgets\WidgetConfig::selectAllowedValues(osc_widget_types()['core.menu']['fields'][0]['options']));

pin('nested lists with a heading', '<nav class="osc-menu" aria-label="Browse"><h2 class="osc-menu-title">Browse</h2><ul>'
    . '<li><a href="/c/' . $motors . '">Motors</a><ul><li><a href="/c/' . $cars . '">Cars</a></li></ul></li>'
    . '<li><a href="https://example.test/?a=1&amp;b=2">Tom &amp; &quot;Jerry&quot;</a></li></ul></nav>', $render(['menu' => 'side', 'heading' => 'Browse']));
pin('no heading: the menu name labels the nav', '<nav class="osc-menu" aria-label="Main menu"><ul><li><a href="/c/' . $motors . '">Motors</a></li></ul></nav>', $render(['menu' => 'main', 'heading' => '']));
pin('an empty menu prints nothing', '', $render(['menu' => 'footer']));
pin('an unknown menu prints nothing', '', $render(['menu' => 'gone']));

exit(harness_result());
