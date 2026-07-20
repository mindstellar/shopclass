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

use mindstellar\storage\ProviderPresets;
use mindstellar\storage\S3Storage;
use mindstellar\storage\StorageManager;
use mindstellar\storage\StorageWorker;

/**
 * Class CAdminSettingsStorage
 */
class CAdminSettingsStorage extends AdminSecBaseModel
{
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_storage');
    }

    //Business Layer...
    public function doModel()
    {
        switch ($this->action) {
            case ('storage'):
                $prefs = array(
                    'storage_active' => osc_get_preference('storage_active', 'osclass'),
                    'storage_s3_endpoint' => osc_get_preference('storage_s3_endpoint', 'osclass'),
                    'storage_s3_region' => osc_get_preference('storage_s3_region', 'osclass'),
                    'storage_s3_bucket' => osc_get_preference('storage_s3_bucket', 'osclass'),
                    'storage_s3_access_key' => osc_get_preference('storage_s3_access_key', 'osclass'),
                    'storage_s3_path_style' => osc_get_bool_preference('storage_s3_path_style', 'osclass'),
                    'storage_s3_public_url' => osc_get_preference('storage_s3_public_url', 'osclass'),
                    'storage_s3_signed_urls' => osc_get_bool_preference('storage_s3_signed_urls', 'osclass'),
                    'storage_s3_signed_ttl' => (int) (osc_get_preference('storage_s3_signed_ttl', 'osclass') ?: 900),
                    'storage_keep_local' => osc_get_preference('storage_keep_local', 'osclass') ?: 'all',
                );

                try {
                    $queue = StorageQueue::newInstance();
                    $queueStats = array(
                        'pending' => $queue->countByStatus('pending'),
                        'error' => $queue->countByStatus('error'),
                        'dead_letters' => $queue->deadLetters(20),
                    );
                } catch (Throwable $e) {
                    $queueStats = array(
                        'pending' => 0,
                        'error' => 0,
                        'dead_letters' => array(),
                    );
                }

                $this->_exportVariableToView('prefs', $prefs);
                $this->_exportVariableToView('provider_presets', ProviderPresets::PRESETS);
                $this->_exportVariableToView('queue_stats', $queueStats);
                $this->_exportVariableToView(
                    'better_s3_active',
                    (bool) osc_get_preference('s3_enable_plugin', 'betters3')
                );
                $this->_exportVariableToView(
                    'better_s3_configured',
                    osc_get_preference('s3_bucket_name', 'betters3') !== ''
                    && osc_get_preference('s3_access_key', 'betters3') !== ''
                    && osc_get_preference('s3_secret_key', 'betters3') !== ''
                    && osc_get_preference('s3_endpoint', 'betters3') !== ''
                );

                $this->doView('settings/storage.php');
                break;
            case ('storage_post'):
                osc_csrf_check();

                // Only accept http(s) URLs; osc_sanitize_url (FILTER_SANITIZE_URL) leaves
                // schemes like javascript: intact, so guard the scheme explicitly before this
                // value reaches any presentation-layer sink (public_url builds visitor image URLs).
                $endpoint = $this->_httpUrlOrEmpty(Params::getParam('storage_s3_endpoint'));
                $publicUrl = $this->_httpUrlOrEmpty(Params::getParam('storage_s3_public_url'));

                $signedTtl = (int) Params::getParam('storage_s3_signed_ttl');
                $signedTtl = $signedTtl > 0 ? max(60, min(604800, $signedTtl)) : 900;

                osc_set_preference('storage_active', Params::getParam('storage_active') === 's3' ? 's3' : 'local');
                osc_set_preference('storage_s3_endpoint', $endpoint);
                osc_set_preference('storage_s3_region', Params::getParam('storage_s3_region'));
                osc_set_preference('storage_s3_bucket', Params::getParam('storage_s3_bucket'));
                osc_set_preference('storage_s3_access_key', Params::getParam('storage_s3_access_key'));
                osc_set_preference('storage_s3_path_style', Params::getParam('storage_s3_path_style') != '');
                osc_set_preference('storage_s3_public_url', $publicUrl);
                osc_set_preference('storage_s3_signed_urls', Params::getParam('storage_s3_signed_urls') != '');
                osc_set_preference('storage_s3_signed_ttl', $signedTtl);
                osc_set_preference(
                    'storage_keep_local',
                    Params::getParam('storage_keep_local') === 'none' ? 'none' : 'all'
                );

                $secret = Params::getParam('storage_s3_secret_key');
                if ($secret !== '') {
                    osc_set_preference('storage_s3_secret_key', $secret);
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

                StorageWorker::run();

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

                        $n = $this->_enqueueByStorage('offload', 'local', $remote->getId());
                        osc_add_flash_ok_message(
                            sprintf(_m('Queued %d images to offload to remote storage.'), $n),
                            'admin'
                        );
                        break;
                    case 'restore_all':
                        $remote = StorageManager::instance()->remote();
                        if ($remote === null) {
                            osc_add_flash_error_message(_m('Configure and activate a remote backend first.'), 'admin');
                            break;
                        }

                        $n = $this->_enqueueByStorage('restore', $remote->getId(), $remote->getId());
                        osc_add_flash_ok_message(
                            sprintf(_m('Queued %d images to download back to local storage.'), $n),
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
                        osc_set_preference('storage_s3_path_style', true);
                        osc_set_preference('storage_s3_public_url', $cdnPath ? $this->_httpUrlOrEmpty('https://' . $cdnPath) : '');
                        osc_set_preference('storage_active', 's3');

                        // Adoption relies on the frozen storage-key scheme (path + id + variant
                        // suffix + extension) being identical to the one the Better S3 plugin used,
                        // so objects it already uploaded are addressable at the same keys without a
                        // re-upload. The 's3' adapter for this run may not exist yet if it wasn't
                        // already active; the worker resolves it fresh from the prefs saved above
                        // on the next cron run, once hStorage.php has registered it.
                        $n = $this->_enqueueByStorage('adopt', 'local', 's3');
                        osc_add_flash_ok_message(
                            sprintf(_m('Better S3 settings imported and %d local images queued for adoption.'), $n),
                            'admin'
                        );
                        break;
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=storage');
                break;
        }
    }

    /**
     * Pages through every resource currently on $sourceStorage and enqueues
     * a $jobType job targeting $targetStorageId for each one. Full rows are
     * passed to StorageQueue::enqueue() (not just ids): the queue trims each
     * snapshot to the fields its handlers need (s_path, s_extension,
     * s_content_type, ...), and those fields have to come from somewhere.
     *
     * @param string $jobType
     * @param string $sourceStorage
     * @param string $targetStorageId
     *
     * @return int
     */
    private function _enqueueByStorage(string $jobType, string $sourceStorage, string $targetStorageId): int
    {
        $queue = StorageQueue::newInstance();
        $model = ItemResource::newInstance();
        $total = 0;
        $offset = 0;
        $batch = 500;

        while (true) {
            $rows = $model->getResourcesBatchByStorage($sourceStorage, $offset, $batch);
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $queue->enqueue($jobType, $targetStorageId, $row);
                $total++;
            }

            $offset += $batch;
        }

        return $total;
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
        $value = osc_sanitize_url((string) $value);

        return preg_match('#^https?://#i', $value) ? $value : '';
    }
}

// EOF: ./oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsStorage.php
