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

use mindstellar\database\SchemaDoctor;

$view     = View::newInstance();
$findings = $view->_get('db_findings') ?: array();
$error    = (string) $view->_get('db_findings_error');
$repair   = $view->_get('db_repair');

$labels = array(
    SchemaDoctor::MISSING_TABLE  => __('Table is missing'),
    SchemaDoctor::MISSING_COLUMN => __('Column is missing'),
    SchemaDoctor::EXTRA_COLUMN   => __('Column is not declared by core'),
    SchemaDoctor::NULLABILITY    => __('Column nullability differs'),
    SchemaDoctor::COLUMN_TYPE    => __('Column type differs'),
    SchemaDoctor::MISSING_INDEX  => __('Index is missing'),
    SchemaDoctor::EXTRA_INDEX    => __('Index is not declared by core'),
    SchemaDoctor::INDEX_COLUMNS  => __('Index columns differ'),
);

osc_admin_page(array(
    'section' => __('Tools'),
    'title'   => __('Database'),
    'help'    => __('Compares your database with the structure this version of Shopclass declares. '
                    . 'Repair adds missing tables, columns, indexes and foreign keys, and corrects '
                    . 'column types and defaults. It never drops anything.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Database'), array(array(
        'label'   => __('Repair'),
        'icon'    => 'bi-wrench',
        'variant' => 'primary',
        'attrs'   => array('data-osc-dialog-open' => '#db-repair-dialog'),
    ))); ?>

    <?php if (is_array($repair)) { ?>
        <?php osc_admin_form_section(__('Repair result')); ?>
        <?php if ($repair['ran'] === array() && $repair['failed'] === array()) { ?>
            <p class="text-muted"><?php _e('Nothing needed repairing.'); ?></p>
        <?php } else { ?>
            <div class="table-responsive">
                <table class="table" style="min-width:34rem">
                    <thead>
                    <tr>
                        <th class="col-status"><?php _e('Result'); ?></th>
                        <th><?php _e('Statement'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($repair['failed'] as $query) { ?>
                        <tr>
                            <td class="col-status"><?php osc_admin_status('failed', __('Failed')); ?></td>
                            <td><code><?php echo osc_esc_html(trim(preg_replace('/\s+/', ' ', (string) $query))); ?></code></td>
                        </tr>
                    <?php } ?>
                    <?php foreach ($repair['ran'] as $query) { ?>
                        <tr>
                            <td class="col-status"><?php osc_admin_status('active', __('Done')); ?></td>
                            <td><code><?php echo osc_esc_html(trim(preg_replace('/\s+/', ' ', (string) $query))); ?></code></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    <?php } ?>

    <?php osc_admin_form_section(__('Differences'), array('spaced' => is_array($repair))); ?>
    <?php if ($error !== '') { ?>
        <div class="flashmessage flashmessage-error">
            <p class="mb-0"><?php printf(__('Could not read the schema: %s'), osc_esc_html($error)); ?></p>
        </div>
    <?php } elseif ($findings === array()) { ?>
        <p class="text-muted"><?php _e('Your database matches what Shopclass declares.'); ?></p>
    <?php } else { ?>
        <div class="table-responsive">
            <table class="table" style="min-width:48rem">
                <thead>
                <tr>
                    <th><?php _e('Table'); ?></th>
                    <th><?php _e('Name'); ?></th>
                    <th><?php _e('Difference'); ?></th>
                    <th><?php _e('Shopclass declares'); ?></th>
                    <th><?php _e('This database has'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($findings as $f) { ?>
                    <tr>
                        <td><code><?php echo osc_esc_html($f['table']); ?></code></td>
                        <td><code><?php echo osc_esc_html($f['name']); ?></code></td>
                        <td><?php echo osc_esc_html($labels[$f['kind']] ?? $f['kind']); ?></td>
                        <td class="text-muted"><?php echo osc_esc_html($f['declared']); ?></td>
                        <td class="text-muted"><?php echo osc_esc_html($f['found']); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <p class="col-hint"><?php _e('A column or index core does not declare is usually something a plugin or this site added on purpose, and Repair leaves it alone. Report a nullability or type difference rather than altering tables by hand.'); ?></p>
    <?php } ?>

    <?php osc_admin_confirm_dialog(array(
        'id'      => 'db-repair-dialog',
        'tone'    => 'plain',
        'method'  => 'post',
        'url'     => osc_admin_base_url(true) . '?page=tools&action=database',
        'fields'  => array('repair' => '1'),
        'title'   => __('Repair the database?'),
        'text'    => __('This changes table structure and can take a while on a large site. Take a backup first.'),
        'confirm' => __('Repair'),
    )); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
