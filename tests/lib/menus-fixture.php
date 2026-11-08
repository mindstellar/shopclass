<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * A three-level menu for the Menus screen browser tests, in the shape the screen gets from
 * MenuService: Home; Vehicles > Cars > Car Parts; Pages > About; a link.
 */

/**
 * The menu items, nested, as menus.php receives them.
 *
 * @return array<int,array<string,mixed>>
 */
function menus_fixture_items(): array
{
    return array(
        array('type' => 'builtin', 'ref' => 'home'),
        array('type' => 'category', 'ref' => 2, 'children' => array(
            array('type' => 'category', 'ref' => 3, 'children' => array(
                array('type' => 'category', 'ref' => 4),
            )),
        )),
        array('type' => 'link', 'ref' => 0, 'url' => '/pages', 'label' => array('en_US' => 'Pages'), 'children' => array(
            array('type' => 'page', 'ref' => 1),
        )),
        array('type' => 'link', 'ref' => 0, 'url' => 'https://example.test/sale', 'label' => array('en_US' => 'Sale')),
    );
}

/**
 * The whole $menuEditor view variable around a set of items.
 *
 * @param array<int,array<string,mixed>>|null $items nested items, the fixture menu when null
 * @param array<string,mixed>                 $over  keys to replace, such as place or draft
 *
 * @return array<string,mixed>
 */
function menus_fixture_editor(?array $items = null, array $over = array()): array
{
    return $over + array(
        'id'         => 'main',
        'menus'      => array('main' => 'Main menu', 'footer' => 'Footer menu'),
        'core'       => true,
        'saved'      => true,
        'themed'     => false,
        'draft'      => false,
        'place'      => null,
        'places'     => array(),
        'items'      => $items ?? menus_fixture_items(),
        'categories' => array(
            array('id' => 1, 'parent' => 0, 'name' => 'For sale'),
            array('id' => 2, 'parent' => 0, 'name' => 'Vehicles'),
            array('id' => 3, 'parent' => 2, 'name' => 'Cars'),
            array('id' => 4, 'parent' => 3, 'name' => 'Car Parts'),
        ),
        'pages'      => array(array('id' => 1, 'name' => 'About')),
        'builtins'   => array(
            array('id' => 'home', 'name' => 'Home', 'on' => true),
            array('id' => 'search', 'name' => 'Search', 'on' => true),
            array('id' => 'contact', 'name' => 'Contact', 'on' => true),
        ),
    );
}

/* file end: ./tests/lib/menus-fixture.php */
