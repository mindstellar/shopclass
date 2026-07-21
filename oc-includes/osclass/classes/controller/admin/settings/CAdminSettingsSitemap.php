<?php if (!defined('ABS_PATH')) {
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

/**
 * Admin screen for the core XML sitemap generator: URLs-per-file and the
 * include toggles, custom-URL CRUD (the `custom_urls` JSON preference), a
 * robots.txt editor, and a manual regenerate / clear-cache action.
 *
 * Class CAdminSettingsSitemap
 */
class CAdminSettingsSitemap extends AdminSecBaseModel
{
    /** @var string[] Boolean include-toggle preference keys, all under the `sitemap` group. */
    private static $toggleKeys = array(
        'sitemap_cities',
        'sitemap_regions',
        'sitemap_countries',
        'sitemap_cat_regions',
        'sitemap_cat_city',
    );

    /** @var string[] Allowed `changefreq` values for a custom URL (sitemaps.org). */
    private static $allowedFreq = array('always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never');

    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_sitemap');
    }

    //Business Layer...
    public function doModel()
    {
        switch ($this->action) {
            case ('sitemap'):
                $this->_exportView();
                $this->doView('settings/sitemap.php');
                break;
            case ('sitemap_settings_post'):
                osc_csrf_check();

                $number = (int) Params::getParam('sitemap_number');
                if ($number <= 0) {
                    $number = 5000;
                }
                $number = min($number, Sitemap::MAX_SITEMAP_URLS);
                osc_set_preference('sitemap_number', $number, Sitemap::PREF_GROUP, 'INTEGER');

                foreach (self::$toggleKeys as $key) {
                    osc_set_preference($key, Params::getParam($key) != '' ? 1 : 0, Sitemap::PREF_GROUP, 'BOOLEAN');
                }

                osc_sitemap_clear_cache();

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

                $index = (int) Params::getParam('sitemap_url_index');
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

                $path     = $this->_robotsPath();
                $writable = file_exists($path) ? is_writable($path) : is_writable(dirname($path));

                if (!$writable) {
                    osc_add_flash_error_message(
                        _m('robots.txt is not writable. Fix the file or folder permissions and try again'),
                        'admin'
                    );
                } else {
                    $content = str_replace("\r\n", "\n", (string) Params::getParam('sitemap_robots'));
                    if (file_put_contents($path, $content, LOCK_EX) === false) {
                        osc_add_flash_error_message(_m('robots.txt could not be saved'), 'admin');
                    } else {
                        osc_add_flash_ok_message(_m('robots.txt has been updated'), 'admin');
                    }
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
     * Gather every value the template needs and export it to the view.
     *
     * @return void
     */
    private function _exportView()
    {
        $prefs = array('sitemap_number' => (int) osc_get_preference('sitemap_number', Sitemap::PREF_GROUP));
        if ($prefs['sitemap_number'] <= 0) {
            $prefs['sitemap_number'] = 5000;
        }
        foreach (self::$toggleKeys as $key) {
            $prefs[$key] = osc_get_bool_preference($key, Sitemap::PREF_GROUP);
        }

        $path    = $this->_robotsPath();
        $exists  = file_exists($path);
        $content = $exists ? (string) file_get_contents($path) : '';
        if (trim($content) === '') {
            $content = osc_sitemap_default_robots_txt();
        }
        $writable = $exists ? is_writable($path) : is_writable(dirname($path));

        $this->_exportVariableToView('prefs', $prefs);
        $this->_exportVariableToView('custom_urls', $this->_customUrls());
        $this->_exportVariableToView('robots_content', $content);
        $this->_exportVariableToView('robots_writable', $writable);
        $this->_exportVariableToView('robots_exists', $exists);
        $this->_exportVariableToView('sitemap_index_url', osc_base_url() . 'sitemapindex.xml');
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
     * @return string
     */
    private function _robotsPath()
    {
        return osc_base_path() . 'robots.txt';
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
