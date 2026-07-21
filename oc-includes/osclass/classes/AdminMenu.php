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
 * AdminMenu class
 *
 * @since      3.0
 * @package    Shopclass
 * @subpackage classes
 * @author     Shopclass
 */
class AdminMenu
{
    private static $instance;
    private $aMenu;

    public function __construct()
    {
        $this->aMenu = array();
    }

    /**
     *  Initialize menu representation.
     */
    public function init()
    {
        $this->add_menu(__('Dashboard'), osc_admin_base_url(), 'dash', 'moderator', 'bi bi-speedometer');

        $this->add_menu(__('Listings'), osc_admin_base_url(true) . '?page=items', 'items', 'moderator', 'bi bi-list-ul');
        $this->add_submenu(
            'items',
            __('Manage listings'),
            osc_admin_base_url(true) . '?page=items',
            'items_manage',
            'moderator'
        );
        $this->add_submenu(
            'items',
            __('Reported listings'),
            osc_admin_base_url(true) . '?page=items&action=items_reported',
            'items_reported',
            'moderator'
        );
        $this->add_submenu(
            'items',
            __('Manage media'),
            osc_admin_base_url(true) . '?page=media',
            'items_media',
            'moderator'
        );
        $this->add_submenu(
            'items',
            __('Comments'),
            osc_admin_base_url(true) . '?page=comments',
            'items_comments',
            'moderator'
        );
        $this->add_submenu(
            'items',
            __('Custom fields'),
            osc_admin_base_url(true) . '?page=cfields',
            'items_cfields',
            'administrator'
        );
        $this->add_submenu(
            'items',
            __('Settings'),
            osc_admin_base_url(true) . '?page=items&action=settings',
            'items_settings',
            'administrator'
        );

        $this->add_menu(__('Appearance'), osc_admin_base_url(true) . '?page=appearance', 'appearance', 'administrator',
                        'bi bi-palette');
        $this->add_submenu(
            'appearance',
            __('Manage themes'),
            osc_admin_base_url(true) . '?page=appearance',
            'appearance_manage',
            'administrator'
        );
        $this->add_submenu(
            'appearance',
            __('Manage widgets'),
            osc_admin_base_url(true) . '?page=appearance&action=widgets',
            'appearance_widgets',
            'administrator'
        );

        $this->add_menu(__('Plugins'), osc_admin_base_url(true) . '?page=plugins', 'plugins', 'administrator', 'bi bi-plugin');
        $this->add_submenu(
            'plugins',
            __('Manage plugins'),
            osc_admin_base_url(true) . '?page=plugins',
            'plugins_manage',
            'administrator'
        );

        $this->add_menu(__('Statistics'), osc_admin_base_url(true) . '?page=stats&action=items', 'stats', 'moderator',
                        'bi bi-bar-chart');
        $this->add_submenu(
            'stats',
            __('Listings'),
            osc_admin_base_url(true) . '?page=stats&action=items',
            'stats_items',
            'moderator'
        );
        $this->add_submenu(
            'stats',
            __('Reports'),
            osc_admin_base_url(true) . '?page=stats&action=reports',
            'stats_reports',
            'moderator'
        );
        $this->add_submenu(
            'stats',
            __('Users'),
            osc_admin_base_url(true) . '?page=stats&action=users',
            'stats_users',
            'moderator'
        );
        $this->add_submenu(
            'stats',
            __('Comments'),
            osc_admin_base_url(true) . '?page=stats&action=comments',
            'stats_comments',
            'moderator'
        );

        $this->add_menu(__('Settings'), osc_admin_base_url(true) . '?page=settings', 'settings', 'administrator', 'bi bi-gear');
        $this->add_submenu(
            'settings',
            __('General'),
            osc_admin_base_url(true) . '?page=settings',
            'settings_general',
            'administrator'
        );
        $this->add_submenu(
            'settings',
            __('Categories'),
            osc_admin_base_url(true) . '?page=categories',
            'settings_categories',
            'administrator'
        );
        $this->add_submenu(
            'settings',
            __('Comments'),
            osc_admin_base_url(true) . '?page=settings&action=comments',
            'settings_comments',
            'administrator'
        );
        $this->add_submenu(
            'settings',
            __('Locations'),
            osc_admin_base_url(true) . '?page=settings&action=locations',
            'settings_locations',
            'administrator'
        );
        $this->add_submenu(
            'settings',
            __('Email templates'),
            osc_admin_base_url(true) . '?page=emails',
            'settings_emails_manage',
            'administrator'
        );
        $this->add_submenu(
            'settings',
            __('Languages'),
            osc_admin_base_url(true) . '?page=languages',
            'settings_language',
            'administrator'
        );
        $this->add_submenu(
            'settings',
            __('Permalinks'),
            osc_admin_base_url(true) . '?page=settings&action=permalinks',
            'settings_permalinks',
            'administrator'
        );
        $this->add_submenu(
            'settings',
            __('Spam and bots'),
            osc_admin_base_url(true) . '?page=settings&action=spamNbots',
            'settings_spambots',
            'administrator'
        );
        $this->add_submenu(
            'settings',
            __('Sitemap'),
            osc_admin_base_url(true) . '?page=settings&action=sitemap',
            'settings_sitemap',
            'administrator'
        );
        $this->add_submenu(
            'settings',
            __('Currencies'),
            osc_admin_base_url(true) . '?page=settings&action=currencies',
            'settings_currencies',
            'administrator'
        );
        $this->add_submenu(
            'settings',
            __('Mail server'),
            osc_admin_base_url(true) . '?page=settings&action=mailserver',
            'settings_mailserver',
            'administrator'
        );
        $this->add_submenu(
            'settings',
            __('Media'),
            osc_admin_base_url(true) . '?page=settings&action=media',
            'settings_media',
            'administrator'
        );
        $this->add_submenu(
            'settings',
            __('Storage'),
            osc_admin_base_url(true) . '?page=settings&action=storage',
            'settings_storage',
            'administrator'
        );
        $this->add_submenu(
            'settings',
            __('Latest searches'),
            osc_admin_base_url(true) . '?page=settings&action=latestsearches',
            'settings_searches',
            'administrator'
        );
        $this->add_submenu(
            'settings',
            __('Advanced'),
            osc_admin_base_url(true) . '?page=settings&action=advanced',
            'settings_advanced',
            'administrator'
        );

        $this->add_menu(__('Pages'), osc_admin_base_url(true) . '?page=pages', 'pages', 'administrator',
                        'bi bi-file-earmark-text');

        $this->add_menu(__('Users'), osc_admin_base_url(true) . '?page=users', 'users', 'moderator', 'bi bi-people');
        $this->add_submenu(
            'users',
            __('Users'),
            osc_admin_base_url(true) . '?page=users',
            'users_manage',
            'administrator'
        );
        $this->add_submenu(
            'users',
            __('User Settings'),
            osc_admin_base_url(true) . '?page=users&action=settings',
            'users_settings',
            'administrator'
        );
        $this->add_submenu(
            'users',
            __('Administrators'),
            osc_admin_base_url(true) . '?page=admins',
            'users_administrators_manage',
            'administrator'
        );
        $this->add_submenu(
            'users',
            __('Your Profile'),
            osc_admin_base_url(true) . '?page=admins&action=edit',
            'users_administrators_profile',
            'moderator'
        );
        $this->add_submenu(
            'users',
            __('Alerts'),
            osc_admin_base_url(true) . '?page=users&action=alerts',
            'users_alerts',
            'administrator'
        );
        $this->add_submenu(
            'users',
            __('Ban rules'),
            osc_admin_base_url(true) . '?page=users&action=ban',
            'users_ban',
            'administrator'
        );

        $this->add_menu(__('Tools'), osc_admin_base_url(true) . '?page=tools&action=import', 'tools', 'administrator', 'bi bi-tools');
        $this->add_submenu(
            'tools',
            __('Import data'),
            osc_admin_base_url(true) . '?page=tools&action=import',
            'tools_import',
            'administrator'
        );
        $this->add_submenu(
            'tools',
            __('Backup data'),
            osc_admin_base_url(true) . '?page=tools&action=backup',
            'tools_backup',
            'administrator'
        );
        $this->add_submenu(
            'tools',
            __('Upgrade Shopclass'),
            osc_admin_base_url(true) . '?page=tools&action=upgrade',
            'tools_upgrade',
            'administrator'
        );
        $this->add_submenu(
            'tools',
            __('Maintenance mode'),
            osc_admin_base_url(true) . '?page=tools&action=maintenance',
            'tools_maintenance',
            'administrator'
        );
        $this->add_submenu(
            'tools',
            __('Renew location stats'),
            osc_admin_base_url(true) . '?page=tools&action=locations',
            'tools_location',
            'administrator'
        );
        $this->add_submenu(
            'tools',
            __('Renew category stats'),
            osc_admin_base_url(true) . '?page=tools&action=category',
            'tools_category',
            'administrator'
        );
        $this->add_submenu(
            'tools',
            __('Cleanup'),
            osc_admin_base_url(true) . '?page=tools&action=cleanup',
            'tools_cleanup',
            'administrator'
        );
        $this->add_submenu(
            'tools',
            __('System info'),
            osc_admin_base_url(true) . '?page=tools&action=system_info',
            'tools_system_info',
            'administrator'
        );
        osc_run_hook('admin_menu_init');
    }

    /**
     * Add menu entry
     *
     * @param $menu_title
     * @param $url
     * @param $menu_id
     * @param $icon_url   (unused)
     * @param $capability (unused)
     * @param $position   (unused)
     */
    public function add_menu($menu_title, $url, $menu_id, $capability = null, $icon_url = null, $position = null)
    {
        $array                 = array(
            $menu_title,
            $url,
            $menu_id,
            $capability,
            $icon_url,
            $position
        );
        $this->aMenu[$menu_id] = $array;
    }

    /**
     * Add submenu under menu id $menu_id
     *
     * @param      $menu_id
     * @param      $submenu_title
     * @param      $url
     * @param      $submenu_id
     * @param      $capability
     * @param null $icon_url
     */
    public function add_submenu($menu_id, $submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
    {
        $array                                     = array(
            $submenu_title,
            $url,
            $submenu_id,
            $menu_id,
            $capability,
            $icon_url
        );
        $this->aMenu[$menu_id]['sub'][$submenu_id] = $array;
    }

    /**
     * Render Admin Menu
     */
    public function renderAdminMenu()
    {
        // actual url
        $actual_url  = urldecode(Params::getServerParam('QUERY_STRING', false, false));
        $actual_page = Params::getParam('page');

        $adminMenu = self::newInstance();
        $aMenu     = $adminMenu->get_array_menu();

        $is_moderator = osc_is_moderator();
// find current menu section
        $current_menu    = '';
        $current_submenu = '';
        $priority        = 0;
        $urlLength       = 0;
        foreach ($aMenu as $key => $value) {
            // --- submenu section
            if (array_key_exists('sub', $value)) {
                $aSubmenu = $value['sub'];
                foreach ($aSubmenu as $aSub) {
                    $credential_sub = $aSub[4] ?? $aSub[3];

                    if (!$is_moderator || ($credential_sub === 'moderator')) { // show
                        $url_submenu = $aSub[1];
                        $url_submenu = str_replace(array(
                                                       osc_admin_base_url(true) . '?',
                                                       osc_admin_base_url()
                                                   ), '', $url_submenu);

                        if ($priority <= 2 && $url_submenu && strpos($actual_url, $url_submenu) === 0) {
                            if ($urlLength < strlen($url_submenu)) {
                                $urlLength       = strlen($url_submenu);
                                $current_submenu = $aSub['2'];
                                $current_menu    = $value[2];
                                $priority        = 2;
                            }
                        } elseif ($actual_page === $value[2] && $priority < 1) {
                            $current_menu    = $value[2];
                            $current_submenu = $aSub['2'];
                            $priority        = 1;
                        }
                    }
                }
            }

            // --- menu section
            $url_menu = $value[1];
            $url_menu = str_replace(array(
                                        osc_admin_base_url(true) . '?',
                                        osc_admin_base_url()
                                    ), '', $url_menu);

            if ($priority <= 2 && $url_menu && @strpos($actual_url, $url_menu) === 0) {
                if ($urlLength < strlen($url_menu)) {
                    $urlLength    = strlen($url_menu);
                    $current_menu = $value[2];
                    $priority     = 2;
                }
            } elseif ($actual_page === $value[2] && $priority < 1) {
                $current_menu = $value[2];
                $priority     = 1;
            } elseif ($url_menu === $actual_page) {
                $current_menu = $value[2];
                $priority     = 0;
            }
        }

        // The dashboard is the default route and its menu entry points at the bare admin
        // base, so no query fragment matches it by URL. An empty query string is the
        // reliable signal for that route — fall back to the dashboard when nothing else
        // matched, so the highlight never silently disappears on the home page.
        if ($current_menu === '' && ($actual_url === '' || $actual_page === 'dashboard')) {
            $current_menu = 'dash';
        }

        $currentMenuId = $current_menu;

        $sMenu = '<!-- menu -->' . PHP_EOL;

        $sMenu .= '<div class="px-1 pt-2">' .
                  PHP_EOL;
        $sMenu .= '<ul id="dashboard-menu" class="oscmenu col-md-12 nav nav-pills flex-column">' .
                  PHP_EOL;


        foreach ($aMenu as $key => $value) {
            $menuId = $key;
            $active = false;
            if ($menuId === $currentMenuId) {
                $active = true;
            }
            $sMenu .= $this->renderMenu($menuId, $value, $current_menu, $current_submenu);
        }

        $sMenu .= '</ul></div>' . PHP_EOL;
        echo $sMenu;
    }

    /**
     * @return \AdminMenu
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Return menu as array
     *
     * @return array
     */
    public function get_array_menu()
    {
        return $this->aMenu;
    }

    /**
     * Render Menu in Admin Sidebar
     *
     * @param $menuId
     * @param $value
     * @param $activeMenu
     * @param $activeSubmenu
     *
     * @return string
     */
    private function renderMenu($menuId, $value, $activeMenu, $activeSubmenu)
    {
        $is_moderator = osc_is_moderator();
        $str          = '';
        //If user is moderator and menu access is not available don't print menu
        if (!$is_moderator || ($value[3] === 'moderator')) {
            if (!$value[4] || strpos($value[4], "http") === 0) {
                $menuTag = '<i class="bi bi-app"></i> ';
            } else {
                $menuTag = '<i class="' . $value[4] . '"></i> ';
            }

            $str .= '<li class="nav-item mb-1">';
            $str .= '<div class="nav-link ' . ($activeMenu === $menuId ? 'active' : '') . '">';
            $str .= '<a class="h6" href="' . $value[1] . '" >';
            $str .= $menuTag . ' ' . $value[0] . '</a>';

            if (isset($value['sub']) && !empty($value['sub'])) {
                $isOpen = ($activeMenu === $menuId);
                $str    .= ' <button type="button" class="nav-chevron' . ($isOpen ? '' : ' collapsed')
                           . '" data-bs-target="#' . $menuId . '-submenu" data-bs-toggle="collapse"'
                           . ' aria-expanded="' . ($isOpen ? 'true' : 'false') . '"'
                           . ' aria-controls="' . $menuId . '-submenu"'
                           . ' aria-label="'
                           . osc_esc_html(sprintf(__('Toggle the %s submenu'), strip_tags($value[0]))) . '">'
                           . '<i class="bi bi-chevron-down" aria-hidden="true"></i></button>';
                $str .= '</div>';
                $str .= $this->renderSubMenu($menuId, $value['sub'], $is_moderator, $activeMenu, $activeSubmenu);
            } else {
                $str .= '</div>';
            }

            $str .= '</li>' . PHP_EOL;
        }

        return $str;
    }

    /**
     * Private function for rendering submenus
     *
     * @param $parentMenuId
     * @param $subMenu
     * @param $is_moderator
     * @param $activeMenu
     * @param $activeSubmenu
     *
     * @return string
     */
    private function renderSubMenu($parentMenuId, $subMenu, $is_moderator, $activeMenu, $activeSubmenu)
    {
        $str =
            '<ul class="sidebar-submenu collapse list-unstyled ' . ($activeMenu === $parentMenuId ? 'show' : '') . '" id="' . $parentMenuId
            . '-submenu" data-bs-parent="#dashboard-menu">';
        foreach ($subMenu as $key => $arrSubMenu) {
            if (!$is_moderator || ($arrSubMenu['sub'][4] === 'moderator')) {
                if (strpos($arrSubMenu[1], 'divider_') === 0) {
                    $str .= '<li class="ps-3 submenu-divide align-middle">' . $arrSubMenu[0] . '</li>'
                            . PHP_EOL;
                } else {
                    $str .= '<li class="mb-auto "><a class="nav-link py-1 ' . ($activeSubmenu === $arrSubMenu[2] ? 'sub-active' : '')
                            . '" id="'
                            . $arrSubMenu[2] . '" href="' . $arrSubMenu[1] . '">' .
                            $arrSubMenu[0]
                            . '</a></li>' . PHP_EOL;
                }
            }
        }
        $str .= '</ul>';

        return $str;

    }

    /**
     * Remove menu and submenus under menu with id $id_menu
     *
     * @param $menu_id
     */
    public function remove_menu($menu_id)
    {
        unset($this->aMenu[$menu_id]);
    }

    /**
     * Remove submenu with id $id_submenu under menu id $id_menu
     *
     * @param $menu_id
     * @param $submenu_id
     */
    public function remove_submenu($menu_id, $submenu_id)
    {
        unset($this->aMenu[$menu_id]['sub'][$submenu_id]);
    }

    // common functions

    /**
     * Add submenu under menu id $menu_id
     *
     * @param      $menu_id
     * @param      $submenu_title
     * @param      $submenu_id
     * @param      $capability
     *
     * @since 3.1
     */
    public function add_submenu_divider($menu_id, $submenu_title, $submenu_id, $capability = null)
    {
        $array                                                  = array(
            $submenu_title,
            'divider_' . $submenu_id,
            $menu_id,
            $capability
        );
        $this->aMenu[$menu_id]['sub']['divider_' . $submenu_id] = $array;
    }

    /**
     * Remove submenu with id $id_submenu under menu id $id_menu
     *
     * @param $menu_id
     * @param $submenu_id
     *
     * @since 3.1
     */
    public function remove_submenu_divider($menu_id, $submenu_id)
    {
        unset($this->aMenu[$menu_id]['sub']['divider_' . $submenu_id]);
    }

    /**
     * @param      $submenu_title
     * @param      $url
     * @param      $submenu_id
     * @param null $capability
     * @param null $icon_url
     */
    public function add_menu_items($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
    {
        $this->add_submenu('items', $submenu_title, $url, $submenu_id, $capability, $icon_url);
    }

    /**
     * @param      $submenu_title
     * @param      $url
     * @param      $submenu_id
     * @param null $capability
     * @param null $icon_url
     */
    public function add_menu_categories($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
    {
        $this->add_submenu('categories', $submenu_title, $url, $submenu_id, $capability, $icon_url);
    }

    /**
     * @param      $submenu_title
     * @param      $url
     * @param      $submenu_id
     * @param null $capability
     * @param null $icon_url
     */
    public function add_menu_pages($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
    {
        $this->add_submenu('pages', $submenu_title, $url, $submenu_id, $capability, $icon_url);
    }

    /**
     * @param      $submenu_title
     * @param      $url
     * @param      $submenu_id
     * @param null $capability
     * @param null $icon_url
     */
    public function add_menu_appearance($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
    {
        $this->add_submenu('appearance', $submenu_title, $url, $submenu_id, $capability, $icon_url);
    }

    /**
     * @param      $submenu_title
     * @param      $url
     * @param      $submenu_id
     * @param null $capability
     * @param null $icon_url
     */
    public function add_menu_plugins($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
    {
        $this->add_submenu('plugins', $submenu_title, $url, $submenu_id, $capability, $icon_url);
    }

    /**
     * @param      $submenu_title
     * @param      $url
     * @param      $submenu_id
     * @param null $capability
     * @param null $icon_url
     */
    public function add_menu_settings($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
    {
        $this->add_submenu('settings', $submenu_title, $url, $submenu_id, $capability, $icon_url);
    }

    /**
     * @param      $submenu_title
     * @param      $url
     * @param      $submenu_id
     * @param null $capability
     * @param null $icon_url
     */
    public function add_menu_tools($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
    {
        $this->add_submenu('tools', $submenu_title, $url, $submenu_id, $capability, $icon_url);
    }

    /**
     * @param      $submenu_title
     * @param      $url
     * @param      $submenu_id
     * @param null $capability
     * @param null $icon_url
     */
    public function add_menu_users($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
    {
        $this->add_submenu('users', $submenu_title, $url, $submenu_id, $capability, $icon_url);
    }

    /**
     * @param      $submenu_title
     * @param      $url
     * @param      $submenu_id
     * @param null $capability
     * @param null $icon_url
     */
    public function add_menu_stats($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
    {
        $this->add_submenu('stats', $submenu_title, $url, $submenu_id, $capability, $icon_url);
    }

    public function clear_menu()
    {
        $this->aMenu = array();
    }
}
