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
     * Dispatch the requested tools action: SQL import, category and location
     * maintenance, the cache, and the SQL/zip backups.
     *
     * @return void
     */
    public function doModel()
    {
        parent::doModel();

        switch ($this->action) {
            case ('import'):
                // Restoring a backup is now a part of Tools > Database; old links land there.
                $this->redirectTo(osc_admin_base_url(true) . DatabaseTools::movedTo('import'));
                break;
            case ('import_post'):
                $back = osc_admin_base_url(true) . DatabaseTools::movedTo('import');
                if ($this->refuseOnDemo($back)) {
                    break;
                }
                osc_csrf_check();
                $sql    = Params::getFiles('sql');
                $handle = isset($sql['size'], $sql['tmp_name']) && $sql['size'] != 0 && is_uploaded_file($sql['tmp_name'])
                    ? fopen($sql['tmp_name'], 'rb')
                    : false;
                if ($handle !== false) {
                    try {
                        DatabaseTools::restore(\mindstellar\database\Connection::instance(), $handle);
                        osc_calculate_location_slug(osc_subdomain_type());
                        osc_add_flash_ok_message(_m('Import complete'), 'admin');
                    } catch (\mindstellar\database\DbException $e) {
                        osc_add_flash_error_message(_m('There was a problem importing data to the database'), 'admin');
                    }
                    fclose($handle);
                } else {
                    osc_add_flash_warning_message(_m('No file was uploaded'), 'admin');
                }
                if (!empty($sql['tmp_name'])) {
                    @unlink($sql['tmp_name']);
                }
                $this->redirectTo($back);
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
                // The backup screen is now a part of Tools > Database; old links land there.
                $this->redirectTo(osc_admin_base_url(true) . DatabaseTools::movedTo($this->action));
                break;
            case ('backup-sql'):
                if ($this->refuseOnDemo(osc_admin_base_url(true) . DatabaseTools::movedTo('backup'))) {
                    break;
                }
                osc_csrf_check();
                //databasse dump...
                if (Params::getParam('bck_dir') != '') {
                    $path = trim(Params::getParam('bck_dir'));
                    if (substr($path, -1, 1) !== '/') {
                        $path .= '/';
                    }
                } else {
                    $path = osc_base_path();
                }
                $filename = 'Osclass_mysqlbackup.' . date('YmdHis') . '.sql';

                switch (osc_dbdump($path, $filename)) {
                    case (-1):
                        $msg = _m('Path is empty');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (-2):
                        $msg = sprintf(_m('Could not connect with the database'));
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (-3):
                        $msg = _m('There are no tables to back up');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (-4):
                        $msg = _m('The folder is not writable');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    default:
                        $msg = _m('Backup completed successfully');
                        osc_add_flash_ok_message($msg, 'admin');
                        break;
                }
                $this->redirectTo(osc_admin_base_url(true) . DatabaseTools::movedTo('backup'));
                break;
            case ('backup-sql_file'):
                if ($this->refuseOnDemo(osc_admin_base_url(true) . DatabaseTools::movedTo('backup'))) {
                    break;
                }
                osc_csrf_check();
                //databasse dump...

                $filename = 'Osclass_mysqlbackup.' . date('YmdHis') . '.sql';
                $path     = sys_get_temp_dir() . '/';

                // Same codes as the server save; osc_dbdump() returns -4 for an unwritable folder.
                switch (osc_dbdump($path, $filename)) {
                    case (-1):
                        osc_add_flash_error_message(_m('Path is empty'), 'admin');
                        break;
                    case (-2):
                        osc_add_flash_error_message(_m('Could not connect with the database'), 'admin');
                        break;
                    case (-3):
                        osc_add_flash_error_message(_m('There are no tables to back up'), 'admin');
                        break;
                    case (-4):
                        osc_add_flash_error_message(_m('The folder is not writable'), 'admin');
                        break;
                    default:
                        $this->sendFile($path . $filename);
                }
                @unlink($path . $filename);
                $this->redirectTo(osc_admin_base_url(true) . DatabaseTools::movedTo('backup'));
                break;
            case ('backup-zip_file'):
                if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=tools&action=upgrade#backup-files')) {
                    break;
                }
                osc_csrf_check();
                $filename = 'Osclass_backup.' . date('YmdHis') . '.zip';
                $path     = sys_get_temp_dir() . '/';

                if (osc_zip_folder(osc_base_path(), $path . $filename)) {
                    $this->sendFile($path . $filename);
                }

                $msg = _m('Error, the zip file was not created in the specified directory');
                osc_add_flash_error_message($msg, 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=upgrade#backup-files');
                break;
            case ('backup-zip'):
                if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=tools&action=upgrade#backup-files')) {
                    break;
                }
                //zip of the code just to back it up
                osc_csrf_check();
                if (Params::getParam('bck_dir')) {
                    $archive_name = trim(Params::getParam('bck_dir'));
                    if (substr(trim($archive_name), -1, 1) !== '/') {
                        $archive_name .= '/';
                    }
                    $archive_name .= '/Osclass_backup.' . date('YmdHis') . '.zip';
                } else {
                    $archive_name = osc_base_path() . 'Osclass_backup.' . date('YmdHis') . '.zip';
                }
                $archive_folder = osc_base_path();

                if (osc_zip_folder($archive_folder, $archive_name)) {
                    $msg = _m('Archived successfully!');
                    osc_add_flash_ok_message($msg, 'admin');
                } else {
                    $msg = _m('Error, the zip file was not created in the specified directory');
                    osc_add_flash_error_message($msg, 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=upgrade#backup-files');
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
     * Send a backup file as a download, delete it, and end the request. Output buffers are
     * dropped first so a large file streams instead of filling memory.
     *
     * @param string $file
     *
     * @return void
     */
    private function sendFile(string $file): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename=' . basename($file));
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        @unlink($file);
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
            if ($error === '' && DatabaseTools::repairable($findings) === array()) {
                osc_add_flash_info_message(_m('Nothing needs repairing.'), 'admin');
                $this->redirectTo($self);

                return;
            }
            try {
                $repair = (new \mindstellar\database\SchemaReconciler($conn))->repair();
            } catch (Throwable $e) {
                $repair = array('ran' => array(), 'failed' => array($e->getMessage()));
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
