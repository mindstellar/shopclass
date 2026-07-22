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

$group      = __get('group');
$categories = __get('categories');
$selected   = __get('selected');
if (!is_array($selected)) {
    $selected = array();
}
$groupId   = (int)($group['pk_i_id'] ?? 0);
$groupName = $group['s_name'] ?? '';
$groupSlug = $group['s_slug'] ?? '';
$groupMeta = (isset($group['s_meta']) && $group['s_meta'] !== '') ? json_decode($group['s_meta'], true) : array();
$isPlaceable = is_array($groupMeta) && !empty($groupMeta['placeable']);
?>
<!-- field group frame -->
<div id="edit-field-group-frame" class="card custom-field-frame">
    <div class="form-horizontal">
        <form id="edit_group_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="ajax" />
            <input type="hidden" name="action" value="group_post" />
            <input type="hidden" name="id" value="<?php echo $groupId; ?>" />
            <h3 class="card-header"><?php _e('Edit field group'); ?></h3>
            <fieldset>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-label"><?php _e('Group name'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="form-control" name="group_name" id="group_name"
                                   value="<?php echo osc_esc_html($groupName); ?>" autocomplete="off" />
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Identifier name'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="form-control" name="group_slug"
                                   value="<?php echo osc_esc_html($groupSlug); ?>" />
                            <p class="help-inline"><?php _e('Only alphanumeric characters are allowed [a-z0-9_-]'); ?></p>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"></div>
                        <div class="form-controls">
                            <label><input type="checkbox" name="group_placeable" value="1"<?php echo $isPlaceable ? ' checked' : ''; ?> />
                                <span><?php _e('Available as a block (place this form on pages and layouts)'); ?></span></label>
                        </div>
                    </div>
                    <div class="form-row">
                        <div><?php _e('Select the categories where you want to apply this group:'); ?></div>
                        <div class="separate-top">
                            <div class="form-label">
                                <a href="javascript:void(0);" onclick="checkAll('group_cat_tree', true); return false;"><?php _e('Check all'); ?></a>
                                &middot;
                                <a href="javascript:void(0);" onclick="checkAll('group_cat_tree', false); return false;"><?php _e('Uncheck all'); ?></a>
                            </div>
                            <div class="form-controls">
                                <ul id="group_cat_tree">
                                    <?php CategoryForm::categories_tree($categories, $selected); ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer form-actions">
                    <input type="submit" id="group_save" value="<?php echo osc_esc_html(__('Save changes')); ?>" class="btn btn-submit" />
                    <input type="button" value="<?php echo osc_esc_html(__('Cancel')); ?>" class="btn btn-dim" onclick="document.getElementById('edit-field-group-frame').remove();" />
                </div>
            </fieldset>
        </form>
    </div>
</div>
<!-- /field group frame -->
<script type="text/javascript">
    (function () {
        if (typeof oscTreeview === 'function') {
            oscTreeview(document.getElementById('group_cat_tree'), {
                collapsed: true,
                toggleLabel: '<?php echo osc_esc_js(__('Toggle subcategories')); ?>'
            });
        }

        var form = document.getElementById('edit_group_form');
        if (!form) { return; }
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var nameInput = document.getElementById('group_name');
            if (nameInput && nameInput.value.trim() === '') {
                setJsMessage('error', '<?php echo osc_esc_js(__('Group name is required.')); ?>');
                return;
            }
            fetch(form.getAttribute('action'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams(new FormData(form))
            }).then(function (r) { return r.text(); }).then(function (data) {
                var ret;
                try { ret = JSON.parse(data); } catch (err) { ret = null; }
                if (ret && ret.ok) {
                    setJsMessage('ok', ret.ok);
                    var label = document.getElementById('group_name_' + ret.group_id);
                    if (label) { label.textContent = ret.text; }
                    var frame = document.getElementById('edit-field-group-frame');
                    if (frame) { frame.remove(); }
                } else {
                    setJsMessage('error', (ret && ret.error) || '<?php echo osc_esc_js(__('Ajax error, try again.')); ?>');
                }
            }).catch(function () {
                setJsMessage('error', '<?php echo osc_esc_js(__('Ajax error, try again.')); ?>');
            });
        });
    })();
</script>
