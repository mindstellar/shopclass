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
            case ('import'):         // calling import view
                $this->doView('tools/import.php');
                break;
            case ('import_post'):
                if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=tools&action=import')) {
                    break;
                }
                // calling
                osc_csrf_check();
                $sql = Params::getFiles('sql');
                if (isset($sql['size']) && $sql['size'] != 0) {
                    $content_file = file_get_contents($sql['tmp_name']);

                    try {
                        \mindstellar\database\Connection::instance()
                            ->executeScript($content_file);
                        osc_calculate_location_slug(osc_subdomain_type());
                        osc_add_flash_ok_message(_m('Import complete'), 'admin');
                    } catch (\mindstellar\database\DbException $e) {
                        osc_add_flash_error_message(_m('There was a problem importing data to the database'), 'admin');
                    }
                } else {
                    osc_add_flash_warning_message(_m('No file was uploaded'), 'admin');
                }
                @unlink($sql['tmp_name']);
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=import');
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
                $this->doView('tools/backup.php');
                break;
            case ('backup-sql'):
                if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=tools&action=backup')) {
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
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=backup');
                break;
            case ('backup-sql_file'):
                if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=tools&action=backup')) {
                    break;
                }
                //databasse dump...

                $filename = 'Osclass_mysqlbackup.' . date('YmdHis') . '.sql';
                $path     = sys_get_temp_dir() . '/';

                switch (osc_dbdump($path, $filename)) {
                    case (-1):
                        $msg = _m('Path is empty');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (-2):
                        $msg = sprintf(
                            _m('Could not connect with the database. Error: %s'),
                            \mindstellar\database\Connection::instance()->lastError()
                        );
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (-3):
                        $msg = sprintf(
                            _m('Could not select the database. Error: %s'),
                            \mindstellar\database\Connection::instance()->lastError()
                        );
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (-4):
                        $msg = _m('There are no tables to back up');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (-5):
                        $msg = _m('The folder is not writable');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    default:
                        $msg = _m('Backup completed successfully');
                        osc_add_flash_ok_message($msg, 'admin');
                        header('Content-Description: File Transfer');
                        header('Content-Type: application/octet-stream');
                        header('Content-Disposition: attachment; filename=' . basename($filename));
                        header('Content-Transfer-Encoding: binary');
                        header('Expires: 0');
                        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                        header('Pragma: public');
                        header('Content-Length: ' . filesize($path . $filename));
                        flush();
                        readfile($path . $filename);
                        exit;
                        break;
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=backup');
                break;
            case ('backup-zip_file'):
                if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=tools&action=backup')) {
                    break;
                }
                $filename = 'Osclass_backup.' . date('YmdHis') . '.zip';
                $path     = sys_get_temp_dir() . '/';

                if (osc_zip_folder(osc_base_path(), $path . $filename)) {
                    $msg = _m('Archived successfully!');
                    osc_add_flash_ok_message($msg, 'admin');
                    header('Content-Description: File Transfer');
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename=' . basename($filename));
                    header('Content-Transfer-Encoding: binary');
                    header('Expires: 0');
                    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                    header('Pragma: public');
                    header('Content-Length: ' . filesize($path . $filename));
                    flush();
                    readfile($path . $filename);
                    exit;
                }

                $msg = _m('Error, the zip file was not created in the specified directory');
                osc_add_flash_error_message($msg, 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=backup');
                break;
            case ('backup-zip'):
                if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=tools&action=backup')) {
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
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=backup');
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
                osc_set_preference('batch_limit', $limit > 0 ? $limit : 250, 'osclass', 'INTEGER');
                foreach (Cleanup::RULES as $rule) {
                    osc_set_preference('enabled_' . $rule, Params::getParam('enabled_' . $rule) ? '1' : '0', 'osclass', 'BOOLEAN');
                    if ($rule !== 'reported') {
                        $days = Params::getParamInt('days_' . $rule);
                        osc_set_preference('days_' . $rule, $days > 0 ? $days : 30, 'osclass', 'INTEGER');
                    }
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
                $total = osc_run_cleanup();
                if ($total > 0) {
                    osc_add_flash_ok_message(sprintf(_m('Cleanup removed %d item(s). Run again to clear any remaining backlog.'), $total), 'admin');
                } else {
                    osc_add_flash_warning_message(_m('Cleanup ran, but nothing matched the enabled rules.'), 'admin');
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
            case 'system_info':
            default:
                $this->doView('tools/system-info.php');
                break;
        }
    }

    //hopefully generic...
}

/* file end: ./oc-admin/CAdminTools.php */
