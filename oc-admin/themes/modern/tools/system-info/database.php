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

use mindstellar\admin\DatabaseTools;
use mindstellar\admin\SystemChecks;
use mindstellar\database\SchemaDoctor;

// System info > Database, below the verdict and the facts: waiting updates and check and repair.
$findings   = (array) ($env['findings'] ?? array());
$error      = (string) ($env['findings_error'] ?? '');
$pending    = (array) ($env['pending'] ?? array());
$repair     = View::getInstance()->_get('db_repair');
$hasPending = $pending !== array();
$canRepair  = DatabaseTools::repairAllowed($findings, $pending);
$self       = SystemChecks::url($env, 'database');

$labels = array(
    SchemaDoctor::MISSING_TABLE  => __('Missing table'),
    SchemaDoctor::MISSING_COLUMN => __('Missing column'),
    SchemaDoctor::MISSING_INDEX  => __('Missing index'),
    SchemaDoctor::COLUMN_TYPE    => __('Column has a different type'),
    SchemaDoctor::EXTRA_COLUMN   => __('Extra column'),
    SchemaDoctor::EXTRA_INDEX    => __('Extra index'),
    SchemaDoctor::INDEX_COLUMNS  => __('Index has different columns'),
    SchemaDoctor::NULLABILITY    => __('Column differs in whether it can be empty'),
);

/** The "What" column label for a finding. A missing primary key gets its own wording. */
$kindLabel = static function (array $f) use ($labels): string {
    if ($f['kind'] === SchemaDoctor::MISSING_INDEX && $f['name'] === 'PRIMARY') {
        return __('Missing primary key');
    }

    return $labels[$f['kind']] ?? $f['kind'];
};

/** 'absent' is SchemaDoctor's internal sentinel; the owner reads "missing" instead. */
$foundText = static function (array $f): string {
    return $f['found'] === 'absent' ? __('missing') : $f['found'];
};

$groups = array(
    array(
        'title'  => $hasPending ? __('Still to do') : __('Repair can fix these'),
        'intro'  => $hasPending
            ? __('Run the database update first. Most of these will then be gone.')
            : __('Press Repair to fix them. Take a backup first.'),
        'kinds'  => DatabaseTools::REPAIRABLE,
        'show_expected' => true,
        'repair' => !$hasPending,
    ),
    array(
        'title' => __('Extra items: nothing to do'),
        'intro' => __('A plugin or someone on your team added these. Shopclass does not need them, and Repair leaves them alone. Delete one only if you are sure nothing uses it.'),
        'kinds' => DatabaseTools::EXTRA,
        'show_expected' => false,
    ),
    array(
        'title' => __('Needs a closer look'),
        'intro' => __('Repair does not change these. Ask for help before changing the table by hand.'),
        'kinds' => DatabaseTools::CLOSER_LOOK,
        'show_expected' => true,
    ),
);
?>
    <?php if ($hasPending) { ?>
        <div id="db-update">
            <?php osc_admin_form_section(__('Database update'), array(
                'spaced' => true,
                'intro_html' => osc_esc_html(__('New Shopclass files are in place, but the database has not caught up yet. Run these updates to finish.'))
                . ' <a href="' . osc_esc_html(osc_admin_base_url(true) . '?page=tools&action=backup') . '">'
                . osc_esc_html(__('Take a backup first.')) . '</a>',
            )); ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                    <tr>
                        <th><?php _e('Update'); ?></th>
                        <th><?php _e('File'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pending as $migration) { ?>
                        <tr>
                            <td><?php echo osc_esc_html(DatabaseTools::title(DatabaseTools::migrationsDir(), (string) $migration)); ?></td>
                            <td class="text-muted"><code><?php echo osc_esc_html((string) $migration); ?></code></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php osc_admin_action_section(array(
                'actions' => array(array(
                    'label'   => __('Run database update'),
                    'icon'    => 'bi-arrow-up-circle',
                    'variant' => 'primary',
                    'confirm' => '#db-update-dialog',
                    'help'    => __('The same update the command line runs with db:upgrade. On a large site it can take a few minutes.'),
                )),
            )); ?>
        </div>
    <?php } ?>

    <?php if (is_array($repair)) { ?>
        <?php osc_admin_form_section(__('Repair result'), array('spaced' => true)); ?>
        <?php if ($repair['ran'] === array() && $repair['failed'] === array()) { ?>
            <p class="text-muted"><?php _e('Nothing needed repairing.'); ?></p>
        <?php } else { ?>
            <div class="table-responsive">
                <table class="table sysinfo-table-wide">
                    <thead>
                    <tr>
                        <th class="col-status"><?php _e('Result'); ?></th>
                        <th><?php _e('Change made'); ?></th>
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

    <div id="db-check">
        <?php if ($error !== '') { ?>
            <?php osc_admin_form_section(__('Check and repair'), array('spaced' => true)); ?>
            <?php osc_admin_verdict(array(array('tone' => 'danger', 'text' => sprintf(__('Could not read the schema: %s'), $error)))); ?>
        <?php } elseif ($findings === array()) { ?>
            <?php osc_admin_form_section(__('Check and repair'), array('spaced' => true)); ?>
            <p class="text-muted mb-0"><?php _e('Everything matches. Nothing to repair.'); ?></p>
        <?php } else { ?>
            <?php foreach ($groups as $group) { ?>
                <?php $rows = array_values(array_filter($findings, static function ($f) use ($group) {
                    return in_array($f['kind'], $group['kinds'], true);
                })); ?>
                <?php if ($rows === array()) {
                    continue;
                } ?>
                <?php osc_admin_form_section($group['title'], array('intro' => $group['intro'], 'spaced' => true)); ?>
                <div class="table-responsive">
                    <table class="table sysinfo-table-findings">
                        <thead>
                        <tr>
                            <th><?php _e('Table'); ?></th>
                            <th><?php _e('What'); ?></th>
                            <?php if ($group['show_expected']) { ?>
                                <th><?php _e('Shopclass expects'); ?></th>
                            <?php } ?>
                            <th><?php _e('Your database has'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $f) { ?>
                            <?php $isPrimary = $f['kind'] === SchemaDoctor::MISSING_INDEX && $f['name'] === 'PRIMARY'; ?>
                            <tr>
                                <td><code><?php echo osc_esc_html($f['table']); ?></code></td>
                                <td><?php echo osc_esc_html($kindLabel($f));
                                    if (!$isPrimary) { ?><br />
                                    <code><?php echo osc_esc_html($f['name']); ?></code>
                                <?php } ?></td>
                                <?php if ($group['show_expected']) { ?>
                                    <td class="text-muted"><?php echo osc_esc_html($f['declared']); ?></td>
                                <?php } ?>
                                <td class="text-muted"><?php echo osc_esc_html($foundText($f)); ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!empty($group['repair'])) { ?>
                    <?php osc_admin_action_section(array(
                        'actions' => array(array(
                            'label'   => __('Repair'),
                            'icon'    => 'bi-wrench',
                            'variant' => 'primary',
                            'confirm' => '#db-repair-dialog',
                            'help'    => __('Adds what is missing and fixes column types. Extra columns and indexes are left alone.'),
                        )),
                    )); ?>
                <?php } ?>
            <?php } ?>
        <?php } ?>
    </div>

    <?php if ($canRepair) { ?>
        <?php osc_admin_confirm_dialog(array(
            'id'      => 'db-repair-dialog',
            'tone'    => 'plain',
            'method'  => 'post',
            'url'     => $self,
            'fields'  => array('repair' => '1'),
            'title'   => __('Repair the database?'),
            'text'    => __('This adds what is missing and fixes column types. It does not touch extra columns or indexes, and it can take a while on a large site. Take a backup first.'),
            'confirm' => __('Repair'),
        )); ?>
    <?php } ?>
