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

/**
 * Class CAdminTools
 */
use mindstellar\admin\DatabaseTools;
use mindstellar\admin\ListPaging;
use mindstellar\backup\BackupJobs;
use mindstellar\backup\BackupManager;
use mindstellar\backup\BackupStore;
use mindstellar\utility\AjaxResponse;

class CAdminTools extends AdminSecBaseModel
{
    /**
     * Let plugins hook the tools section before anything is dispatched.
     */
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_tools');
    }

    //Business Layer...

    /**
     * Dispatch the requested tools action: backup and restore, category and location
     * maintenance, the cache, and the database.
     *
     * @return void
     */
    public function doModel()
    {
        parent::doModel();

        switch ($this->action) {
            case ('import'):
                // Restoring a backup is now a part of Tools > Backup and restore; old links land there.
                $this->redirectTo(osc_admin_base_url(true) . DatabaseTools::movedTo('import'));
                break;
            case ('import_post'):
            case ('backup_upload'):
                $this->backupUpload($this->action === 'import_post' ? 'sql' : 'backup_file');
                break;
            case ('category'):
                $this->doView('tools/category.php');
                break;
            case ('category_post'):
                if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=tools&action=category')) {
                    break;
                }
                osc_update_cat_stats();
                osc_add_flash_ok_message(_m('Recount category stats has been successful'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=category');
                break;
            case ('locations'):
                $this->doView('tools/locations.php');
                break;
            case ('locations_post'):
                // Also posted from the Locations Data tab, which asks to come back there.
                $fromLocations = Params::getParamString('return') === 'locations';
                $isXhr         = strtolower(Params::getServerParam('HTTP_X_REQUESTED_WITH')) === 'xmlhttprequest';
                $back          = $fromLocations
                    ? osc_admin_base_url(true) . '?page=settings&action=locations&tab=data'
                    : osc_admin_base_url(true) . '?page=tools&action=locations';
                if ($isXhr && !defined('IS_AJAX')) {
                    define('IS_AJAX', true);
                }
                osc_csrf_check();
                if (defined('DEMO')) {
                    if ($isXhr) {
                        AjaxResponse::json(array('error' => _m('This action cannot be done because it is a demo site')));
                        exit;
                    }
                    osc_add_flash_warning_message(_m('This action cannot be done because it is a demo site'), 'admin');
                    $this->redirectTo($back);
                }

                $started = (float) (Params::getServerParam('REQUEST_TIME_FLOAT') ?: microtime(true));
                $queued  = (int) LocationsTmp::newInstance()->count();
                $pending = (int) osc_update_location_stats(true);
                $total   = $queued === 0 ? $pending : max($pending, (int) osc_get_preference('location_todo'));

                if ($isXhr) {
                    header('Cache-Control: no-store');
                    AjaxResponse::json(array(
                        'status'  => $pending > 0 ? 'more' : 'done',
                        'pending' => $pending,
                        'total'   => $total,
                    ));
                    exit;
                }

                if ($fromLocations) {
                    // Without the script, keep counting while this request has time left.
                    $until = microtime(true) + \mindstellar\location\LocationAdminView::recountBudget(
                        (int) ini_get('max_execution_time'),
                        microtime(true) - $started
                    );
                    while ($pending > 0 && microtime(true) < $until) {
                        $next = (int) osc_update_location_stats();
                        if ($next >= $pending) {
                            // A batch's writes are all failing; stop spinning until the next run.
                            break;
                        }
                        $pending = $next;
                    }
                    if ($pending > 0) {
                        osc_add_flash_info_message(sprintf(
                            _m('%s locations are still to be counted. Continue counting to finish.'),
                            number_format($pending)
                        ), 'admin');
                    } else {
                        osc_add_flash_ok_message(_m('Listing counts are recalculated'), 'admin');
                    }
                }

                $this->redirectTo($back);
                break;
            case ('upgrade'):
                if ($this->refuseOnDemo(osc_admin_base_url(true))) {
                    break;
                }
                $this->doView('tools/upgrade.php');
                break;
            case 'version':
                $this->doView('tools/version.php');
                break;
            case ('cache'):
                $this->doView('tools/cache.php');
                break;
            case ('cache_clear'):
                osc_csrf_check();
                if (osc_cache_flush()) {
                    osc_add_flash_ok_message(_m('The cache has been cleared'), 'admin');
                } else {
                    osc_add_flash_error_message(_m('The cache could not be cleared'), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=cache');
                break;
            case ('backup'):
            case ('backup_post'):
                $this->backupPage();
                break;
            case ('backup_start'):
            case ('backup-sql'):
            case ('backup-sql_file'):
            case ('backup-zip'):
            case ('backup-zip_file'):
                $this->backupStart();
                break;
            case ('backup_cancel'):
                if ($this->refuseOnDemo(self::backupUrl())) {
                    break;
                }
                osc_csrf_check();
                BackupManager::cancel();
                $this->redirectTo(self::backupUrl());
                break;
            case ('backup_download'):
                $this->backupDownload();
                break;
            case ('backup_delete'):
                if ($this->refuseOnDemo(self::backupUrl())) {
                    break;
                }
                osc_csrf_check();
                if (BackupStore::site()->delete(Params::getParamString('name', false, false))) {
                    osc_add_flash_ok_message(_m('The backup is deleted.'), 'admin');
                } else {
                    osc_add_flash_error_message(_m('That backup is not in the list any more.'), 'admin');
                }
                $this->redirectTo(self::backupUrl());
                break;
            case ('backup_restore'):
                if ($this->refuseOnDemo(self::backupUrl())) {
                    break;
                }
                osc_csrf_check();
                $parts  = Params::getParamArray('parts');
                $choose = Params::getParamInt('choose') === 1;
                $error  = BackupManager::startRestore(
                    Params::getParamString('name', false, false),
                    !$choose || in_array('database', $parts, true),
                    !$choose || in_array('files', $parts, true)
                );
                if ($error !== '') {
                    osc_add_flash_error_message(osc_esc_html($error), 'admin');
                }
                $this->redirectTo(self::backupUrl());
                break;
            case ('backup_dismiss'):
                osc_csrf_check();
                BackupManager::dismiss();
                $this->redirectTo(self::backupUrl());
                break;
            case ('backup_reopen'):
                if ($this->refuseOnDemo(self::backupUrl())) {
                    break;
                }
                osc_csrf_check();
                if (BackupManager::reopen()) {
                    osc_add_flash_ok_message(_m('The site is open again. Check the database below.'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=database');
                    break;
                }
                $this->redirectTo(self::backupUrl());
                break;
            case ('maintenance'):
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m('This action cannot be done because it is a demo site'), 'admin');
                    $this->doView('tools/maintenance.php');
                    break;
                }
                $mode = Params::getParam('mode');
                if ($mode === 'on') {
                    osc_csrf_check();
                    $maintenance_file = osc_base_path() . '.maintenance';
                    $fileHandler      = @fopen($maintenance_file, 'wb');
                    if ($fileHandler) {
                        fclose($fileHandler);
                        osc_add_flash_ok_message(_m('Maintenance mode is ON'), 'admin');
                    } else {
                        osc_add_flash_error_message(
                            _m('There was an error creating the .maintenance file, please create it manually at the root folder'),
                            'admin'
                        );
                    }
                    $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=maintenance');
                } elseif ($mode === 'off') {
                    osc_csrf_check();
                    $deleted = @unlink(osc_base_path() . '.maintenance');
                    if ($deleted) {
                        osc_add_flash_ok_message(_m('Maintenance mode is OFF'), 'admin');
                    } else {
                        osc_add_flash_error_message(
                            _m('There was an error removing the .maintenance file, please remove it manually from the root folder'),
                            'admin'
                        );
                    }
                    $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=maintenance');
                } elseif ($mode === 'save') {
                    osc_csrf_check();
                    osc_set_preference(
                        OSC_MAINTENANCE_PREF_LOCKOUT,
                        Params::getParamString('maintenance_lockout') === '1' ? '1' : '0',
                        OSC_MAINTENANCE_PREF_SECTION,
                        'BOOLEAN'
                    );
                    osc_set_preference(
                        OSC_MAINTENANCE_PREF_MESSAGE,
                        osc_sanitize_maintenance_message(Params::getParamString('maintenance_message')),
                        OSC_MAINTENANCE_PREF_SECTION,
                        'STRING'
                    );
                    osc_reset_preferences();
                    osc_add_flash_ok_message(_m('Maintenance settings saved'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=maintenance');
                }
                $this->doView('tools/maintenance.php');
                break;
            case 'cleanup':
                $this->_exportVariableToView('cleanup_history', $this->jobLog(array('cleanup'), 10));
                $this->doView('tools/cleanup.php');
                break;
            case 'jobs':
                $queue = \mindstellar\job\JobQueue::instance();
                \mindstellar\job\JobWorker::registerHandlers();

                $status = Params::getParamString('status');
                if (!in_array($status, array('pending', 'running', 'error'), true)) {
                    $status = '';
                }

                $this->_exportVariableToView('jobs_summary', $queue->summary());
                $this->_exportVariableToView('jobs_status', $status);
                $this->_exportVariableToView('jobs_rows', $queue->page($status ?: null, null, 100));
                $this->_exportVariableToView('jobs_queued_types', $queue->queuedTypes());
                $this->_exportVariableToView('jobs_registered_types', \mindstellar\job\JobRegistry::types());
                $this->_exportVariableToView('jobs_history', $this->jobLog(array(), 20));
                $this->doView('tools/jobs.php');
                break;
            case 'jobs_run':
                if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=tools&action=jobs')) {
                    break;
                }
                osc_csrf_check();
                $ran = osc_job_run(20);
                if ($ran > 0) {
                    osc_add_flash_ok_message(
                        sprintf(_mn('%d job ran.', '%d jobs ran.', $ran), $ran),
                        'admin'
                    );
                } else {
                    osc_add_flash_warning_message(_m('Nothing was waiting to run.'), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=jobs');
                break;
            case 'jobs_retry':
                if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=tools&action=jobs')) {
                    break;
                }
                osc_csrf_check();
                $id = Params::getParamInt('id');
                if ($id > 0) {
                    $done = \mindstellar\job\JobQueue::instance()->retry($id);
                } else {
                    $done = \mindstellar\job\JobQueue::instance()->retryAll() > 0;
                }
                if ($done) {
                    osc_add_flash_ok_message(_m('Queued again. It runs on the next cron tick.'), 'admin');
                } else {
                    osc_add_flash_warning_message(_m('Nothing to queue again.'), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=jobs');
                break;
            case 'jobs_forget':
                if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=tools&action=jobs')) {
                    break;
                }
                osc_csrf_check();
                $id = Params::getParamInt('id');
                if ($id > 0) {
                    $done = \mindstellar\job\JobQueue::instance()->forget($id);
                } else {
                    $done = \mindstellar\job\JobQueue::instance()->forgetAll() > 0;
                }
                if ($done) {
                    osc_add_flash_ok_message(_m('Thrown away.'), 'admin');
                } else {
                    osc_add_flash_warning_message(_m('Nothing to throw away.'), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=jobs');
                break;
            case 'cleanup_post':
                if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=tools&action=cleanup')) {
                    break;
                }
                osc_csrf_check();
                $limit = Params::getParamInt('batch_limit');
                osc_set_preference('batch_limit', $limit > 0 ? $limit : Cleanup::DEFAULT_BATCH, 'osclass', 'INTEGER');
                foreach (Cleanup::RULES as $rule) {
                    osc_set_preference('enabled_' . $rule, Params::getParam('enabled_' . $rule) ? '1' : '0', 'osclass', 'BOOLEAN');
                    $days = Params::getParamInt('days_' . $rule);
                    osc_set_preference('days_' . $rule, $days > 0 ? $days : Cleanup::DEFAULT_DAYS, 'osclass', 'INTEGER');
                }
                osc_set_preference(
                    'item_views_enabled',
                    Params::getParam('item_views_enabled') ? '1' : '0',
                    'osclass',
                    'BOOLEAN'
                );
                osc_set_preference(
                    'count_bot_views',
                    Params::getParam('count_bot_views') ? '1' : '0',
                    'osclass',
                    'BOOLEAN'
                );
                osc_set_preference(
                    'item_stats_retention_days',
                    max(0, Params::getParamInt('item_stats_retention_days')),
                    'osclass',
                    'INTEGER'
                );
                osc_reset_preferences();
                osc_add_flash_ok_message(_m('Cleanup settings saved'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=cleanup');
                break;
            case 'cleanup_run':
                if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=tools&action=cleanup')) {
                    break;
                }
                osc_csrf_check();
                if (\mindstellar\job\CleanupJobs::isRunning()) {
                    osc_add_flash_warning_message(_m('Cleanup is already running in the background.'), 'admin');
                } elseif (\mindstellar\job\CleanupJobs::queue() > 0) {
                    // Start now, so the first batches do not wait for cron. Cron does the rest.
                    \mindstellar\job\JobWorker::run(10);
                    osc_add_flash_ok_message(_m('Cleanup started. It runs in the background until nothing matches.'), 'admin');
                } else {
                    osc_add_flash_warning_message(_m('Nothing matches the enabled rules.'), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=cleanup');
                break;
            case ('logs'):
                // set default iDisplayLength (same cookie behaviour as the listings)
                ListPaging::rememberedLength(20);
                $this->_exportVariableToView('iDisplayLength', Params::getParam('iDisplayLength'));

                if (Params::getParam('sort') == '') {
                    Params::setParam('sort', 'date');
                }
                if (Params::getParam('direction') == '') {
                    Params::setParam('direction', 'desc');
                }

                $page = ListPaging::page();

                $logsDataTable = new LogsDataTable();
                $logsDataTable->table(Params::getParamsAsArray());
                $aData = $logsDataTable->getData();

                if (count($aData['aRows']) == 0 && $page != 1) {
                    $total   = (int) $aData['iTotalDisplayRecords'];
                    $maxPage = (int) ceil($total / (int) $aData['iDisplayLength']);

                    $url = osc_admin_base_url(true) . '?' . Params::getServerParam('QUERY_STRING', false, false);
                    if ($maxPage == 0) {
                        $this->redirectTo(preg_replace('/&iPage=(\d)+/', '&iPage=1', $url));
                    }
                    if ($page > 1) {
                        $this->redirectTo(preg_replace('/&iPage=(\d)+/', '&iPage=' . $maxPage, $url));
                    }
                }

                $this->_exportVariableToView('aData', $aData);
                $this->_exportVariableToView('sections', Log::newInstance()->distinctSections());
                $this->_exportVariableToView('log_enabled', osc_is_admin_log_enabled());
                $this->_exportVariableToView('log_retention_days', osc_admin_log_retention_days());
                $this->doView('tools/logs.php');
                break;
            case ('logs_settings_post'):
                osc_csrf_check();
                $retention = Params::getParamInt('admin_log_retention_days');
                osc_set_preference(
                    'admin_log_enabled',
                    Params::getParam('admin_log_enabled') != '' ? 1 : 0,
                    'osclass',
                    'BOOLEAN'
                );
                osc_set_preference(
                    'admin_log_retention_days',
                    $retention > 0 ? $retention : 0,
                    'osclass',
                    'INTEGER'
                );
                osc_reset_preferences();
                osc_add_flash_ok_message(_m('Activity log settings saved'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=logs');
                break;
            case ('logs_clear'):
                if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=tools&action=logs')) {
                    break;
                }
                osc_csrf_check();
                $removed = Log::newInstance()->clearAll();
                osc_add_flash_ok_message(
                    sprintf(_mn('%d log entry has been removed', '%d log entries have been removed', $removed), $removed),
                    'admin'
                );
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=logs');
                break;
            case 'database':
                $this->databasePage();
                break;
            case 'system_info':
            default:
                $this->doView('tools/system-info.php');
                break;
        }
    }

    /**
     * The Backup and restore page.
     *
     * @return string
     */
    private static function backupUrl(): string
    {
        return osc_admin_base_url(true) . '?page=tools&action=backup';
    }

    /**
     * Tools > Backup and restore. A finished run stays on the page until it is dismissed
     * or the next run starts; a plain visit changes no state.
     *
     * @return void
     */
    private function backupPage(): void
    {
        $store  = BackupStore::site();
        $state  = BackupManager::current();
        $busy   = BackupManager::busy();
        $keep   = '';
        $notice = null;
        $status = (string) ($state['status'] ?? '');
        if ($status === 'done' && $state['kind'] === 'backup' && $state['where'] === 'download') {
            if ($store->path((string) $state['name']) !== null) {
                $keep = (string) $state['name'];
            } else {
                $state = array();
            }
        } elseif ($status === 'done') {
            $when   = BackupJobs::when((string) ($state['source_created'] ?? ''));
            $notice = array('tone' => 'success', 'lines' => array($state['kind'] === 'restore'
                ? ($when !== '' ? sprintf(__('The backup from %s is restored.'), $when) : __('The backup is restored.'))
                : sprintf(
                    __('Backup saved: %1$s, %2$s, %3$s.'),
                    BackupJobs::when(date('c', (int) $state['started'])),
                    BackupJobs::whatWord((string) $state['what']),
                    DatabaseTools::bytes((int) $state['size'])
                )));
        } elseif ($status === 'cancelled') {
            $notice = array('tone' => 'info', 'lines' => array(__('Backup cancelled. Nothing was saved.')));
        }
        if ($notice !== null) {
            $notice['lines'][] = self::skippedLine($state);
        }
        if (!$busy && is_dir($store->dir())) {
            $store->sweep(false, $keep);
        }

        $list    = $store->all();
        $confirm = null;
        $name    = Params::getParamString('confirm', false, false);
        if ($name !== '' && !$busy) {
            $check = BackupManager::check($name);
            if ($check['reason'] !== '') {
                osc_add_flash_error_message(osc_esc_html($check['reason']), 'admin');
            } else {
                $confirm = array('name' => $name) + $check;
            }
        }

        $this->_exportVariableToView('backup_state', $state);
        $this->_exportVariableToView('backup_notice', $notice);
        $this->_exportVariableToView('backup_skipped', $keep !== '' ? self::skippedLine($state) : '');
        $this->_exportVariableToView('backup_busy', $busy);
        $this->_exportVariableToView('backup_list', $list);
        $this->_exportVariableToView('backup_confirm', $confirm);
        $this->_exportVariableToView('backup_probe', $list !== array() ? BackupManager::probe() : null);
        $this->doView('tools/backup.php');
    }

    /**
     * The linked folders a backup skipped because they point outside oc-content, in
     * words; '' when there were none.
     *
     * @param array<string,mixed> $state
     *
     * @return string
     */
    private static function skippedLine(array $state): string
    {
        $skipped = (array) ($state['skipped'] ?? array());
        if ($skipped === array()) {
            return '';
        }

        return sprintf(
            _n(
                '%1$d linked folder was skipped: %2$s (it points outside oc-content).',
                '%1$d linked folders were skipped: %2$s (they point outside oc-content).',
                count($skipped)
            ),
            count($skipped),
            implode(', ', array_map('strval', $skipped))
        );
    }

    /**
     * Start a backup from the form, or from an old Tools URL that made one.
     *
     * @return void
     */
    private function backupStart(): void
    {
        if ($this->refuseOnDemo(self::backupUrl())) {
            return;
        }
        osc_csrf_check();
        $aliases = array(
            'backup-sql'      => array('database', 'server'),
            'backup-sql_file' => array('database', 'download'),
            'backup-zip'      => array('files', 'server'),
            'backup-zip_file' => array('files', 'download'),
        );
        list($what, $where) = $aliases[$this->action] ?? array(
            (string) Params::getParamEnum('what', BackupManager::WHAT, ''),
            (string) Params::getParamEnum('where', BackupManager::WHERE, ''),
        );
        $error = BackupManager::startBackup($what, $where);
        if ($error !== '') {
            osc_add_flash_error_message(osc_esc_html($error), 'admin');
        }
        $this->redirectTo(self::backupUrl());
    }

    /**
     * Send a listed backup, or a finished download, and end the request. Only a name from
     * the folder is accepted, never a path.
     *
     * @return void
     */
    private function backupDownload(): void
    {
        if ($this->refuseOnDemo(self::backupUrl())) {
            return;
        }
        osc_csrf_check();
        $store    = BackupStore::site();
        $name     = Params::getParamString('name', false, false);
        $path     = preg_match(BackupStore::NAME, $name) ? $store->path($name) : null;
        $manifest = $path !== null ? $store->manifest($name) : null;
        if ($manifest === null) {
            osc_add_flash_error_message(_m('That backup is not in the list any more.'), 'admin');
            $this->redirectTo(self::backupUrl());

            return;
        }
        if (($manifest['kind'] ?? '') === 'download') {
            // A download is not kept once it has been fetched.
            register_shutdown_function(static function () use ($store, $name): void {
                $store->delete($name);
                $state = $store->state();
                if (($state['name'] ?? '') === $name) {
                    $store->clearState();
                }
            });
        }
        $this->sendFile($path, $name);
    }

    /**
     * Take an uploaded .zip or .sql, check it, and show the restore confirm for it.
     *
     * @param string $field the file field
     *
     * @return void
     */
    private function backupUpload(string $field): void
    {
        $back = self::backupUrl() . '#restore';
        if ($this->refuseOnDemo($back)) {
            return;
        }
        $file = Params::getFiles($field);
        // Over post_max_size PHP drops the whole body, token included, so say why first.
        if ($file === array() && Params::getServerParam('REQUEST_METHOD') === 'POST'
            && (int) Params::getServerParam('CONTENT_LENGTH') > DatabaseTools::uploadLimit()
        ) {
            osc_add_flash_error_message(DatabaseTools::tooLargeMessage(), 'admin');
            $this->redirectTo($back);

            return;
        }
        osc_csrf_check();
        $error = DatabaseTools::uploadError($file);
        if ($error === '' && !is_uploaded_file($file['tmp_name'])) {
            $error = _m('No file was uploaded');
        }
        $ext = $error === '' ? self::backupType($file['tmp_name']) : '';
        if ($error === '' && $ext === '') {
            $error = _m('Choose a .zip or .sql backup file.');
        }
        $store = BackupStore::site();
        if ($error === '' && !$store->protect()) {
            $error = sprintf(_m('The backup folder cannot be written: %s'), BackupStore::FOLDER);
        }
        $name = BackupStore::uploadName($ext);
        if ($error === '' && !move_uploaded_file($file['tmp_name'], $store->dir() . $name)) {
            $error = _m('The upload failed. Try again.');
        }
        if ($error !== '') {
            osc_add_flash_error_message(osc_esc_html($error), 'admin');
            $this->redirectTo($back);

            return;
        }
        @chmod($store->dir() . $name, 0600);
        $check = BackupManager::check($name);
        if ($check['reason'] !== '') {
            @unlink($store->dir() . $name);
            osc_add_flash_error_message(osc_esc_html($check['reason']), 'admin');
            $this->redirectTo($back);

            return;
        }
        $this->redirectTo(self::backupUrl() . '&confirm=' . rawurlencode($name));
    }

    /**
     * zip or sql, read from the file's first bytes; '' for anything else.
     *
     * @param string $file
     *
     * @return string
     */
    private static function backupType(string $file): string
    {
        $head = (string) @file_get_contents($file, false, null, 0, 8192);
        if (strncmp($head, "PK\x03\x04", 4) === 0) {
            return 'zip';
        }
        if ($head === '' || strpos($head, "\0") !== false) {
            return '';
        }
        if (function_exists('finfo_open')) {
            $mime = (string) finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $head);
            if (strpos($mime, 'text/') !== 0 && $mime !== 'application/sql') {
                return '';
            }
        }

        return 'sql';
    }

    /**
     * Send a file as a download and end the request. Output buffers are dropped first so
     * a large file streams instead of filling memory.
     *
     * @param string $file
     * @param string $name the name the browser saves it under
     *
     * @return void
     */
    private function sendFile(string $file, string $name): void
    {
        while (ob_get_level() > 0 && @ob_end_clean()) {
        }
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: no-store');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    /**
     * Tools > Database: status, waiting updates, check and repair, and backup. The update
     * and Repair post back here so their result renders in place.
     *
     * @return void
     */
    private function databasePage(): void
    {
        $self    = osc_admin_base_url(true) . '?page=tools&action=database';
        $conn    = \mindstellar\database\Connection::instance();
        $dir     = osc_lib_path() . 'osclass/installer/migrations';
        $pending = DatabaseTools::pending($conn, $dir);
        $isPost  = Params::getServerParam('REQUEST_METHOD') === 'POST';

        if ($isPost && Params::getParam('upgrade') !== '') {
            if ($this->refuseOnDemo($self)) {
                return;
            }
            osc_csrf_check();
            $this->_exportVariableToView('db_upgrade', DatabaseTools::upgrade());
            osc_reset_preferences();
            $pending = DatabaseTools::pending($conn, $dir);
        }

        list($findings, $error) = $this->schemaFindings();

        if ($isPost && Params::getParam('repair') !== '') {
            if ($this->refuseOnDemo($self)) {
                return;
            }
            osc_csrf_check();
            if ($pending !== array()) {
                osc_add_flash_error_message(
                    _m('An update is waiting. Run it first: it fixes most of these safely.'),
                    'admin'
                );
                $this->redirectTo($self);

                return;
            }
            if ($error === '' && !DatabaseTools::repairAllowed($findings, $pending)) {
                osc_add_flash_info_message(_m('Nothing needs repairing.'), 'admin');
                $this->redirectTo($self);

                return;
            }
            try {
                $release = DatabaseTools::upgradeLock($conn);
            } catch (\mindstellar\database\DbException $e) {
                $release = null;
            }
            if ($release === null) {
                osc_add_flash_error_message(_m('An upgrade is running. Try again when it has finished.'), 'admin');
                $this->redirectTo($self);

                return;
            }
            try {
                $repair = (new \mindstellar\database\SchemaReconciler($conn))->repair();
            } catch (Throwable $e) {
                $repair = array('ran' => array(), 'failed' => array($e->getMessage()));
            } finally {
                $release();
            }
            $this->_exportVariableToView('db_repair', $repair);
            list($findings, $error) = $this->schemaFindings();
        }

        $this->_exportVariableToView('db_pending', $pending);
        $this->_exportVariableToView('db_findings', $findings);
        $this->_exportVariableToView('db_findings_error', $error);
        $this->_exportVariableToView('db_size', DatabaseTools::size($conn, DB_TABLE_PREFIX));
        try {
            $server = $conn->serverInfo();
        } catch (Throwable $e) {
            $server = '';
        }
        $this->_exportVariableToView('db_server', $server);
        $this->doView('tools/database.php');
    }

    /**
     * The SchemaDoctor findings, and the error that stopped them ('' when none).
     *
     * @return array{0:array<int,array<string,string>>,1:string}
     */
    private function schemaFindings(): array
    {
        try {
            return array(
                (new \mindstellar\database\SchemaDoctor(\mindstellar\database\Connection::instance()))->diagnose(),
                '',
            );
        } catch (Throwable $e) {
            return array(array(), $e->getMessage());
        }
    }

    /**
     * The latest background-job rows from the activity log, newest first.
     *
     * @param array<int,string> $actions narrow to these actions; empty for all
     * @param int               $limit
     *
     * @return array<int,array<string,string>>
     */
    private function jobLog(array $actions, int $limit): array
    {
        try {
            $query = osc_db_table(DB_TABLE_PREFIX . 't_log')
                ->select('dt_date', 's_action', 'fk_i_id', 's_data')
                ->where('s_section', 'jobs');
            if ($actions !== array()) {
                $query = $query->whereIn('s_action', $actions);
            }

            return $query->orderBy('dt_date', 'DESC')->limit($limit)->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }
    }

    //hopefully generic...
}

/* file end: ./oc-admin/CAdminTools.php */
