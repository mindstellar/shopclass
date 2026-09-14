<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Helper Menu Admin
 *
 * @package    Shopclass
 * @subpackage Helpers
 * @author     Shopclass
 */

/**
 * Draws menu with sections and subsections
 *
 * @return void
 */
function osc_draw_admin_menu()
{
    AdminMenu::newInstance()->renderAdminMenu();
}

/**
 * Add menu entry
 *
 * @param string      $menu_title
 * @param string      $url
 * @param string      $menu_id
 * @param string      $capability
 * @param string|null $icon_url
 * @param int|null    $position
 *
 * @return void
 */
function osc_add_admin_menu_page(
    $menu_title,
    $url,
    $menu_id,
    $capability = 'administrator',
    $icon_url = null,
    $position = null
) {
    AdminMenu::newInstance()->add_menu($menu_title, $url, $menu_id, $capability, $icon_url, $position);
}

/**
 * Remove the whole menu
 *
 * @return void
 */
function osc_remove_admin_menu()
{
    AdminMenu::newInstance()->clear_menu();
}

/**
 * Remove menu section with id $menu_id
 *
 * @param string $menu_id
 *
 * @return void
 */
function osc_remove_admin_menu_page($menu_id)
{
    AdminMenu::newInstance()->remove_menu($menu_id);
}

/**
 * Add submenu under menu id $menu_id
 *
 * @param string $menu_id
 * @param string $submenu_title
 * @param string $url
 * @param string $submenu_id
 * @param string $capability
 *
 * @return void
 */
function osc_add_admin_submenu_page($menu_id, $submenu_title, $url, $submenu_id, $capability = 'administrator')
{
    AdminMenu::newInstance()->add_submenu($menu_id, $submenu_title, $url, $submenu_id, $capability);
}

/**
 * Remove submenu with id $submenu_id under menu id $menu_id
 *
 * @param string $menu_id
 * @param string $submenu_id
 *
 * @return void
 */
function osc_remove_admin_submenu_page($menu_id, $submenu_id)
{
    AdminMenu::newInstance()->remove_submenu($menu_id, $submenu_id);
}

/**
 * Add submenu divider under menu id $menu_id
 *
 * @param string      $menu_id
 * @param string      $submenu_title
 * @param string      $submenu_id
 * @param string|null $capability
 *
 * @return void
 * @since 3.1
 */
function osc_add_admin_submenu_divider($menu_id, $submenu_title, $submenu_id, $capability = null)
{
    AdminMenu::newInstance()->add_submenu_divider($menu_id, $submenu_title, $submenu_id, $capability);
}

/**
 * Remove submenu divider with id $submenu_id under menu id $menu_id
 *
 * @param string $menu_id
 * @param string $submenu_id
 *
 * @return void
 * @since 3.1
 */
function osc_remove_admin_submenu_divider($menu_id, $submenu_id)
{
    AdminMenu::newInstance()->remove_submenu_divider($menu_id, $submenu_id);
}

/**
 * Add a submenu entry under the listings menu page
 *
 * @param string      $submenu_title
 * @param string      $url
 * @param string      $submenu_id
 * @param string|null $capability
 * @param string|null $icon_url
 *
 * @return void
 */
function osc_admin_menu_items($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
{
    AdminMenu::newInstance()->add_menu_items($submenu_title, $url, $submenu_id, $capability, $icon_url);
}

/**
 * Add a submenu entry under the categories menu page
 *
 * @param string      $submenu_title
 * @param string      $url
 * @param string      $submenu_id
 * @param string|null $capability
 * @param string|null $icon_url
 *
 * @return void
 */
function osc_admin_menu_categories($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
{
    AdminMenu::newInstance()->add_menu_categories($submenu_title, $url, $submenu_id, $capability, $icon_url);
}

/**
 * Add a submenu entry under the pages menu page
 *
 * @param string      $submenu_title
 * @param string      $url
 * @param string      $submenu_id
 * @param string|null $capability
 * @param string|null $icon_url
 *
 * @return void
 */
function osc_admin_menu_pages($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
{
    AdminMenu::newInstance()->add_menu_pages($submenu_title, $url, $submenu_id, $capability, $icon_url);
}

/**
 * Add a submenu entry under the appearance menu page
 *
 * @param string      $submenu_title
 * @param string      $url
 * @param string      $submenu_id
 * @param string|null $capability
 * @param string|null $icon_url
 *
 * @return void
 */
function osc_admin_menu_appearance($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
{
    AdminMenu::newInstance()->add_menu_appearance($submenu_title, $url, $submenu_id, $capability, $icon_url);
}

/**
 * Add a submenu entry under the plugins menu page
 *
 * @param string      $submenu_title
 * @param string      $url
 * @param string      $submenu_id
 * @param string|null $capability
 * @param string|null $icon_url
 *
 * @return void
 */
function osc_admin_menu_plugins($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
{
    AdminMenu::newInstance()->add_menu_plugins($submenu_title, $url, $submenu_id, $capability, $icon_url);
}

/**
 * Add a submenu entry under the settings menu page
 *
 * @param string      $submenu_title
 * @param string      $url
 * @param string      $submenu_id
 * @param string|null $capability
 * @param string|null $icon_url
 *
 * @return void
 */
function osc_admin_menu_settings($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
{
    AdminMenu::newInstance()->add_menu_settings($submenu_title, $url, $submenu_id, $capability, $icon_url);
}

/**
 * Add a submenu entry under the tools menu page
 *
 * @param string      $submenu_title
 * @param string      $url
 * @param string      $submenu_id
 * @param string|null $capability
 * @param string|null $icon_url
 *
 * @return void
 */
function osc_admin_menu_tools($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
{
    AdminMenu::newInstance()->add_menu_tools($submenu_title, $url, $submenu_id, $capability, $icon_url);
}

/**
 * Add a submenu entry under the users menu page
 *
 * @param string      $submenu_title
 * @param string      $url
 * @param string      $submenu_id
 * @param string|null $capability
 * @param string|null $icon_url
 *
 * @return void
 */
function osc_admin_menu_users($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
{
    AdminMenu::newInstance()->add_menu_users($submenu_title, $url, $submenu_id, $capability, $icon_url);
}

/**
 * Add a submenu entry under the stats menu page
 *
 * @param string      $submenu_title
 * @param string      $url
 * @param string      $submenu_id
 * @param string|null $capability
 * @param string|null $icon_url
 *
 * @return void
 */
function osc_admin_menu_stats($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
{
    AdminMenu::newInstance()->add_menu_stats($submenu_title, $url, $submenu_id, $capability, $icon_url);
}

/**
 * Id of the admin menu section matching the current request URL.
 *
 * @return string Empty string when nothing matches
 */
function osc_current_menu()
{
    $menu_id            = '';
    $current_menu       = 'dash';
    $something_selected = false;
    $aMenu              = AdminMenu::newInstance()->get_array_menu();

    $url_actual = '?' . Params::getServerParam('QUERY_STRING', false, false);
    if (preg_match('/(^.*action=\w+)/', $url_actual, $matches)) {
        $url_actual = $matches[1];
    } elseif (preg_match('/(^.*page=\w+)/', $url_actual, $matches)) {
        $url_actual = $matches[1];
    } elseif ($url_actual === '?') {
        $url_actual = '';
    }

    foreach ($aMenu as $key => $value) {
        $aMenu_actions = array();
        $url           = $value[1];
        $url           = str_replace(array(osc_admin_base_url(true), osc_admin_base_url()), '', $url);

        $aMenu_actions[] = $url;
        if (array_key_exists('sub', $value)) {
            $aSubmenu = $value['sub'];
            if ($aSubmenu) {
                foreach ($aSubmenu as $aSub) {
                    $url             = str_replace(osc_admin_base_url(true), '', $aSub[1]);
                    $aMenu_actions[] = $url;
                }
            }
        }

        if (in_array($url_actual, $aMenu_actions)) {
            $something_selected = true;
            $menu_id            = $value[2];
        }
    }

    if ($something_selected) {
        return $menu_id;
    }

    // try again without action
    $url_actual = preg_replace('/(&action=.+)/', '', $url_actual);
    foreach ($aMenu as $key => $value) {
        $aMenu_actions = array();
        $url           = $value[1];
        $url           = str_replace(array(osc_admin_base_url(true), osc_admin_base_url()), '', $url);

        $aMenu_actions[] = $url;
        if (array_key_exists('sub', $value)) {
            $aSubmenu = $value['sub'];
            if ($aSubmenu) {
                foreach ($aSubmenu as $aSub) {
                    $url             = str_replace(osc_admin_base_url(true), '', $aSub[1]);
                    $aMenu_actions[] = $url;
                }
            }
        }
        if (in_array($url_actual, $aMenu_actions)) {
            $something_selected = true;
            $menu_id            = $value[2];
        }
    }

    return $menu_id;
}
