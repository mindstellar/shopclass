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
$view    = View::newInstance();
$failed  = (array) ($view->_get('jobs_failed') ?: array());
$active  = (array) ($view->_get('jobs_active') ?: array());
$history = (array) ($view->_get('jobs_history') ?: array());
$demo    = defined('DEMO');

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
        <section class="sysinfo-group" id="jobs-failed">
            <?php osc_admin_form_section(__('Failed jobs'), array(
                'spaced' => true,
                'intro'  => __('These stopped retrying. Fix the cause the reason names, then try them again.'),
            )); ?>
            <div class="table-responsive">
                <table class="table sysinfo-list">
                    <thead>
                    <tr>
                        <th><?php _e('Job'); ?></th>
                        <th><?php _e('Reason'); ?></th>
                        <th class="text-end"><?php _e('Tries'); ?></th>
                        <th><span class="visually-hidden"><?php _e('Actions'); ?></span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($failed as $row) { ?>
                        <tr>
                            <td class="sysinfo-list-main"><?php $describe($row); ?></td>
                            <td class="sysinfo-list-reason"><?php echo osc_esc_html((string) ($row['s_last_error'] ?? '')); ?></td>
                            <td class="text-end sysinfo-list-meta"><?php printf(
                                osc_esc_html(_n('%d try', '%d tries', (int) $row['i_attempts'])),
                                (int) $row['i_attempts']
                            ); ?></td>
                            <td class="text-end sysinfo-list-actions">
                                <?php osc_admin_form_open(array(
                                    'page'       => 'tools',
                                    'action'     => 'jobs_retry',
                                    'horizontal' => false,
                                    'class'      => 'd-inline',
                                    'fields'     => array('id' => (int) $row['pk_i_id']),
                                )); ?>
                                    <button type="submit" class="btn btn-sm btn-secondary"<?php echo $demo ? ' disabled' : ''; ?>><?php _e('Try again'); ?></button>
                                <?php osc_admin_form_close(null, array('horizontal' => false)); ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
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
        </section>
    <?php } ?>

    <section class="sysinfo-group" id="jobs-queue">
        <?php osc_admin_form_section(__('Waiting and running'), array(
            'spaced' => true,
            'intro'  => __('Slow work, such as emptying a large category or moving photos to a bucket, waits here. Cron runs it on every tick.'),
        )); ?>
        <?php if ($active === array()) { ?>
            <p class="text-muted"><?php _e('Nothing is waiting.'); ?></p>
        <?php } else { ?>
            <div class="table-responsive">
                <table class="table sysinfo-list">
                    <thead>
                    <tr>
                        <th><?php _e('Job'); ?></th>
                        <th><?php _e('State'); ?></th>
                        <th class="text-end"><?php _e('Tries'); ?></th>
                        <th><?php _e('Next run'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($active as $row) {
                        $running = (string) $row['s_status'] === 'running'; ?>
                        <tr>
                            <td class="sysinfo-list-main"><?php $describe($row); ?></td>
                            <td class="sysinfo-list-meta"><?php echo osc_esc_html($running ? __('Running') : __('Waiting')); ?></td>
                            <td class="text-end sysinfo-list-meta"><?php printf(
                                osc_esc_html(_n('%d try', '%d tries', (int) $row['i_attempts'])),
                                (int) $row['i_attempts']
                            ); ?></td>
                            <td class="sysinfo-list-meta"><?php echo $running ? '&mdash;' : osc_admin_when($row['dt_next_run'] ?? null); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
        <div class="sysinfo-bulk">
            <?php osc_admin_action_button(array(
                'label' => __('Run waiting jobs now'),
                'icon'  => 'bi-play-fill',
                'type'  => 'submit',
                'attrs' => array('form' => 'jobs-run-form') + ($demo ? array('disabled' => 'disabled') : array()),
            )); ?>
        </div>
    </section>

    <section class="sysinfo-group" id="jobs-history">
        <?php osc_admin_form_section(__('Recent activity'), array(
            'spaced'     => true,
            'intro_html' => sprintf(
                __('What background work did, newest first. The full history is in the <a href="%s">activity log</a>.'),
                osc_esc_html(osc_admin_base_url(true) . '?page=tools&action=logs&section=jobs')
            ),
        )); ?>
        <?php if ($history === array()) { ?>
            <p class="text-muted"><?php _e('No background work has run yet.'); ?></p>
        <?php } else { ?>
            <div class="table-responsive">
                <table class="table sysinfo-list">
                    <thead>
                    <tr>
                        <th><?php _e('When'); ?></th>
                        <th><?php _e('What'); ?></th>
                        <th><?php _e('Details'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $entry) { ?>
                        <tr>
                            <td class="sysinfo-list-main text-nowrap"><?php echo osc_admin_when($entry['dt_date']); ?></td>
                            <td class="sysinfo-list-meta"><?php echo osc_esc_html($historyWords[$entry['s_action']] ?? ucfirst(str_replace('_', ' ', (string) $entry['s_action']))); ?></td>
                            <td class="sysinfo-list-reason"><?php echo osc_esc_html((string) $entry['s_data']); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    </section>

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
