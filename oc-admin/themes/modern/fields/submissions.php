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
    <h1><?php _e('Forms'); ?></h1>
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

    <div class="relative">
        <?php if (count($forms) > 0) { ?>
            <div id="listing-toolbar">
                <div class="d-flex justify-content-end gap-1">
                    <div class="input-group input-group-sm">
                        <label class="input-group-text" for="form-picker"><?php _e('Form'); ?></label>
                        <select class="form-select" id="form-picker">
                            <?php foreach ($forms as $f) {
                                $fid = (int)$f['pk_i_id']; ?>
                                <option value="<?php echo $fid; ?>"<?php echo $fid === $currentFormId ? ' selected' : ''; ?>><?php
                                    echo osc_esc_html($f['s_name'] . ' (' . (int)$f['submission_count'] . ')'); ?></option>
                            <?php } ?>
                        </select>
                        <?php if ($currentFormId > 0) { ?>
                            <label class="input-group-text" for="status-picker"><?php _e('Status'); ?></label>
                            <select class="form-select" id="status-picker">
                                <option value=""<?php echo $currentStatus === null ? ' selected' : ''; ?>><?php
                                    echo osc_esc_html(sprintf(__('All (%d)'), $totalForForm)); ?></option>
                                <?php foreach ($statusLabels as $key => $label) { ?>
                                    <option value="<?php echo $key; ?>"<?php echo $currentStatus === $key ? ' selected' : ''; ?>><?php
                                        echo osc_esc_html($label . ' (' . (int)($statusCounts[$key] ?? 0) . ')'); ?></option>
                                <?php } ?>
                            </select>
                        <?php } ?>
                        <?php if ($currentFormId > 0 && $totalForForm > 0) { ?>
                            <button type="button" class="btn btn-dim" id="purge-button"
                                    data-form-id="<?php echo $currentFormId; ?>"><?php _e('Delete all'); ?></button>
                        <?php } ?>
                    </div>
                </div>
            </div>
        <?php } ?>

        <div class="table-contains-actions">
            <table class="table" cellpadding="0" cellspacing="0">
                <thead>
                <tr>
                    <th class="col-status"><?php _e('Status'); ?></th>
                    <th class="col-received"><?php _e('Received'); ?></th>
                    <th class="col-submission"><?php _e('Submission'); ?></th>
                    <th class="col-source"><?php _e('Source'); ?></th>
                    <th class="col-ip"><?php _e('IP'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($currentFormId > 0 && count($submissions) > 0) {
                    foreach ($submissions as $s) {
                        $sid    = (int)$s['pk_i_id'];
                        $status = (string)$s['s_status'];
                        $ctx    = osc_form_context_display($s['s_context_type'], (int)$s['i_context_id']);
                        // One-line preview, so a row keeps its height however many fields
                        // the form grows to. The full set opens in the detail dialog.
                        $preview = array();
                        foreach ($s['values'] as $fieldId => $value) {
                            $field = $fieldsById[(int)$fieldId] ?? null;
                            $label = $field !== null ? $field['s_name'] : (__('Field') . ' #' . (int)$fieldId);
                            // submission_value_display() already escapes; strip_tags only
                            // removes the <br> it adds, so the text stays escaped.
                            $plain = trim(preg_replace('/\s+/', ' ', strip_tags(submission_value_display($field, $value))));
                            if ($plain !== '') {
                                $preview[] = osc_esc_html($label) . ': ' . $plain;
                            }
                        } ?>
                        <tr class="status-<?php echo osc_esc_html($status); ?>" data-sub-id="<?php echo $sid; ?>">
                            <td class="col-status" data-col-name="<?php echo osc_esc_html(__('Status')); ?>">
                                <span class="osc-status"><?php echo osc_esc_html($statusLabels[$status] ?? $status); ?></span>
                            </td>
                            <td class="col-received" data-col-name="<?php echo osc_esc_html(__('Received')); ?>">
                                <strong><?php echo osc_esc_html(osc_format_date($s['dt_created'])); ?></strong>
                                <div class="actions">
                                    <ul>
                                        <li><a href="#" onclick="view_submission(<?php echo $sid; ?>); return false;"><?php _e('View'); ?></a></li>
                                        <?php foreach ($statusLabels as $key => $label) {
                                            if ($key === $status) {
                                                continue;
                                            } ?>
                                            <li><a href="#" onclick="set_submission_status(<?php echo $sid; ?>, '<?php echo $key; ?>'); return false;"><?php
                                                echo osc_esc_html(sprintf(__('Mark %s'), mb_strtolower($label))); ?></a></li>
                                        <?php } ?>
                                        <li><a href="#" class="row-action-danger" onclick="delete_dialog(<?php echo $sid; ?>); return false;"><?php
                                            _e('Delete'); ?></a></li>
                                    </ul>
                                </div>
                            </td>
                            <td class="col-submission" data-col-name="<?php echo osc_esc_html(__('Submission')); ?>">
                                <a href="#" class="submission-preview" onclick="view_submission(<?php echo $sid; ?>); return false;"><?php
                                    echo $preview === array()
                                        ? osc_esc_html(__('(no values)'))
                                        : implode(' · ', $preview); ?></a>
                                <template class="submission-detail">
                                    <dl class="submission-values">
                                        <?php foreach ($s['values'] as $fieldId => $value) {
                                            $field = $fieldsById[(int)$fieldId] ?? null;
                                            $label = $field !== null ? $field['s_name'] : (__('Field') . ' #' . (int)$fieldId); ?>
                                            <dt><?php echo osc_esc_html($label); ?></dt>
                                            <dd><?php echo submission_value_display($field, $value); ?></dd>
                                        <?php } ?>
                                    </dl>
                                </template>
                            </td>
                            <td class="col-source" data-col-name="<?php echo osc_esc_html(__('Source')); ?>">
                                <?php if (!empty($ctx['url'])) {
                                    echo '<a href="' . osc_esc_html($ctx['url']) . '" target="_blank" rel="noopener">'
                                        . osc_esc_html($ctx['label']) . '</a>';
                                } else {
                                    echo osc_esc_html($ctx['label']);
                                } ?>
                            </td>
                            <td class="col-ip" data-col-name="<?php echo osc_esc_html(__('IP')); ?>">
                                <?php echo osc_esc_html($s['s_ip'] ?? ''); ?>
                            </td>
                        </tr>
                    <?php }
                } else { ?>
                    <tr>
                        <td colspan="5" class="text-center">
                            <p><?php if (count($forms) === 0) {
                                _e('No forms yet. Create a form and place it on a page to start collecting submissions.');
                            } elseif ($currentFormId > 0) {
                                _e('No submissions in this view yet.');
                            } else {
                                _e('Pick a form to see its submissions.');
                            } ?></p>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
            <div id="table-row-actions"></div><!-- used for table actions -->
        </div>
    </div>

    <dialog id="submissionModal" class="osc-dialog osc-dialog-wide">
        <div class="osc-dialog-body">
            <p class="osc-dialog-title" id="submissionModalTitle"></p>
            <div id="submissionModalBody"></div>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Close'); ?></button>
        </div>
    </dialog>
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
        var BASE = '<?php echo osc_admin_base_url(true); ?>';
        var CSRF = '<?php echo osc_csrf_token_url(); ?>';
        var SUBS_BASE = '<?php echo osc_esc_js($base); ?>';

        // Row action: move a submission to another status, then reload so the row
        // lands in the right filter and the counts stay honest.
        function set_submission_status(id, status) {
            var body = new URLSearchParams();
            body.set('id', id);
            body.set('status', status);
            fetch(BASE + '?page=ajax&action=form_submission_status&' + CSRF, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body
            }).then(function (r) { return r.json(); }).then(function (o) {
                if (o.error) { setJsMessage('error', o.error); return; }
                location.reload();
            }).catch(function () { setJsMessage('error', '<?php echo osc_esc_js(__('Ajax error, try again.')); ?>'); });
            return false;
        }

        // The row shows a one-line preview; the whole submission opens here, so a form
        // with many fields never stretches the row.
        function view_submission(id) {
            var row = document.querySelector('tr[data-sub-id="' + id + '"]');
            var tpl = row ? row.querySelector('template.submission-detail') : null;
            var body = document.getElementById('submissionModalBody');
            if (!tpl || !body) { return false; }
            body.replaceChildren(tpl.content.cloneNode(true));
            var received = row.querySelector('.col-received strong');
            document.getElementById('submissionModalTitle').textContent = received ? received.textContent : '';
            document.getElementById('submissionModal').showModal();
            return false;
        }

        // Named delete_dialog to match the other listing screens.
        function delete_dialog(id) {
            var m = document.getElementById('deleteSubModal');
            m.setAttribute('data-sub-id', id);
            m.showModal();
            return false;
        }

        (function () {
            // Toolbar filters navigate on change.
            var formPicker = document.getElementById('form-picker');
            if (formPicker) {
                formPicker.addEventListener('change', function () {
                    window.location = SUBS_BASE + '&form_id=' + encodeURIComponent(formPicker.value);
                });
            }
            var statusPicker = document.getElementById('status-picker');
            if (statusPicker) {
                statusPicker.addEventListener('change', function () {
                    var url = SUBS_BASE + '&form_id=' + encodeURIComponent('<?php echo $currentFormId; ?>');
                    if (statusPicker.value !== '') { url += '&status=' + encodeURIComponent(statusPicker.value); }
                    window.location = url;
                });
            }

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
