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

$view = View::newInstance();

$summary        = $view->_get('jobs_summary') ?: array('pending' => 0, 'running' => 0, 'error' => 0);
$rows           = $view->_get('jobs_rows') ?: array();
$status         = (string) $view->_get('jobs_status');
$queuedTypes    = $view->_get('jobs_queued_types') ?: array();
$registeredType = $view->_get('jobs_registered_types') ?: array();
$history        = $view->_get('jobs_history') ?: array();

$base = osc_admin_base_url(true) . '?page=tools&action=jobs';

$filters = array(
    ''        => array('label' => __('All'), 'count' => array_sum($summary)),
    'pending' => array('label' => __('Waiting'), 'count' => $summary['pending']),
    'running' => array('label' => __('Running'), 'count' => $summary['running']),
    'error'   => array('label' => __('Gave up'), 'count' => $summary['error']),
);

// Job states use the admin's existing badge vocabulary.
$stateClass = array(
    'pending' => 'pending',
    'running' => 'active',
    'error'   => 'failed',
);

$historyWords = array(
    'run'     => array('active', __('Worker run')),
    'cleanup' => array('active', __('Cleanup')),
    'gave_up' => array('failed', __('Gave up')),
);

// A queued type with no handler is almost always a plugin switched off with work waiting.
$orphans = array_values(array_diff($queuedTypes, $registeredType));

$cronLast = osc_cron_last_run();

$actions = array(
    array(
        'label'   => __('Run waiting jobs now'),
        'icon'    => 'bi-play-fill',
        'variant' => 'primary',
        'type'    => 'submit',
        'attrs'   => array('form' => 'jobs-run-form'),
    ),
);
if ($summary['error'] > 0) {
    $actions[] = array(
        'label' => __('Try all failed again'),
        'icon'  => 'bi-arrow-repeat',
        'type'  => 'submit',
        'attrs' => array('form' => 'jobs-retry-form'),
    );
    $actions[] = array(
        'label'   => __('Throw away all failed'),
        'variant' => 'outline-danger',
        'attrs'   => array('data-osc-dialog-open' => '#jobs-forget-dialog'),
    );
}

osc_admin_page(array(
    'section' => __('Tools'),
    'title'   => __('Background jobs'),
    'help'    => __('Work that is too slow to do while somebody waits — emptying a large '
                    . 'category, moving photos to remote storage, cleanup — is queued here and run by '
                    . 'cron. A job that keeps failing stops retrying and waits for you.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Background jobs'), $actions); ?>

    <?php osc_admin_form_open(array('page' => 'tools', 'action' => 'jobs_run', 'id' => 'jobs-run-form', 'horizontal' => false)); ?>
    <?php osc_admin_form_close(null, array('horizontal' => false)); ?>
    <?php osc_admin_form_open(array('page' => 'tools', 'action' => 'jobs_retry', 'id' => 'jobs-retry-form', 'horizontal' => false)); ?>
    <?php osc_admin_form_close(null, array('horizontal' => false)); ?>

    <p class="text-muted">
        <i class="bi bi-clock-history" aria-hidden="true"></i>
        <?php if ($cronLast > 0) {
            printf(__('Waiting jobs run on every cron tick. Cron last ran %s.'), osc_admin_when(date('Y-m-d H:i:s', $cronLast)));
        } else {
            _e('Waiting jobs run on every cron tick, but cron has not run yet.');
        } ?>
    </p>

    <?php if ($orphans !== array()) { ?>
        <div class="flashmessage flashmessage-warning">
            <p class="mb-0">
                <?php _e('Queued work has no handler, so it cannot run. This usually means a '
                         . 'plugin was deactivated with jobs still waiting:'); ?>
                <code><?php echo osc_esc_html(implode(', ', $orphans)); ?></code>
            </p>
        </div>
    <?php } ?>

    <?php osc_admin_form_section(__('Queue')); ?>
        <ul class="osc-tabnav mb-3">
            <?php foreach ($filters as $key => $filter) { ?>
                <li>
                    <a<?php echo $status === $key ? ' class="is-active"' : ''; ?>
                       href="<?php echo osc_esc_html($base . ($key === '' ? '' : '&status=' . $key)); ?>">
                        <?php echo osc_esc_html($filter['label']); ?>
                        <span class="tab-count">(<?php echo number_format($filter['count']); ?>)</span>
                    </a>
                </li>
            <?php } ?>
        </ul>

        <?php if ($rows === array()) { ?>
            <p class="text-muted mb-0"><?php _e('Nothing is waiting. Finished work is listed under Recent activity.'); ?></p>
        <?php } else { ?>
            <div class="table-responsive">
                <table class="table" style="min-width:48rem">
                    <thead>
                    <tr>
                        <th class="col-status"><?php _e('State'); ?></th>
                        <th><?php _e('Job'); ?></th>
                        <th class="text-end"><?php _e('Tries'); ?></th>
                        <th><?php _e('Next run'); ?></th>
                        <th><?php _e('Last error'); ?></th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row) {
                        $rowStatus = (string) $row['s_status'];
                        $type      = (string) $row['s_type'];
                        $payload   = json_decode((string) ($row['s_payload'] ?? ''), true);
                        $detail    = JobRegistry::detail($type, is_array($payload) ? $payload : array()); ?>
                        <tr>
                            <td class="col-status"><?php osc_admin_status(
                                $stateClass[$rowStatus] ?? 'inactive',
                                $filters[$rowStatus]['label'] ?? $rowStatus
                            ); ?></td>
                            <td>
                                <strong><?php echo osc_esc_html(JobRegistry::name($type)); ?></strong>
                                <?php if ($detail !== '') { ?>
                                    <div><?php echo osc_esc_html($detail); ?></div>
                                <?php } ?>
                                <div class="text-muted small">
                                    #<?php echo (int) $row['pk_i_id']; ?> · <code><?php echo osc_esc_html($type); ?></code>
                                </div>
                            </td>
                            <td class="text-end"><?php echo (int) $row['i_attempts']; ?></td>
                            <td><?php echo $rowStatus === 'pending' ? osc_admin_when($row['dt_next_run'] ?? null) : '<span class="text-muted">&mdash;</span>'; ?></td>
                            <td class="text-muted"><?php echo osc_esc_html((string) ($row['s_last_error'] ?? '')); ?></td>
                            <td class="text-end">
                                <?php if ($rowStatus === 'error') { ?>
                                    <?php osc_admin_form_open(array(
                                        'page'       => 'tools',
                                        'action'     => 'jobs_retry',
                                        'horizontal' => false,
                                        'class'      => 'd-inline',
                                        'fields'     => array('id' => (int) $row['pk_i_id']),
                                    )); ?>
                                        <button type="submit" class="btn btn-sm btn-secondary"><?php _e('Try again'); ?></button>
                                    <?php osc_admin_form_close(null, array('horizontal' => false)); ?>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>

    <?php osc_admin_form_section(__('Recent activity'), array(
        'spaced'     => true,
        'intro_html' => sprintf(
            __('What background work did, newest first. The full history is in the <a href="%s">activity log</a>.'),
            osc_esc_html(osc_admin_base_url(true) . '?page=tools&action=logs&section=jobs')
        ),
    )); ?>
        <?php if ($history === array()) { ?>
            <p class="text-muted mb-0"><?php _e('No background work has run yet.'); ?></p>
        <?php } else { ?>
            <div class="table-responsive">
                <table class="table" style="min-width:40rem">
                    <thead>
                    <tr>
                        <th><?php _e('When'); ?></th>
                        <th class="col-status"><?php _e('What'); ?></th>
                        <th><?php _e('Details'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $entry) {
                        $word = $historyWords[$entry['s_action']] ?? array('inactive', $entry['s_action']); ?>
                        <tr>
                            <td class="text-nowrap"><?php echo osc_admin_when($entry['dt_date']); ?></td>
                            <td class="col-status"><?php osc_admin_status($word[0], $word[1]); ?></td>
                            <td><?php echo osc_esc_html((string) $entry['s_data']); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>

    <?php osc_admin_confirm_dialog(array(
        'id'      => 'jobs-forget-dialog',
        'method'  => 'post',
        'fields'  => array('page' => 'tools', 'action' => 'jobs_forget'),
        'title'   => __('Throw away every failed job?'),
        'text'    => __("The work they were going to do will never happen. This can't be undone."),
        'confirm' => __('Throw them away'),
    )); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
