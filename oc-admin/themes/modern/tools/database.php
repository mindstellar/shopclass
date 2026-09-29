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

$view           = View::newInstance();
$findings       = $view->_get('db_findings') ?: array();
$error          = (string) $view->_get('db_findings_error');
$repair         = $view->_get('db_repair');
$pendingUpgrade = (bool) $view->_get('db_pending_upgrade');

// Plain label per kind. Grouped by what the owner should do about it: what Repair
// actually fixes was checked by hand, table by table, against the reconciler.
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
        'title' => $pendingUpgrade ? __('Still to do') : __('Repair can fix these'),
        'intro' => $pendingUpgrade
            ? __('Finish the upgrade first. Most of these will then be gone.')
            : __('Press Repair to fix them. Take a backup first.'),
        'kinds' => array(SchemaDoctor::MISSING_TABLE, SchemaDoctor::MISSING_COLUMN, SchemaDoctor::MISSING_INDEX, SchemaDoctor::COLUMN_TYPE),
        'show_expected' => true,
    ),
    array(
        'title' => __('Extra items: nothing to do'),
        'intro' => __('A plugin or someone on your team added these. Shopclass does not need them, and Repair leaves them alone. Delete one only if you are sure nothing uses it.'),
        'kinds' => array(SchemaDoctor::EXTRA_COLUMN, SchemaDoctor::EXTRA_INDEX),
        'show_expected' => false,
    ),
    array(
        'title' => __('Needs a closer look'),
        'intro' => __('Repair does not change these. Ask for help before changing the table by hand.'),
        'kinds' => array(SchemaDoctor::INDEX_COLUMNS, SchemaDoctor::NULLABILITY),
        'show_expected' => true,
    ),
);

osc_admin_page(array(
    'section' => __('Tools'),
    'title'   => __('Database'),
    'help'    => __('Compares your database with what Shopclass expects, and lists what is different. '
                    . 'Repair fixes what it safely can; some things need a person to look at them.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Database'), $pendingUpgrade ? array() : array(array(
        'label'   => __('Repair'),
        'icon'    => 'bi-wrench',
        'variant' => 'primary',
        'attrs'   => array('data-osc-dialog-open' => '#db-repair-dialog'),
    ))); ?>

    <?php if ($pendingUpgrade) { ?>
        <div class="callout-warning callout-block">
            <?php _e('An upgrade is waiting. Finish it first: it fixes most of these safely.'); ?>
            <a class="callout-link" href="<?php echo osc_esc_html(osc_admin_base_url(true) . '?page=tools&action=upgrade'); ?>">
                <?php _e('Tools &raquo; Upgrade'); ?>
            </a>
        </div>
    <?php } ?>

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

    <?php $spaceNext = is_array($repair) || $pendingUpgrade; ?>
    <?php if ($error !== '') { ?>
        <div class="flashmessage flashmessage-error<?php echo $spaceNext ? ' separate-top' : ''; ?>">
            <p class="mb-0"><?php printf(__('Could not read the schema: %s'), osc_esc_html($error)); ?></p>
        </div>
    <?php } elseif ($findings === array()) { ?>
        <p class="text-muted<?php echo $spaceNext ? ' separate-top' : ''; ?>"><?php _e('Your database matches what Shopclass expects.'); ?></p>
    <?php } else { ?>
        <?php foreach ($groups as $group) { ?>
            <?php $rows = array_values(array_filter($findings, static function ($f) use ($group) {
                return in_array($f['kind'], $group['kinds'], true);
            })); ?>
            <?php if ($rows === array()) {
                continue;
            } ?>
            <?php osc_admin_form_section($group['title'], array('intro' => $group['intro'], 'spaced' => $spaceNext)); $spaceNext = false; ?>
            <div class="table-responsive">
                <table class="table" style="min-width:44rem">
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
        <?php } ?>
    <?php } ?>

    <?php if (!$pendingUpgrade) { ?>
        <?php osc_admin_confirm_dialog(array(
            'id'      => 'db-repair-dialog',
            'tone'    => 'plain',
            'method'  => 'post',
            'url'     => osc_admin_base_url(true) . '?page=tools&action=database',
            'fields'  => array('repair' => '1'),
            'title'   => __('Repair the database?'),
            'text'    => __('This adds what is missing and fixes column types. It does not touch extra columns or indexes, and it can take a while on a large site. Take a backup first.'),
            'confirm' => __('Repair'),
        )); ?>
    <?php } ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
