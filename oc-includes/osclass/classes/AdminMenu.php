<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
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
    use \mindstellar\base\SharedInstance;

    /**
     * Menu ids that open a new band in the sidebar, drawn with a hairline above.
     *
     * The rail is read top to bottom as four groups: where am I (Dashboard), the work
     * (listings through statistics), how the site is built (appearance, plugins), and how
     * it is kept running (settings, tools). Sections added later — by plugins — append to
     * the last band, which is where a plugin's own screens belong anyway.
     */
    /** The entry that closes each section that has settings of its own. */
    private const SETTINGS_ENTRY = array(
        'items'   => 'items_settings',
        'users'   => 'users_settings',
        'media'   => 'media_settings',
        'billing' => 'billing_settings',
    );

    private const BAND_STARTS = array('items', 'appearance', 'settings');

    private $aMenu;

    /** Submenu keys per section as core left them, before anyone else registered. */
    private $aCoreSubmenus = array();

    /** @var array<string,array<string,int>> weight a plugin gave each entry it added, per section */
    private $aWeight = array();

    /** Submenu keys a plugin placed inside a group heading, per section. */
    private $aPlaced = array();

    /**
     * Start with an empty menu tree.
     */
    public function __construct()
    {
        $this->aMenu = array();
    }

    /**
     *  Initialize menu representation.
     *
     * @return void
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
            __('Messages'),
            osc_admin_base_url(true) . '?page=messages',
            'items_messages',
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
            // Orders waiting to ship show as a badge; Orders then opens on the To ship list.
            $toShip = $this->ordersToShip();
            $this->add_menu(
                __('Billing'),
                osc_admin_base_url(true) . '?page=billing',
                'billing',
                'administrator',
                'bi bi-receipt'
            );
            $this->aMenu['billing']['count'] = $toShip;
            $this->add_submenu(
                'billing',
                __('Orders'),
                osc_admin_base_url(true) . '?page=billing' . ($toShip > 0 ? '&toShip=1' : ''),
                'billing_orders',
                'administrator',
                null,
                null,
                array(osc_admin_base_url(true) . '?page=billing')
            );
            $this->aMenu['billing']['sub']['billing_orders']['count'] = $toShip;
            if (osc_billing_credits_enabled()) {
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
            }
            // Plugin pages that belong to selling (Shipping, Tax, ...) land before this entry.
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
        $this->add_submenu(
            'appearance',
            __('Menus'),
            osc_admin_base_url(true) . '?page=appearance&action=menus',
            'appearance_menus',
            'administrator'
        );
        // Shown only for a theme that declares a customiser, which it does on init.
        osc_add_hook('init_admin', static function () {
            if (osc_customiser_spec() !== null) {
                AdminMenu::getInstance()->add_submenu(
                    'appearance',
                    __('Customise'),
                    osc_admin_base_url(true) . '?page=appearance&action=customise',
                    'appearance_customise',
                    'administrator'
                );
            }
        });

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

        // Settings holds what no section owns, under one heading per area. A section's own
        // settings (Listings, Users, Media, Billing) stay in that section. The headings are
        // plain subheads; the sidebar lists them and each screen draws its group's tabs.
        $b = osc_admin_base_url(true);
        $this->add_menu(__('Settings'), $b . '?page=settings', 'settings', 'administrator', 'bi bi-gear');
        $rows = array(
            'general'  => array(__('General'), array(
                array(__('General'), '?page=settings', 'settings_general'),
                array(__('Permalinks'), '?page=settings&action=permalinks', 'settings_permalinks'),
                array(__('Languages'), '?page=languages', 'settings_language'),
                array(__('Sitemap'), '?page=settings&action=sitemap', 'settings_sitemap'),
            )),
            'content'  => array(__('Content'), array(
                array(__('Comments'), '?page=settings&action=comments', 'settings_comments'),
                array(__('Latest searches'), '?page=settings&action=latestsearches', 'settings_searches'),
            )),
            'email'    => array(__('Email'), array(
                array(__('Email templates'), '?page=emails', 'settings_emails_manage'),
                array(__('Mail server'), '?page=settings&action=mailserver', 'settings_mailserver'),
            )),
            'security' => array(__('Security'), array(
                array(__('Spam and bots'), '?page=settings&action=spamNbots', 'settings_spambots'),
                array(__('Keyword blocklist'), '?page=settings&action=keyword_block', 'settings_keyword_block'),
                array(__('Ban rules'), '?page=users&action=ban', 'users_ban'),
            )),
            'system'   => array(__('System'), array(
                array(__('Storage'), '?page=settings&action=storage', 'settings_storage'),
                // Only while billing is off: it is the switch that turns it on. Once on, the
                // Billing section carries its own Settings entry.
                array(__('Billing'), '?page=settings&action=billing', 'settings_billing'),
                array(__('API'), '?page=settings&action=api', 'settings_api'),
                array(__('Advanced'), '?page=settings&action=advanced', 'settings_advanced'),
            )),
        );
        foreach ($rows as $group => list($title, $entries)) {
            $this->add_submenu_divider('settings', $title, 'settings_group_' . $group, 'administrator');
            foreach ($entries as list($label, $path, $id)) {
                if ($id === 'settings_billing' && osc_billing_enabled()) {
                    continue;
                }
                $this->add_submenu('settings', $label, $b . $path, $id, 'administrator');
            }
        }

        $this->add_menu(__('Tools'), osc_admin_base_url(true) . '?page=tools&action=system-info', 'tools', 'administrator', 'bi bi-tools');
        $this->add_submenu(
            'tools',
            __('System info'),
            osc_admin_base_url(true) . '?page=tools&action=system-info',
            'tools_system_info',
            'administrator'
        );
        $this->add_submenu(
            'tools',
            __('Backup and restore'),
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
        // Snapshot what core registered, so the renderer can tell a plugin's or a theme's
        // entries from ours. Both arrive after this line — plugins on the hook below, the
        // admin theme's functions.php once oc-load has finished here.
        foreach ($this->aMenu as $menuId => $value) {
            $this->aCoreSubmenus[$menuId] = isset($value['sub']) ? array_keys($value['sub']) : array();
        }

        osc_run_hook('admin_menu_init');
    }

    /**
     * The count badge beside a menu title: nothing at zero. The admin theme's own
     * osc_admin_count() is used when it is loaded, else the core one.
     */
    private static function countBadge(int $count): string
    {
        if ($count <= 0) {
            return '';
        }
        if (!function_exists('osc_admin_count')) {
            require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
        }

        return osc_admin_count($count);
    }

    /**
     * Orders waiting to ship, for the badge. Zero for a moderator, who cannot open Billing.
     *
     * @return int
     */
    private function ordersToShip(): int
    {
        return osc_is_moderator() || !osc_is_shop_mode() ? 0 : \mindstellar\billing\OrderStore::toShipCount();
    }

    /**
     * Add menu entry
     *
     * @param string      $menu_title
     * @param string      $url
     * @param string      $menu_id
     * @param string|null $capability 'moderator' to keep the section visible to moderators
     * @param string|null $icon_url   Bootstrap icon class, or an http(s) url
     * @param int|null    $position   (unused)
     *
     * @return void
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
     * @param string      $menu_id
     * @param string      $submenu_title
     * @param string      $url
     * @param string      $submenu_id
     * @param string|null $capability 'moderator' to keep it visible to moderators
     * @param string|null $icon_url
     * @param string|null $group     a group heading in this section: its short name such as
     *                               'content', or its full id 'settings_group_content'. The entry goes at the end of that
     *                               group. Unknown or null: it is appended to the section.
     * @param string[]    $match     further admin urls that mark this entry as the current one,
     *                               such as the other tabs of a screen
     * @param int         $weight    in Listings, Users, Media and Billing: where a plugin's list screen goes
     *                               among the plugin entries, lowest first
     *
     * @return void
     */
    public function add_submenu(
        $menu_id,
        $submenu_title,
        $url,
        $submenu_id,
        $capability = null,
        $icon_url = null,
        $group = null,
        $match = array(),
        $weight = 0
    ) {
        $array = array(
            $submenu_title,
            $url,
            $submenu_id,
            $menu_id,
            $capability,
            $icon_url
        );
        if ($match) {
            $array[6] = array_values((array)$match);
        }

        // Billing is only a menu while billing is on; a page placed there otherwise goes to Settings.
        if ($menu_id === 'billing' && !isset($this->aMenu['billing'])) {
            $menu_id  = 'settings';
            $group    = null;
            $array[3] = $menu_id;
        }

        $sub = $this->aMenu[$menu_id]['sub'] ?? array();
        unset($sub[$submenu_id]);
        $at = null;
        if ($group !== null) {
            // The short name ('content') and the full heading id ('settings_group_content') mean the same group.
            $at = self::groupEnd($sub, 'divider_' . $group)
                ?? self::groupEnd($sub, 'divider_' . $menu_id . '_group_' . $group);
        }
        if ($at === null) {
            $sub[$submenu_id] = $array;
        } else {
            $sub = array_slice($sub, 0, $at, true) + array($submenu_id => $array) + array_slice($sub, $at, null, true);
            $this->aPlaced[$menu_id][] = $submenu_id;
        }
        $this->aMenu[$menu_id]['sub'] = $sub;
        $this->aWeight[$menu_id][$submenu_id] = (int)$weight;
        $this->arrange($menu_id);
    }

    /**
     * Order a section that closes with its own Settings entry: what core registered keeps its
     * place, then plugin list screens by weight (lowest first, then the order they were
     * added), then plugin settings pages, then the Settings entry. Load order alone decides
     * nothing a plugin can set.
     */
    private function arrange(string $menuId): void
    {
        $last = self::SETTINGS_ENTRY[$menuId] ?? null;
        $core = $this->aCoreSubmenus[$menuId] ?? null;
        if ($last === null || $core === null) {
            return;
        }
        $sub    = $this->aMenu[$menuId]['sub'];
        $kept   = array();
        $added  = array();
        $seq    = 0;
        foreach ($sub as $key => $entry) {
            if ($key === $last) {
                continue;
            }
            if (in_array($key, $core, true)) {
                $kept[$key] = $entry;
                continue;
            }
            $added[$key] = array(strpos($key, 'settings-page-') === 0 ? 1 : 0, $this->aWeight[$menuId][$key] ?? 0, $seq++);
        }
        uasort($added, static fn ($a, $b) => $a <=> $b);
        $out = $kept;
        foreach (array_keys($added) as $key) {
            $out[$key] = $sub[$key];
        }
        if (isset($sub[$last])) {
            $out[$last] = $sub[$last];
        }
        $this->aMenu[$menuId]['sub'] = $out;
    }

    /**
     * Position just after the last entry of the group headed by $dividerKey, or null when
     * there is no such heading.
     *
     * @param array<string,array<mixed>> $sub
     * @param string                     $dividerKey
     *
     * @return int|null
     */
    private static function groupEnd(array $sub, string $dividerKey): ?int
    {
        $at = null;
        $i  = 0;
        foreach ($sub as $key => $entry) {
            if ($at !== null && strpos($entry[1], 'divider_') === 0) {
                return $at;
            }
            $i++;
            if ($at !== null || $key === $dividerKey) {
                $at = $i;
            }
        }

        return $at;
    }

    /**
     * Render Admin Menu
     *
     * @return void
     */
    public function renderAdminMenu()
    {
        $aMenu = $this->get_array_menu();
        [$current_menu, $current_submenu] = $this->currentIds();

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
     * The section and entry ids that the request being served lights up, as [menu, submenu].
     *
     * @return array{0:string,1:string}
     */
    public function currentIds(): array
    {
        $actual_url  = urldecode(Params::getServerParam('QUERY_STRING', false, false));
        $actual_page = Params::getParam('page');
        // A refused save redraws its form from a POST, which has no query string.
        if ($actual_url === '' && is_string($actual_page) && $actual_page !== '') {
            $actual_url = 'page=' . $actual_page;
        }

        // A declared page with no menu entry sits in no Settings group; the prefix match would light General.
        if (Params::getParam('page') === 'settings' && Params::getParam('action') === 'custom') {
            $declared = osc_settings_page(Params::getParamString('id'));
            if ($declared !== null && $declared['menu'] === '') {
                return array('settings', '');
            }
        }

        return self::current(
            $this->get_array_menu(),
            $actual_url,
            $actual_page,
            osc_is_moderator(),
            array(osc_admin_base_url(true) . '?', osc_admin_base_url())
        );
    }

    /**
     * The Settings entries gathered by the heading they sit under, in menu order. Entries
     * a plugin added with no group of its own are gathered under "Other". A group with
     * nothing visible in it is left out.
     *
     * @param bool $isModerator hide entries a moderator may not see
     *
     * @return array<int,array{id:string,title:string,entries:array<int,array{title:string,url:string,id:string}>}>
     */
    public function settingsGroups(bool $isModerator = false): array
    {
        $core   = array_merge($this->aCoreSubmenus['settings'] ?? array(), $this->aPlaced['settings'] ?? array());
        $groups = array();
        $other  = array();
        $cur    = null;
        $curOwn = false;
        foreach ($this->aMenu['settings']['sub'] ?? array() as $key => $entry) {
            if (strpos($entry[1], 'divider_') === 0) {
                $cur            = substr($entry[1], 8);
                $curOwn         = !in_array($key, $core, true);
                $groups[$cur]   = array('id' => $cur, 'title' => $entry[0], 'entries' => array());
                continue;
            }
            if ($isModerator && ($entry[4] ?? $entry[3]) !== 'moderator') {
                continue;
            }
            $row = array('title' => $entry[0], 'url' => $entry[1], 'id' => $entry[2]);
            if ($cur !== null && (in_array($key, $core, true) || $curOwn)) {
                $groups[$cur]['entries'][] = $row;
            } else {
                $other[] = $row;
            }
        }
        $groups = array_values(array_filter($groups, static fn ($group) => $group['entries'] !== array()));
        if ($other !== array()) {
            $groups[] = array('id' => 'settings_group_other', 'title' => __('Other'), 'entries' => $other);
        }

        return $groups;
    }

    /**
     * Every screen the settings search can send an admin to, by entry id: the Settings
     * entries with their group, each section's own Settings entry, and the pages plugins add
     * to Billing or to a section as a declared settings page.
     *
     * @return array<string,array{path:string[],url:string}> path is the menu titles to click
     */
    public function settingsLocations(bool $isModerator = false): array
    {
        $out = array();
        foreach ($this->settingsGroups($isModerator) as $group) {
            foreach ($group['entries'] as $entry) {
                $out[$entry['id']] = array('path' => array($this->aMenu['settings'][0] ?? 'Settings', $group['title'], $entry['title']), 'url' => $entry['url']);
            }
        }
        $own = array('items_settings', 'users_settings', 'media_settings', 'billing_settings');
        foreach ($this->aMenu as $menuId => $menu) {
            if ($menuId === 'settings' || !self::isSection($menu)) {
                continue;
            }
            $core = $this->aCoreSubmenus[$menuId] ?? array();
            foreach ($menu['sub'] ?? array() as $key => $entry) {
                $isOwn   = in_array($key, $own, true);
                $isAdded = $menuId === 'billing' && !in_array($key, $core, true);
                if (!$isOwn && !$isAdded && strpos($key, 'settings-page-') !== 0) {
                    continue;
                }
                if ($isModerator && ($entry[4] ?? $entry[3]) !== 'moderator') {
                    continue;
                }
                $out[$key] = array('path' => array($menu[0], $entry[0]), 'url' => $entry[1]);
            }
        }

        return $out;
    }

    /**
     * The tab links for the Settings group that holds the entry $currentId: label, url,
     * active. Empty when the entry is in no group, or its group has one screen only.
     *
     * @return array<int,array{label:string,url:string,active:bool}>
     */
    public function settingsGroupTabs(string $currentId, bool $isModerator = false): array
    {
        foreach ($this->settingsGroups($isModerator) as $group) {
            foreach ($group['entries'] as $entry) {
                if ($entry['id'] !== $currentId) {
                    continue;
                }
                if (count($group['entries']) < 2) {
                    return array();
                }

                return array_map(static fn ($e) => array(
                    'label'  => $e['title'],
                    'url'    => $e['url'],
                    'active' => $e['id'] === $currentId,
                ), $group['entries']);
            }
        }

        return array();
    }

    /**
     * The sidebar list for Settings: one entry per group, linking to the group's first screen.
     *
     * @return string
     */
    private function renderGroupList($parentMenuId, $activeMenu, $activeSubmenu)
    {
        $str = '<ul class="sidebar-submenu collapse list-unstyled ' . ($activeMenu === $parentMenuId ? 'show' : '') . '" id="' . $parentMenuId
               . '-submenu" data-osc-parent="#dashboard-menu">';
        foreach ($this->settingsGroups(osc_is_moderator()) as $group) {
            $isCurrent = in_array($activeSubmenu, array_column($group['entries'], 'id'), true);
            $str       .= '<li><a class="nav-link py-1 ' . ($isCurrent ? 'sub-active' : '')
                          . '" id="' . $group['id'] . '" href="' . $group['entries'][0]['url'] . '"'
                          . ($isCurrent ? ' aria-current="page"' : '') . '>'
                          . $group['title'] . '</a></li>' . PHP_EOL;
        }

        return $str . '</ul>';
    }

    /**
     * The section and entry ids to highlight for a query string, as [menu, submenu].
     * The longest matching link wins; on a tie, the entry in the page's own section.
     *
     * @param array<string,mixed> $aMenu
     * @param string[]            $bases admin URL prefixes stripped from each link
     *
     * @return array{0:string,1:string}
     */
    public static function current(array $aMenu, string $actual_url, $actual_page, bool $is_moderator, array $bases): array
    {
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
                        $url_submenu = str_replace($bases, '', $aSub[1]);
                        // A screen with tabs claims the urls of its other tabs; the longest one that matches counts.
                        if (strpos($actual_url, $url_submenu) !== 0) {
                            foreach ($aSub[6] ?? array() as $extra) {
                                $extra = str_replace($bases, '', $extra);
                                if ($extra !== '' && strpos($actual_url, $extra) === 0) {
                                    $url_submenu = $extra;
                                    break;
                                }
                            }
                        }

                        if ($url_submenu && strpos($actual_url, $url_submenu) === 0) {
                            // Two entries can share a link, such as a plugin's shortcut to a core screen; the one in the page's own section wins.
                            if ($urlLength < strlen($url_submenu)
                                || ($urlLength === strlen($url_submenu) && $value[2] === $actual_page && $current_menu !== $actual_page)
                            ) {
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
            $url_menu = str_replace($bases, '', $url_menu);

            if ($url_menu && @strpos($actual_url, $url_menu) === 0) {
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

        return array($current_menu, $current_submenu);
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
     * @param string                                   $menuId
     * @param array<int,array{0:bool,1:array,2:string}> $visible list of array($isDivider, $entry, $key)
     *
     * @return string|null
     */
    private function firstAddedKey($menuId, array $visible)
    {
        $core = $this->aCoreSubmenus[$menuId] ?? null;
        if ($core === null) {
            return null;
        }
        $core = array_merge($core, $this->aPlaced[$menuId] ?? array());

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
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance()
    {
        return self::getInstance();
    }

    /**
     * Return menu as array
     *
     * @return array<string,mixed> Sections keyed by menu id, each with an optional 'sub' list
     */
    public function get_array_menu()
    {
        return $this->aMenu;
    }

    /**
     * Render Menu in Admin Sidebar
     *
     * @param string $menuId
     * @param mixed  $value         The section entry; anything that is not a section renders ''
     * @param string $activeMenu
     * @param string $activeSubmenu
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
            $str .= $menuTag . ' ' . $value[0] . self::countBadge($value['count'] ?? 0) . '</a>';

            if (isset($value['sub']) && !empty($value['sub'])) {
                $isOpen = ($activeMenu === $menuId);
                $str    .= ' <button type="button" class="nav-chevron' . ($isOpen ? '' : ' collapsed')
                           . '" data-osc-collapse="#' . $menuId . '-submenu"'
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
     * @param string                     $parentMenuId
     * @param array<string,array<mixed>> $subMenu
     * @param bool                       $is_moderator
     * @param string                     $activeMenu
     * @param string                     $activeSubmenu
     *
     * @return string
     */
    private function renderSubMenu($parentMenuId, $subMenu, $is_moderator, $activeMenu, $activeSubmenu)
    {
        if ($parentMenuId === 'settings') {
            return $this->renderGroupList($parentMenuId, $activeMenu, $activeSubmenu);
        }
        $str =
            '<ul class="sidebar-submenu collapse list-unstyled ' . ($activeMenu === $parentMenuId ? 'show' : '') . '" id="' . $parentMenuId
            . '-submenu" data-osc-parent="#dashboard-menu">';
        // Which items this admin may see. Index 4 is the capability on a submenu entry.
        //
        // A divider is skipped here on purpose. It is a label with no destination, so it can
        // expose nothing, and judging it by its own capability gives the two ways a heading
        // can be wrong: `add_submenu_divider()` defaults the capability to null, hiding a
        // plugin's heading from a moderator while its items still show, and a heading whose
        // whole group is filtered out stays behind titling nothing. Its visibility is decided
        // below, by what actually follows it.
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
                              . $arrSubMenu[0] . self::countBadge($arrSubMenu['count'] ?? 0)
                              . '</a></li>' . PHP_EOL;
            }
        }
        $str .= '</ul>';

        return $str;

    }

    /**
     * Remove menu and submenus under menu with id $id_menu
     *
     * @param string $menu_id
     *
     * @return void
     */
    public function remove_menu($menu_id)
    {
        unset($this->aMenu[$menu_id]);
    }

    /**
     * Remove submenu with id $id_submenu under menu id $id_menu
     *
     * @param string $menu_id
     * @param string $submenu_id
     *
     * @return void
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
     * @param string      $menu_id
     * @param string      $submenu_title
     * @param string      $submenu_id
     * @param string|null $capability Kept for signature compatibility; not used for visibility.
     *
     * @return void
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
     * Remove the group heading $submenu_id under menu id $menu_id
     *
     * @param string $menu_id
     * @param string $submenu_id
     *
     * @return void
     * @since 3.1
     */
    public function remove_submenu_divider($menu_id, $submenu_id)
    {
        unset($this->aMenu[$menu_id]['sub']['divider_' . $submenu_id]);
    }

    /**
     * Add a submenu entry under the Listings section.
     *
     * @param string      $submenu_title
     * @param string      $url
     * @param string      $submenu_id
     * @param string|null $capability 'moderator' to keep it visible to moderators
     * @param string|null $icon_url
     *
     * @return void
     */
    public function add_menu_items($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
    {
        $this->add_submenu('items', $submenu_title, $url, $submenu_id, $capability, $icon_url);
    }

    /**
     * Add a submenu entry under the Listings section.
     *
     * @param string      $submenu_title
     * @param string      $url
     * @param string      $submenu_id
     * @param string|null $capability 'moderator' to keep it visible to moderators
     * @param string|null $icon_url
     *
     * @return void
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
     * Add a submenu entry under the Pages section.
     *
     * @param string      $submenu_title
     * @param string      $url
     * @param string      $submenu_id
     * @param string|null $capability 'moderator' to keep it visible to moderators
     * @param string|null $icon_url
     *
     * @return void
     */
    public function add_menu_pages($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
    {
        $this->add_submenu('pages', $submenu_title, $url, $submenu_id, $capability, $icon_url);
    }

    /**
     * Add a submenu entry under the Appearance section.
     *
     * @param string      $submenu_title
     * @param string      $url
     * @param string      $submenu_id
     * @param string|null $capability 'moderator' to keep it visible to moderators
     * @param string|null $icon_url
     *
     * @return void
     */
    public function add_menu_appearance($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
    {
        $this->add_submenu('appearance', $submenu_title, $url, $submenu_id, $capability, $icon_url);
    }

    /**
     * Add a submenu entry under the Plugins section.
     *
     * @param string      $submenu_title
     * @param string      $url
     * @param string      $submenu_id
     * @param string|null $capability 'moderator' to keep it visible to moderators
     * @param string|null $icon_url
     *
     * @return void
     */
    public function add_menu_plugins($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
    {
        $this->add_submenu('plugins', $submenu_title, $url, $submenu_id, $capability, $icon_url);
    }

    /**
     * Add a submenu entry under the Settings section.
     *
     * @param string      $submenu_title
     * @param string      $url
     * @param string      $submenu_id
     * @param string|null $capability 'moderator' to keep it visible to moderators
     * @param string|null $icon_url
     *
     * @return void
     */
    public function add_menu_settings($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
    {
        $this->add_submenu('settings', $submenu_title, $url, $submenu_id, $capability, $icon_url);
    }

    /**
     * Add a submenu entry under the Tools section.
     *
     * @param string      $submenu_title
     * @param string      $url
     * @param string      $submenu_id
     * @param string|null $capability 'moderator' to keep it visible to moderators
     * @param string|null $icon_url
     *
     * @return void
     */
    public function add_menu_tools($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
    {
        $this->add_submenu('tools', $submenu_title, $url, $submenu_id, $capability, $icon_url);
    }

    /**
     * Add a submenu entry under the Users section.
     *
     * @param string      $submenu_title
     * @param string      $url
     * @param string      $submenu_id
     * @param string|null $capability 'moderator' to keep it visible to moderators
     * @param string|null $icon_url
     *
     * @return void
     */
    public function add_menu_users($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
    {
        $this->add_submenu('users', $submenu_title, $url, $submenu_id, $capability, $icon_url);
    }

    /**
     * Add a submenu entry under the Statistics section.
     *
     * @param string      $submenu_title
     * @param string      $url
     * @param string      $submenu_id
     * @param string|null $capability 'moderator' to keep it visible to moderators
     * @param string|null $icon_url
     *
     * @return void
     */
    public function add_menu_stats($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)
    {
        $this->add_submenu('stats', $submenu_title, $url, $submenu_id, $capability, $icon_url);
    }

    /**
     * Drop every registered menu and submenu.
     *
     * @return void
     */
    public function clear_menu()
    {
        $this->aMenu = array();
    }
}
