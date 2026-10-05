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
use mindstellar\admin\SystemChecks;
use mindstellar\backup\BackupBucket;
use mindstellar\backup\BackupJobs;
use mindstellar\backup\BackupService;
use mindstellar\backup\BackupStore;
use mindstellar\security\AdminReauth;
use mindstellar\upgrade\BuildInfo;
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
            // The recount lives on the Categories screen; the old page URL still lands there.
            case ('category'):
                $this->redirectTo(osc_admin_base_url(true) . '?page=categories');
                break;
            case ('category_post'):
                osc_csrf_check();
                if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=categories')) {
                    break;
                }
                osc_update_cat_stats();
                osc_add_flash_ok_message(_m('Recount category stats has been successful'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=categories');
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
                $queued  = \mindstellar\location\LocationRecountJobs::pending();
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
                // Only the confirm dialog's POST starts the run; a link with confirm=true just shows the page.
                $start = Params::getServerParam('REQUEST_METHOD') === 'POST' && Params::getParamString('confirm') === 'true';
                if ($start) {
                    osc_csrf_check();
                }
                $this->_exportVariableToView('upgrade_start', $start);
                $this->doView('tools/upgrade.php');
                break;
            case 'version':
                $this->doView('tools/version.php');
                break;
            case ('cache'):
            case 'jobs':
                // These pages are now tabs of System info.
                $this->redirectTo(osc_admin_base_url(true) . DatabaseTools::movedTo($this->action));
                break;
            case ('cache_clear'):
                if ($this->refuseOnDemo(self::cacheUrl())) {
                    break;
                }
                osc_csrf_check();
                if (osc_cache_flush()) {
                    osc_add_flash_ok_message(_m('The cache has been cleared'), 'admin');
                } else {
                    osc_add_flash_error_message(_m('The cache could not be cleared'), 'admin');
                }
                $this->redirectTo(self::cacheUrl());
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
                BackupService::cancel();
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
                $name   = Params::getParamString('name', false, false);
                $bucket = Params::getParamString('from') === 'bucket' ? BackupBucket::adapter() : false;
                if ($bucket === false ? BackupStore::site()->delete($name) : $bucket !== null && BackupStore::site()->bucketDelete($bucket, $name)) {
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
                if ($this->refuseRestoreOff()) {
                    break;
                }
                $name       = Params::getParamString('name', false, false);
                $fromBucket = Params::getParamString('from') === 'bucket';
                $admin  = Admin::newInstance()->findByPrimaryKey(osc_logged_admin_id());
                $reauth = is_array($admin) ? AdminReauth::verify(
                    $admin,
                    Params::getParamString('password', false, false),
                    Params::getParamString('code')
                ) : _m("You don't have enough permissions");
                if ($reauth !== '') {
                    Session::newInstance()->_set('backupReauthError', $reauth);
                    $this->redirectTo(self::backupUrl() . '&confirm=' . rawurlencode($name) . ($fromBucket ? '&from=bucket' : ''));
                    break;
                }
                $parts  = Params::getParamArray('parts');
                $choose = Params::getParamInt('choose') === 1;
                $error  = BackupService::startRestore(
                    $name,
                    !$choose || in_array('database', $parts, true),
                    !$choose || in_array('files', $parts, true),
                    $fromBucket
                );
                if ($error !== '') {
                    osc_add_flash_error_message(osc_esc_html($error), 'admin');
                }
                $this->redirectTo(self::backupUrl());
                break;
            case ('backup_dismiss'):
                osc_csrf_check();
                BackupService::dismiss();
                $this->redirectTo(self::backupUrl());
                break;
            case ('backup_reopen'):
                if ($this->refuseOnDemo(self::backupUrl())) {
                    break;
                }
                osc_csrf_check();
                if (BackupService::reopen()) {
                    osc_add_flash_ok_message(_m('The site is open again. Check the database below.'), 'admin');
                    $this->redirectTo(self::databaseUrl());
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
                        osc_purge_page_cache('maintenance');
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
                        osc_purge_page_cache('maintenance');
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
                    osc_purge_page_cache('maintenance');
                    osc_add_flash_ok_message(_m('Maintenance settings saved'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=maintenance');
                }
                $this->doView('tools/maintenance.php');
                break;
            case 'cleanup':
                $this->_exportVariableToView('cleanup_history', $this->jobLog(array('cleanup'), 10));
                $this->doView('tools/cleanup.php');
                break;
            case 'jobs_run':
                if ($this->refuseOnDemo(self::jobsUrl())) {
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
                $this->redirectTo(self::jobsUrl());
                break;
            case 'jobs_retry':
                if ($this->refuseOnDemo(self::jobsUrl())) {
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
                $this->redirectTo(self::jobsUrl());
                break;
            case 'jobs_forget':
                if ($this->refuseOnDemo(self::jobsUrl())) {
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
                $this->redirectTo(self::jobsUrl());
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
                // The Database page is now a tab of System info.
                $this->redirectTo(self::databaseUrl());
                break;
            case 'system_info':
            case 'system-info':
            default:
                $this->systemInfoPage();
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
        $state  = BackupService::current();
        $busy   = BackupService::busy();
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

        $list       = $store->all();
        $bucket     = BackupBucket::adapter();
        $bucketRows = $bucket !== null ? $store->bucketAll($bucket) : array();
        if ($bucketRows !== null && $bucketRows !== array()) {
            $list = array_merge($list, $bucketRows);
            usort($list, static function (array $a, array $b): int {
                return strcmp($b['name'], $a['name']);
            });
        }
        $confirm    = null;
        $name       = Params::getParamString('confirm', false, false);
        $fromBucket = $bucket !== null && Params::getParamString('from') === 'bucket';
        $reauth     = (string) Session::newInstance()->_get('backupReauthError');
        Session::newInstance()->_drop('backupReauthError');
        if ($name !== '' && !$busy && !osc_web_restore_disabled()) {
            $check = $fromBucket ? BackupService::checkBucket($name) : BackupService::check($name);
            if ($check['reason'] !== '') {
                osc_add_flash_error_message(osc_esc_html($check['reason']), 'admin');
            } else {
                $confirm = array('name' => $name, 'from' => $fromBucket ? 'bucket' : 'server') + $check;
            }
        }

        $this->_exportVariableToView('backup_state', $state);
        $this->_exportVariableToView('backup_notice', $notice);
        $this->_exportVariableToView('backup_skipped', $keep !== '' ? self::skippedLine($state) : '');
        $this->_exportVariableToView('backup_busy', $busy);
        $this->_exportVariableToView('backup_list', $list);
        $this->_exportVariableToView('backup_bucket', $bucket !== null ? array(
            'label'    => BackupBucket::label(),
            'exposed'  => BackupBucket::exposed(),
            'readable' => $bucketRows !== null,
        ) : null);
        $this->_exportVariableToView('backup_bucket_address', BackupBucket::addressProblem());
        $this->_exportVariableToView('backup_confirm', $confirm);
        $this->_exportVariableToView('backup_reauth_error', $confirm !== null ? $reauth : '');
        $me = $confirm !== null ? Admin::newInstance()->findByPrimaryKey(osc_logged_admin_id()) : null;
        $this->_exportVariableToView('backup_reauth_2fa', is_array($me) && \mindstellar\security\AdminTwoFactor::enabled($me));
        $this->_exportVariableToView('backup_probe', $list !== array() ? BackupService::probe() : null);
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
     * Refuse a restore when OSC_DISABLE_WEB_RESTORE is set. Returns true when it redirected.
     *
     * @return bool
     */
    private function refuseRestoreOff(): bool
    {
        if (!osc_web_restore_disabled()) {
            return false;
        }
        osc_add_flash_error_message(_m('Restore is turned off on this site. Use the command line.'), 'admin');
        $this->redirectTo(self::backupUrl());

        return true;
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
            (string) Params::getParamEnum('what', BackupService::WHAT, ''),
            (string) Params::getParamEnum('where', BackupService::WHERE, ''),
        );
        $error = BackupService::startBackup($what, $where);
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
        if (Params::getParamString('from') === 'bucket') {
            $this->bucketDownload($store, $name);

            return;
        }
        $path     = BackupStore::isName($name) ? $store->path($name) : null;
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
     * Send the browser to a short-lived link for a backup in the bucket. The link is only
     * ever in this redirect, never in a page.
     *
     * @param BackupStore $store
     * @param string      $name
     *
     * @return void
     */
    private function bucketDownload(BackupStore $store, string $name): void
    {
        $bucket = BackupBucket::adapter();
        $link   = $bucket !== null ? $store->bucketLink($bucket, $name) : '';
        if ($link === '') {
            osc_add_flash_error_message(_m('That backup is not in the list any more.'), 'admin');
            $this->redirectTo(self::backupUrl());

            return;
        }
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        $this->redirectTo($link, 303);
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
        if ($this->refuseOnDemo($back) || $this->refuseRestoreOff()) {
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
            $error = BackupStore::unwritable();
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
        $check = BackupService::check($name);
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
     * System info > Jobs.
     *
     * @return string
     */
    private static function jobsUrl(): string
    {
        return osc_admin_base_url(true) . DatabaseTools::movedTo('jobs');
    }

    /**
     * System info > Cache.
     *
     * @return string
     */
    private static function cacheUrl(): string
    {
        return osc_admin_base_url(true) . DatabaseTools::movedTo('cache');
    }

    /**
     * System info > Database.
     *
     * @return string
     */
    private static function databaseUrl(): string
    {
        return osc_admin_base_url(true) . DatabaseTools::movedTo('database');
    }

    /**
     * Tools > System info and its tabs. The database update and Repair post to the
     * Database tab so their result renders in place.
     *
     * @return void
     */
    private function systemInfoPage(): void
    {
        $tab = SystemChecks::tab(Params::getParamString('tab'), Params::getParamString('info-type'));
        $env = $this->systemEnvironment($tab !== 'server');
        if ($tab === 'database' && Params::getServerParam('REQUEST_METHOD') === 'POST') {
            if (!$this->databasePost($env)) {
                return;
            }
        }

        if ($tab === 'overview' || $tab === 'database') {
            // Counting zero dates scans every table with a date column, so only the Database tab does it.
            $env['strict'] = \mindstellar\database\StrictModeReadiness::report(DB_TABLE_PREFIX, $env['now'], $tab === 'database');
        }

        if ($tab === 'jobs') {
            $queue = \mindstellar\job\JobQueue::instance();
            $this->_exportVariableToView('jobs_failed', $queue->page(\mindstellar\job\JobQueue::STATUS_ERROR, null, 50));
            $this->_exportVariableToView('jobs_active', array_merge(
                $queue->page(\mindstellar\job\JobQueue::STATUS_RUNNING, null, 50),
                $queue->page(\mindstellar\job\JobQueue::STATUS_PENDING, null, 50)
            ));
            $this->_exportVariableToView('jobs_history', $this->jobLog(array(), 20));
        }

        $this->_exportVariableToView('sysinfo_tab', $tab);
        $this->_exportVariableToView('sysinfo_env', $env);
        $this->_exportVariableToView('sysinfo_report', SystemChecks::report($tab, $env));
        $this->doView('tools/system-info.php');
    }

    /**
     * Run a posted database update or Repair, and refresh $env with what it changed.
     * Returns false when the request was answered with a redirect.
     *
     * @param array<string,mixed> $env
     *
     * @return bool
     */
    private function databasePost(array &$env): bool
    {
        $self = self::databaseUrl();
        $conn = \mindstellar\database\Connection::instance();
        $dir  = DatabaseTools::migrationsDir();

        if (Params::getParam('upgrade') !== '') {
            if ($this->refuseOnDemo($self)) {
                return false;
            }
            osc_csrf_check();
            $this->_exportVariableToView('db_upgrade', DatabaseTools::upgrade());
            osc_reset_preferences();
            $env['pending']    = DatabaseTools::pending($conn, $dir);
            $env['db_version'] = (string) osc_version();
            list($env['findings'], $env['findings_error']) = $this->schemaFindings();
        }

        if (Params::getParam('repair') !== '') {
            if ($this->refuseOnDemo($self)) {
                return false;
            }
            osc_csrf_check();
            $pending  = $env['pending'];
            $findings = $env['findings'];
            if ($pending !== array()) {
                osc_add_flash_error_message(
                    _m('An update is waiting. Run it first: it fixes most of these safely.'),
                    'admin'
                );
                $this->redirectTo($self);

                return false;
            }
            if ($env['findings_error'] === '' && !DatabaseTools::repairAllowed($findings, $pending)) {
                osc_add_flash_info_message(_m('Nothing needs repairing.'), 'admin');
                $this->redirectTo($self);

                return false;
            }
            try {
                $release = DatabaseTools::upgradeLock($conn);
            } catch (\mindstellar\database\DbException $e) {
                $release = null;
            }
            if ($release === null) {
                osc_add_flash_error_message(_m('An upgrade is running. Try again when it has finished.'), 'admin');
                $this->redirectTo($self);

                return false;
            }
            try {
                $repair = (new \mindstellar\database\SchemaReconciler($conn))->repair();
            } catch (Throwable $e) {
                $repair = array('ran' => array(), 'failed' => array($e->getMessage()));
            } finally {
                $release();
            }
            $this->_exportVariableToView('db_repair', $repair);
            list($env['findings'], $env['findings_error']) = $this->schemaFindings();
            $env['db_size'] = DatabaseTools::size($conn, DB_TABLE_PREFIX);
        }

        return true;
    }

    /**
     * What System info checks, read once. Every read is defensive: this is the page an
     * owner opens when something is already wrong.
     *
     * @param bool $withDatabase also read the schema check, which the Server tab skips
     *
     * @return array<string,mixed>
     */
    private function systemEnvironment(bool $withDatabase): array
    {
        $conn = \mindstellar\database\Connection::instance();
        try {
            $server = $conn->serverInfo();
        } catch (Throwable $e) {
            $server = '';
        }
        $cacheDriver = defined('OSC_CACHE') ? (string) OSC_CACHE : 'default';
        $maintenance = '';
        if (file_exists(ABS_PATH . '.maintenance')) {
            $maintenance = osc_maintenance_lockout_enabled() ? 'locked' : 'banner';
        }
        $uploads = osc_uploads_path();
        $free    = function_exists('disk_free_space') ? @disk_free_space($uploads) : false;
        $queue   = \mindstellar\job\JobQueue::instance();
        \mindstellar\job\JobWorker::registerHandlers();
        $stats    = $queue->stats();
        $signins  = \mindstellar\security\LoginThrottle::activity();
        $drivers  = array();
        foreach (SystemChecks::CACHE_DRIVERS as $name) {
            $drivers[$name] = self::cacheSupported($name);
        }
        $cacheOn  = $cacheDriver === 'default' || self::cacheSupported($cacheDriver);
        $prefs   = Preference::newInstance()->listAll();
        $last    = json_decode((string) osc_get_preference('backup_last'), true);
        $htaccess     = osc_base_path() . '.htaccess';
        $htaccessAuth = osc_rewrite_enabled() && !osc_server_is_nginx() && is_file($htaccess)
            ? \mindstellar\routing\ServerRules::passesAuthorization($htaccess)
            : null;
        try {
            $reservedSlugs = \mindstellar\routing\ReservedSlugs::conflicts();
        } catch (Throwable $e) {
            $reservedSlugs = array();
        }

        $env = array(
            'admin_url'        => osc_admin_base_url(true),
            'now'              => time(),
            'version'          => BuildInfo::label(OSCLASS_VERSION),
            'db_version'       => (string) osc_version(),
            'php'              => PHP_VERSION,
            'os'               => PHP_OS . ' (' . php_uname('m') . ')',
            'web_server'       => (string) Params::getServerParam('SERVER_SOFTWARE'),
            'db_server'        => $server,
            'prefix'           => DB_TABLE_PREFIX,
            'site_url'         => osc_base_url(),
            'content_path'     => osc_content_path(),
            'uploads_path'     => $uploads,
            'plugins_path'     => osc_plugins_path(),
            'themes_path'      => osc_themes_path(),
            'ini_file'         => (string) php_ini_loaded_file(),
            'prefs_count'      => count($prefs),
            'prefs_bytes'      => strlen(serialize($prefs)),
            'memory'           => SystemChecks::iniBytes((string) ini_get('memory_limit')),
            'upload'           => SystemChecks::iniBytes((string) ini_get('upload_max_filesize')),
            'post'             => SystemChecks::iniBytes((string) ini_get('post_max_size')),
            'max_files'        => (int) ini_get('max_file_uploads'),
            'max_exec'         => (int) ini_get('max_execution_time'),
            'max_input_vars'   => (string) ini_get('max_input_vars'),
            'timezone'         => (string) ini_get('date.timezone'),
            'photos'           => (int) osc_max_images_per_item(),
            'extensions'       => get_loaded_extensions(),
            'imagick'          => extension_loaded('imagick'),
            'imagick_on'       => extension_loaded('imagick') && osc_use_imagick(),
            'gd'               => extension_loaded('gd'),
            'opcache'          => function_exists('opcache_get_status') && ini_get('opcache.enable'),
            'allow_url_fopen'  => (bool) ini_get('allow_url_fopen'),
            'uploads_writable' => @is_writable($uploads),
            'php_user'         => \mindstellar\admin\SystemChecks::userName(function_exists('posix_geteuid') ? posix_geteuid() : null),
            'file_owner'       => \mindstellar\admin\SystemChecks::userName(@fileowner(ABS_PATH . 'index.php') ?: null),
            'self_update_off'  => osc_self_update_disabled(),
            'read_only'        => array_keys(array_filter(array(
                'core'      => ABS_PATH . 'oc-includes',
                'downloads' => osc_content_path() . 'downloads',
                'plugins'   => osc_plugins_path(),
                'themes'    => osc_themes_path(),
                'languages' => osc_translations_path(),
            ), static fn ($dir) => is_dir($dir) && !@is_writable($dir))),
            'free_disk'        => is_numeric($free) ? (int) $free : null,
            'config_writable'  => @is_writable(ABS_PATH . 'config.php'),
            'debug'            => defined('OSC_DEBUG') && OSC_DEBUG,
            'maintenance'      => $maintenance,
            'htaccess_auth'    => $htaccessAuth,
            'reserved_slugs'   => $reservedSlugs,
            'cache_driver'     => $cacheDriver,
            'cache_supported'  => $cacheOn,
            'cache_working'    => $cacheDriver !== 'default' && $cacheOn ? self::cacheAnswers() : null,
            'cache_stats'      => $cacheOn ? osc_cache_stats() : null,
            'cache_drivers'    => $drivers,
            'jobs'             => array(
                'pending'   => (int) $stats['pending'],
                'running'   => (int) $stats['running'],
                'error'     => (int) $stats['error'],
                'oldest'    => $stats['oldest'],
                'due'       => (int) $stats['due'],
                'due_since' => $stats['due_since'],
                'orphans'   => array_values(array_diff($queue->queuedTypes(), \mindstellar\job\JobRegistry::types())),
            ) + $queue->health(),
            'me'               => (int) osc_logged_admin_id(),
            'admins'           => \mindstellar\security\AdminTwoFactor::admins(),
            'throttle'         => array(
                'enabled'     => (bool) osc_login_throttle_enabled(),
                'window'      => (int) osc_login_throttle_window(),
                'max_ip'      => (int) osc_login_throttle_max_ip(),
                'max_account' => (int) osc_login_throttle_max_account(),
                'captcha'     => (bool) osc_captcha_enabled(),
                'blocked'     => count(array_filter($signins, static function ($row) {
                    return !empty($row['blocked']);
                })),
            ),
            'signins'          => $signins,
            'web_restore_off'  => (bool) osc_web_restore_disabled(),
            'package_installs_off' => (bool) osc_package_installs_disabled(),
            'cron_last'        => osc_cron_last_run(),
            // This request came through the same proxy every visitor does.
            'proxy'            => osc_proxy_ip_mismatch(),
            'backup_last'      => is_array($last) ? $last : null,
            'backup_probe'     => BackupService::probe(),
            'storage'          => array(
                'active'     => (string) osc_get_preference('storage_active'),
                'bucket'     => (string) osc_get_preference('storage_s3_bucket'),
                'keep_local' => (string) osc_get_preference('storage_keep_local'),
            ),
            'pending'          => array(),
            'findings'         => array(),
            'findings_error'   => '',
            'db_size'          => null,
        );
        if ($withDatabase) {
            $env['pending'] = DatabaseTools::pending($conn, DatabaseTools::migrationsDir());
            list($env['findings'], $env['findings_error']) = $this->schemaFindings();
            $env['db_size'] = DatabaseTools::size($conn, DB_TABLE_PREFIX);
        }

        return $env;
    }

    /**
     * Whether an object-cache driver has a class here and says this server can run it.
     *
     * @param string $driver
     *
     * @return bool
     */
    private static function cacheSupported(string $driver): bool
    {
        $class = 'Object_Cache_' . $driver;

        return class_exists($class) && method_exists($class, 'is_supported') && call_user_func(array($class, 'is_supported'));
    }

    /**
     * Whether the cache keeps a value: write a probe key, read it back, remove it. The
     * write's own answer counts, because a driver may serve the read from its in-request copy.
     *
     * @return bool
     */
    private static function cacheAnswers(): bool
    {
        $cache = \Object_Cache_Factory::newInstance();
        $key   = 'osc_sysinfo_probe';
        $value = bin2hex(random_bytes(8));
        try {
            if ($cache->set($key, $value, 60) === false) {
                return false;
            }
            $found = null;
            $read  = $cache->get($key, $found);
            $cache->delete($key);
        } catch (Throwable $e) {
            return false;
        }

        return $found !== false && $read === $value;
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
