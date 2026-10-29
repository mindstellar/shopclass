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
use mindstellar\market\Catalog;
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
    /** Sends the empty answer and returns true while new files wait for their database upgrade (the tables are not there yet). */
    private static function answeredWhileDatabaseBehind(): bool
    {
        if (!Osclass::databaseBehind()) {
            return false;
        }
        AjaxResponse::json(array('msg' => __('Checked updates'), 'total' => 0));

        return true;
    }

    public function checkVersion(): void
    {
        if (BuildInfo::isEdge()) {
            AjaxResponse::json(array('error' => 0, 'msg' => __('No update available')));

            return;
        }
        if (self::answeredWhileDatabaseBehind()) {
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
        if (self::answeredWhileDatabaseBehind()) {
            return;
        }
        $total = _osc_check_languages_update();
        AjaxResponse::json(array('msg' => __('Checked updates'), 'total' => $total));
    }

    public function checkThemes(): void
    {
        if (self::answeredWhileDatabaseBehind()) {
            return;
        }
        Catalog::forThemes()->refreshIfDue();
        $total = _osc_check_themes_update();
        AjaxResponse::json(array('msg' => __('Checked updates'), 'total' => $total));
    }

    public function checkPlugins(): void
    {
        if (self::answeredWhileDatabaseBehind()) {
            return;
        }
        Catalog::forPlugins()->refreshIfDue();
        $total = _osc_check_plugins_update();
        AjaxResponse::json(array('msg' => __('Checked updates'), 'total' => $total));
    }

    /** Upgrade to the available release. */
    public function upgrade(): void
    {
        AjaxResponse::json(self::replaceFiles(false));
    }

    /** Reinstall the current release over itself. */
    public function reinstall(): void
    {
        AjaxResponse::json(self::replaceFiles(true));
    }

    /**
     * Put the release files in place and send the screen on to the database step. That step runs
     * in its own request, so it loads the new files and not the class map this one started with.
     *
     * @param bool $force reinstall the current release
     *
     * @return array{error:int, message:string, next?:string}
     */
    private static function replaceFiles(bool $force): array
    {
        $result = self::refusal();
        if ($result !== null) {
            return $result;
        }
        try {
            (new Upgrade(new Osclass(Osclass::getPackageInfo(), $force)))->doUpgrade();
        } catch (Exception $e) {
            osc_add_flash_error_message($e->getMessage(), 'admin');

            return ['error' => 1, 'message' => $e->getMessage()];
        }

        return [
            'error'   => 0,
            'message' => __('The new files are in place. Updating the database...'),
            'next'    => osc_admin_base_url(true) . '?page=upgrade',
        ];
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
