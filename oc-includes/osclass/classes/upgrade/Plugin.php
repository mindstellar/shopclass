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
 * Created by Mindstellar Community.
 * User: navjottomer
 * Date: 15/07/20
 * Time: 7:03 PM
 * License is provided in root directory.
 */

namespace mindstellar\upgrade;

use mindstellar\utility\FileSystem;
use Plugins;
use RuntimeException;

/**
 * Class Plugin
 *
 * @package mindstellar\upgrade
 */
class Plugin extends UpgradePackage
{

    /**
     * @return bool
     */
    public function afterProcessUpgrade()
    {
        return true;
    }

    /**
     * prepare plugin upgrade package info
     *                           [
     *                           's_title' => package title,
     *                           's_source_url' => package source file,
     *                           's_new_version' => package new version, "PHP-standardized" version number string
     *                           's_installed_version' => package installed version, "PHP-standardized" version number
     *                           strings
     *                           's_short_name' => package short_name,
     *                           's_target_directory => installation target directory
     *                           'a_filtered_files => array of directory/files name which shouldn't overwrite
     *                           's_compatible' => csv of compatible osclass version (optional)
     *                           's_prerelease' => true or false (Optional)
     *                           ]
     */
    public static function getPackageInfo($plugin_short_name)
    {
        if (!isset($plugin_short_name) && file_exists(THEMES_PATH . $plugin_short_name)) {
            throw new RuntimeException(__('Invalid plugin name.'));
        }

        $plugin_info                          = Plugins::getInfo($plugin_short_name);
        $package_info['s_title']             = $plugin_info['plugin_name'];
        $package_info['s_short_name']        = $plugin_short_name;
        $package_info['s_installed_version'] = $plugin_info['version'];
        $package_info['s_target_directory']  = PLUGINS_PATH . $plugin_short_name;

        $json_url = $plugin_info['plugin_update_uri'];
        if (!filter_var($json_url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException($plugin_short_name . ':' . __('Invalid plugin update uri'));
        }

        if (stripos($json_url, 'api.github.com') === true) { //It's a Github API URI
            $plugin_info_json = (new FileSystem())->getContents($json_url);
            if ($plugin_info_json) {
                $aSelfPackage = json_decode($plugin_info_json, true);
                if (!$aSelfPackage['draft']) {
                    if (isset($aSelfPackage['assets'][0]['browser_download_url'])) {
                        $download_url                  = $aSelfPackage['assets'][0]['browser_download_url'];
                        $package_info['s_source_url'] = $download_url;
                    }
                    if (isset($aSelfPackage['tag_name'])) {
                        $package_info['s_new_version'] = ltrim(trim($aSelfPackage['tag_name']), 'v');
                    }

                    $package_info['s_prerelease'] = $aSelfPackage['prerelease'];
                }
            }
        } else {
            $plugin_info_json = (new FileSystem())->getContents($json_url);
            if ($plugin_info_json) {
                $aSelfPackage = json_decode($plugin_info_json, true);
                if (isset($aSelfPackage['s_source_file'])) {
                    $package_info['s_source_url'] = $aSelfPackage['s_source_file'];
                }
                if (isset($aSelfPackage['s_version'])) {
                    $package_info['s_new_version'] = ltrim(trim($aSelfPackage['s_version']), 'v');
                }
                if (isset($aSelfPackage['s_compatible']) && trim($aSelfPackage['s_compatible'])) {
                    $package_info['s_compatible'] = $aSelfPackage['s_compatible'];
                }
            }
        }
    }
}
