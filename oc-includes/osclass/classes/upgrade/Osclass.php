<?php
/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * Created by Mindstellar Community.
 * User: navjottomer
 * Date: 15/07/20
 * Time: 7:03 PM
 * License is provided in root directory.
 */

namespace mindstellar\upgrade;

use DBCommandClass;
use DBConnectionClass;
use mindstellar\migration\MigrationRunner;
use mindstellar\utility\FileSystem;
use mindstellar\utility\Utils;
use Plugins;
use Preference;

/**
 * Class Osclass
 *
 * @package mindstellar\upgrade
 */
class Osclass extends UpgradePackage
{

    /**
     * Osclass constructor.
     *
     * @param array $package_info
     * @param bool  $force_upgrade
     */
    public function __construct(
        array $package_info,
        bool  $force_upgrade = false
    ) {
        $enable_prerelease = false;
        if (osc_get_preference('allow_update_prerelease')) {
            $enable_prerelease = true;
        }
        if (defined('ENABLE_PRERELEASE') && ENABLE_PRERELEASE === true) {
            $enable_prerelease = true;
        }

        parent::__construct($package_info, $force_upgrade, $enable_prerelease);
    }

    /**
     * Upgrade Osclass Database
     *
     * @param bool $skip_db
     *
     * @return false|string
     */
    public static function upgradeDB($skip_db = false)
    {
        set_time_limit(0);

        if (file_exists(osc_lib_path() . 'osclass/installer/struct.sql')) {
            $sql = file_get_contents(osc_lib_path() . 'osclass/installer/struct.sql');

            $conn = DBConnectionClass::newInstance();
            $c_db = $conn->getOsclassDb();
            $comm = new DBCommandClass($c_db);

            $result = $comm->updateDB(str_replace('/*TABLE_PREFIX*/', DB_TABLE_PREFIX, $sql));
            list($status, $message, $errorQueries) = $result;
        }
        if (isset($status, $message, $errorQueries)) {
            if (!$skip_db && count($errorQueries) > 0) {
                $skip_db_link = osc_admin_base_url(true) . '?page=upgrade&confirm=true&skipdb=true';
                $message      = '<p>';
                $message      .= __('Osclass &raquo; Has some errors') . PHP_EOL;
                $message      .= __('We\'ve encountered some problems while updating the database structure. The following queries failed:');
                $message      .= '</p>' . PHP_EOL;
                $message      .= '<pre>';
                $message      .= implode(PHP_EOL, $errorQueries) . PHP_EOL;
                $message      .= '</pre>';
                $message      .= __('These errors could be false-positive errors.');
                $message      .= __(" If you're sure that is the case, you can continue with the upgrade.");
                $message      .= '<a class="btn btn-sm btn-primary" href="' . $skip_db_link . '">' . __('Continue with upgrade') . '</a>';
                $message      .= __(" Or you can ask help in our support forum");
                $message      .= ': <a class="btn btn-sm btn-info" href="https://osclass.discourse.group">' . __('Support Forum') . '</a>';

                return json_encode(['error' => 2, 'message' => $message]);
            }

            if (osc_version() < 390) {
                osc_delete_preference('marketAllowExternalSources');
                osc_delete_preference('marketURL');
                osc_delete_preference('marketAPIConnect');
                osc_delete_preference('marketCategories');
                osc_delete_preference('marketDataUpdate');
            }

            osc_set_preference('admin_theme', 'modern');

            $runner = new MigrationRunner($comm, osc_lib_path() . 'osclass/installer/migrations');
            $runner->ensureLedger();
            $migrated = $runner->run();
            if (!$migrated['ok']) {
                return json_encode([
                    'error'   => 3,
                    'message' => sprintf(
                        __('Migration failed: %s'),
                        $migrated['failed']
                    ) . ' — ' . $migrated['error']
                ]);
            }

            Utils::changeOsclassVersionTo(OSCLASS_VERSION);

            return json_encode(['error' => 0, 'message' => __('Osclass DB Upgraded Successfully')]);
        }

        return json_encode(['error' => 1, 'message' => __('Unable to upgrade Database')]);
    }

    /**
     * prepare osclass upgrade package info
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
    public static function getPackageInfo($force = true)
    {
        $preference = Preference::newInstance();
        if ($force === true
            || (!$preference->get('update_core_json') && (time() - $preference->get('last_version_check')) > (24 * 3600)
            )
        ) {
            if ((defined('ENABLE_PRERELEASE') && ENABLE_PRERELEASE === true) || osc_get_bool_preference('allow_update_prerelease')) {
                $json_url                  = 'https://api.github.com/repos/mindstellar/osclass/releases';
                $osclass_package_info_json = (new FileSystem())->getContents($json_url);
                if ($osclass_package_info_json) {
                    $aSelfPackage = json_decode($osclass_package_info_json, true)[0];
                }
            } else {
                $json_url                  = 'https://api.github.com/repos/mindstellar/osclass/releases/latest';
                $osclass_package_info_json = (new FileSystem())->getContents($json_url);
                if ($osclass_package_info_json) {
                    $aSelfPackage = json_decode($osclass_package_info_json, true);
                }
            }

            if (!empty($aSelfPackage) && !$aSelfPackage['draft']) {
                if (isset($aSelfPackage['name'])) {
                    $package_info['s_title'] = $aSelfPackage['name'];
                }
                if (isset($aSelfPackage['assets'][0]['browser_download_url'])) {
                    $package_info['s_source_url'] = $aSelfPackage['assets'][0]['browser_download_url'];
                }
                if (isset($aSelfPackage['tag_name'])) {
                    $package_info['s_new_version'] = ltrim(trim($aSelfPackage['tag_name']), 'v');
                }
                $package_info['s_installed_version'] = OSCLASS_VERSION;
                $package_info['s_short_name']        = 'osclass';
                $package_info['s_target_directory']  = ABS_PATH;
                $package_info['a_filtered_files']    = ['oc-content', 'config.php'];
                $package_info['s_prerelease']        = $aSelfPackage['prerelease'];
            }
        }
        if (!isset($package_info) || empty($package_info)) {
            $package_info = json_decode($preference->get('update_core_json'), true);
        }

        return Plugins::applyFilter('osclass_upgrade_package', $package_info);
    }

    /**
     * Extra actions after upgradeProcess is done
     *
     * @return true
     */
    public function afterProcessUpgrade()
    {
        osc_set_preference('update_core_available');
        osc_set_preference('update_core_json');

        return true;
    }
}
