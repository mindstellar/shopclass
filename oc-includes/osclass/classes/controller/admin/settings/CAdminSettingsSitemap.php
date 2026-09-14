<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\admin\form\CoreSettings;
use mindstellar\admin\form\SitemapSettingsForm;

/**
 * Admin screen for the core XML sitemap generator. The settings and robots.txt forms are
 * declared in SitemapSettingsForm; the custom-URL list (the `custom_urls` JSON preference)
 * and the regenerate / clear-cache action are handled here.
 *
 * Class CAdminSettingsSitemap
 */
class CAdminSettingsSitemap extends AdminSecBaseModel
{
    /** @var string[] Allowed `changefreq` values for a custom URL (sitemaps.org). */
    private static $allowedFreq = array('always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never');

    /**
     * Boots the admin controller and fires the init_admin_settings_sitemap hook.
     */
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_sitemap');
    }

    //Business Layer...
    /**
     * Routes the sitemap actions: the settings save, the custom URL list, the robots.txt editor and a forced regeneration.
     *
     * @return void
     */
    public function doModel()
    {
        switch ($this->action) {
            case ('sitemap'):
                $this->drawForms();
                break;
            case ('sitemap_settings_post'):
                osc_csrf_check();

                $result = CoreSettings::attempt(SitemapSettingsForm::register());
                if ($result['errors'] !== array()) {
                    $this->drawForms(SitemapSettingsForm::PAGE_ID, $result['values']);
                    break;
                }

                osc_add_flash_ok_message(_m('Sitemap settings have been updated'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=sitemap');
                break;
            case ('sitemap_custom_url_add'):
                osc_csrf_check();

                $url     = trim((string) Params::getParam('sitemap_url'));
                $freq    = trim((string) Params::getParam('sitemap_freq'));
                $lastmod = trim((string) Params::getParam('sitemap_lastmod'));

                if (!in_array($freq, self::$allowedFreq, true)) {
                    $freq = 'weekly';
                }

                if ($lastmod !== '') {
                    $date = DateTime::createFromFormat('Y-m-d', $lastmod);
                    if (!$date || $date->format('Y-m-d') !== $lastmod) {
                        $lastmod = '';
                    }
                }
                if ($lastmod === '') {
                    $lastmod = date('Y-m-d');
                }

                if ($url === '' || !$this->_isHttpUrl($url)) {
                    osc_add_flash_error_message(_m('Enter a valid URL, including the scheme (e.g. https://example.com/page)'), 'admin');
                } else {
                    $list   = $this->_customUrls();
                    $list[] = array('url' => $url, 'freq' => $freq, 'lastmod' => $lastmod);
                    $this->_saveCustomUrls($list);

                    osc_add_flash_ok_message(_m('Custom URL added to the sitemap'), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=sitemap');
                break;
            case ('sitemap_custom_url_remove'):
                osc_csrf_check();

                $index = Params::getParamInt('sitemap_url_index');
                $list  = $this->_customUrls();

                if (isset($list[$index])) {
                    unset($list[$index]);
                    $this->_saveCustomUrls(array_values($list));
                    osc_add_flash_ok_message(_m('Custom URL removed from the sitemap'), 'admin');
                } else {
                    osc_add_flash_error_message(_m('That custom URL no longer exists'), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=sitemap');
                break;
            case ('sitemap_robots_post'):
                osc_csrf_check();

                // An unwritable file is refused in validation; a write that fails anyway is
                // reported by the after_save, and either way the typed content comes back.
                $result = CoreSettings::attempt(SitemapSettingsForm::registerRobots());
                if ($result['errors'] !== array() || !SitemapSettingsForm::robotsWritten()) {
                    $this->drawForms(SitemapSettingsForm::PAGE_ROBOTS, $result['values']);
                    break;
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=sitemap');
                break;
            case ('sitemap_regenerate'):
                osc_csrf_check();

                osc_sitemap_clear_cache();
                osc_sitemap_warm_cache();

                osc_add_flash_ok_message(_m('Sitemap cache cleared and regenerated'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=sitemap');
                break;
        }
    }

    /**
     * Draw the screen: two declared forms, at most one of which is being handed back what
     * was typed into it, beside the custom-URL list.
     *
     * @param string     $rejected the page id of the form that was refused, if any
     * @param array|null $values   that form's submitted values
     *
     * @return void
     */
    private function drawForms(string $rejected = '', ?array $values = null)
    {
        $forms = SitemapSettingsForm::formVars($rejected, $values);

        // The names these have always had: a replaced admin theme's own view still reads
        // them, and View::_get() answers '' for a key nobody exported.
        $shown = $forms['settings']['values'];
        $prefs = array('sitemap_number' => (int) $shown['sitemap_number']);
        foreach (array_keys(SitemapSettingsForm::TOGGLES) as $key) {
            $prefs[$key] = !empty($shown[$key]);
        }
        $path = SitemapSettingsForm::robotsPath();

        $this->_exportVariableToView('sitemap_forms', $forms);
        $this->_exportVariableToView('prefs', $prefs);
        $this->_exportVariableToView('custom_urls', $this->_customUrls());
        $this->_exportVariableToView('robots_content', (string) $forms['robots']['values'][SitemapSettingsForm::ROBOTS]);
        $this->_exportVariableToView('robots_writable', SitemapSettingsForm::robotsWritable());
        $this->_exportVariableToView('robots_exists', file_exists($path));
        $this->_exportVariableToView('sitemap_index_url', osc_base_url() . 'sitemapindex.xml');
        $this->doView('settings/sitemap.php');
    }

    /**
     * Decoded `custom_urls` JSON preference, as a plain list.
     *
     * @return array<int, array<string, string>>
     */
    private function _customUrls()
    {
        $raw = osc_get_preference('custom_urls', Sitemap::PREF_GROUP);
        if ($raw === '' || $raw === null) {
            return array();
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : array();
    }

    /**
     * Writes the custom sitemap URL list back to preferences and clears the sitemap cache.
     *
     * @param array<int, array<string, string>> $list
     *
     * @return void
     */
    private function _saveCustomUrls(array $list)
    {
        osc_set_preference('custom_urls', json_encode($list), Sitemap::PREF_GROUP, 'STRING');
        osc_sitemap_clear_cache();
    }

    /**
     * FILTER_VALIDATE_URL alone accepts any scheme with an authority component
     * (e.g. `javascript://…`), so a custom sitemap URL is only accepted once it
     * is both filter-valid AND explicitly http/https.
     *
     * @param string $url
     *
     * @return bool
     */
    private function _isHttpUrl($url)
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && preg_match('#^https?://#i', $url) === 1;
    }
}

// EOF: ./oc-admin/controller/settings/CAdminSettingsSitemap.php
