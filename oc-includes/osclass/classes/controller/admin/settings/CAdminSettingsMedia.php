<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\admin\form\CoreSettings;
use mindstellar\admin\form\MediaSettingsForm;

/**
 * Class CAdminSettingsMedia
 */
class CAdminSettingsMedia extends AdminSecBaseModel
{
    /**
     * Boots the admin controller and fires the init_admin_settings_media hook.
     */
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_media');
    }

    //Business Layer...
    /**
     * Draws the media settings screen, saves its declared form, or regenerates every image.
     *
     * @return void
     */
    public function doModel()
    {
        switch ($this->action) {
            case ('media'):
                $this->drawForm();
                break;
            case ('media_post'):
                osc_csrf_check();

                // A bad watermark file is refused in validation with everything else, so
                // nothing is written; a good one that cannot be moved is reported by the
                // after_save, beside the values that did save.
                $result = CoreSettings::attempt(MediaSettingsForm::register());
                if ($result['errors'] !== array()) {
                    $this->drawForm($result['values']);
                    break;
                }

                $lowered = MediaSettingsForm::lowered();
                if ($lowered !== null) {
                    osc_add_flash_warning_message(
                        sprintf(
                            _m('You cannot set a maximum file size higher than the one allowed in the PHP configuration: <b>%d KB</b>'),
                            $lowered
                        ),
                        'admin'
                    );
                } elseif (!MediaSettingsForm::uploadFailed()) {
                    osc_add_flash_ok_message(_m('Media config has been updated'), 'admin');
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=media');
                break;
            case ('images_post'):
                $this->refuseOnDemo(osc_admin_base_url(true) . '?page=settings&action=media');
                osc_csrf_check();

                if (\mindstellar\storage\StorageManager::instance()->remote() === null) {
                    // No remote storage configured: regenerate every resource inline, exactly as before.
                    $aResources = ItemResource::newInstance()->getAllResources();
                    foreach ($aResources as $resource) {
                        ItemActions::regenerateResourceImages($resource);
                    }

                    osc_add_flash_ok_message(_m('Re-generation complete'), 'admin');
                } else {
                    // A remote adapter is active: regenerating inline would mean one synchronous
                    // download per resource, so page through resource ids (never loading full rows)
                    // and queue a 'regenerate' job per resource for the storage worker to process.
                    $remoteId = \mindstellar\storage\StorageManager::instance()->remote()->getId();
                    $itemResourceManager = ItemResource::newInstance();
                    $batchSize = 500;
                    $offset    = 0;
                    $count     = 0;
                    do {
                        $ids = $itemResourceManager->getResourceIdsBatch($offset, $batchSize);
                        foreach ($ids as $id) {
                            StorageQueue::newInstance()->enqueue(
                                'regenerate',
                                $remoteId,
                                array('pk_i_id' => $id, 's_storage' => $remoteId)
                            );
                            $count++;
                        }
                        $offset += $batchSize;
                    } while (count($ids) === $batchSize);

                    osc_add_flash_ok_message(
                        sprintf(
                            _m('Queued %d images for background regeneration. They will process automatically via cron.'),
                            $count
                        ),
                        'admin'
                    );
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=media');
                break;
        }
    }

    /**
     * Exports the media settings form and renders its view.
     *
     * @param array|null $values values a rejected save is handing back
     *
     * @return void
     */
    private function drawForm(?array $values = null)
    {
        // The name a replaced admin theme's own copy of the view still reads.
        $this->_exportVariableToView('max_size_upload', MediaSettingsForm::uploadLimitKb());
        $this->_exportVariableToView('media_form', MediaSettingsForm::formVars($values));
        $this->doView('settings/media.php');
    }

    /**
     * Converts a php.ini-style byte size ("8M", "1G") to kilobytes.
     *
     * @param string $sSize
     *
     * @return int
     */
    public function _sizeToKB($sSize)
    {
        return MediaSettingsForm::sizeToKb((string)$sSize);
    }
}

// EOF: ./oc-admin/controller/settings/CAdminSettingsMedia.php
