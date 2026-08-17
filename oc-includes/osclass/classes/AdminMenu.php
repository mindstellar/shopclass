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
    /**
     * Menu ids that open a new band in the sidebar, drawn with a hairline above.
     *
     * The rail is read top to bottom as four groups: where am I (Dashboard), the work
     * (listings through statistics), how the site is built (appearance, plugins), and how
     * it is kept running (settings, tools). Sections added later — by plugins — append to
     * the last band, which is where a plugin's own screens belong anyway.
     */
    private const BAND_STARTS = array('items', 'appearance', 'settings');

    private static $instance;
    private $aMenu;

    /** Submenu keys per section as core left them, before anyone else registered. */
    private $aCoreSubmenus = array();

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
            __('Comments'),
            osc_admin_base_url(true) . '?page=comments',
            'items_comments',
            'moderator'
        );
        $this->add_submenu(
            'items',
            __('Categories'),
            osc_admin_base_url(true) . '?page=categories',
            'settings_categories',
            'administrator'
        );
        $this->add_submenu(
            'items',
            __('Locations'),
            osc_admin_base_url(true) . '?page=settings&action=locations',
            'settings_locations',
            'administrator'
        );
        $this->add_submenu(
            'items',
            __('Currencies'),
            osc_admin_base_url(true) . '?page=settings&action=currencies',
            'settings_currencies',
            'administrator'
        );
        $this->add_submenu(
            'items',
            __('Settings'),
            osc_admin_base_url(true) . '?page=items&action=settings',
            'items_settings',
            'administrator'
        );

        // Administrator-only, unlike the sections above it: every screen in here is user
        // administration and a moderator is refused all of them, so showing the section
        // to one only offered a row that bounced them back to the dashboard. Their own
        // profile is not user administration either — it lives in the account menu.
        $this->add_menu(__('Users'), osc_admin_base_url(true) . '?page=users', 'users', 'administrator', 'bi bi-people');
        $this->add_submenu(
            'users',
            __('Manage users'),
            osc_admin_base_url(true) . '?page=users',
            'users_manage',
            'administrator'
        );
        $this->add_submenu(
            'users',
            __('Ban rules'),
            osc_admin_base_url(true) . '?page=users&action=ban',
            'users_ban',
            'administrator'
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
            __('Administrators'),
            osc_admin_base_url(true) . '?page=admins',
            'users_administrators_manage',
            'administrator'
        );
        $this->add_submenu(
            'users',
            __('Settings'),
            osc_admin_base_url(true) . '?page=users&action=settings',
            'users_settings',
            'administrator'
        );

        $this->add_menu(__('Media'), osc_admin_base_url(true) . '?page=media', 'media', 'moderator', 'bi bi-images');
        $this->add_submenu(
            'media',
            __('Library'),
            osc_admin_base_url(true) . '?page=media',
            'media_library',
            'moderator'
        );
        $this->add_submenu(
            'media',
            __('Settings'),
            osc_admin_base_url(true) . '?page=settings&action=media',
            'media_settings',
            'administrator'
        );

        $this->add_menu(
            __('Pages'),
            osc_admin_base_url(true) . '?page=pages',
            'pages',
            'administrator',
            'bi bi-file-earmark-text'
        );

        // Forms: the field/form builder and the entries its placeable forms collect.
        // Its own section rather than a listings sub-item — a form is no longer only a
        // listing's custom-field section, it can also be a standalone placeable form.
        $this->add_menu(__('Forms'), osc_admin_base_url(true) . '?page=cfields', 'forms', 'administrator', 'bi bi-ui-checks-grid');
        $this->add_submenu(
            'forms',
            __('Manage forms'),
            osc_admin_base_url(true) . '?page=cfields',
            'items_cfields',
            'administrator'
        );
        $this->add_submenu(
            'forms',
            __('Submissions'),
            osc_admin_base_url(true) . '?page=cfields&action=submissions',
            'items_form_submissions',
            'administrator'
        );

        // Billing appears only once an admin has switched it on. Most sites never sell
        // anything, and a permanent menu entry for a feature they will not use is the
        // clutter that makes an admin panel feel like someone else's product. While it is
        // off the switch lives under Settings instead (see below), so this is reversible.
        if (osc_billing_enabled()) {
            $this->add_menu(
                __('Billing'),
                osc_admin_base_url(true) . '?page=billing',
                'billing',
                'administrator',
                'bi bi-receipt'
            );
            $this->add_submenu(
                'billing',
                __('Orders'),
                osc_admin_base_url(true) . '?page=billing',
                'billing_orders',
                'administrator'
            );
            $this->add_submenu(
                'billing',
                __('Packages'),
                osc_admin_base_url(true) . '?page=billing&action=packages',
                'billing_packages',
                'administrator'
            );
            $this->add_submenu(
                'billing',
                __('Credits'),
                osc_admin_base_url(true) . '?page=billing&action=credits',
                'billing_credits',
                'administrator'
            );
            $this->add_submenu(
                'billing',
                __('Settings'),
                osc_admin_base_url(true) . '?page=settings&action=billing',
                'billing_settings',
                'administrator'
            );
        }

        $this->add_menu(
            __('Statistics'),
            osc_admin_base_url(true) . '?page=stats&action=items',
            'stats',
            'moderator',
            'bi bi-bar-chart'
        );
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
        // The two entries below run a recalculation rather than opening a report, so they
        // are held apart from the views above instead of sitting in the same list.
        $this->add_submenu_divider('stats', __('Maintenance'), 'stats_maintenance', 'administrator');
        $this->add_submenu(
            'stats',
            __('Recalculate location stats'),
            osc_admin_base_url(true) . '?page=tools&action=locations',
            'tools_location',
            'administrator'
        );
        $this->add_submenu(
            'stats',
            __('Recalculate category stats'),
            osc_admin_base_url(true) . '?page=tools&action=category',
            'tools_category',
            'administrator'
        );

        $this->add_menu(
            __('Appearance'),
            osc_admin_base_url(true) . '?page=appearance',
            'appearance',
            'administrator',
            'bi bi-palette'
        );
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

        // "Manage plugins" points at the same page as the section row, which looks like
        // duplication until a plugin registers a screen of its own here — then it is the
        // way back to the list, and it has to stay first: the current-item fallback marks
        // the first child when a section matches by page alone, so without it the plugin
        // list highlights whichever plugin happens to be registered first.
        $this->add_menu(__('Plugins'), osc_admin_base_url(true) . '?page=plugins', 'plugins', 'administrator', 'bi bi-plugin');
        $this->add_submenu(
            'plugins',
            __('Manage plugins'),
            osc_admin_base_url(true) . '?page=plugins',
            'plugins_manage',
            'administrator'
        );

        // Thirteen entries is too many to scan as one list, so they are grouped by what
        // the setting governs. Every group heading is a plain subhead, not a link.
        $this->add_menu(__('Settings'), osc_admin_base_url(true) . '?page=settings', 'settings', 'administrator', 'bi bi-gear');
        $this->add_submenu_divider('settings', __('Site'), 'settings_group_site', 'administrator');
        $this->add_submenu(
            'settings',
            __('General'),
            osc_admin_base_url(true) . '?page=settings',
            'settings_general',
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
            __('Languages'),
            osc_admin_base_url(true) . '?page=languages',
            'settings_language',
            'administrator'
        );
        $this->add_submenu(
            'settings',
            __('Sitemap'),
            osc_admin_base_url(true) . '?page=settings&action=sitemap',
            'settings_sitemap',
            'administrator'
        );
        $this->add_submenu_divider('settings', __('Content'), 'settings_group_content', 'administrator');
        $this->add_submenu(
            'settings',
            __('Comments'),
            osc_admin_base_url(true) . '?page=settings&action=comments',
            'settings_comments',
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
            __('Keyword blocklist'),
            osc_admin_base_url(true) . '?page=settings&action=keyword_block',
            'settings_keyword_block',
            'administrator'
        );
        $this->add_submenu(
            'settings',
            __('Latest searches'),
            osc_admin_base_url(true) . '?page=settings&action=latestsearches',
            'settings_searches',
            'administrator'
        );
        $this->add_submenu_divider('settings', __('Email'), 'settings_group_email', 'administrator');
        $this->add_submenu(
            'settings',
            __('Email templates'),
            osc_admin_base_url(true) . '?page=emails',
            'settings_emails_manage',
            'administrator'
        );
        $this->add_submenu(
            'settings',
            __('Mail server'),
            osc_admin_base_url(true) . '?page=settings&action=mailserver',
            'settings_mailserver',
            'administrator'
        );
        $this->add_submenu_divider('settings', __('System'), 'settings_group_system', 'administrator');
        $this->add_submenu(
            'settings',
            __('Storage'),
            osc_admin_base_url(true) . '?page=settings&action=storage',
            'settings_storage',
            'administrator'
        );
        // Only while billing is off — this is the switch that turns it on, so it has to be
        // reachable. Once it is on, the Billing section carries its own Settings entry and
        // listing the same page twice would leave two menu rows fighting to look current.
        if (!osc_billing_enabled()) {
            $this->add_submenu(
                'settings',
                __('Billing'),
                osc_admin_base_url(true) . '?page=settings&action=billing',
                'settings_billing',
                'administrator'
            );
        }
        $this->add_submenu(
            'settings',
            __('Advanced'),
            osc_admin_base_url(true) . '?page=settings&action=advanced',
            'settings_advanced',
            'administrator'
        );

        $this->add_menu(__('Tools'), osc_admin_base_url(true) . '?page=tools&action=import', 'tools', 'administrator', 'bi bi-tools');
        $this->add_submenu(
            'tools',
            __('Upgrade Shopclass'),
            osc_admin_base_url(true) . '?page=tools&action=upgrade',
            'tools_upgrade',
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
            __('Import data'),
            osc_admin_base_url(true) . '?page=tools&action=import',
            'tools_import',
            'administrator'
        );
        $this->add_submenu(
            'tools',
            __('Cache'),
            osc_admin_base_url(true) . '?page=tools&action=cache',
            'tools_cache',
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
            __('Maintenance mode'),
            osc_admin_base_url(true) . '?page=tools&action=maintenance',
            'tools_maintenance',
            'administrator'
        );
        $this->add_submenu(
            'tools',
            __('Activity log'),
            osc_admin_base_url(true) . '?page=tools&action=logs',
            'tools_logs',
            'administrator'
        );
        $this->add_submenu(
            'tools',
            __('System info'),
            osc_admin_base_url(true) . '?page=tools&action=system_info',
            'tools_system_info',
            'administrator'
        );
        // Snapshot what core registered, so the renderer can tell a plugin's or a theme's
        // entries from ours. Both arrive after this line — plugins on the hook below, the
        // admin theme's functions.php once oc-load has finished here.
        foreach ($this->aMenu as $menuId => $value) {
            $this->aCoreSubmenus[$menuId] = isset($value['sub']) ? array_keys($value['sub']) : array();
        }

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
            if (!self::isSection($value)) {
                continue;
            }

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
            $sMenu .= $this->renderMenu($key, $value, $current_menu, $current_submenu);
        }

        $sMenu .= '</ul></div>' . PHP_EOL;
        echo $sMenu;
    }

    /**
     * Is this array a real section, or the husk add_submenu() leaves behind?
     *
     * Handing add_submenu() a menu id that was never registered still creates
     * `$aMenu[$id]['sub'][…]`, producing an entry with a submenu but no title, url or
     * capability. Rendering that gives a blank, clickable row, and reading its missing
     * indices warns on PHP 8 — so both render paths skip it and the plugin author sees
     * their item missing rather than the whole sidebar growing an empty section.
     *
     * @param mixed $value
     *
     * @return bool
     */
    private static function isSection($value)
    {
        return is_array($value) && isset($value[0], $value[1]) && $value[0] !== '';
    }

    /**
     * Key of the first entry in this section that core did not register, or null.
     *
     * Only meaningful where core grouped the section under headings — elsewhere there is
     * no group for an appended entry to be mistaken for, and a rule would be noise. An
     * entry that brings its own heading needs no rule either: the heading is the boundary.
     *
     * @param string $menuId
     * @param array  $visible list of array($isDivider, $entry, $key)
     *
     * @return string|null
     */
    private function firstAddedKey($menuId, array $visible)
    {
        $core = $this->aCoreSubmenus[$menuId] ?? null;
        if ($core === null) {
            return null;
        }

        $grouped = false;
        foreach ($visible as $entry) {
            if ($entry[0] && in_array($entry[2], $core, true)) {
                $grouped = true;
                break;
            }
        }
        if (!$grouped) {
            return null;
        }

        foreach ($visible as $entry) {
            if (!in_array($entry[2], $core, true)) {
                return $entry[0] ? null : $entry[2];
            }
        }

        return null;
    }

    /**
     * @return \AdminMenu
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
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
        if (!self::isSection($value)) {
            return '';
        }

        $is_moderator = osc_is_moderator();
        $str          = '';
        //If user is moderator and menu access is not available don't print menu
        if (!$is_moderator || ($value[3] === 'moderator')) {
            if (!$value[4] || strpos($value[4], "http") === 0) {
                $menuTag = '<i class="bi bi-app"></i> ';
            } else {
                $menuTag = '<i class="' . $value[4] . '"></i> ';
            }

            $isCurrent = ($activeMenu === $menuId);
            $band      = in_array($menuId, self::BAND_STARTS, true) ? ' nav-item-band' : '';

            $str .= '<li class="nav-item mb-1' . $band . '">';
            $str .= '<div class="nav-link ' . ($isCurrent ? 'active' : '') . '">';
            // aria-current on the section only when it has no submenu to carry it: with a
            // submenu the child link is the actual current page, and two aria-currents in
            // one tree tell a screen reader the user is in two places at once.
            $ariaSection = ($isCurrent && empty($value['sub'])) ? ' aria-current="page"' : '';
            $str .= '<a class="h6" href="' . $value[1] . '"' . $ariaSection . '>';
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
        // Which items this admin may see. Index 4 is the capability on a submenu; this
        // read used to be `$arrSubMenu['sub'][4]`, a key that exists on no entry — so it
        // always evaluated null and a moderator was shown no submenu items at all, in any
        // section.
        //
        // A divider is skipped here on purpose. It is a label with no destination, so it
        // can expose nothing, and judging it by its own capability produced the two ways
        // a heading can be wrong: `add_submenu_divider()` defaults the capability to null,
        // which hid a plugin's heading from a moderator while its items still showed, and
        // a heading whose whole group was filtered out stayed behind titling nothing. Its
        // visibility is decided below, by what actually follows it.
        $visible = array();
        foreach ($subMenu as $key => $arrSubMenu) {
            $isDivider = strpos($arrSubMenu[1], 'divider_') === 0;
            if (!$isDivider) {
                $capability = $arrSubMenu[4] ?? $arrSubMenu[3];
                if ($is_moderator && $capability !== 'moderator') {
                    continue;
                }
            }
            $visible[] = array($isDivider, $arrSubMenu, $key);
        }

        // Entries registered after core finished — by a plugin or the admin theme — are
        // appended, so in a section that uses headings they fall under whichever one core
        // happened to write last and read as part of it. A hairline closes core's final
        // group ahead of them. It carries no label: naming the group would mean inventing
        // a translated string for "everything else", and the boundary is the whole point.
        $break = $this->firstAddedKey($parentMenuId, $visible);

        foreach ($visible as $i => list($isDivider, $arrSubMenu, $key)) {
            if ($break !== null && $key === $break) {
                $str .= '<li class="submenu-break" aria-hidden="true"></li>' . PHP_EOL;
            }
            if ($isDivider) {
                // Keep it only if a real item follows before the next heading.
                $next = $visible[$i + 1] ?? null;
                if ($next === null || $next[0]) {
                    continue;
                }
                $str .= '<li class="submenu-divide">' . $arrSubMenu[0] . '</li>' . PHP_EOL;
            } else {
                $isCurrent = ($activeSubmenu === $arrSubMenu[2]);
                $str       .= '<li><a class="nav-link py-1 ' . ($isCurrent ? 'sub-active' : '')
                              . '" id="' . $arrSubMenu[2] . '" href="' . $arrSubMenu[1] . '"'
                              . ($isCurrent ? ' aria-current="page"' : '') . '>'
                              . $arrSubMenu[0]
                              . '</a></li>' . PHP_EOL;
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
     * Add a group heading under menu id $menu_id.
     *
     * The heading titles whatever is added after it, until the next heading. It is drawn
     * only when a visible item follows, so $capability does not govern it — a label has no
     * destination to protect, and the items decide whether their heading is worth drawing.
     *
     * @param      $menu_id
     * @param      $submenu_title
     * @param      $submenu_id
     * @param      $capability   Kept for signature compatibility; not used for visibility.
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
        // Categories is an entry under Listings and has never been a section of its own,
        // so there is no 'categories' menu to attach to. Naming one anyway left behind a
        // menu entry holding nothing but a 'sub' key — no title, no url, no capability —
        // which rendered as a blank clickable row at the foot of the sidebar. Put the
        // item where Categories actually lives instead.
        $this->add_submenu('items', $submenu_title, $url, $submenu_id, $capability, $icon_url);
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
