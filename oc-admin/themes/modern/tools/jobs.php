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

/*
 * A queue nobody can see is a support ticket waiting to happen: the work was accepted,
 * nothing visible happened, and the only evidence is a database row. So this screen has
 * one job -- say what is waiting, what is stuck, and why.
 */

$view = View::newInstance();

$summary        = $view->_get('jobs_summary') ?: array('pending' => 0, 'running' => 0, 'error' => 0);
$rows           = $view->_get('jobs_rows') ?: array();
$status         = (string) $view->_get('jobs_status');
$queuedTypes    = $view->_get('jobs_queued_types') ?: array();
$registeredType = $view->_get('jobs_registered_types') ?: array();

$base = osc_admin_base_url(true) . '?page=tools&action=jobs';

$filters = array(
    ''        => array('label' => __('All'), 'count' => array_sum($summary)),
    'pending' => array('label' => __('Waiting'), 'count' => $summary['pending']),
    'running' => array('label' => __('Running'), 'count' => $summary['running']),
    'error'   => array('label' => __('Gave up'), 'count' => $summary['error']),
);

// A type on the queue that nothing registered a handler for. Almost always a plugin
// switched off with its work still queued, and worth naming before the admin goes
// looking for a bug that is not there.
$orphans = array_values(array_diff($queuedTypes, $registeredType));

osc_admin_page(array(
    'section' => __('Tools'),
    'title'   => __('Background jobs'),
    'help'    => __('Work that is too slow to do while somebody waits — emptying a large '
                    . 'category, moving photos to remote storage — is queued here and run by '
                    . 'cron. A job that keeps failing stops retrying and waits for you.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Background jobs')); ?>

    <?php if ($orphans !== array()) { ?>
        <div class="flashmessage flashmessage-warning">
            <p>
                <?php _e('Queued work has no handler, so it cannot run. This usually means a '
                         . 'plugin was deactivated with jobs still waiting.'); ?>
            </p>
            <p class="mb-0"><code><?php echo osc_esc_html(implode(', ', $orphans)); ?></code></p>
        </div>
    <?php } ?>

    <ul class="osc-tabnav mb-3">
        <?php foreach ($filters as $key => $filter) { ?>
            <li>
                <a<?php echo $status === $key ? ' class="is-active"' : ''; ?>
                   href="<?php echo osc_esc_html($base . ($key === '' ? '' : '&status=' . $key)); ?>">
                    <?php echo osc_esc_html($filter['label']); ?>
                    <span class="badge bg-secondary"><?php echo number_format($filter['count']); ?></span>
                </a>
            </li>
        <?php } ?>
    </ul>

    <?php if ($rows === array()) { ?>
        <p class="text-muted"><?php _e('Nothing here.'); ?></p>
    <?php } else { ?>
        <div class="table-responsive">
            <table class="table" style="min-width:48rem">
                <thead>
                <tr>
                    <th><?php _e('#'); ?></th>
                    <th><?php _e('What'); ?></th>
                    <th><?php _e('State'); ?></th>
                    <th class="text-end"><?php _e('Tries'); ?></th>
                    <th><?php _e('Next run'); ?></th>
                    <th><?php _e('Last error'); ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row) {
                    $rowStatus = (string) $row['s_status'];
                    $label     = $filters[$rowStatus]['label'] ?? $rowStatus;
                    $variant   = $rowStatus === 'error' ? 'danger' : ($rowStatus === 'running' ? 'info' : 'secondary'); ?>
                    <tr>
                        <td><?php echo (int) $row['pk_i_id']; ?></td>
                        <td>
                            <code><?php echo osc_esc_html((string) $row['s_type']); ?></code>
                            <?php if (!empty($row['s_storage'])) { ?>
                                <span class="text-muted"><?php echo osc_esc_html((string) $row['s_storage']); ?></span>
                            <?php } ?>
                        </td>
                        <td><span class="badge bg-<?php echo $variant; ?>"><?php echo osc_esc_html($label); ?></span></td>
                        <td class="text-end"><?php echo (int) $row['i_attempts']; ?></td>
                        <td class="text-muted"><?php echo osc_esc_html((string) $row['dt_next_run']); ?></td>
                        <td class="text-muted"><?php echo osc_esc_html((string) ($row['s_last_error'] ?? '')); ?></td>
                        <td class="text-end">
                            <?php if ($rowStatus === 'error') { ?>
                                <?php osc_admin_form_open(array(
                                    'page'       => 'tools',
                                    'action'     => 'jobs_retry',
                                    'horizontal' => false,
                                    'class'      => 'd-inline',
                                )); ?>
                                    <input type="hidden" name="id" value="<?php echo (int) $row['pk_i_id']; ?>">
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

    <div class="d-flex gap-2 mt-3 align-items-center">
        <?php osc_admin_form_open(array(
            'page'       => 'tools',
            'action'     => 'jobs_run',
            'horizontal' => false,
            'class'      => 'd-inline',
        )); ?>
            <button type="submit" class="btn btn-primary"><?php _e('Run waiting jobs now'); ?></button>
        <?php osc_admin_form_close(null, array('horizontal' => false)); ?>

        <?php if ($summary['error'] > 0) { ?>
            <?php osc_admin_form_open(array(
                'page'       => 'tools',
                'action'     => 'jobs_retry',
                'horizontal' => false,
                'class'      => 'd-inline',
            )); ?>
                <button type="submit" class="btn btn-secondary"><?php _e('Try all failed again'); ?></button>
            <?php osc_admin_form_close(null, array('horizontal' => false)); ?>

            <button type="button" class="btn btn-danger" data-osc-dialog-open="#jobs-forget-dialog">
                <?php _e('Throw away all failed'); ?>
            </button>
        <?php } ?>
    </div>

    <p class="text-muted mt-2">
        <i class="bi bi-clock-history"></i>
        <?php _e('Waiting jobs also run on every cron tick.'); ?>
    </p>

    <?php osc_admin_confirm_dialog(array(
        'id'      => 'jobs-forget-dialog',
        'method'  => 'post',
        'fields'  => array('page' => 'tools', 'action' => 'jobs_forget'),
        'title'   => __('Throw away every failed job?'),
        'text'    => __("The work they were going to do will never happen. This can't be undone."),
        'confirm' => __('Throw them away'),
    )); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
