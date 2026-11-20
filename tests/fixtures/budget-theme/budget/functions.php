<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

osc_add_hook('init', static function (): void {
    osc_add_theme_support('menus', array(
        'main' => array('label' => 'Header', 'depth' => 1, 'default' => array(
            array('type' => 'builtin', 'ref' => 'home'),
            array('type' => 'builtin', 'ref' => 'search'),
            array('type' => 'builtin', 'ref' => 'contact'),
        )),
        'footer' => array('label' => 'Footer', 'depth' => 1),
    ));
});

function budget_listing(): void
{
    echo '<li><a href="', osc_esc_html(osc_item_url()), '">', osc_esc_html(osc_item_title()), '</a> ',
        osc_esc_html(osc_item_formatted_price()), ' ', osc_esc_html(osc_item_city()), ' ',
        osc_esc_html(osc_format_date(osc_item_pub_date())), '</li>';
}
