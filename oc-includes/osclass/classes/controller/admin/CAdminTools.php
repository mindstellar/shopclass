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
use mindstellar\admin\AdminNoticeFeed;
use mindstellar\admin\AdminNoticeStore;
use mindstellar\admin\DatabaseTools;
use mindstellar\admin\form\CleanupSettingsScreen;
use mindstellar\admin\form\CoreSettings;
use mindstellar\admin\form\LogSettingsScreen;
use mindstellar\admin\form\MaintenanceSettingsScreen;
use mindstellar\admin\ListPaging;
use mindstellar\admin\SystemChecks;
use mindstellar\admin\SystemEnvironment;
use mindstellar\backup\BackupBucket;
use mindstellar\backup\BackupJobs;
use mindstellar\backup\BackupService;
use mindstellar\backup\BackupStore;
use mindstellar\backup\RestoreUpload;
use mindstellar\logger\LogStore;
use mindstellar\security\AdminReauth;
use mindstellar\security\Demo;
use mindstellar\utility\AjaxResponse;
use mindstellar\utility\Formatting;

class CAdminTools extends AdminSecBaseModel
{
    use \mindstellar\base\ActionMap;

    /** Each action and the method that answers it; any other action goes to systemInfo(). */
    private const ACTIONS = array(
        'import'             => 'importMoved',
        'backup_upload'      => 'uploadBackup',
        'category'           => 'categoryMoved',
        'category_post'      => 'recountCategories',
        'locations'          => 'locationsPage',
        'locations_post'     => 'recountLocations',
        'upgrade'            => 'upgradePage',
        'version'            => 'versionPage',
        'cache'              => 'movedToSystemInfo',
        'jobs'               => 'movedToSystemInfo',
        'cache_clear'        => 'clearCache',
        'backup'             => 'backupScreen',
        'backup_start'       => 'startBackup',
        'backup_cancel'      => 'cancelBackup',
        'backup_download'    => 'downloadBackup',
        'backup_delete'      => 'deleteBackup',
        'backup_restore'     => 'restoreBackup',
        'backup_dismiss'     => 'dismissBackup',
        'backup_reopen'      => 'reopenBackup',
        'maintenance'        => 'maintenance',
        'cleanup'            => 'cleanupPage',
        'jobs_run'           => 'runJobs',
        'jobs_retry'         => 'retryJob',
        'jobs_forget'        => 'forgetJob',
        'cleanup_post'       => 'cleanupSave',
        'cleanup_run'        => 'runCleanup',
        'logs'               => 'logsPage',
        'logs_settings_post' => 'logSettingsSave',
        'logs_clear'         => 'clearLogs',
        'database'           => 'databaseMoved',
        'system-info'        => 'systemInfo',
    );

    /** Old Tools actions whose screen is now a part of another page, with where they land. */
    public const MOVED = array(
        'import'   => 'backup#restore',
        'database' => 'system-info&tab=database',
        'jobs'     => 'system-info&tab=jobs',
        'cache'    => 'system-info&tab=cache',
    );

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

        $method = $this->actionMethod('systemInfo');

        $this->$method();
    }

    /**
     * The old restore page, now part of Backup and restore.
     */
    private function importMoved(): void
    {
        // Restoring a backup is now a part of Tools > Backup and restore; old links land there.
        $this->redirectTo(osc_admin_base_url(true) . self::movedTo('import'));
    }

    /**
     * Restore from an uploaded backup file.
     */
    private function uploadBackup(): void
    {
        $this->backupUpload('backup_file');
    }

    /**
     * The old category recount page, now on the Categories screen.
     */
    private function categoryMoved(): void
    {
        // The recount lives on the Categories screen; the old page URL still lands there.
        $this->redirectTo(osc_admin_base_url(true) . '?page=categories');
    }

    /**
     * Count the listings in every category again.
     */
    private function recountCategories(): void
    {
        osc_csrf_check();
        if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=categories')) {
            return;
        }
        osc_update_cat_stats();
        osc_add_flash_ok_message(_m('Recount category stats has been successful'), 'admin');
        $this->redirectTo(osc_admin_base_url(true) . '?page=categories');
    }

    /**
     * The location recount page.
     */
    private function locationsPage(): void
    {
        $this->doView('tools/locations.php');
    }

    /**
     * Count the listings in every place again, a batch at a time.
     */
    private function recountLocations(): void
    {
        // Also posted from the Locations Data tab, which asks to come back there.
        $fromLocations = Params::getParamString('return') === 'locations';
        $isXhr         = $this->isXhrRequest();
        $back          = $fromLocations
            ? osc_admin_base_url(true) . '?page=settings&action=locations&tab=data'
            : osc_admin_base_url(true) . '?page=tools&action=locations';
        if ($isXhr && !defined('IS_AJAX')) {
            define('IS_AJAX', true);
        }
        osc_csrf_check();
        if (Demo::active()) {
            if ($isXhr) {
                AjaxResponse::json(array('error' => Demo::message()));
                exit;
            }
            osc_add_flash_warning_message(Demo::message(), 'admin');
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
    }

    /**
     * The core upgrade page; a confirmed POST starts the upgrade.
     */
    private function upgradePage(): void
    {
        if ($this->refuseOnDemo(osc_admin_base_url(true))) {
            return;
        }
        // Only the confirm dialog's POST starts the run; a link with confirm=true just shows the page.
        $start = Params::getServerParam('REQUEST_METHOD') === 'POST' && Params::getParamString('confirm') === 'true';
        if ($start) {
            osc_csrf_check();
        }
        $this->_exportVariableToView('upgrade_start', $start);
        $this->doView('tools/upgrade.php');
    }

    /**
     * The version and changelog page.
     */
    private function versionPage(): void
    {
        $this->doView('tools/version.php');
    }

    /**
     * The old cache and jobs pages, now tabs of System info.
     */
    private function movedToSystemInfo(): void
    {
        // These pages are now tabs of System info.
        $this->redirectTo(osc_admin_base_url(true) . self::movedTo($this->action));
    }

    /**
     * Clear the object cache.
     */
    private function clearCache(): void
    {
        if ($this->refuseOnDemo(self::cacheUrl())) {
            return;
        }
        osc_csrf_check();
        if (osc_cache_flush()) {
            osc_add_flash_ok_message(_m('The cache has been cleared'), 'admin');
        } else {
            osc_add_flash_error_message(_m('The cache could not be cleared'), 'admin');
        }
        $this->redirectTo(self::cacheUrl());
    }

    /**
     * The Backup and restore screen.
     */
    private function backupScreen(): void
    {
        $this->backupPage();
    }

    /**
     * Start a backup.
     */
    private function startBackup(): void
    {
        $this->backupStart();
    }

    /**
     * Cancel the running backup.
     */
    private function cancelBackup(): void
    {
        if ($this->refuseOnDemo(self::backupUrl())) {
            return;
        }
        osc_csrf_check();
        BackupService::cancel();
        $this->redirectTo(self::backupUrl());
    }

    /**
     * Download a backup file.
     */
    private function downloadBackup(): void
    {
        $this->backupDownload();
    }

    /**
     * Delete a backup file.
     */
    private function deleteBackup(): void
    {
        if ($this->refuseOnDemo(self::backupUrl())) {
            return;
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
    }

    /**
     * Restore a backup, after asking to confirm.
     */
    private function restoreBackup(): void
    {
        if ($this->refuseOnDemo(self::backupUrl())) {
            return;
        }
        osc_csrf_check();
        if ($this->refuseRestoreOff()) {
            return;
        }
        $name       = Params::getParamString('name', false, false);
        $fromBucket = Params::getParamString('from') === 'bucket';
        $admin  = Admin::getInstance()->findByPrimaryKey(osc_logged_admin_id());
        $reauth = is_array($admin) ? AdminReauth::verify(
            $admin,
            Params::getParamString('password', false, false),
            Params::getParamString('code')
        ) : _m("You don't have enough permissions");
        if ($reauth !== '') {
            Session::getInstance()->_set('backupReauthError', $reauth);
            $this->redirectTo(self::backupUrl() . '&confirm=' . rawurlencode($name) . ($fromBucket ? '&from=bucket' : ''));
            return;
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
    }

    /**
     * Hide the last backup's result.
     */
    private function dismissBackup(): void
    {
        osc_csrf_check();
        BackupService::dismiss();
        $this->redirectTo(self::backupUrl());
    }

    /**
     * Open the site again after a restore that was left closed.
     */
    private function reopenBackup(): void
    {
        if ($this->refuseOnDemo(self::backupUrl())) {
            return;
        }
        osc_csrf_check();
        if (BackupService::reopen()) {
            osc_add_flash_ok_message(_m('The site is open again. Check the database below.'), 'admin');
            $this->redirectTo(self::databaseUrl());
            return;
        }
        $this->redirectTo(self::backupUrl());
    }

    /**
     * Turn maintenance mode on or off.
     */
    private function maintenance(): void
    {
        if (Demo::active()) {
            osc_add_flash_warning_message(Demo::message(), 'admin');
            $this->_exportVariableToView('maintenance_form', MaintenanceSettingsScreen::formVars());
            $this->doView('tools/maintenance.php');
            return;
        }
        $mode = Params::getParam('mode');
        $form = null;
        if ($mode === 'on' || $mode === 'off') {
            AdminNoticeFeed::forget();
        }
        if ($mode === 'on') {
            osc_csrf_check();
            if (osc_maintenance_set(true)) {
                osc_add_flash_ok_message(_m('Maintenance mode is ON'), 'admin');
            } else {
                osc_add_flash_error_message(
                    sprintf(_m('The %s file could not be created. Create it yourself in the site root folder.'), '.maintenance'),
                    'admin'
                );
            }
            $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=maintenance');
        } elseif ($mode === 'off') {
            osc_csrf_check();
            if (osc_maintenance_set(false)) {
                osc_add_flash_ok_message(_m('Maintenance mode is OFF'), 'admin');
            } else {
                osc_add_flash_error_message(
                    sprintf(_m('The %s file could not be deleted. Delete it yourself from the site root folder.'), '.maintenance'),
                    'admin'
                );
            }
            $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=maintenance');
        } elseif ($mode === 'save') {
            osc_csrf_check();
            $result = CoreSettings::attempt(MaintenanceSettingsScreen::register());
            if ($result['errors'] === array()) {
                osc_purge_page_cache('maintenance');
                osc_add_flash_ok_message(_m('Maintenance settings saved'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=maintenance');
            }
            $form = $result['values'];
        }
        $this->_exportVariableToView('maintenance_form', MaintenanceSettingsScreen::formVars($form));
        $this->doView('tools/maintenance.php');
    }

    /**
     * The cleanup page.
     */
    private function cleanupPage(): void
    {
        $this->drawCleanup();
    }

    /**
     * Run the waiting background jobs now.
     */
    private function runJobs(): void
    {
        if ($this->refuseOnDemo(self::jobsUrl())) {
            return;
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
    }

    /**
     * Run a failed background job again.
     */
    private function retryJob(): void
    {
        if ($this->refuseOnDemo(self::jobsUrl())) {
            return;
        }
        osc_csrf_check();
        $id = Params::getParamInt('id');
        if ($id > 0) {
            $done = \mindstellar\job\JobQueue::getInstance()->retry($id);
        } else {
            $done = \mindstellar\job\JobQueue::getInstance()->retryAll() > 0;
        }
        AdminNoticeFeed::forget();
        if ($done) {
            osc_add_flash_ok_message(_m('Queued again. It runs on the next cron tick.'), 'admin');
        } else {
            osc_add_flash_warning_message(_m('Nothing to queue again.'), 'admin');
        }
        $this->redirectTo(self::jobsUrl());
    }

    /**
     * Remove a failed background job.
     */
    private function forgetJob(): void
    {
        if ($this->refuseOnDemo(self::jobsUrl())) {
            return;
        }
        osc_csrf_check();
        $id = Params::getParamInt('id');
        if ($id > 0) {
            $done = \mindstellar\job\JobQueue::getInstance()->forget($id);
        } else {
            $done = \mindstellar\job\JobQueue::getInstance()->forgetAll() > 0;
        }
        AdminNoticeFeed::forget();
        if ($done) {
            osc_add_flash_ok_message(_mn('Failed job removed.', 'Failed jobs removed.', $id > 0 ? 1 : 2), 'admin');
        } else {
            osc_add_flash_warning_message(_m('Nothing to throw away.'), 'admin');
        }
        $this->redirectTo(self::jobsUrl());
    }

    /**
     * Save the cleanup settings.
     */
    private function cleanupSave(): void
    {
        if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=tools&action=cleanup')) {
            return;
        }
        osc_csrf_check();
        $result = CoreSettings::attempt(CleanupSettingsScreen::register());
        if ($result['errors'] !== array()) {
            $this->drawCleanup($result['values']);
            return;
        }
        osc_add_flash_ok_message(_m('Cleanup settings saved'), 'admin');
        $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=cleanup');
    }

    /**
     * Start the cleanup now, in the background.
     */
    private function runCleanup(): void
    {
        if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=tools&action=cleanup')) {
            return;
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
    }

    /**
     * The activity log.
     */
    private function logsPage(): void
    {
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

        $pastEnd = ListPaging::pastEnd($aData, (int) $page);
        if ($pastEnd !== null) {
            $this->redirectTo($pastEnd);
        }

        $this->_exportVariableToView('aData', $aData);
        $this->_exportVariableToView('sections', Log::getInstance()->distinctSections());
        $this->_exportVariableToView('log_form', LogSettingsScreen::formVars());
        $this->doView('tools/logs.php');
    }

    /**
     * Save the activity log settings.
     */
    private function logSettingsSave(): void
    {
        osc_csrf_check();
        if (CoreSettings::attempt(LogSettingsScreen::register())['errors'] === array()) {
            osc_add_flash_ok_message(_m('Activity log settings saved'), 'admin');
        }
        $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=logs');
    }

    /**
     * Clear the activity log.
     */
    private function clearLogs(): void
    {
        if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=tools&action=logs')) {
            return;
        }
        osc_csrf_check();
        try {
            $removed = LogStore::clearAll();
            osc_add_flash_ok_message(
                sprintf(_mn('%d log entry has been removed', '%d log entries have been removed', $removed), $removed),
                'admin'
            );
        } catch (\mindstellar\database\DbException $e) {
            osc_add_flash_error_message(_m('The log could not be cleared'), 'admin');
        }
        $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=logs');
    }

    /**
     * The old database page, now a tab of System info.
     */
    private function databaseMoved(): void
    {
        // The Database page is now a tab of System info.
        $this->redirectTo(self::databaseUrl());
    }

    /**
     * The System info screen.
     */
    private function systemInfo(): void
    {
        $this->systemInfoPage();
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
     * The Cleanup screen, with the values a rejected save hands back, or the stored ones.
     *
     * @param array<string,mixed>|null $values
     */
    private function drawCleanup(?array $values = null): void
    {
        $this->_exportVariableToView('cleanup_history', $this->jobLog(array('cleanup'), 10));
        $this->_exportVariableToView('cleanup_form', CleanupSettingsScreen::formVars($values));
        $this->doView('tools/cleanup.php');
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
                    Formatting::bytes((int) $state['size'])
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
        $reauth     = (string) Session::getInstance()->_get('backupReauthError');
        Session::getInstance()->_drop('backupReauthError');
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
        $me = $confirm !== null ? Admin::getInstance()->findByPrimaryKey(osc_logged_admin_id()) : null;
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
     * Start a backup from the form.
     *
     * @return void
     */
    private function backupStart(): void
    {
        if ($this->refuseOnDemo(self::backupUrl())) {
            return;
        }
        osc_csrf_check();
        $error = BackupService::startBackup(
            (string) Params::getParamEnum('what', BackupService::WHAT, ''),
            (string) Params::getParamEnum('where', BackupService::WHERE, '')
        );
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
            && (int) Params::getServerParam('CONTENT_LENGTH') > RestoreUpload::limit()
        ) {
            osc_add_flash_error_message(RestoreUpload::tooLargeMessage(), 'admin');
            $this->redirectTo($back);

            return;
        }
        osc_csrf_check();
        ['name' => $name, 'error' => $error] = RestoreUpload::store($file);
        if ($error !== '') {
            osc_add_flash_error_message(osc_esc_html($error), 'admin');
            $this->redirectTo($back);

            return;
        }
        $this->redirectTo(self::backupUrl() . '&confirm=' . rawurlencode($name));
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
     * Where an old Tools URL now points, as a query string for the admin index, or null
     * when the action still has its own screen.
     *
     * @param string $action
     *
     * @return string|null
     */
    public static function movedTo(string $action): ?string
    {
        if (!isset(self::MOVED[$action])) {
            return null;
        }
        list($target, $fragment) = explode('#', self::MOVED[$action]) + array(1 => '');

        return '?page=tools&action=' . $target . ($fragment !== '' ? '#' . $fragment : '');
    }

    /**
     * System info > Jobs.
     *
     * @return string
     */
    private static function jobsUrl(): string
    {
        return osc_admin_base_url(true) . self::movedTo('jobs');
    }

    /**
     * System info > Cache.
     *
     * @return string
     */
    private static function cacheUrl(): string
    {
        return osc_admin_base_url(true) . self::movedTo('cache');
    }

    /**
     * System info > Database.
     *
     * @return string
     */
    private static function databaseUrl(): string
    {
        return osc_admin_base_url(true) . self::movedTo('database');
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
            $queue = \mindstellar\job\JobQueue::getInstance();
            $this->_exportVariableToView('jobs_failed', $queue->page(\mindstellar\job\JobQueue::STATUS_ERROR, null, 50));
            $this->_exportVariableToView('jobs_active', array_merge(
                $queue->page(\mindstellar\job\JobQueue::STATUS_RUNNING, null, 50),
                $queue->page(\mindstellar\job\JobQueue::STATUS_PENDING, null, 50)
            ));
            $this->_exportVariableToView('jobs_history', $this->jobLog(array(), 20));
        }

        try {
            (new AdminNoticeFeed())->remember(new AdminNoticeStore(), $env, time());
        } catch (\Throwable $e) {
            error_log('Admin notice checks not remembered: ' . $e->getMessage());
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

        if (Params::getParam('upgrade') !== '') {
            if ($this->refuseOnDemo($self)) {
                return false;
            }
            osc_csrf_check();
            $this->_exportVariableToView('db_upgrade', DatabaseTools::upgrade());
            osc_reset_preferences();
            $env['pending']    = DatabaseTools::pending();
            $env['db_version'] = (string) osc_version();
            list($env['findings'], $env['findings_error']) = DatabaseTools::findings();
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
            $repair = DatabaseTools::repair();
            if ($repair === null) {
                osc_add_flash_error_message(_m('An upgrade is running. Try again when it has finished.'), 'admin');
                $this->redirectTo($self);

                return false;
            }
            $this->_exportVariableToView('db_repair', $repair);
            list($env['findings'], $env['findings_error']) = DatabaseTools::findings();
            $env['db_size'] = DatabaseTools::size();
        }

        if (Params::getParam('facet_rebuild') !== '') {
            if ($this->refuseOnDemo($self)) {
                return false;
            }
            osc_csrf_check();
            $queued = DatabaseTools::facetRebuild($env['pending']);
            if ($queued === 'queued') {
                osc_add_flash_ok_message(_m('Rebuild queued.'), 'admin');
            } elseif ($queued === 'building') {
                osc_add_flash_info_message(_m('Already building.'), 'admin');
            } elseif ($queued === 'update') {
                osc_add_flash_error_message(_m('An update is waiting. Run it first, then rebuild the filter index.'), 'admin');
            } else {
                osc_add_flash_error_message(_m('The rebuild could not be queued.'), 'admin');
            }
            $this->redirectTo($self);

            return false;
        }

        return true;
    }

    /**
     * What the Database tab shows about the listing filter index.
     *
     * @return array<string,mixed>
     */
    private static function facetEnvironment(): array
    {
        $ready = \mindstellar\search\FacetIndex::ready();
        try {
            $missing = $ready ? \mindstellar\search\FacetIndex::missingRows() : null;
        } catch (\Throwable $e) {
            $missing = null;
        }

        return array(
            'facet_ready'     => $ready,
            'facet_built_at'  => (string) osc_get_preference(\mindstellar\search\FacetIndex::PREF_BUILT, 'osclass'),
            'facet_jobs'      => \mindstellar\search\FacetJobs::status(),
            'facet_listings'  => DatabaseTools::listingCount(),
            'facet_auto_cron' => osc_auto_cron(),
            'facet_now'       => time(),
            'facet_missing'   => $missing,
        );
    }

    /**
     * What System info checks, read once.
     *
     * @param bool $withDatabase also read the schema check, which the Server tab skips
     *
     * @return array<string,mixed>
     */
    private function systemEnvironment(bool $withDatabase): array
    {
        $env = SystemEnvironment::read($withDatabase);
        if ($withDatabase) {
            $env += self::facetEnvironment();
        }

        return $env;
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
            return \mindstellar\logger\LogQuery::jobs($actions, $limit);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }
    }

    //hopefully generic...
}

/* file end: ./oc-admin/CAdminTools.php */
