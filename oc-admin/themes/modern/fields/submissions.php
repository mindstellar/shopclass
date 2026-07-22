<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$forms         = __get('forms');
$currentFormId = (int)__get('current_form_id');
$currentStatus = __get('current_status');
$statusCounts  = __get('status_counts');
$formFields    = __get('form_fields');
$submissions   = __get('submissions');
foreach (array('forms', 'statusCounts', 'formFields', 'submissions') as $v) {
    if (!is_array($$v)) {
        $$v = array();
    }
}

// field id -> definition, for labelling values.
$fieldsById = array();
foreach ($formFields as $f) {
    $fieldsById[(int)$f['pk_i_id']] = $f;
}

$statusLabels = array(
    'new'      => __('New'),
    'read'     => __('Read'),
    'spam'     => __('Spam'),
    'archived' => __('Archived'),
);
$totalForForm = 0;
foreach ($statusCounts as $n) {
    $totalForForm += (int)$n;
}

if (!function_exists('submission_value_display')) {
    /**
     * Escaped display of a stored submission value by field type.
     *
     * @param array|null $field
     * @param mixed      $value
     *
     * @return string
     */
    function submission_value_display($field, $value)
    {
        if (is_array($value)) {
            $parts = array();
            foreach (array('from', 'to') as $k) {
                if (isset($value[$k]) && $value[$k] !== '') {
                    $parts[] = ucfirst($k) . ': ' . osc_esc_html(date(osc_date_format(), (int)$value[$k]));
                }
            }

            return implode(' — ', $parts);
        }
        $eType = $field['e_type'] ?? 'TEXT';
        if ($eType === 'CHECKBOX') {
            return $value == '1' ? __('Yes') : __('No');
        }
        if ($eType === 'DATE' && is_numeric($value)) {
            return osc_esc_html(date(osc_date_format(), (int)$value));
        }

        return nl2br(osc_esc_html((string)$value));
    }
}

function customPageHeader()
{
    ?>
    <h1><?php _e('Listings'); ?></h1>
    <?php
}

osc_add_hook('admin_page_header', 'customPageHeader');

function customPageTitle($string)
{
    return sprintf(__('Form submissions &raquo; %s'), $string);
}

osc_add_filter('admin_title', 'customPageTitle');

$base = osc_admin_base_url(true) . '?page=cfields&action=submissions';

osc_current_admin_theme_path('parts/header.php');
?>
    <h2 class="render-title"><?php _e('Form submissions'); ?></h2>

    <?php if (count($forms) === 0) { ?>
        <div class="builder-empty">
            <?php _e('No forms yet. Create a form and place it on a page to start collecting submissions.'); ?>
        </div>
    <?php } else { ?>

        <div class="submission-forms">
            <?php foreach ($forms as $f) {
                $fid = (int)$f['pk_i_id'];
                $active = ($fid === $currentFormId) ? ' is-active' : ''; ?>
                <a class="submission-pill<?php echo $active; ?>"
                   href="<?php echo $base . '&form_id=' . $fid; ?>">
                    <?php echo osc_esc_html($f['s_name']); ?>
                    <span class="submission-pill-count"><?php echo (int)$f['submission_count']; ?></span>
                </a>
            <?php } ?>
        </div>

        <?php if ($currentFormId > 0) { ?>
            <div class="submission-toolbar">
                <div class="submission-status-tabs">
                    <a class="submission-tab<?php echo $currentStatus === null ? ' is-active' : ''; ?>"
                       href="<?php echo $base . '&form_id=' . $currentFormId; ?>">
                        <?php _e('All'); ?> <span><?php echo $totalForForm; ?></span></a>
                    <?php foreach ($statusLabels as $key => $label) {
                        $count = (int)($statusCounts[$key] ?? 0); ?>
                        <a class="submission-tab<?php echo $currentStatus === $key ? ' is-active' : ''; ?>"
                           href="<?php echo $base . '&form_id=' . $currentFormId . '&status=' . $key; ?>">
                            <?php echo osc_esc_html($label); ?> <span><?php echo $count; ?></span></a>
                    <?php } ?>
                </div>
                <?php if ($totalForForm > 0) { ?>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="purge-button"
                            data-form-id="<?php echo $currentFormId; ?>">
                        <i class="bi bi-trash" aria-hidden="true"></i> <?php _e('Delete all'); ?>
                    </button>
                <?php } ?>
            </div>

            <?php if (count($submissions) === 0) { ?>
                <div class="builder-empty"><?php _e('No submissions in this view yet.'); ?></div>
            <?php } else { ?>
                <div class="table-responsive">
                    <table class="table submission-table">
                        <thead>
                        <tr>
                            <th><?php _e('Received'); ?></th>
                            <th><?php _e('Submission'); ?></th>
                            <th><?php _e('Status'); ?></th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($submissions as $s) {
                            $sid = (int)$s['pk_i_id']; ?>
                            <tr data-sub-id="<?php echo $sid; ?>">
                                <td data-col-name="<?php echo osc_esc_html(__('Received')); ?>">
                                    <?php echo osc_esc_html(osc_format_date($s['dt_created'])); ?>
                                    <div class="submission-origin">
                                        <?php
                                        $ctx = osc_form_context_display($s['s_context_type'], (int)$s['i_context_id']);
                                        if (!empty($ctx['url'])) {
                                            echo '<a href="' . osc_esc_html($ctx['url']) . '" target="_blank" rel="noopener">'
                                                . osc_esc_html($ctx['label']) . '</a>';
                                        } else {
                                            echo osc_esc_html($ctx['label']);
                                        }
                                        if (!empty($s['s_ip'])) {
                                            echo ' · ' . osc_esc_html($s['s_ip']);
                                        } ?>
                                    </div>
                                </td>
                                <td data-col-name="<?php echo osc_esc_html(__('Submission')); ?>">
                                    <dl class="submission-values">
                                        <?php foreach ($s['values'] as $fieldId => $value) {
                                            $field = $fieldsById[(int)$fieldId] ?? null;
                                            $label = $field !== null ? $field['s_name'] : (__('Field') . ' #' . (int)$fieldId); ?>
                                            <dt><?php echo osc_esc_html($label); ?></dt>
                                            <dd><?php echo submission_value_display($field, $value); ?></dd>
                                        <?php } ?>
                                    </dl>
                                </td>
                                <td data-col-name="<?php echo osc_esc_html(__('Status')); ?>">
                                    <select class="form-select form-select-sm submission-status" data-sub-id="<?php echo $sid; ?>">
                                        <?php foreach ($statusLabels as $key => $label) {
                                            $sel = ($s['s_status'] === $key) ? ' selected' : ''; ?>
                                            <option value="<?php echo $key; ?>"<?php echo $sel; ?>><?php echo osc_esc_html($label); ?></option>
                                        <?php } ?>
                                    </select>
                                </td>
                                <td class="submission-actions">
                                    <button type="button" class="cfield-action cfield-action-danger submission-delete"
                                            data-sub-id="<?php echo $sid; ?>" title="<?php echo osc_esc_html(__('Delete')); ?>">
                                        <i class="bi bi-trash-fill" aria-hidden="true"></i></button>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>
        <?php } ?>
    <?php } ?>

    <dialog id="deleteSubModal" class="osc-dialog osc-dialog-danger" data-sub-id="">
        <div class="osc-dialog-body">
            <p class="osc-dialog-title"><i class="bi bi-exclamation-triangle-fill"></i> <?php echo __('Delete submission'); ?></p>
            <p class="osc-dialog-text"><?php _e('This permanently deletes the submission. Continue?'); ?></p>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
            <button id="deleteSubSubmit" type="button" class="btn btn-danger btn-sm"><?php echo __('Delete'); ?></button>
        </div>
    </dialog>
    <dialog id="purgeSubModal" class="osc-dialog osc-dialog-danger" data-form-id="">
        <div class="osc-dialog-body">
            <p class="osc-dialog-title"><i class="bi bi-exclamation-triangle-fill"></i> <?php echo __('Delete all submissions'); ?></p>
            <p class="osc-dialog-text"><?php _e('This permanently deletes every submission for this form. Continue?'); ?></p>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
            <button id="purgeSubSubmit" type="button" class="btn btn-danger btn-sm"><?php echo __('Delete all'); ?></button>
        </div>
    </dialog>
    <script>
        (function () {
            var BASE = '<?php echo osc_admin_base_url(true); ?>';
            var CSRF = '<?php echo osc_csrf_token_url(); ?>';

            // status change
            document.querySelectorAll('.submission-status').forEach(function (sel) {
                sel.addEventListener('change', function () {
                    var body = new URLSearchParams();
                    body.set('id', sel.getAttribute('data-sub-id'));
                    body.set('status', sel.value);
                    fetch(BASE + '?page=ajax&action=form_submission_status&' + CSRF, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body
                    }).then(function (r) { return r.json(); }).then(function (o) {
                        if (o.error) { setJsMessage('error', o.error); } else { setJsMessage('ok', o.ok); }
                    }).catch(function () { setJsMessage('error', '<?php echo osc_esc_js(__('Ajax error, try again.')); ?>'); });
                });
            });

            // delete one
            document.querySelectorAll('.submission-delete').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var m = document.getElementById('deleteSubModal');
                    m.setAttribute('data-sub-id', btn.getAttribute('data-sub-id'));
                    m.showModal();
                });
            });
            document.getElementById('deleteSubSubmit').onclick = function () {
                var m = document.getElementById('deleteSubModal');
                var id = m.dataset.subId;
                m.close();
                fetch(BASE + '?page=ajax&action=form_submission_delete&' + CSRF + '&id=' + id, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); }).then(function (o) {
                        if (o.error) { setJsMessage('error', o.error); }
                        if (o.ok) {
                            setJsMessage('ok', o.ok);
                            var row = document.querySelector('tr[data-sub-id="' + id + '"]');
                            if (row) { row.remove(); }
                        }
                    }).catch(function () { setJsMessage('error', '<?php echo osc_esc_js(__('Ajax error, try again.')); ?>'); });
            };

            // purge all
            var purgeBtn = document.getElementById('purge-button');
            if (purgeBtn) {
                purgeBtn.addEventListener('click', function () {
                    var m = document.getElementById('purgeSubModal');
                    m.setAttribute('data-form-id', purgeBtn.getAttribute('data-form-id'));
                    m.showModal();
                });
            }
            document.getElementById('purgeSubSubmit').onclick = function () {
                var m = document.getElementById('purgeSubModal');
                var fid = m.dataset.formId;
                m.close();
                var body = new URLSearchParams();
                body.set('form_id', fid);
                fetch(BASE + '?page=ajax&action=form_submissions_purge&' + CSRF, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body
                }).then(function (r) { return r.json(); }).then(function (o) {
                    if (o.error) { setJsMessage('error', o.error); }
                    if (o.ok) { location.reload(); }
                }).catch(function () { setJsMessage('error', '<?php echo osc_esc_js(__('Ajax error, try again.')); ?>'); });
            };
        })();
    </script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
