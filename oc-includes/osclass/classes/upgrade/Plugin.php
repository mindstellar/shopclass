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
     *                           's_requires' => minimum required Shopclass version (optional)
     *                           's_tested_up_to' => highest Shopclass version verified (optional)
     *                           's_requires_php' => minimum required PHP version (optional)
     *                           ]
     *
     * @return array
     */
    public static function getPackageInfo($plugin_short_name): array
    {
        // Callers pass either the bare slug or the "slug/index.php" form that
        // Plugins::listAll() emits; getInfo() needs the latter, the paths below the former.
        $slug = is_string($plugin_short_name) ? explode('/', trim($plugin_short_name, '/'))[0] : '';
        if ($slug === ''
            || !preg_match('/^[a-zA-Z0-9._-]+$/', $slug)
            || !is_file(PLUGINS_PATH . $slug . '/index.php')
        ) {
            throw new RuntimeException(__('Invalid plugin name.'));
        }

        $plugin_info                         = Plugins::getInfo($slug . '/index.php');
        $package_info                        = [];
        $package_info['s_title']             = $plugin_info['plugin_name'];
        $package_info['s_short_name']        = $slug;
        $package_info['s_installed_version'] = $plugin_info['version'];
        $package_info['s_target_directory']  = PLUGINS_PATH . $slug;
        $package_info['s_requires']          = $plugin_info['requires'] ?? '';
        $package_info['s_tested_up_to']      = $plugin_info['tested_up_to'] ?? '';
        $package_info['s_requires_php']      = $plugin_info['requires_php'] ?? '';

        $json_url = $plugin_info['plugin_update_uri'];
        if (!filter_var($json_url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException($plugin_short_name . ':' . __('Invalid plugin update uri'));
        }

        if (stripos($json_url, 'api.github.com') !== false) { //It's a Github API URI
            $plugin_info_json = (new FileSystem())->getContents($json_url);
            $aSelfPackage     = $plugin_info_json ? json_decode($plugin_info_json, true) : null;
            if (!is_array($aSelfPackage) || !empty($aSelfPackage['draft'])) {
                throw new RuntimeException(
                    $plugin_short_name . ':' . __('Unable to fetch a usable package info from the update URI.')
                );
            }

            if (isset($aSelfPackage['assets'][0]['browser_download_url'])) {
                $package_info['s_source_url'] = $aSelfPackage['assets'][0]['browser_download_url'];
            }
            if (isset($aSelfPackage['tag_name'])) {
                $package_info['s_new_version'] = ltrim(trim($aSelfPackage['tag_name']), 'v');
            }

            $package_info['s_prerelease'] = $aSelfPackage['prerelease'] ?? false;
        } else {
            $plugin_info_json = (new FileSystem())->getContents($json_url);
            $aSelfPackage     = $plugin_info_json ? json_decode($plugin_info_json, true) : null;
            if (!is_array($aSelfPackage)) {
                throw new RuntimeException(
                    $plugin_short_name . ':' . __('Unable to fetch a usable package info from the update URI.')
                );
            }

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

        if (empty($package_info['s_source_url']) || empty($package_info['s_new_version'])) {
            throw new RuntimeException(
                $plugin_short_name . ':' . __('Unable to fetch a usable package info from the update URI.')
            );
        }

        return $package_info;
    }
}
