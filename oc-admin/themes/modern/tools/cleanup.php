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

osc_admin_page(array(
    'section' => __('Tools'),
    'title'   => __('Cleanup'),
    'help'    => __('Remove stale content in bulk — expired, unactivated, spam, blocked and '
                    . 'reported listings, and unactivated users. Choose what to clean and how old '
                    . 'it must be, then run it now or let the daily task handle it.'),
));

$running     = \mindstellar\job\CleanupJobs::isRunning();
$history     = View::getInstance()->_get('cleanup_history') ?: array();

osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Cleanup'), array(array(
        'label'   => __('Run cleanup now'),
        'icon'    => 'bi-trash3',
        'variant' => 'outline-danger',
        'attrs'   => array('data-osc-dialog-open' => '#cleanup-run-dialog'),
    ))); ?>

    <p class="text-muted">
        <?php if ($running) {
            osc_admin_status('active', __('Running'));
            echo ' ';
            _e('Cleanup is running in the background. Reload this page to see the counts go down.');
        } else { ?>
            <i class="bi bi-clock-history" aria-hidden="true"></i>
            <?php if ($history !== array()) {
                printf(__('Enabled rules run once a day. The last cleanup finished %s.'), osc_admin_when($history[0]['dt_date']));
            } else {
                _e('Enabled rules run once a day.');
            }
        } ?>
    </p>

    <?php $form = __get('cleanup_form');
    osc_admin_settings_form($form['id'], $form); ?>

    <?php osc_admin_form_section(__('Recent cleanups'), array('spaced' => true)); ?>
    <?php if ($history === array()) { ?>
        <p class="text-muted"><?php _e('Nothing has been cleaned up yet.'); ?></p>
    <?php } else { ?>
        <div class="table-responsive">
            <table class="table" style="min-width:30rem">
                <thead>
                <tr>
                    <th><?php _e('When'); ?></th>
                    <th><?php _e('Details'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $entry) { ?>
                    <tr>
                        <td class="text-nowrap"><?php echo osc_admin_when($entry['dt_date']); ?></td>
                        <td><?php echo osc_esc_html((string) $entry['s_data']); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>

    <?php osc_admin_confirm_dialog(array(
            'id'      => 'cleanup-run-dialog',
            'method'  => 'post',
            'fields'  => array('page' => 'tools', 'action' => 'cleanup_run'),
            'title'   => __('Run cleanup now?'),
            'text'    => __("This permanently deletes the matching listings, users and profile pictures for every enabled rule. It runs in the background. This can't be undone."),
            'confirm' => __('Delete matching items'),
        )); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
