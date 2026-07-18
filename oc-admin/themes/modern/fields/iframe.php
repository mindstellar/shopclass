<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$field      = __get('field');
$categories = __get('categories');
$selected   = __get('selected');
?>
<!-- custom field frame -->
<div id="edit-custom-field-frame" class="card custom-field-frame">
    <div class="form-horizontal">
        <form id="nedit_field_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="ajax" />
            <input type="hidden" name="action" value="field_categories_post" />
            <?php FieldForm::primary_input_hidden($field); ?>
            <h3 class="card-header"><?php _e('Edit custom field'); ?></h3>
            <fieldset>
                <div class="card-body">
                    <div class="form-row">
                        <?php FieldForm::multiLangTitle($field); ?>
                    </div>
                    <div class="col-md-6">
                        <div class="form-row" id="div_field_options">
                            <div class="form-label"><?php _e('Options'); ?></div>
                            <div class="form-controls">
                                <?php FieldForm::options_input_text($field); ?>
                                <p class="help-inline"><?php _e('Separate options with commas'); ?></p>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-label"><?php _e('Type'); ?></div>
                            <div class="form-controls"><?php FieldForm::type_select($field); ?></div>
                        </div>
                        <div class="form-row">
                            <div class="form-label"></div>
                            <div class="form-controls"><label><?php FieldForm::required_checkbox($field); ?>
                                    <span><?php _e('This field is required'); ?></span></label></div>
                        </div>
                        <div class="form-row">
                            <div><?php _e('Select the categories where you want to apply this attribute:'); ?></div>
                            <div class="separate-top">
                                <div class="form-label">
                                    <a href="javascript:void(0);" onclick="checkAll('cat_tree', true); return false;"><?php _e('Check all'); ?></a>
                                    &middot;
                                    <a href="javascript:void(0);" onclick="checkAll('cat_tree', false); return false;"><?php _e('Uncheck all'); ?></a>
                                </div>
                                <div class="form-controls">
                                    <ul id="cat_tree">
                                        <?php CategoryForm::categories_tree($categories, $selected); ?>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div id="advanced_fields_iframe" class="custom-field-shrink">
                            <span class="icon-more"></span><?php _e('Advanced options'); ?>
                        </div>
                        <div id="more-options_iframe" class="input-line">
                            <div class="form-row" id="div_field_options">
                                <div class="form-label"><?php _e('Identifier name'); ?></div>
                                <div class="form-controls">
                                    <input type="text" class="form-control" name="field_slug" value="<?php echo $field['s_slug']; ?>" />
                                    <p class="help-inline"><?php _e('Only alphanumeric characters are allowed [a-z0-9_-]'); ?></p>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-label"></div>
                                <div class="form-controls">
                                    <label><?php FieldForm::searchable_checkbox($field); ?><?php
                                                                                            _e('Tick to allow searches by this field'); ?></label>
                                </div>
                            </div>
                            <div class="form-row" id="field_newtab" style="display: none;">
                                <div class="form-label"></div>
                                <div class="form-controls">
                                    <label><?php FieldForm::newtab_checkbox($field); ?><?php
                                                                                        _e('Tick to open links in new tab'); ?></label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer form-actions">
                    <input type="submit" id="cfield_save" value="<?php echo osc_esc_html(__('Save changes')); ?>" class="btn btn-submit" />
                    <input type="button" value="<?php echo osc_esc_html(__('Cancel')); ?>" class="btn btn-dim" onclick="document.getElementById('edit-custom-field-frame').remove();" />
                </div>
            </fieldset>
        </form>
    </div>
</div>
<!-- /custom field frame -->
<script type="text/javascript">
    (function () {
        if (typeof oscTreeview === 'function') {
            oscTreeview(document.getElementById('cat_tree'), {
                collapsed: true,
                toggleLabel: '<?php echo osc_esc_js(__('Toggle subcategories')); ?>'
            });
        }

        var typeInput = document.querySelector('select[name="field_type"]');
        var optionsDiv = document.getElementById('div_field_options');
        var optionsInput = optionsDiv ? optionsDiv.querySelector('input[name="s_options"]') : null;
        var fieldNewtab = document.getElementById('field_newtab');
        var defaultLocale = '<?php echo osc_esc_js(osc_current_admin_locale()); ?>';
        var form = document.getElementById('nedit_field_form');

        // Show the options field only for DROPDOWN/RADIO, the new-tab toggle only
        // for URL — mirror the state on load and on every type change.
        function syncType() {
            var v = typeInput ? typeInput.value : '';
            if (optionsDiv) { optionsDiv.style.display = (v === 'DROPDOWN' || v === 'RADIO') ? '' : 'none'; }
            if (fieldNewtab) { fieldNewtab.style.display = (v === 'URL') ? '' : 'none'; }
        }
        if (typeInput) { typeInput.addEventListener('change', syncType); }
        syncType();

        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var message = '';
                var nameInput = form.querySelector('[name="meta_s_name[' + defaultLocale + ']"]');
                if (nameInput && nameInput.value === '') {
                    message += '<?php echo osc_esc_js(__('Name for default locale is required.')); ?>';
                }
                var v = typeInput ? typeInput.value : '';
                if (v === 'DROPDOWN' || v === 'RADIO') {
                    if (optionsInput && optionsInput.value === '') {
                        message += '<?php echo osc_esc_js(__('Options are required.')); ?>';
                    }
                } else if (optionsInput) {
                    optionsInput.value = '';
                }
                if (message !== '') {
                    setJsMessage('error', message);
                    return;
                }

                fetch(form.getAttribute('action'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new URLSearchParams(new FormData(form))
                }).then(function (r) {
                    return r.text();
                }).then(function (data) {
                    var ret;
                    try { ret = JSON.parse(data); } catch (err) { ret = (new Function('return (' + data + ')'))(); }
                    if (ret && ret.ok) {
                        var label = document.getElementById('quick_edit_' + ret.field_id);
                        if (label) { label.textContent = ret.text; }
                        setJsMessage('ok', ret.ok);
                        var cl = document.querySelector('.content_list_<?php echo (int)$field['pk_i_id']; ?>');
                        if (cl) { cl.innerHTML = ''; }
                    } else {
                        setJsMessage('error', (ret && ret.error) || '<?php echo osc_esc_js(__('Ajax error, try again.')); ?>');
                    }
                }).catch(function () {
                    setJsMessage('error', '<?php echo osc_esc_js(__('Ajax error, try again.')); ?>');
                });
            });
        }

        var advanced = document.getElementById('advanced_fields_iframe');
        var moreOptions = document.getElementById('more-options_iframe');
        if (moreOptions) { moreOptions.style.display = 'none'; }
        if (advanced) {
            advanced.addEventListener('click', function () {
                if (moreOptions) {
                    moreOptions.style.display = (moreOptions.style.display === 'none') ? '' : 'none';
                }
                advanced.classList.toggle('custom-field-shrink');
                advanced.classList.toggle('custom-field-expanded');
            });
        }
    })();
</script>