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

namespace mindstellar\admin\ajax;

use Exception;
use mindstellar\security\Demo;
use mindstellar\upgrade\BuildInfo;
use mindstellar\upgrade\Osclass;
use mindstellar\upgrade\Upgrade;
use mindstellar\utility\AjaxResponse;

/**
 * Update checks for core, languages, themes and plugins, and the in-app core upgrade.
 */
final class UpdateAjax extends AjaxHandler
{
    public function checkVersion(): void
    {
        if (BuildInfo::isEdge()) {
            AjaxResponse::json(array('error' => 0, 'msg' => __('No update available')));

            return;
        }
        // getPackageInfo() reaches GitHub and it, or the Osclass constructor, can throw on
        // an odd payload. A routine background poll degrades to "couldn't check" instead
        // of a 500; \Throwable so an Error is caught as well.
        try {
            $isFresh      = false;
            $package_json = Osclass::getPackageInfo(true, $isFresh);
            if ($isFresh && is_array($package_json) && !empty($package_json)) {
                // A live fetch succeeded: record the result and reset the once-a-day clock.
                $upgradeOsclass    = new Osclass($package_json);
                $upgrade_available = $upgradeOsclass->isUpgradable();
                osc_update_check_save('core', array(
                    'checked'   => time(),
                    'available' => $upgrade_available,
                    'package'   => $package_json,
                ));
                AjaxResponse::json(array(
                    'error' => 0,
                    'msg'   => $upgrade_available ? __('Update available') : __('No update available'),
                ));
            } else {
                // Only the stale cache, or nothing. Leave the stored result alone and retry
                // in an hour rather than freezing the badge for a full day.
                self::scheduleRetry();
                AjaxResponse::json(array('error' => 1, 'msg' => __('Could not check for updates')));
            }
        } catch (\Throwable $e) {
            self::scheduleRetry();
            AjaxResponse::json(array('error' => 1, 'msg' => __('Could not check for updates')));
        }
    }

    public function checkLanguages(): void
    {
        $total = _osc_check_languages_update();
        AjaxResponse::json(array('msg' => __('Checked updates'), 'total' => $total));
    }

    public function checkThemes(): void
    {
        MarketAjax::refreshCatalogIfDue('theme');
        $total = _osc_check_themes_update();
        AjaxResponse::json(array('msg' => __('Checked updates'), 'total' => $total));
    }

    public function checkPlugins(): void
    {
        MarketAjax::refreshCatalogIfDue('plugin');
        $total = _osc_check_plugins_update();
        AjaxResponse::json(array('msg' => __('Checked updates'), 'total' => $total));
    }

    /** Upgrade to the available release. */
    public function upgrade(): void
    {
        $result = self::refusal();
        if ($result === null) {
            $osclassUpgradeObj = new Osclass(Osclass::getPackageInfo());
            $upgradeOsclass    = new Upgrade($osclassUpgradeObj);
            try {
                $upgradeOsclass->doUpgrade();
                $db_upgrade_result = json_decode((string) $osclassUpgradeObj::upgradeDB(), true);
                $result            = [
                    'error'   => $db_upgrade_result['error'],
                    'message' => $db_upgrade_result['message'],
                ];
            } catch (Exception $e) {
                $result = ['error' => 1, 'message' => $e->getMessage()];
                osc_add_flash_error_message($e->getMessage(), 'admin');
            }
            if (isset($db_upgrade_result) && $db_upgrade_result['error'] == 1) {
                $result = ['error' => 5, 'message' => $db_upgrade_result['message']];
                osc_add_flash_warning_message(__('Error occurred while upgrading osclass Database.'), 'admin');
            }
        }
        AjaxResponse::json($result);
    }

    /** Reinstall the current release over itself. */
    public function reinstall(): void
    {
        $result = self::refusal();
        if ($result === null) {
            $osclassUpgradeObj = new Osclass(Osclass::getPackageInfo(), true);
            $upgradeOsclass    = new Upgrade($osclassUpgradeObj);
            try {
                $upgradeOsclass->doUpgrade();
                $db_upgrade_result = json_decode((string) $osclassUpgradeObj::upgradeDB(), true);
                $result            = [
                    'error'   => 0,
                    'message' => __('Shopclass upgraded successfully.'),
                ];
            } catch (Exception $e) {
                $result = ['error' => 1, 'message' => $e->getMessage()];
                osc_add_flash_error_message($e->getMessage(), 'admin');
            }
            // upgradeDB() reports through 'error'; it has no 'status' key.
            if (isset($db_upgrade_result) && (int) $db_upgrade_result['error'] !== 0) {
                $result = ['error' => 5, 'message' => $db_upgrade_result['message']];
                osc_add_flash_warning_message(__('Error occurred while upgrading osclass Database.'), 'admin');
            }
        }
        AjaxResponse::json($result);
    }

    /** Run the database migrations and echo their JSON report. */
    public function upgradeDb(): void
    {
        if ($this->controller->refuseDemo(osc_admin_base_url(true))) {
            return;
        }
        echo Osclass::upgradeDB();
    }

    /**
     * Why an in-app upgrade is refused here, with a flash for the next page, or null.
     *
     * @return array{error:int, message:string}|null
     */
    private static function refusal(): ?array
    {
        if (osc_self_update_disabled()) {
            $msg = __('In-app updates are disabled on this installation. Update by deploying a newer container image; the database is migrated automatically on start.');
        } elseif (Demo::active()) {
            $msg = Demo::message();
        } else {
            return null;
        }
        osc_add_flash_warning_message($msg, 'admin');

        return array('error' => 6, 'message' => $msg);
    }

    /**
     * Back-date the core update-check stamp so the next check is due in about an hour, not
     * a day. Used when a check could not reach GitHub.
     */
    private static function scheduleRetry(): void
    {
        $dayInSeconds   = 24 * 3600;
        $retryInSeconds = 3600;
        $state            = osc_update_check_state('core');
        $state['checked'] = time() - ($dayInSeconds - $retryInSeconds);
        osc_update_check_save('core', $state);
    }
}
