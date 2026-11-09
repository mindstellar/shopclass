<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\job\JobRegistry;

// System info > Jobs, below the verdict and the facts: failed jobs, the queue, and what ran.
$view    = View::getInstance();
$failed  = (array) ($view->_get('jobs_failed') ?: array());
$active  = (array) ($view->_get('jobs_active') ?: array());
$history = (array) ($view->_get('jobs_history') ?: array());
$demo    = \mindstellar\security\Demo::active();

$historyWords = array(
    'run'     => __('Worker run'),
    'cleanup' => __('Cleanup'),
    'gave_up' => __('Gave up'),
    'backup'  => __('Backup'),
);

/** The job's name, what it works on, and its id and type. */
$describe = static function (array $row): void {
    $type    = (string) $row['s_type'];
    $payload = json_decode((string) ($row['s_payload'] ?? ''), true);
    $detail  = JobRegistry::detail($type, is_array($payload) ? $payload : array()); ?>
    <strong><?php echo osc_esc_html(JobRegistry::name($type)); ?></strong>
    <?php if ($detail !== '') { ?>
        <span class="sysinfo-list-sub"><?php echo osc_esc_html($detail); ?></span>
    <?php } ?>
    <span class="sysinfo-list-sub">#<?php echo (int) $row['pk_i_id']; ?> · <code><?php echo osc_esc_html($type); ?></code></span>
    <?php
};
?>
    <?php osc_admin_form_open(array('page' => 'tools', 'action' => 'jobs_run', 'id' => 'jobs-run-form', 'horizontal' => false)); ?>
    <?php osc_admin_form_close(null, array('horizontal' => false)); ?>

    <?php if ($failed !== array()) { ?>
        <?php osc_admin_section(__('Failed jobs'), array(
                'class' => 'sysinfo-group',
                'id'    => 'jobs-failed',
                'intro' => __('These stopped retrying. Fix the cause the reason names, then try them again.'),
            )); ?>
            <?php
            $failedRows = array();
            foreach ($failed as $row) {
                $failedRows[] = array(
                    'job'     => array('render' => static function () use ($describe, $row) {
                        $describe($row);
                    }),
                    'reason'  => (string) ($row['s_last_error'] ?? ''),
                    'tries'   => sprintf(_n('%d try', '%d tries', (int) $row['i_attempts']), (int) $row['i_attempts']),
                    'actions' => array('render' => static function () use ($row, $demo) {
                        osc_admin_form_open(array(
                            'page'       => 'tools',
                            'action'     => 'jobs_retry',
                            'horizontal' => false,
                            'class'      => 'd-inline',
                            'fields'     => array('id' => (int) $row['pk_i_id']),
                        )); ?>
                            <button type="submit" class="btn btn-sm btn-secondary"<?php echo $demo ? ' disabled' : ''; ?>><?php _e('Try again'); ?></button>
                        <?php osc_admin_form_close(null, array('horizontal' => false));
                    }),
                );
            }
            osc_admin_table(array(
                'job'     => array('label' => __('Job'), 'td_class' => 'sysinfo-list-main'),
                'reason'  => array('label' => __('Reason'), 'td_class' => 'sysinfo-list-reason'),
                'tries'   => array('label' => __('Tries'), 'class' => 'text-end', 'td_class' => 'sysinfo-list-meta'),
                'actions' => array('label' => __('Actions'), 'hide_label' => true, 'td_class' => 'text-end sysinfo-list-actions', 'col_name' => false),
            ), $failedRows, array('class' => 'sysinfo-list')); ?>
            <div class="sysinfo-bulk">
                <?php osc_admin_form_open(array('page' => 'tools', 'action' => 'jobs_retry', 'horizontal' => false, 'class' => 'd-inline')); ?>
                    <?php osc_admin_action_button(array(
                        'label' => __('Try all again'),
                        'icon'  => 'bi-arrow-repeat',
                        'type'  => 'submit',
                        'attrs' => $demo ? array('disabled' => 'disabled') : array(),
                    )); ?>
                <?php osc_admin_form_close(null, array('horizontal' => false)); ?>
                <?php osc_admin_action_button(array(
                    'label'   => __('Throw all away…'),
                    'variant' => 'outline-danger',
                    'class'   => 'sysinfo-bulk-danger',
                    'attrs'   => array('data-osc-dialog-open' => '#jobs-forget-dialog') + ($demo ? array('disabled' => 'disabled') : array()),
                )); ?>
            </div>
        <?php osc_admin_section_close(); ?>
    <?php } ?>

    <?php osc_admin_section(__('Waiting and running'), array(
            'class' => 'sysinfo-group',
            'id'    => 'jobs-queue',
            'intro' => __('Slow work, such as emptying a large category or moving photos to a bucket, waits here. Cron runs it on every tick.'),
        )); ?>
        <?php if ($active === array()) { ?>
            <?php osc_admin_empty(array('title' => __('Nothing is waiting.'))); ?>
        <?php } else { ?>
            <?php
            $activeRows = array();
            foreach ($active as $row) {
                $running = (string) $row['s_status'] === 'running';
                $activeRows[] = array(
                    'state' => $running ? __('Running') : __('Waiting'),
                    'job'   => array('render' => static function () use ($describe, $row) {
                        $describe($row);
                    }),
                    'tries' => sprintf(_n('%d try', '%d tries', (int) $row['i_attempts']), (int) $row['i_attempts']),
                    'next'  => array('html' => $running ? '&mdash;' : osc_admin_when($row['dt_next_run'] ?? null)),
                );
            }
            osc_admin_table(array(
                'state' => array('label' => __('State'), 'class' => 'col-status', 'td_class' => 'sysinfo-list-meta'),
                'job'   => array('label' => __('Job'), 'td_class' => 'sysinfo-list-main'),
                'tries' => array('label' => __('Tries'), 'class' => 'text-end', 'td_class' => 'sysinfo-list-meta'),
                'next'  => array('label' => __('Next run'), 'td_class' => 'sysinfo-list-meta'),
            ), $activeRows, array('class' => 'sysinfo-list')); ?>
        <?php } ?>
        <div class="sysinfo-bulk">
            <?php osc_admin_action_button(array(
                'label' => __('Run waiting jobs now'),
                'icon'  => 'bi-play-fill',
                'type'  => 'submit',
                'attrs' => array('form' => 'jobs-run-form') + ($demo ? array('disabled' => 'disabled') : array()),
            )); ?>
        </div>
    <?php osc_admin_section_close(); ?>

    <?php osc_admin_section(__('Recent activity'), array(
            'class'      => 'sysinfo-group',
            'id'         => 'jobs-history',
            'intro_html' => sprintf(
                __('What background work did, newest first. The full history is in the <a href="%s">activity log</a>.'),
                osc_esc_html(osc_admin_base_url(true) . '?page=tools&action=logs&section=jobs')
            ),
        )); ?>
        <?php if ($history === array()) { ?>
            <?php osc_admin_empty(array('title' => __('No background work has run yet.'))); ?>
        <?php } else { ?>
            <?php
            $historyRows = array();
            foreach ($history as $entry) {
                $historyRows[] = array(
                    'when'    => array('html' => osc_admin_when($entry['dt_date'])),
                    'what'    => $historyWords[$entry['s_action']] ?? ucfirst(str_replace('_', ' ', (string) $entry['s_action'])),
                    'details' => (string) $entry['s_data'],
                );
            }
            osc_admin_table(array(
                'when'    => array('label' => __('When'), 'td_class' => 'sysinfo-list-main text-nowrap'),
                'what'    => array('label' => __('What'), 'td_class' => 'sysinfo-list-meta'),
                'details' => array('label' => __('Details'), 'td_class' => 'sysinfo-list-reason'),
            ), $historyRows, array('class' => 'sysinfo-list')); ?>
        <?php } ?>
    <?php osc_admin_section_close(); ?>

    <?php if ($failed !== array()) {
        osc_admin_confirm_dialog(array(
            'id'      => 'jobs-forget-dialog',
            'method'  => 'post',
            'fields'  => array('page' => 'tools', 'action' => 'jobs_forget'),
            'title'   => __('Throw away every failed job?'),
            'text'    => __("The work they were going to do will never happen. This can't be undone."),
            'confirm' => __('Throw them away'),
        ));
    } ?>
