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

use mindstellar\database\Connection;
use mindstellar\database\SchemaReconciler;
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
     * Upgrade Shopclass Database.
     *
     * The migrations are what build the schema. Every change to struct.sql is
     * required to have a migration behind it, and tests/schema-drift.php holds each
     * release to that by rebuilding the schema from migrations alone and refusing to
     * pass if the reconciler is left with anything to do.
     *
     * The reconciler still runs first, and still repairs. What it is for is an install
     * that has drifted by some route the migrations cannot know about -- a
     * hand-edited column, a plugin's leftovers, an upgrade interrupted half way -- and
     * on an install in good order it now finds nothing and issues nothing. Anything it
     * does apply is reported back in `repairs` rather than being applied silently,
     * because on a healthy install that list is expected to be empty and a non-empty
     * one is worth seeing.
     *
     * It runs before the migrations rather than after, which is the order this has
     * always used: an install coming from a much older release runs the whole
     * migration sequence in one go, and repairing the schema first is what has made
     * that work. The drift check covers the last release only, so there is no evidence
     * to justify reversing it.
     *
     * @param bool $skip_db        continue even when the reconciler reports failed statements
     * @param bool $skip_reconcile run the migrations alone, without the repair pass
     *
     * @return false|string
     */
    public static function upgradeDB($skip_db = false, $skip_reconcile = false)
    {
        set_time_limit(0);

        $repairs = array();

        if (file_exists(osc_lib_path() . 'osclass/installer/struct.sql')) {
            if ($skip_reconcile) {
                $status       = true;
                $message      = array();
                $errorQueries = array();
            } else {
                $sql = file_get_contents(osc_lib_path() . 'osclass/installer/struct.sql');

                $result = (new SchemaReconciler(Connection::instance()))
                    ->reconcile(str_replace('/*TABLE_PREFIX*/', DB_TABLE_PREFIX, $sql));
                list($status, $message, $errorQueries) = $result;

                // The second element is every statement the pass ran, keyed by table
                // where it creates one. Only the ones that succeeded are a repair.
                $repairs = array_values(array_diff(array_values($message), $errorQueries));
            }
        }
        if (isset($status, $message, $errorQueries)) {
            if (!$skip_db && count($errorQueries) > 0) {
                $skip_db_link = osc_admin_base_url(true) . '?page=upgrade&confirm=true&skipdb=true';
                $message      = '<p>';
                $message      .= __('Shopclass &raquo; Has some errors') . PHP_EOL;
                $message      .= __('We\'ve encountered some problems while updating the database structure. The following queries failed:');
                $message      .= '</p>' . PHP_EOL;
                $message      .= '<pre>';
                $message      .= implode(PHP_EOL, $errorQueries) . PHP_EOL;
                $message      .= '</pre>';
                $message      .= __('These errors could be false-positive errors.');
                $message      .= __(" If you're sure that is the case, you can continue with the upgrade.");
                $message      .= '<a class="btn btn-sm btn-primary" href="' . $skip_db_link . '">' . __('Continue with upgrade') . '</a>';
                $message      .= __(" Or you can ask for help in our community discussions");
                $message      .= ': <a class="btn btn-sm btn-info" href="https://github.com/mindstellar/shopclass/discussions">' . __('Community discussions') . '</a>';

                return json_encode(['error' => 2, 'message' => $message]);
            }

            // Legacy installs store the version as an MMN integer (3.9.0 => 390); modern ones
            // store a dotted string (5.3.0.dev). Only the former can predate 3.9.0, so restrict
            // the numeric comparison to numeric values — a dotted string is always newer.
            $storedVersion = osc_version();
            if (is_numeric($storedVersion) && (int) $storedVersion < 390) {
                osc_delete_preference('marketAllowExternalSources');
                osc_delete_preference('marketURL');
                osc_delete_preference('marketAPIConnect');
                osc_delete_preference('marketCategories');
                osc_delete_preference('marketDataUpdate');
            }

            osc_set_preference('admin_theme', 'modern');

            $runner = new MigrationRunner(Connection::instance(), osc_lib_path() . 'osclass/installer/migrations');
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

            Utils::changeOsclassVersionTo(self::newVersionOnDisk());

            return json_encode([
                'error'   => 0,
                'message' => __('Shopclass DB Upgraded Successfully'),
                'repairs' => $repairs,
            ]);
        }

        return json_encode(['error' => 1, 'message' => __('Unable to upgrade Database')]);
    }

    /**
     * The version from the freshly-synced code on disk, not the OSCLASS_VERSION
     * constant.
     *
     * The one-request upgrade (doUpgrade() then upgradeDB() in the same request)
     * has already replaced default-constants.php on disk, but OSCLASS_VERSION was
     * defined at the start of the request from the OLD code and cannot be
     * redefined — so recording it would write the pre-upgrade version into the
     * `version` preference, which then never catches up. This upgrade reconciled
     * the schema against the struct.sql and migrations already on disk, so the
     * version it records must come from disk too. Falls back to the constant when
     * the file can't be read (e.g. a plain in-process db:upgrade, where they match).
     */
    private static function newVersionOnDisk(): string
    {
        $file = osc_lib_path() . 'osclass/default-constants.php';
        if (is_readable($file)) {
            $contents = file_get_contents($file);
            if ($contents !== false
                && preg_match('/define\(\s*[\'"]OSCLASS_VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $contents, $m)
            ) {
                return $m[1];
            }
        }

        return OSCLASS_VERSION;
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
    public static function getPackageInfo($force = true, &$isFresh = null)
    {
        // Signals to the caller whether the returned payload came from a live GitHub
        // fetch this call ($isFresh = true) or is the last-known-good cache served
        // because the fetch produced nothing (rate limit, outage). Only a fresh result
        // should be allowed to reset the once-a-day check clock or claim "checked now".
        $isFresh    = false;
        $preference = Preference::newInstance();
        if ($force === true
            || (
                !$preference->get('update_core_json') && (time() - $preference->get('last_version_check')) > (24 * 3600)
            )
        ) {
            if ((defined('ENABLE_PRERELEASE') && ENABLE_PRERELEASE === true) || osc_get_bool_preference('allow_update_prerelease')) {
                $json_url                  = 'https://api.github.com/repos/mindstellar/shopclass/releases';
                $osclass_package_info_json = (new FileSystem())->getContents($json_url);
                if ($osclass_package_info_json) {
                    $releases = json_decode($osclass_package_info_json, true);
                    if (is_array($releases)) {
                        // GitHub's /releases list is NOT guaranteed newest-first — it has
                        // returned e.g. beta10 *below* beta9 — so taking the first non-draft
                        // could pin an older release than one further down the list and never
                        // offer the real newest. Scan them all and keep the highest version by
                        // version_compare (drafts skipped; prereleases kept, since this branch
                        // only runs when prerelease updates are opted in).
                        foreach ($releases as $release) {
                            // A GitHub error body (404, rate limit) decodes to an associative
                            // array of strings, not a list of release objects — is_array()
                            // guards the offset reads below against those non-array entries.
                            if (!is_array($release) || !empty($release['draft']) || empty($release['tag_name'])) {
                                continue;
                            }
                            if (
                                !isset($aSelfPackage)
                                || version_compare(
                                    ltrim(trim($release['tag_name']), 'v'),
                                    ltrim(trim($aSelfPackage['tag_name']), 'v'),
                                    'gt'
                                )
                            ) {
                                $aSelfPackage = $release;
                            }
                        }
                    }
                }
            } else {
                $json_url                  = 'https://api.github.com/repos/mindstellar/shopclass/releases/latest';
                $osclass_package_info_json = (new FileSystem())->getContents($json_url);
                if ($osclass_package_info_json) {
                    $aSelfPackage = json_decode($osclass_package_info_json, true);
                }
            }

            // Require a real release payload: a GitHub error body (404 "Not Found", a rate-limit
            // message) is a non-empty array too, but carries no tag_name. Treating it as a release
            // would build a versionless package and, worse, flag the check as a fresh success.
            if (!empty($aSelfPackage['tag_name']) && empty($aSelfPackage['draft'])) {
                if (isset($aSelfPackage['name'])) {
                    $package_info['s_title'] = $aSelfPackage['name'];
                }
                $s_source_url = self::selectReleaseAssetUrl($aSelfPackage['assets'] ?? array());
                if ($s_source_url !== null) {
                    $package_info['s_source_url'] = $s_source_url;
                }
                if (isset($aSelfPackage['tag_name'])) {
                    $package_info['s_new_version'] = ltrim(trim($aSelfPackage['tag_name']), 'v');
                }
                $package_info['s_installed_version'] = OSCLASS_VERSION;
                $package_info['s_short_name']        = 'osclass';
                $package_info['s_target_directory']  = ABS_PATH;
                $package_info['a_filtered_files']    = ['oc-content', 'config.php'];
                $package_info['s_prerelease']        = $aSelfPackage['prerelease'] ?? false;
                $isFresh                             = true;
            }
        }
        if (!isset($package_info) || empty($package_info)) {
            $package_info = json_decode($preference->get('update_core_json'), true);
        }

        return Plugins::applyFilter('osclass_upgrade_package', $package_info);
    }

    /**
     * Pick the Shopclass package asset from a GitHub release's assets list. Prefers the
     * canonical `osclass_v*.zip`, then any `.zip`, so extra release assets do not break
     * selection (the old code blindly took assets[0]).
     *
     * @param array $assets GitHub release "assets" array
     *
     * @return string|null browser_download_url, or null if none suitable
     */
    private static function selectReleaseAssetUrl($assets)
    {
        if (!is_array($assets)) {
            return null;
        }
        $firstZip = null;
        foreach ($assets as $asset) {
            if (!isset($asset['name'], $asset['browser_download_url'])) {
                continue;
            }
            if (preg_match('/^osclass_v.*\.zip$/i', $asset['name'])) {
                return $asset['browser_download_url'];
            }
            if ($firstZip === null && substr(strtolower($asset['name']), -4) === '.zip') {
                $firstZip = $asset['browser_download_url'];
            }
        }

        return $firstZip;
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
