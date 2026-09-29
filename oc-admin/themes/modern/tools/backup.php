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
use mindstellar\backup\BackupBucket;
use mindstellar\backup\BackupJobs;
use mindstellar\backup\BackupManager;
use mindstellar\backup\BackupStore;

$view    = View::newInstance();
$state   = $view->_get('backup_state') ?: array();
$busy    = (bool) $view->_get('backup_busy');
$list    = $view->_get('backup_list') ?: array();
$confirm = is_array($view->_get('backup_confirm')) ? $view->_get('backup_confirm') : null;
$notice  = is_array($view->_get('backup_notice')) ? $view->_get('backup_notice') : null;
$skipped = (string) $view->_get('backup_skipped');
$probe   = $view->_get('backup_probe');
$bucket  = is_array($view->_get('backup_bucket')) ? $view->_get('backup_bucket') : null;
$plain   = $bucket !== null && BackupBucket::insecure();
$open    = $bucket !== null && BackupBucket::flaggedPublic();
$demo    = defined('DEMO');
$status  = (string) ($state['status'] ?? '');
$live    = in_array($status, array('queued', 'running'), true);
$locked  = $demo || $busy || $live;
$offload = osc_get_preference('storage_active') === 's3';
$keep    = BackupJobs::keepCount();
$max     = DatabaseTools::uploadLimit();
$poll    = osc_admin_base_url(true) . '?page=ajax&action=backup_status&' . osc_csrf_token_url();
$noWeb   = osc_web_restore_disabled();
$offLine = __('Restore is turned off on this site. Use the command line.');
$reauth  = (string) $view->_get('backup_reauth_error');
$twoStep = (bool) $view->_get('backup_reauth_2fa');

/** A row's date, what, and size in words, for the list and the delete confirm. */
$describe = static function (array $row): array {
    return array(
        'when' => BackupJobs::when($row['created']),
        'what' => $row['kind'] === 'safety' ? __('Safety copy') : BackupJobs::whatWord($row['what']),
        'size' => DatabaseTools::bytes($row['size']),
    );
};

/** The fields that name a listed backup in a post. */
$names = static function (array $row): array {
    return ($row['where'] ?? '') === 'bucket' ? array('name' => $row['name'], 'from' => 'bucket') : array('name' => $row['name']);
};

/** A button that posts one action from its own small form. */
$postButton = static function (string $action, string $label, array $fields = array(), string $variant = 'secondary') {
    osc_admin_form_open(array('page' => 'tools', 'action' => $action, 'fields' => $fields, 'horizontal' => false));
    osc_admin_action_button(array('label' => $label, 'type' => 'submit', 'variant' => $variant));
    osc_admin_form_close(null, array('horizontal' => false));
};

osc_add_hook('admin_footer', static function () use ($live, $confirm) { ?>
    <script>
        (function () {
            var what = document.querySelectorAll('input[name="what"]');
            var note = document.getElementById('backup-bucket-note');
            if (note && what.length) {
                var sync = function () {
                    var picked = document.querySelector('input[name="what"]:checked');
                    note.hidden = picked !== null && picked.value === 'database';
                };
                what.forEach(function (input) { input.addEventListener('change', sync); });
                sync();
            }
            <?php if ($confirm !== null) { ?>
            var dialog = document.getElementById('backup-restore-dialog');
            if (dialog && typeof dialog.showModal === 'function') {
                dialog.showModal();
            }
            <?php } ?>
            <?php if ($live) { ?>
            var box = document.getElementById('backup-run');
            if (!box) {
                return;
            }
            var bar = box.querySelector('progress');
            var line = box.querySelector('.osc-progress-status');
            var poll = function () {
                fetch(box.getAttribute('data-status-url'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest'}
                }).then(function (r) {
                    return r.json();
                }).then(function (d) {
                    if (!d || d.error) {
                        window.setTimeout(poll, 5000);
                        return;
                    }
                    if (d.status !== 'queued' && d.status !== 'running') {
                        window.location.replace(box.getAttribute('data-done-url'));
                        return;
                    }
                    line.textContent = d.line;
                    if (d.percent === null) {
                        bar.removeAttribute('value');
                    } else {
                        bar.value = d.percent;
                    }
                    window.setTimeout(poll, 2000);
                }).catch(function () {
                    window.setTimeout(poll, 5000);
                });
            };
            window.setTimeout(poll, 1000);
            <?php } ?>
        })();
    </script>
<?php });

osc_admin_page(array(
    'section' => __('Tools'),
    'title'   => __('Backup and restore'),
    'help'    => __('Save a copy of your database and your files, and put one back. Backups you save on the server are kept in a folder closed to the web.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Backup and restore')); ?>

    <?php
    $issues = array();
    if ($probe === true) {
        $issues[] = array(
            'tone'   => 'danger',
            'text'   => __('Your backups folder is open to the web. Anyone who guesses a file name could download a backup.'),
            'action' => array('label' => __('How to close it'), 'url' => 'https://mindstellar.com/docs/deploy/security/#the-backups-folder'),
        );
    }
    if ($bucket !== null && $bucket['exposed']) {
        $issues[] = array(
            'tone'   => 'warning',
            'text'   => __('Your photo bucket is public. Anyone with the link could download a backup saved there. Use a separate private bucket.'),
            'action' => array('label' => __('Set a backups bucket'), 'url' => osc_admin_base_url(true) . '?page=settings&action=storage'),
        );
    }
    if ($open) {
        $issues[] = array(
            'tone'   => 'danger',
            'text'   => __('Anyone can read your backups in this bucket. Make the bucket private.'),
            'action' => array('label' => __('How to close it'), 'url' => 'https://mindstellar.com/docs/deploy/security/#backups-in-a-bucket'),
        );
    }
    if ($plain) {
        $issues[] = array(
            'tone'   => 'warning',
            'text'   => __('Your S3 endpoint uses plain http, so a backup and its download link would travel unencrypted. Saving to the bucket is off until the endpoint uses https.'),
            'action' => array('label' => __('Open storage settings'), 'url' => osc_admin_base_url(true) . '?page=settings&action=storage'),
        );
    }
    osc_admin_verdict($issues);
    ?>

    <?php if ($live) {
        $words   = BackupManager::progress($state);
        $restore = $state['kind'] === 'restore'; ?>
        <div class="callout-<?php echo $restore ? 'warning' : 'info'; ?> callout-block backup-callout" id="backup-run"
             data-status-url="<?php echo osc_esc_html($poll); ?>"
             data-done-url="<?php echo osc_esc_html(osc_admin_base_url(true) . '?page=tools&action=backup'); ?>">
            <div class="backup-callout-body backup-callout-stack">
                <p class="backup-callout-title"><?php echo osc_esc_html($words['title']); ?></p>
                <?php if ($restore) { ?>
                    <p><?php _e('Visitors see the maintenance page until this finishes.'); ?></p>
                <?php } ?>
                <?php osc_admin_progress(array(
                    'id'     => 'backup-progress',
                    'value'  => $words['percent'],
                    'label'  => $words['title'],
                    'status' => $words['line'],
                    'cancel' => $restore ? null : array(
                        'label' => __('Cancel'),
                        'type'  => 'submit',
                        'attrs' => array('form' => 'backup-cancel-form'),
                    ),
                )); ?>
                <p class="backup-callout-note"><?php _e('This keeps going while the page is open. With cron set up it also carries on if you close it.'); ?></p>
            </div>
        </div>
        <?php if (!$restore) {
            osc_admin_form_open(array('page' => 'tools', 'action' => 'backup_cancel', 'id' => 'backup-cancel-form', 'horizontal' => false));
            osc_admin_form_close(null, array('horizontal' => false));
        } ?>
    <?php } elseif ($status === 'failed') {
        $failure = BackupManager::failure($state); ?>
        <div class="callout-danger callout-block backup-callout" role="alert">
            <div class="backup-callout-body">
                <div class="backup-callout-text">
                    <p class="backup-callout-title"><?php echo osc_esc_html(array_shift($failure['lines'])); ?></p>
                    <?php foreach ($failure['lines'] as $line) { ?>
                        <p><?php echo osc_esc_html($line); ?></p>
                    <?php } ?>
                </div>
                <div class="backup-callout-actions">
                    <?php if ($failure['reopen']) {
                        $postButton('backup_reopen', __('Open the site again'), array(), 'primary');
                        osc_admin_action_button(array('label' => __('See the job'), 'url' => osc_admin_base_url(true) . '?page=tools&action=system-info&tab=jobs'));
                    } else {
                        if ($state['kind'] === 'backup') {
                            $postButton('backup_start', __('Try again'), array('what' => $state['what'], 'where' => $state['where']));
                        }
                        $postButton('backup_dismiss', __('Dismiss'));
                    } ?>
                </div>
            </div>
        </div>
    <?php } elseif ($status === 'done' && ($state['where'] ?? '') === 'download') { ?>
        <div class="callout-success callout-block backup-callout">
            <div class="backup-callout-body">
                <div class="backup-callout-text">
                    <p class="backup-callout-title"><?php printf(
                        osc_esc_html(__('Your backup is ready: %1$s, %2$s, %3$s.')),
                        osc_esc_html(BackupJobs::when(date('c', (int) $state['started']))),
                        osc_esc_html(BackupJobs::whatWord((string) $state['what'])),
                        osc_esc_html(DatabaseTools::bytes((int) $state['size']))
                    ); ?></p>
                    <p class="backup-callout-note"><?php _e('It is removed after you download it, or after one hour.'); ?></p>
                    <?php if ($skipped !== '') { ?>
                        <p><?php echo osc_esc_html($skipped); ?></p>
                    <?php } ?>
                </div>
                <div class="backup-callout-actions">
                    <?php $postButton('backup_download', __('Download now'), array('name' => $state['name']), 'primary'); ?>
                </div>
            </div>
        </div>
    <?php } elseif ($notice !== null) {
        $lines = array_values(array_filter($notice['lines'])); ?>
        <div class="callout-<?php echo $notice['tone'] === 'success' ? 'success' : 'info'; ?> callout-block backup-callout" role="status">
            <div class="backup-callout-body">
                <div class="backup-callout-text">
                    <p class="backup-callout-title"><?php echo osc_esc_html(array_shift($lines)); ?></p>
                    <?php foreach ($lines as $line) { ?>
                        <p><?php echo osc_esc_html($line); ?></p>
                    <?php } ?>
                </div>
                <div class="backup-callout-actions">
                    <?php $postButton('backup_dismiss', __('Dismiss')); ?>
                </div>
            </div>
        </div>
    <?php } ?>

    <?php osc_admin_form_section(__('Make a backup'), array(
        'intro' => __('Save a copy of your site. Do it before an update, and now and then anyway.'),
    )); ?>
    <?php osc_admin_form_open(array('page' => 'tools', 'action' => 'backup_start', 'id' => 'backup-form')); ?>
        <?php
        $note = static function (string $text): string {
            return '<span class="backup-choice-note">' . osc_esc_html($text) . '</span>';
        };
        osc_admin_radio_group(array(
            'name'     => 'what',
            'label'    => __('What'),
            'selected' => 'everything',
            'disabled' => $locked,
            'options'  => array(
                'database'   => array('label' => __('Database'), 'custom_html' => $note(__('Listings, users, categories, settings'))),
                'files'      => array('label' => __('Files'), 'custom_html' => $note(__('Photos, plugins, themes, languages'))),
                'everything' => array('label' => __('Everything'), 'custom_html' => $note(__('Database and files'))),
            ),
        ));
        $places = array(
            'download' => __('Download to this computer'),
            'server'   => array(
                'label'       => __('Save on the server'),
                'custom_html' => '<code class="backup-choice-note">' . osc_esc_html(BackupStore::FOLDER) . '</code>',
            ),
        );
        if ($bucket !== null && !$plain) {
            $places['bucket'] = array(
                'label'       => __('Save to your S3 bucket'),
                'custom_html' => '<code class="backup-choice-note">' . osc_esc_html($bucket['label']) . '</code>',
            );
        }
        osc_admin_radio_group(array(
            'name'     => 'where',
            'label'    => __('Where'),
            'selected' => 'download',
            'disabled' => $locked,
            'options'  => $places,
        ));
        ?>
        <?php if ($offload) { ?>
            <?php osc_admin_form_row_open(''); ?>
                <div class="callout-info callout-block" id="backup-bucket-note">
                    <?php _e('Photos live in your S3 bucket and are not copied. They stay there. Turn on versioning in the bucket to keep old copies.'); ?>
                </div>
            <?php osc_admin_form_row_close(); ?>
        <?php } ?>
        <?php if ($locked) { ?>
            <?php osc_admin_form_row_open(''); ?>
                <p class="backup-form-note"><?php echo osc_esc_html($demo ? __('Not available on the demo site.') : __('One backup at a time.')); ?></p>
            <?php osc_admin_form_row_close(); ?>
        <?php } ?>
    <?php osc_admin_form_close(array(
        array(
            'label'   => __('Make backup'),
            'type'    => 'submit',
            'variant' => 'primary',
            'attrs'   => $locked ? array('disabled' => 'disabled') : array(),
        ),
    )); ?>

    <?php osc_admin_form_section(__('Saved backups'), array(
        'spaced'     => true,
        'intro_html' => $list === array() ? '' : osc_esc_html($bucket !== null
            ? sprintf(__('Newest first. Kept: the last %1$d on the server and the last %1$d in the bucket.'), $keep)
            : sprintf(__('Newest first. Kept: the last %d on the server.'), $keep))
            . ' ' . osc_esc_html(__("A backup holds your users' password hashes and your site's keys. Keep it as private as your database.")),
    )); ?>
    <?php if ($bucket !== null && !$bucket['readable']) { ?>
        <p class="text-muted" id="backup-bucket-unread"><?php echo osc_esc_html(BackupBucket::listFailure() === 'timeout'
            ? __('The bucket did not answer; its backups are not listed right now.')
            : __('The bucket could not be read, so backups saved there are not listed. Check the connection in Settings > Storage.')); ?></p>
    <?php } ?>
    <?php if ($list === array()) { ?>
        <p class="text-muted mb-0" id="backup-list-empty"><?php echo osc_esc_html(__('No saved backups yet.') . ' ' . ($bucket !== null
            ? __('Backups you save on the server or in the bucket are listed here. Downloads are not kept.')
            : __('Backups you save on the server are listed here. Downloads are not kept.'))); ?></p>
    <?php } else { ?>
        <div class="table-responsive">
            <table class="table" id="backup-list">
                <thead>
                <tr>
                    <th><?php _e('Date'); ?></th>
                    <th><?php _e('What'); ?></th>
                    <th><?php _e('Where'); ?></th>
                    <th><?php _e('Size'); ?></th>
                    <th><span class="visually-hidden"><?php _e('Actions'); ?></span></th>
                    <th><span class="visually-hidden"><?php _e('Delete'); ?></span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($list as $i => $row) {
                    $words = $describe($row); ?>
                    <tr>
                        <td class="backup-when"><?php echo osc_esc_html($words['when']); ?></td>
                        <td class="backup-meta-cell backup-meta-first"><?php echo osc_esc_html($words['what']); ?>
                            <?php if ($row['kind'] === 'safety') { ?>
                                <span class="backup-sub"><?php _e('made before a restore'); ?></span>
                            <?php } ?>
                        </td>
                        <td class="backup-meta-cell"><?php echo ($row['where'] ?? '') === 'bucket' ? osc_esc_html(__('Bucket')) : osc_esc_html(__('Server')); ?></td>
                        <td class="backup-meta-cell"><?php echo osc_esc_html($words['size']); ?></td>
                        <td>
                            <div class="backup-actions">
                                <?php
                                $postButton('backup_download', __('Download'), $names($row));
                                if ($locked && !$noWeb) {
                                    osc_admin_action_button(array('label' => __('Restore…'), 'attrs' => array('disabled' => 'disabled')));
                                } elseif (!$noWeb) {
                                    osc_admin_action_button(array(
                                        'label' => __('Restore…'),
                                        'url'   => osc_admin_base_url(true) . '?page=tools&action=backup&confirm=' . rawurlencode($row['name'])
                                            . (($row['where'] ?? '') === 'bucket' ? '&from=bucket' : ''),
                                    ));
                                } ?>
                            </div>
                        </td>
                        <td class="backup-delete">
                            <button type="button" class="btn btn-sm btn-link backup-delete-btn"
                                    data-osc-dialog-open="#backup-delete-<?php echo (int) $i; ?>"<?php echo $demo ? ' disabled' : ''; ?>><?php _e('Delete'); ?></button>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php foreach ($list as $i => $row) {
            $words = $describe($row);
            osc_admin_confirm_dialog(array(
                'id'      => 'backup-delete-' . (int) $i,
                'title'   => __('Delete this backup?'),
                'text'    => sprintf(__('%1$s, %2$s, %3$s. This only removes the backup file.'), $words['when'], $words['what'], $words['size']),
                'confirm' => __('Delete'),
                'fields'  => array('page' => 'tools', 'action' => 'backup_delete') + $names($row),
            ));
        } ?>
    <?php } ?>

    <div id="restore">
        <?php osc_admin_form_section(__('Restore from a file'), array('spaced' => true)); ?>
        <?php if ($noWeb) { ?>
            <p class="text-muted mb-0" id="backup-restore-off"><?php echo osc_esc_html($offLine); ?></p>
        <?php } else { ?>
        <?php osc_admin_form_open(array('page' => 'tools', 'action' => 'backup_upload', 'id' => 'backup-upload-form', 'upload' => true)); ?>
            <?php osc_admin_form_row_open(''); ?>
                <div class="callout-danger callout-block">
                    <?php _e("Restoring replaces what is on the site now. It cannot be undone. A safety copy of today's database is saved first."); ?>
                </div>
            <?php osc_admin_form_row_close(); ?>
            <?php osc_admin_field(array(
                'type'     => 'file',
                'id'       => 'backup_file',
                'name'     => 'backup_file',
                'label'    => __('Backup file (.zip or .sql)'),
                'disabled' => $locked,
                'attrs'    => array('accept' => '.zip,.sql'),
                'help'     => $max < PHP_INT_MAX
                    ? sprintf(__('This server accepts files up to %s.'), DatabaseTools::bytes($max))
                    : '',
            )); ?>
        <?php osc_admin_form_close(array(
            array(
                'label'   => __('Restore from this file'),
                'icon'    => 'bi-upload',
                'type'    => 'submit',
                'variant' => 'danger',
                'attrs'   => $locked ? array('disabled' => 'disabled') : array(),
            ),
        )); ?>
        <?php } ?>
    </div>

    <?php if ($confirm !== null) {
        $manifest = $confirm['manifest'];
        $when     = $manifest !== null ? BackupJobs::when((string) $manifest['created']) : '';
        $hasDb    = $confirm['database'];
        $hasFiles = $confirm['files'] > 0;
        $dbBytes  = (int) $confirm['db_bytes'];
        $fBytes   = (int) $confirm['files_bytes'];
        $free     = BackupStore::site()->freeSpace();

        $text = $when !== ''
            ? sprintf(__('Your site goes back to how it was on %s. Anything added since then is lost. Visitors see the maintenance page until it finishes.'), $when)
            : __('Your database is replaced with the one in this file. Anything added since it was made is lost. Visitors see the maintenance page until it finishes.');
        if ($hasDb) {
            $text .= ' ' . __("A safety copy of today's database is saved first.");
        }

        $body = '<p class="backup-restore-note">' . osc_esc_html(__('Only restore backups you made. A restore runs the SQL and puts back the files in it, and it brings back the admin passwords and keys it holds.')) . '</p>';
        if ($hasDb && $hasFiles) {
            $body .= '<input type="hidden" name="choose" value="1" />'
                . '<div class="backup-restore-parts" role="group" aria-label="' . osc_esc_html(__('Put back')) . '">'
                . '<p class="mb-1">' . osc_esc_html(__('Put back:')) . '</p>'
                . '<label><input type="checkbox" name="parts[]" value="database" checked /> '
                . osc_esc_html(sprintf(__('Database (%s)'), DatabaseTools::bytes($dbBytes))) . '</label>'
                . '<label><input type="checkbox" name="parts[]" value="files" checked /> '
                . osc_esc_html(sprintf(__('Files (%s)'), DatabaseTools::bytes($fBytes))) . '</label>'
                . '</div>';
        }
        if ($confirm['from'] === 'bucket') {
            $body .= '<p class="backup-restore-note">' . osc_esc_html(sprintf(__('It is downloaded from the bucket first (%s).'), DatabaseTools::bytes((int) $confirm['size']))) . '</p>';
        }
        if ($confirm['note'] !== '') {
            $body .= '<p class="backup-restore-note">' . osc_esc_html($confirm['note']) . '</p>';
        }
        if ($hasFiles) {
            $body .= '<p class="backup-restore-note">' . osc_esc_html(__('Files added since the backup are left in place.')) . '</p>';
            if ($free !== null && $free < 2 * $fBytes) {
                $body .= '<p class="backup-restore-note">' . osc_esc_html(__('There is not enough space for a safety copy of the files. Only the database is copied first.')) . '</p>';
            }
        }

        // The server checks these again before anything is queued.
        ob_start();
        osc_admin_field(array(
            'type'     => 'secret',
            'name'     => 'password',
            'id'       => 'backup-reauth-password',
            'row'      => false,
            'required' => true,
            'attrs'    => array('autofocus' => true),
        ));
        $passwordField = (string) ob_get_clean();
        $body .= '<div class="backup-reauth">';
        if ($reauth !== '') {
            $body .= '<div class="callout-danger callout-block" role="alert">' . osc_esc_html($reauth) . '</div>';
        }
        $body .= '<div class="backup-reauth-field"><label class="form-label" for="backup-reauth-password">' . osc_esc_html(__('Your password')) . '</label>' . $passwordField . '</div>';
        if ($twoStep) {
            ob_start();
            osc_admin_field(array(
                'type'     => 'text',
                'name'     => 'code',
                'id'       => 'backup-reauth-code',
                'row'      => false,
                'required' => true,
                'attrs'    => array('inputmode' => 'numeric', 'autocomplete' => 'one-time-code', 'maxlength' => '10'),
            ));
            $body .= '<div class="backup-reauth-field"><label class="form-label" for="backup-reauth-code">' . osc_esc_html(__('Code from your app, or a backup code')) . '</label>'
                . (string) ob_get_clean() . '</div>';
        }
        $body .= '</div>';

        osc_admin_confirm_dialog(array(
            'id'        => 'backup-restore-dialog',
            'title'     => __('Restore this backup?'),
            'text'      => $text,
            'body_html' => $body,
            'confirm'   => __('Restore'),
            'fields'    => array('page' => 'tools', 'action' => 'backup_restore') + $names(array('name' => $confirm['name'], 'where' => $confirm['from'])),
        ));
    } ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
