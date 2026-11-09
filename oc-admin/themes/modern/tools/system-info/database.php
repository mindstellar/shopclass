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
use mindstellar\migration\MigrationRunner;

// System info > Database, below the verdict and the facts: waiting updates and check and repair.
/** @var array<string, mixed> $env */
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
            <?php osc_admin_section(__('Database update'), array(
                'id' => 'db-update',
                'intro_html' => osc_esc_html(__('New Shopclass files are in place, but the database has not caught up yet. Run these updates to finish.'))
                . ' <a href="' . osc_esc_html(osc_admin_base_url(true) . '?page=tools&action=backup') . '">'
                . osc_esc_html(__('Take a backup first.')) . '</a>',
            )); ?>
            <?php
            $pendingRows = array();
            foreach ($pending as $migration) {
                $pendingRows[] = array(
                    'update' => DatabaseTools::title(MigrationRunner::coreDir(), (string) $migration),
                    'file'   => array('html' => '<code>' . osc_esc_html((string) $migration) . '</code>', 'class' => 'text-muted'),
                );
            }
            osc_admin_table(array('update' => __('Update'), 'file' => __('File')), $pendingRows); ?>
            <?php osc_admin_action_section(array(
                'actions' => array(array(
                    'label'   => __('Run database update'),
                    'icon'    => 'bi-arrow-up-circle',
                    'variant' => 'primary',
                    'confirm' => '#db-update-dialog',
                    'help'    => __('The same update the command line runs with db:upgrade. On a large site it can take a few minutes.'),
                )),
            )); ?>
            <?php osc_admin_section_close(); ?>
    <?php } ?>

    <?php if (is_array($repair)) { ?>
        <?php osc_admin_section(__('Repair result')); ?>
        <?php if ($repair['ran'] === array() && $repair['failed'] === array()) { ?>
            <?php osc_admin_empty(array('title' => __('Nothing needed repairing.'))); ?>
        <?php } else { ?>
            <?php
            $repairRows = array();
            foreach ($repair['failed'] as $query) {
                $repairRows[] = array('result' => array('status' => array('failed', __('Failed'))), 'change' => array('html' => '<code>' . osc_esc_html(trim(preg_replace('/\s+/', ' ', (string) $query))) . '</code>'));
            }
            foreach ($repair['ran'] as $query) {
                $repairRows[] = array('result' => array('status' => array('active', __('Done'))), 'change' => array('html' => '<code>' . osc_esc_html(trim(preg_replace('/\s+/', ' ', (string) $query))) . '</code>'));
            }
            osc_admin_table(array(
                'result' => array('label' => __('Result'), 'class' => 'col-status'),
                'change' => __('Change made'),
            ), $repairRows, array('class' => 'sysinfo-table-wide')); ?>
        <?php } ?>
        <?php osc_admin_section_close(); ?>
    <?php } ?>

    <?php osc_admin_body_open(array('id' => 'db-check')); ?>
        <?php if ($error !== '') { ?>
            <?php osc_admin_section(__('Check and repair')); ?>
            <?php osc_admin_verdict(array(array('tone' => 'danger', 'text' => sprintf(__('Could not read the schema: %s'), $error)))); ?>
            <?php osc_admin_section_close(); ?>
        <?php } elseif ($findings === array()) { ?>
            <?php osc_admin_section(__('Check and repair')); ?>
            <?php osc_admin_empty(array('title' => __('Everything matches. Nothing to repair.'))); ?>
            <?php osc_admin_section_close(); ?>
        <?php } else { ?>
            <?php foreach ($groups as $group) { ?>
                <?php $rows = array_values(array_filter($findings, static function ($f) use ($group) {
                    return in_array($f['kind'], $group['kinds'], true);
                })); ?>
                <?php if ($rows === array()) {
                    continue;
                } ?>
                <?php osc_admin_section($group['title'], array('intro' => $group['intro'])); ?>
                <?php
                $findingRows = array();
                foreach ($rows as $f) {
                    $isPrimary = $f['kind'] === SchemaDoctor::MISSING_INDEX && $f['name'] === 'PRIMARY';
                    $findingRows[] = array(
                        'table'    => array('html' => '<code>' . osc_esc_html($f['table']) . '</code>'),
                        'what'     => array('html' => osc_esc_html($kindLabel($f)) . ($isPrimary ? '' : '<br /><code>' . osc_esc_html($f['name']) . '</code>')),
                        'expected' => array('html' => osc_esc_html($f['declared']), 'class' => 'text-muted'),
                        'found'    => array('html' => osc_esc_html($foundText($f)), 'class' => 'text-muted'),
                    );
                }
                $findingCols = array(
                    'table' => __('Table'),
                    'what'  => __('What'),
                );
                if ($group['show_expected']) {
                    $findingCols['expected'] = __('Shopclass expects');
                }
                $findingCols['found'] = __('Your database has');
                osc_admin_table($findingCols, $findingRows, array('class' => 'sysinfo-table-findings')); ?>
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
                <?php osc_admin_section_close(); ?>
            <?php } ?>
        <?php } ?>
    <?php osc_admin_body_close(); ?>

    <?php if (isset($env['facet_ready'])) { ?>
        <?php $facet = DatabaseTools::facetStatus($env); ?>
            <?php osc_admin_section(__('Listing filter index'), array('id' => 'db-facet')); ?>
            <p class="text-muted"><?php echo osc_esc_html($facet['text']); ?></p>
            <?php if ($facet['note'] !== '') { ?>
                <p class="text-muted small"><?php echo osc_esc_html($facet['note']); ?></p>
            <?php } ?>
            <?php osc_admin_action_section(array(
                'actions' => array(array(
                    'label'   => __('Rebuild filter index'),
                    'icon'    => 'bi-arrow-repeat',
                    'variant' => 'primary',
                    'confirm' => '#db-facet-dialog',
                    'help'    => __('Searches keep working while it rebuilds.'),
                )),
            )); ?>
            <?php osc_admin_section_close(); ?>
        <?php osc_admin_confirm_dialog(array(
            'id'      => 'db-facet-dialog',
            'tone'    => 'plain',
            'method'  => 'post',
            'url'     => $self,
            'fields'  => array('facet_rebuild' => '1'),
            'title'   => __('Rebuild the filter index?'),
            'text'    => __('It rebuilds in the background, a batch of listings at a time. On a large site it can take a while.'),
            'confirm' => __('Rebuild'),
        )); ?>
    <?php } ?>

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
