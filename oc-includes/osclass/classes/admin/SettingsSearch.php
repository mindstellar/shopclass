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

namespace mindstellar\admin;

use AdminMenu;
use mindstellar\admin\form\AdvancedSettingsScreen;
use mindstellar\admin\form\ApiSettingsScreen;
use mindstellar\admin\form\BillingSettingsScreen;
use mindstellar\admin\form\CommentSettingsScreen;
use mindstellar\admin\form\ItemSettingsScreen;
use mindstellar\admin\form\KeywordBlockSettingsScreen;
use mindstellar\admin\form\LatestSearchSettingsScreen;
use mindstellar\admin\form\MailServerSettingsScreen;
use mindstellar\admin\form\MainSettingsScreen;
use mindstellar\admin\form\MediaSettingsScreen;
use mindstellar\admin\form\PermalinkSettingsScreen;
use mindstellar\admin\form\SitemapSettingsScreen;
use mindstellar\admin\form\SpamSettingsScreen;
use mindstellar\admin\form\StorageSettingsScreen;
use mindstellar\admin\form\UserSettingsScreen;
use mindstellar\admin\ui\Field;
use mindstellar\admin\ui\Form;

/**
 * The list behind the top bar's "Find a setting" box: every Settings screen, the sections
 * on it and the fields in them, each with the path an admin would click and a link that
 * opens the page at that spot. Built on request by one admin-only endpoint, never on a
 * page load.
 */
final class SettingsSearch
{
    public const TYPE_PAGE    = 0;
    public const TYPE_SECTION = 1;
    public const TYPE_FIELD   = 2;

    /** The longest help text kept per field. */
    private const HELP_MAX = 140;

    /**
     * Where each core settings form lives: page id => [menu entry id, tab query, section label].
     * A section label means the form is one tab of a larger screen and is listed as a section.
     * Core forms not listed here are not settings screens (tools, profile, ban rule form).
     */
    private const CORE_PAGES = array(
        'core.settings_main'           => array('settings_general', '', null),
        'core.settings_permalinks'     => array('settings_permalinks', '', null),
        'core.settings_sitemap'        => array('settings_sitemap', '', null),
        'core.settings_sitemap_robots' => array('settings_sitemap', '', 'robots.txt'),
        'core.settings_items'          => array('items_settings', '', null),
        'core.settings_comments'       => array('settings_comments', '', null),
        'core.settings_latest_searches' => array('settings_searches', '', null),
        'core.settings_users'          => array('users_settings', '', null),
        'core.settings_media'          => array('media_settings', '', null),
        'core.settings_storage'        => array('settings_storage', '', null),
        'core.settings_mailserver'     => array('settings_mailserver', '', null),
        'core.settings_keyword_block'  => array('settings_keyword_block', '', null),
        'core.settings_akismet'        => array('settings_spambots', '&tab=akismet', 'Akismet'),
        'core.settings_captcha'        => array('settings_spambots', '&tab=captcha', 'Captcha'),
        'core.settings_alerts'         => array('settings_spambots', '&tab=alerts', 'Search alerts'),
        'core.settings_login_throttle' => array('settings_spambots', '&tab=signin', 'Sign-in protection'),
        'core.settings_messages'       => array('settings_spambots', '&tab=messages', 'Messages'),
        'core.settings_limits'         => array('settings_spambots', '&tab=limits', 'Limits'),
        'core.settings_billing'        => array('billing_settings', '&tab=switch', 'Billing'),
        'core.settings_billing_pricing' => array('billing_settings', '&tab=pricing', 'Pricing'),
        'core.settings_billing_offline' => array('billing_settings', '&tab=offline', 'Bank transfer'),
        'core.settings_billing_upgrades' => array('billing_settings', '&tab=upgrades', 'Upgrades'),
        'core.settings_billing_limits' => array('billing_settings', '&tab=limits', 'Seller limits'),
        'core.settings_billing_receipts' => array('billing_settings', '&tab=receipts', 'Receipts'),
        'core.settings_billing_shop'   => array('billing_settings', '&tab=shop', 'Shop'),
        'core.settings_api'            => array('settings_api', '', null),
        'core.settings_advanced'       => array('settings_advanced', '', null),
    );

    /** Search words for the core screens, by menu entry id. */
    private const WORDS = array(
        'settings_general'       => 'site name title description contact email timezone date format pagination',
        'settings_permalinks'    => 'friendly urls slug seo rewrite',
        'settings_language'      => 'translation locale',
        'settings_sitemap'       => 'xml search engine google robots',
        'items_settings'         => 'listing expiry moderation validate images price currency required',
        'settings_comments'      => 'reviews moderate comment',
        'settings_searches'      => 'recent popular queries',
        'users_settings'         => 'registration register profile activate account delete',
        'media_settings'         => 'photo image size thumbnail upload watermark resize',
        'settings_storage'       => 'files s3 bucket cloud disk upload',
        'settings_emails_manage' => 'template message notification subject',
        'settings_mailserver'    => 'smtp sender from host port password ssl tls authentication',
        'settings_spambots'      => 'captcha recaptcha turnstile akismet honeypot throttle sign-in login',
        'settings_keyword_block' => 'banned words filter profanity',
        'users_ban'              => 'block ip address email ban user',
        'settings_billing'       => 'payment currency price tax invoice paypal stripe credits packages checkout',
        'billing_settings'       => 'payment currency price tax invoice paypal stripe credits packages checkout',
        'settings_api'           => 'rest token key webhook endpoint',
        'settings_advanced'      => 'cache debug cron cookie session',
    );

    /**
     * Sections of hand-written core screens, which no declared form lists: entry id => titles.
     * Each links to the heading of the same name (see Form::anchor()).
     */
    private const SECTIONS = array(
        'settings_api'           => array('Keys', 'New key', 'Webhooks', 'New webhook endpoint'),
        'settings_sitemap'       => array('Sitemap settings', 'Custom sitemap URLs'),
        'settings_keyword_block' => array('Moderation', 'Import keyword list', 'Blocked keywords'),
        'settings_permalinks'    => array('Server rules (nginx)', 'Server rules (.htaccess)'),
        'settings_storage'       => array('Connection test', 'Storage queue', 'Migration'),
    );

    /**
     * The cheap key the top bar puts on the index URL: it changes when the version, the
     * language, the active plugins or the declared pages change, and the browser then
     * fetches a fresh list.
     */
    public static function version(): string
    {
        $pages = array();
        foreach (osc_settings_pages() as $id => $page) {
            $count = 0;
            foreach ($page['groups'] as $group) {
                $count += count($group['fields']);
            }
            $pages[] = $id . ':' . $count;
        }

        return substr(md5(implode('|', array(
            osc_version(),
            osc_current_admin_locale(),
            json_encode(osc_active_plugins()),
            osc_billing_enabled() ? 'b' : '',
            osc_site_mode(),
            implode(',', $pages),
        ))), 0, 12);
    }

    /**
     * The whole list, one row per entry: [type, label, path, url, text]. The text is what
     * a search may match besides the label: help, search words.
     *
     * @return array<int,array{0:int,1:string,2:string,3:string,4:string}>
     */
    public static function build(): array
    {
        self::registerCore();
        $menu    = AdminMenu::getInstance();
        $rows    = array();
        $entries = array();
        $own     = array(
            'items_settings' => __('Listings'), 'users_settings' => __('Users'),
            'media_settings' => __('Media'), 'billing_settings' => __('Billing'),
        );
        foreach ($menu->settingsLocations() as $id => $where) {
            $path    = implode(' › ', $where['path']);
            $label   = array_slice($where['path'], -1)[0];
            $parent  = implode(' › ', array_slice($where['path'], 0, -1));
            if (isset($own[$id])) {
                $label = sprintf(__('%s settings'), $own[$id]);
            }
            $entries[$id] = array('path' => $path, 'url' => $where['url']);
            $rows[]       = array(self::TYPE_PAGE, $label, $parent, $where['url'], self::WORDS[$id] ?? '');
        }

        // With billing off, its switch sits in Settings under this other id.
        if (isset($entries['settings_billing'])) {
            $entries['billing_settings'] = $entries['settings_billing'];
        }

        foreach (self::SECTIONS as $entryId => $titles) {
            if (!isset($entries[$entryId])) {
                continue;
            }
            foreach ($titles as $title) {
                $title  = __($title);
                $rows[] = array(self::TYPE_SECTION, $title, $entries[$entryId]['path'], $entries[$entryId]['url'] . '#' . Form::anchor($title), '');
            }
        }
        if (isset($entries['billing_settings'])) {
            $title  = __('Payment methods');
            $rows[] = array(self::TYPE_SECTION, $title, $entries['billing_settings']['path'], $entries['billing_settings']['url'] . '&tab=methods', '');
        }

        $menus = $menu->get_array_menu();
        foreach (osc_settings_pages() as $id => $page) {
            if (isset(self::CORE_PAGES[$id])) {
                [$entryId, $query, $tab] = self::CORE_PAGES[$id];
                if (!isset($entries[$entryId])) {
                    continue;
                }
                $path = $entries[$entryId]['path'];
                $url  = $entries[$entryId]['url'] . $query;
                if ($tab !== null && strcasecmp(__($tab), array_slice(explode(' › ', $path), -1)[0]) !== 0) {
                    $tab    = __($tab);
                    $rows[] = array(self::TYPE_SECTION, $tab, $path, $url, '');
                    $path  .= ' › ' . $tab;
                }
            } elseif (isset($entries['settings-page-' . $id])) {
                $path = $entries['settings-page-' . $id]['path'];
                $url  = $entries['settings-page-' . $id]['url'];
            } elseif (strpos($id, 'core.') === 0 || $page['menu'] === '') {
                continue;
            } else {
                $url  = osc_settings_page_url($id);
                $path = ($menus[$page['menu']][0] ?? $page['menu']) . ' › ' . $page['menu_title'];
                $rows[] = array(self::TYPE_PAGE, $page['menu_title'], (string)($menus[$page['menu']][0] ?? $page['menu']), $url, '');
            }

            foreach ($page['groups'] as $group) {
                $where = $path;
                $base  = $url;
                $last  = mb_strtolower((string)array_slice(explode(' › ', $path), -1)[0]);
                if ($group['title'] !== '' && mb_strtolower($group['title']) !== $last) {
                    $base   = $url . '#' . Form::anchor($group['title']);
                    $rows[] = array(self::TYPE_SECTION, $group['title'], $path, $base, '');
                    $where  = $path . ' › ' . $group['title'];
                }
                foreach ($group['fields'] as $field) {
                    $label = (string)($field['label'] ?? $field['row_label'] ?? '');
                    if ($label === '') {
                        continue;
                    }
                    $rows[] = array(
                        self::TYPE_FIELD,
                        $label,
                        $where,
                        $url . '#' . Field::idFor($field),
                        self::shorten((string)($field['help'] ?? '')),
                    );
                }
            }
        }

        return $rows;
    }

    /**
     * Core's own settings forms are declared when their screen is drawn; declare them now so
     * their sections and fields are listed. A form that cannot be declared is left out.
     */
    private static function registerCore(): void
    {
        $screens = array(
            MainSettingsScreen::class, ItemSettingsScreen::class, UserSettingsScreen::class,
            MediaSettingsScreen::class, PermalinkSettingsScreen::class, StorageSettingsScreen::class,
            MailServerSettingsScreen::class, CommentSettingsScreen::class, LatestSearchSettingsScreen::class,
            KeywordBlockSettingsScreen::class, AdvancedSettingsScreen::class, ApiSettingsScreen::class,
        );
        $calls = array(
            array(SitemapSettingsScreen::class, 'register'), array(SitemapSettingsScreen::class, 'registerRobots'),
        );
        foreach ($screens as $class) {
            $calls[] = array($class, 'register');
        }
        foreach (array('Akismet', 'Captcha', 'Alerts', 'LoginThrottle', 'Messages', 'Limits') as $name) {
            $calls[] = array(SpamSettingsScreen::class, 'register' . $name);
        }
        foreach (array('Switch', 'Pricing', 'Offline', 'Upgrades', 'Limits', 'Receipts', 'Shop') as $name) {
            $calls[] = array(BillingSettingsScreen::class, 'register' . $name);
        }
        foreach ($calls as $call) {
            try {
                $call();
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    private static function shorten(string $text): string
    {
        $text = trim((string)preg_replace('/\s+/', ' ', strip_tags($text)));

        return mb_strlen($text) > self::HELP_MAX ? mb_substr($text, 0, self::HELP_MAX - 1) . '…' : $text;
    }
}
