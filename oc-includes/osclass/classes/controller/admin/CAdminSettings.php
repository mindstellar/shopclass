<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

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
 * Class CAdminSettings
 */
class CAdminSettings
{

    public function __construct()
    {
        osc_run_hook('init_admin_settings');
    }

    //Business Layer...
    public function doModel()
    {
        switch (Params::getParam('action')) {
            case ('advanced'):
            case ('advanced_post'):
            case ('advanced_cache_flush'):
                $do = new CAdminSettingsAdvanced();
                break;
            case ('comments'):
            case ('comments_post'):
                $do = new CAdminSettingsComments();
                break;
            case ('locations'):
                $do = new CAdminSettingsLocations();
                break;
            case ('permalinks'):
            case ('permalinks_post'):
                $do = new CAdminSettingsPermalinks();
                break;
            case ('spamNbots'):
            case ('akismet_post'):
            case ('recaptcha_post'):
                $do = new CAdminSettingsSpamnBots();
                break;
            case ('sitemap'):
            case ('sitemap_settings_post'):
            case ('sitemap_custom_url_add'):
            case ('sitemap_custom_url_remove'):
            case ('sitemap_robots_post'):
            case ('sitemap_regenerate'):
                $do = new CAdminSettingsSitemap();
                break;
            case ('keyword_block'):
            case ('keyword_block_add'):
            case ('keyword_block_add_post'):
            case ('keyword_block_edit'):
            case ('keyword_block_edit_post'):
            case ('keyword_block_delete'):
            case ('keyword_block_import_post'):
            case ('keyword_block_prefs_post'):
                $do = new CAdminSettingsKeywordBlock();
                break;
            case ('currencies'):
                $do = new CAdminSettingsCurrencies();
                break;
            case ('mailserver'):
            case ('mailserver_post'):
                $do = new CAdminSettingsMailserver();
                break;
            case ('media'):
            case ('media_post'):
            case ('images_post'):
                $do = new CAdminSettingsMedia();
                break;
            case ('latestsearches'):
            case ('latestsearches_post'):
                $do = new CAdminSettingsLatestSearches();
                break;
            case ('storage'):
            case ('storage_post'):
            case ('storage_test_post'):
            case ('storage_queue_run'):
            case ('storage_migrate_post'):
                $do = new CAdminSettingsStorage();
                break;
            case ('update'):
            case ('check_updates'):
            default:
                $do = new CAdminSettingsMain();
                break;
        }

        $do->doModel();
    }
}

/* file end: ./oc-admin/CAdminSettings.php */
