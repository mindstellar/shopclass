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
use mindstellar\database\SchemaDoctor;

$view       = View::newInstance();
$findings   = $view->_get('db_findings') ?: array();
$error      = (string) $view->_get('db_findings_error');
$repair     = $view->_get('db_repair');
$upgrade    = $view->_get('db_upgrade');
$pending    = $view->_get('db_pending') ?: array();
$size       = $view->_get('db_size');
$server     = (string) $view->_get('db_server');
$hasPending = $pending !== array();
$repairable = DatabaseTools::repairable($findings);
$canRepair  = DatabaseTools::repairAllowed($findings, $pending);
$uploadMax  = DatabaseTools::uploadLimit();
$self       = osc_admin_base_url(true) . '?page=tools&action=database';

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

$serverInfo = DatabaseTools::server($server);
$closer     = array_filter($findings, static function ($f) {
    return in_array($f['kind'], array(SchemaDoctor::INDEX_COLUMNS, SchemaDoctor::NULLABILITY), true);
});

// The verdict: one line per thing to act on, or one healthy line.
$issues = array();
if ($error !== '') {
    $issues[] = array('tone' => 'danger', 'action' => null, 'text' => sprintf(__('Could not read the database structure: %s'), $error));
}
if (!$serverInfo['supported']) {
    $issues[] = array('tone' => 'danger', 'action' => null, 'text' => sprintf(
        __('%1$s is older than Shopclass supports. Ask your host for MySQL %2$s or MariaDB %3$s or newer.'),
        $serverInfo['label'],
        DatabaseTools::SERVER_FLOOR['MySQL'],
        DatabaseTools::SERVER_FLOOR['MariaDB']
    ));
}
if ($hasPending) {
    $issues[] = array(
        'tone'   => 'warning',
        'text'   => sprintf(_n('%d database update is waiting.', '%d database updates are waiting.', count($pending)), count($pending)),
        'action' => array('label' => __('Run it'), 'attrs' => array('data-osc-dialog-open' => '#db-update-dialog')),
    );
} elseif ($repairable !== array()) {
    $issues[] = array(
        'tone'   => 'warning',
        'text'   => sprintf(_n('%d difference Repair can fix.', '%d differences Repair can fix.', count($repairable)), count($repairable)),
        'action' => array('label' => __('See the list'), 'url' => '#db-check'),
    );
}
if ($closer !== array()) {
    $issues[] = array(
        'tone'   => 'warning',
        'text'   => sprintf(_n('%d difference needs a closer look.', '%d differences need a closer look.', count($closer)), count($closer)),
        'action' => array('label' => __('See the list'), 'url' => '#db-check'),
    );
}

$facts = array(
    array('label' => __('Shopclass version'), 'value' => OSCLASS_VERSION),
    array('label' => __('Database version'), 'value' => (string) osc_version()),
);
if ($serverInfo['label'] !== '') {
    $facts[] = array('label' => __('Server'), 'value' => $serverInfo['label']);
}
if (is_array($size)) {
    $facts[] = array(
        'label' => __('Size'),
        'value' => sprintf(_n('%d table', '%d tables', $size['tables']), $size['tables']) . ' · ' . DatabaseTools::bytes($size['bytes']),
    );
}

osc_admin_page(array(
    'section' => __('Tools'),
    'title'   => __('Database'),
    'help'    => __('Everything about your database in one place: its state, waiting updates, '
                    . 'a check against what Shopclass expects, backups and restores.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Database')); ?>

    <?php if (is_array($upgrade)) { ?>
        <?php if ($upgrade['error'] === 0) { ?>
            <div class="callout-success callout-block mb-3">
                <?php echo osc_esc_html($upgrade['applied'] === array()
                    ? __('Nothing was waiting. The database is up to date.')
                    : sprintf(_n('The database is updated. %d update ran.', 'The database is updated. %d updates ran.', count($upgrade['applied'])), count($upgrade['applied']))); ?>
            </div>
        <?php } else { ?>
            <div class="flashmessage flashmessage-error">
                <p class="mb-0"><?php echo osc_esc_html($upgrade['message'] !== '' ? $upgrade['message'] : __('The database update failed.')); ?></p>
            </div>
        <?php } ?>
    <?php } ?>

    <?php osc_admin_form_section(__('Status')); ?>
    <?php foreach ($issues as $issue) { ?>
        <div class="callout-<?php echo $issue['tone']; ?> callout-block align-items-center mb-2">
            <span class="flex-grow-1"><?php echo osc_esc_html($issue['text']); ?></span>
            <?php if ($issue['action'] !== null) {
                osc_admin_action_button($issue['action']);
            } ?>
        </div>
    <?php } ?>
    <?php if ($issues === array()) { ?>
        <div class="callout-success callout-block mb-2"><?php _e('Your database is up to date and healthy.'); ?></div>
    <?php } ?>
    <div class="mt-3">
        <?php osc_admin_panel_open(); ?>
        <?php osc_admin_definition($facts); ?>
        <?php osc_admin_panel_close(); ?>
    </div>

    <?php if ($hasPending) { ?>
        <div id="db-update">
            <?php osc_admin_form_section(__('Database update'), array(
                'spaced' => true,
                'intro'  => __('New Shopclass files are in place, but the database has not caught up yet. '
                               . 'Run these updates to finish. Take a backup first.'),
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
                            <td><?php echo osc_esc_html(DatabaseTools::title(osc_lib_path() . 'osclass/installer/migrations', (string) $migration)); ?></td>
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

    <div id="db-check">
        <?php if ($error !== '') { ?>
            <?php osc_admin_form_section(__('Check and repair'), array('spaced' => true)); ?>
            <div class="flashmessage flashmessage-error">
                <p class="mb-0"><?php printf(__('Could not read the schema: %s'), osc_esc_html($error)); ?></p>
            </div>
        <?php } elseif ($findings === array()) { ?>
            <?php osc_admin_form_section(__('Check and repair'), array('spaced' => true)); ?>
            <?php osc_admin_empty(array(
                'icon'  => 'bi-check2-circle',
                'title' => __('Everything matches. Nothing to repair.'),
                'text'  => __('Your database has every table, column and index Shopclass expects.'),
            )); ?>
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

    <div id="backup">
        <?php osc_admin_form_section(__('Backup'), array(
            'spaced'     => true,
            'intro_html' => osc_esc_html(__('Save a copy of the database: listings, users, categories and settings. Take one before an update or a repair.'))
                . ' ' . sprintf(
                    __('Photos, plugins and themes are files, not database: back them up on %s.'),
                    '<a href="' . osc_esc_html(osc_admin_base_url(true) . '?page=tools&action=upgrade#backup-files') . '">'
                    . osc_esc_html(__('Upgrade Shopclass')) . '</a>'
                ),
        )); ?>
        <?php osc_admin_form_open(array('page' => 'tools', 'id' => 'backup_form', 'name' => 'backup_form')); ?>
            <?php osc_admin_select(array(
                'name'     => 'action',
                'label'    => __('Backup'),
                'width'    => 'text',
                'selected' => 'backup-sql_file',
                'options'  => array(
                    'backup-sql_file' => __('Download to this computer'),
                    'backup-sql'      => __('Save in a folder on the server'),
                ),
            )); ?>
            <?php osc_admin_text(array(
                'name'          => 'bck_dir',
                'label'         => __('Server folder'),
                'value'         => DatabaseTools::defaultBackupDir(osc_base_path()),
                'width'         => 'key',
                'depends'       => 'action',
                'depends_value' => array('backup-sql'),
                'help'          => __('It must be outside the site folder, so the public cannot download it.'),
            )); ?>
        <?php osc_admin_form_close(array(
            array('label' => __('Back up the database'), 'icon' => 'bi-download', 'type' => 'submit', 'variant' => 'secondary'),
        )); ?>
    </div>

    <div id="restore">
        <?php osc_admin_form_section(__('Restore from a backup'), array(
            'spaced' => true,
            'intro'  => __('Run an .sql backup file against this database, such as one saved above.'),
        )); ?>
        <div class="callout-danger callout-block mb-3">
            <?php _e('This overwrites data. The file can change or delete anything in your database, and it cannot be undone. Take a backup first.'); ?>
        </div>
        <?php osc_admin_form_open(array(
            'page'   => 'tools',
            'action' => 'import_post',
            'id'     => 'db-restore-form',
            'upload' => true,
        )); ?>
            <?php osc_admin_field(array(
                'type'  => 'file',
                'id'    => 'sql',
                'name'  => 'sql',
                'label' => __('Backup file (.sql)'),
                'attrs' => array('accept' => '.sql'),
                'help'  => $uploadMax < PHP_INT_MAX
                    ? sprintf(__('This server accepts files up to %s. Restore a larger file from the command line.'), DatabaseTools::bytes($uploadMax))
                    : '',
            )); ?>
        <?php osc_admin_form_close(array(
            array(
                'label'   => __('Restore from this file'),
                'icon'    => 'bi-upload',
                'type'    => 'submit',
                'variant' => 'danger',
                'attrs'   => array('data-osc-dialog-open' => '#db-restore-dialog'),
            ),
        )); ?>
    </div>

    <?php osc_admin_confirm_dialog(array(
        'id'           => 'db-restore-dialog',
        'title'        => __('Restore from this file?'),
        'text'         => __('Every statement in the file runs against your live database. It can change or delete what is there now, and it cannot be undone.'),
        'confirm'      => __('Restore'),
        'confirm_form' => 'db-restore-form',
    )); ?>

    <?php if ($hasPending) { ?>
        <?php osc_admin_confirm_dialog(array(
            'id'      => 'db-update-dialog',
            'tone'    => 'plain',
            'method'  => 'post',
            'url'     => $self . '#db-update',
            'fields'  => array('upgrade' => '1'),
            'title'   => __('Run the database update?'),
            'text'    => __('This applies the waiting updates in order. Keep the page open until it finishes. Take a backup first.'),
            'confirm' => __('Run database update'),
        )); ?>
    <?php } elseif ($canRepair) { ?>
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
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
