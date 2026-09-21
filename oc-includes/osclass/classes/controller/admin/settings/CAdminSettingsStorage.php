<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\admin\form\CoreSettings;
use mindstellar\admin\form\StorageSettingsForm;
use mindstellar\job\JobQueue;
use mindstellar\storage\ProviderPresets;
use mindstellar\storage\S3Storage;
use mindstellar\storage\StorageJobs;
use mindstellar\storage\StorageManager;

/**
 * Class CAdminSettingsStorage
 */
class CAdminSettingsStorage extends AdminSecBaseModel
{
    /**
     * Boots the admin controller and fires the init_admin_settings_storage hook.
     */
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_storage');
    }

    //Business Layer...
    /**
     * Routes the storage actions: the settings screen and its save, the connection test, the queue runner and the migration start.
     *
     * @return void
     */
    public function doModel()
    {
        switch ($this->action) {
            case ('storage'):
                $this->drawForm();
                break;
            case ('storage_post'):
                osc_csrf_check();

                $result = CoreSettings::attempt(StorageSettingsForm::register());
                if ($result['errors'] !== array()) {
                    $this->drawForm($result['values']);
                    break;
                }

                osc_add_flash_ok_message(_m('Storage settings updated'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=storage');
                break;
            case ('storage_test_post'):
                osc_csrf_check();

                try {
                    $adapter = new S3Storage($this->_savedS3Config());

                    $key = 'shopclass-healthcheck.txt';
                    $probe = 'shopclass-storage-check-' . uniqid();
                    $tmpFile = tempnam(sys_get_temp_dir(), 'oscs3');
                    file_put_contents($tmpFile, $probe);

                    $ok = $adapter->put($tmpFile, $key, 'text/plain')
                        && $adapter->exists($key)
                        && $adapter->get($key) === $probe;

                    @unlink($tmpFile);
                    $adapter->delete($key);

                    if ($ok) {
                        osc_add_flash_ok_message(_m('Connection test succeeded'), 'admin');
                    } else {
                        osc_add_flash_error_message(_m('Connection test failed. Check your credentials and settings.'), 'admin');
                    }
                } catch (Throwable $e) {
                    osc_add_flash_error_message(_m('Connection test failed. Check your credentials and settings.'), 'admin');
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=storage');
                break;
            case ('storage_queue_run'):
                osc_csrf_check();

                osc_job_run();

                osc_add_flash_ok_message(_m('Storage queue processed'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=storage');
                break;
            case ('storage_migrate_post'):
                osc_csrf_check();

                $op = Params::getParam('op');

                switch ($op) {
                    case 'offload_all':
                        $remote = StorageManager::instance()->remote();
                        if ($remote === null) {
                            osc_add_flash_error_message(_m('Configure and activate a remote backend first.'), 'admin');
                            break;
                        }

                        StorageJobs::enqueueSeed('offload', 'local', $remote->getId());
                        osc_add_flash_ok_message(
                            _m('Offload queued. Images move to remote storage in the background as the storage worker runs.'),
                            'admin'
                        );
                        break;
                    case 'restore_all':
                        $remote = StorageManager::instance()->remote();
                        if ($remote === null) {
                            osc_add_flash_error_message(_m('Configure and activate a remote backend first.'), 'admin');
                            break;
                        }

                        StorageJobs::enqueueSeed('restore', $remote->getId(), $remote->getId());
                        osc_add_flash_ok_message(
                            _m('Restore queued. Images download back to local storage in the background as the storage worker runs.'),
                            'admin'
                        );
                        break;
                    case 'adopt_better_s3':
                        $bucket = osc_get_preference('s3_bucket_name', 'betters3');
                        $accessKey = osc_get_preference('s3_access_key', 'betters3');
                        $secretKey = osc_get_preference('s3_secret_key', 'betters3');
                        $endpoint = osc_get_preference('s3_endpoint', 'betters3');

                        if ($bucket === '' || $accessKey === '' || $secretKey === '' || $endpoint === '') {
                            osc_add_flash_error_message(_m('Better S3 is not configured; nothing to adopt.'), 'admin');
                            break;
                        }

                        $cdnPath = osc_get_preference('s3_cdn_path', 'betters3');

                        osc_set_preference('storage_s3_endpoint', $this->_httpUrlOrEmpty('https://' . $endpoint));
                        osc_set_preference('storage_s3_bucket', $bucket);
                        osc_set_preference('storage_s3_access_key', $accessKey);
                        osc_set_preference('storage_s3_secret_key', $secretKey);
                        osc_set_preference('storage_s3_region', 'auto');
                        osc_set_preference('storage_s3_provider', 'r2');
                        osc_set_preference('storage_s3_path_style', true);
                        osc_set_preference('storage_s3_public_url', $cdnPath ? $this->_httpUrlOrEmpty('https://' . $cdnPath) : '');
                        osc_set_preference('storage_active', 's3');

                        // Adoption relies on the frozen storage-key scheme (path + id + variant
                        // suffix + extension) being identical to the one the Better S3 plugin used,
                        // so objects it already uploaded are addressable at the same keys without a
                        // re-upload. The 's3' adapter for this run may not exist yet if it wasn't
                        // already active; the worker resolves it fresh from the prefs saved above
                        // on the next cron run, once hStorage.php has registered it.
                        StorageJobs::enqueueSeed('adopt', 'local', 's3');
                        osc_add_flash_ok_message(
                            _m('Better S3 settings imported. Existing images are adopted in the background as the storage worker runs.'),
                            'admin'
                        );
                        break;
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=storage');
                break;
        }
    }

    /**
     * Exports the storage form, the queue counts and the Better S3 state, and renders the view.
     *
     * @param array|null $values values a rejected save is handing back
     *
     * @return void
     */
    private function drawForm(?array $values = null)
    {
        $form  = StorageSettingsForm::formVars($values);
        $shown = $form['values'];

        // The names these have always had: a replaced admin theme's own view still reads them.
        $prefs = array(
            'storage_active'         => (string) $shown['storage_active'],
            'storage_s3_provider'    => (string) ($shown['storage_s3_provider'] ?: 'custom'),
            'storage_s3_endpoint'    => (string) $shown['storage_s3_endpoint'],
            'storage_s3_region'      => (string) $shown['storage_s3_region'],
            'storage_s3_bucket'      => (string) $shown['storage_s3_bucket'],
            'storage_s3_access_key'  => (string) $shown['storage_s3_access_key'],
            'storage_s3_path_style'  => !empty($shown['storage_s3_path_style']),
            'storage_s3_public_url'  => (string) $shown['storage_s3_public_url'],
            'storage_s3_signed_urls' => !empty($shown['storage_s3_signed_urls']),
            'storage_s3_signed_ttl'  => (int) ($shown['storage_s3_signed_ttl'] ?: 900),
            'storage_keep_local'     => (string) ($shown['storage_keep_local'] ?: 'all'),
        );

        try {
            $queue = JobQueue::instance();
            $queueStats = array(
                'pending' => $queue->count(JobQueue::STATUS_PENDING),
                'error' => $queue->count(JobQueue::STATUS_ERROR),
                'dead_letters' => $queue->deadLetters(20),
            );
        } catch (Throwable $e) {
            $queueStats = array(
                'pending' => 0,
                'error' => 0,
                'dead_letters' => array(),
            );
        }

        $this->_exportVariableToView('storage_form', $form);
        $this->_exportVariableToView('prefs', $prefs);
        $this->_exportVariableToView('provider_presets', ProviderPresets::PRESETS);
        $this->_exportVariableToView('queue_stats', $queueStats);
        // The conflict warning must reflect real activation — whether the plugin is
        // in Osclass's active list — not its own leftover s3_enable_plugin preference,
        // which survives deactivation and would keep the warning up forever.
        $this->_exportVariableToView(
            'better_s3_active',
            osc_plugin_is_enabled('better-s3/index.php')
        );
        // Config presence stays preference-based on purpose: adoption reads the
        // (now-disabled) plugin's saved connection settings to import them.
        $this->_exportVariableToView(
            'better_s3_configured',
            osc_get_preference('s3_bucket_name', 'betters3') !== ''
            && osc_get_preference('s3_access_key', 'betters3') !== ''
            && osc_get_preference('s3_secret_key', 'betters3') !== ''
            && osc_get_preference('s3_endpoint', 'betters3') !== ''
        );

        $this->doView('settings/storage.php');
    }

    /**
     * The same S3Storage config array hStorage.php builds from saved
     * preferences, kept in one place so the connection test exercises
     * exactly what a live request would use.
     *
     * @return array
     */
    private function _savedS3Config()
    {
        return array(
            'endpoint' => osc_get_preference('storage_s3_endpoint', 'osclass'),
            'region' => osc_get_preference('storage_s3_region', 'osclass') ?: 'us-east-1',
            'bucket' => osc_get_preference('storage_s3_bucket', 'osclass'),
            'access_key' => osc_get_preference('storage_s3_access_key', 'osclass'),
            'secret_key' => osc_get_preference('storage_s3_secret_key', 'osclass'),
            'path_style' => osc_get_bool_preference('storage_s3_path_style', 'osclass'),
            'public_url_base' => osc_get_preference('storage_s3_public_url', 'osclass') ?: '',
            'signed_urls' => osc_get_bool_preference('storage_s3_signed_urls', 'osclass'),
            'signed_ttl' => (int) (osc_get_preference('storage_s3_signed_ttl', 'osclass') ?: 900),
        );
    }

    /**
     * Sanitize a URL and require an http/https scheme; anything else (empty,
     * javascript:, data:, ...) becomes an empty string.
     *
     * @param string $value
     *
     * @return string
     */
    private function _httpUrlOrEmpty($value)
    {
        return StorageSettingsForm::httpUrlOrEmpty((string) $value);
    }
}

// EOF: ./oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsStorage.php
