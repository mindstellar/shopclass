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
$allFields  = __get('allFields');
if (!is_array($allFields)) {
    $allFields = array();
}
$allGroups  = __get('allGroups');
if (!is_array($allGroups)) {
    $allGroups = array();
}
$fieldGroupId = (int)($field['fk_i_group_id'] ?? 0);

// Per-type config keys, so the editor can show only the inputs a type supports.
$fieldTypeConfig = array();
foreach (osc_field_types() as $ftId => $ftSpec) {
    $fieldTypeConfig[$ftId] = $ftSpec['config'];
}

// Existing per-field config values (merged from s_meta by extendField).
$cfgValue = static function ($key) use ($field) {
    return isset($field[$key]) && !is_array($field[$key]) ? $field[$key] : '';
};

// Existing conditional rule (single show_when/required_when condition supported by
// this editor; the stored schema allows more and is preserved on the wire).
$rules      = (isset($field['rules']) && is_array($field['rules'])) ? $field['rules'] : array();
$ruleAction = isset($rules['required_when']) ? 'required_when' : (isset($rules['show_when']) ? 'show_when' : '');
$ruleCond   = $rules[$ruleAction] ?? array();

// Existing cascading-options config, rebuilt into the "ParentValue: a, b, c" text.
$cascadeParent = (isset($field['cascade_parent']) && !is_array($field['cascade_parent'])) ? $field['cascade_parent'] : '';
$cascadeText   = '';
if (isset($field['cascade_map']) && is_array($field['cascade_map'])) {
    $lines = array();
    foreach ($field['cascade_map'] as $parentValue => $opts) {
        $lines[] = $parentValue . ': ' . implode(', ', (array)$opts);
    }
    $cascadeText = implode("\n", $lines);
}

// Builder mode: opened from the two-pane forms builder. Membership (which forms)
// and category placement are managed by drag-drop and on the form, so the field
// editor hides its Group dropdown and category tree and saves the definition only.
$builderMode = Params::getParam('builder') == '1';
?>
<!-- custom field frame -->
<div id="edit-custom-field-frame" class="card custom-field-frame">
    <div class="form-horizontal">
        <form id="nedit_field_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="ajax" />
            <input type="hidden" name="action" value="field_categories_post" />
            <?php if ($builderMode) { ?><input type="hidden" name="builder" value="1" /><?php } ?>
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
                        <?php if (!$builderMode) { ?>
                        <div class="form-row">
                            <div class="form-label"><?php _e('Group'); ?></div>
                            <div class="form-controls">
                                <select class="form-select" name="field_group" id="field_group">
                                    <option value="0"><?php _e('Ungrouped'); ?></option>
                                    <?php foreach ($allGroups as $g) {
                                        $selAttr = ($fieldGroupId === (int)$g['pk_i_id']) ? ' selected' : '';
                                        echo '<option value="' . (int)$g['pk_i_id'] . '"' . $selAttr . '>'
                                            . osc_esc_html($g['s_name']) . '</option>';
                                    } ?>
                                </select>
                                <p class="help-inline"><?php _e('Grouped fields inherit their categories from the group and render as a section.'); ?></p>
                            </div>
                        </div>
                        <?php } ?>
                        <div class="form-row">
                            <div class="form-label"></div>
                            <div class="form-controls"><label><?php FieldForm::required_checkbox($field); ?>
                                    <span><?php _e('This field is required'); ?></span></label></div>
                        </div>

                        <?php
                        // Configuration inputs. Each row carries the config key it
                        // sets; the JS shows only the rows the selected type supports.
                        $cfgRows = array(
                            'placeholder' => array('label' => __('Placeholder'), 'type' => 'text'),
                            'help_text'   => array('label' => __('Help text'), 'type' => 'text'),
                            'default'     => array('label' => __('Default value'), 'type' => 'text'),
                            'min'         => array('label' => __('Minimum'), 'type' => 'number'),
                            'max'         => array('label' => __('Maximum'), 'type' => 'number'),
                            'step'        => array('label' => __('Step'), 'type' => 'number'),
                            'maxlength'   => array('label' => __('Max length'), 'type' => 'number'),
                            'rows'        => array('label' => __('Rows'), 'type' => 'number'),
                            'pattern'     => array('label' => __('Pattern (regex)'), 'type' => 'text'),
                        );
                        ?>
                        <div id="cf_config_block" class="cf-config-block">
                            <?php foreach ($cfgRows as $cfgKey => $cfgRow) { ?>
                                <div class="form-row cf-config-row" data-cfg-key="<?php echo osc_esc_html($cfgKey); ?>">
                                    <div class="form-label"><?php echo osc_esc_html($cfgRow['label']); ?></div>
                                    <div class="form-controls">
                                        <input type="<?php echo $cfgRow['type']; ?>" class="form-control"
                                               name="cfg_<?php echo osc_esc_html($cfgKey); ?>"
                                               value="<?php echo osc_esc_html($cfgValue($cfgKey)); ?>"<?php
                                               echo $cfgRow['type'] === 'number' ? ' step="any"' : ''; ?> />
                                    </div>
                                </div>
                            <?php } ?>
                        </div>

                        <?php
                        // Conditional visibility / requirement — one condition against
                        // a sibling field. Emitted as JSON in cfg_rules on submit.
                        ?>
                        <div id="cf_rules_block" class="cf-rules-block">
                            <div class="form-row">
                                <div class="form-label"><?php _e('Conditional logic'); ?></div>
                                <div class="form-controls">
                                    <select class="form-select" id="cf_rule_action">
                                        <option value=""<?php echo $ruleAction === '' ? ' selected' : ''; ?>><?php _e('Always show'); ?></option>
                                        <option value="show_when"<?php echo $ruleAction === 'show_when' ? ' selected' : ''; ?>><?php _e('Show only when…'); ?></option>
                                        <option value="required_when"<?php echo $ruleAction === 'required_when' ? ' selected' : ''; ?>><?php _e('Required only when…'); ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row" id="cf_rule_condition" style="display:none;">
                                <div class="form-label"></div>
                                <div class="form-controls cf-rule-condition">
                                    <select class="form-select" id="cf_rule_field">
                                        <option value=""><?php _e('Select a field'); ?></option>
                                        <?php foreach ($allFields as $sibling) {
                                            if ((int)$sibling['pk_i_id'] === (int)$field['pk_i_id']) {
                                                continue; // a field cannot depend on itself
                                            }
                                            $selAttr = (isset($ruleCond['field']) && $ruleCond['field'] === $sibling['s_slug']) ? ' selected' : '';
                                            echo '<option value="' . osc_esc_html($sibling['s_slug']) . '"' . $selAttr . '>'
                                                . osc_esc_html($sibling['s_name']) . '</option>';
                                        } ?>
                                    </select>
                                    <select class="form-select" id="cf_rule_op">
                                        <?php
                                        $ops = array('eq' => __('is'), 'neq' => __('is not'), 'filled' => __('is filled'), 'gt' => __('is greater than'), 'lt' => __('is less than'));
                                        foreach ($ops as $opKey => $opLabel) {
                                            $selAttr = (isset($ruleCond['op']) && $ruleCond['op'] === $opKey) ? ' selected' : '';
                                            echo '<option value="' . $opKey . '"' . $selAttr . '>' . osc_esc_html($opLabel) . '</option>';
                                        } ?>
                                    </select>
                                    <input type="text" class="form-control" id="cf_rule_value"
                                           value="<?php echo osc_esc_html($ruleCond['value'] ?? ''); ?>"
                                           placeholder="<?php echo osc_esc_html(__('Value')); ?>" />
                                </div>
                            </div>
                            <input type="hidden" name="cfg_rules" id="cfg_rules" value="" />
                        </div>

                        <?php
                        // Cascading options — only meaningful for choice fields; the JS
                        // shows this block for DROPDOWN/RADIO only.
                        ?>
                        <div id="cf_cascade_block" class="cf-rules-block" style="display:none;">
                            <div class="form-row">
                                <div class="form-label"><?php _e('Cascading options'); ?></div>
                                <div class="form-controls">
                                    <select class="form-select" name="cfg_cascade_parent" id="cfg_cascade_parent">
                                        <option value=""><?php _e('Not cascading'); ?></option>
                                        <?php foreach ($allFields as $sibling) {
                                            if ((int)$sibling['pk_i_id'] === (int)$field['pk_i_id']) {
                                                continue;
                                            }
                                            $selAttr = ($cascadeParent === $sibling['s_slug']) ? ' selected' : '';
                                            echo '<option value="' . osc_esc_html($sibling['s_slug']) . '"' . $selAttr . '>'
                                                . osc_esc_html($sibling['s_name']) . '</option>';
                                        } ?>
                                    </select>
                                    <p class="help-inline"><?php _e('Filter this field\'s options by the value of a parent field.'); ?></p>
                                </div>
                            </div>
                            <div class="form-row" id="cf_cascade_map_row" style="display:none;">
                                <div class="form-label"><?php _e('Option map'); ?></div>
                                <div class="form-controls">
                                    <textarea class="form-control" name="cfg_cascade_map" id="cfg_cascade_map" rows="5"
                                              placeholder="Toyota: Corolla, Camry, RAV4&#10;Honda: Civic, Accord"><?php echo osc_esc_html($cascadeText); ?></textarea>
                                    <p class="help-inline"><?php _e('One line per parent value: "ParentValue: option1, option2".'); ?></p>
                                </div>
                            </div>
                        </div>
                        <?php if (!$builderMode) { ?>
                        <div class="form-row" id="field_cat_select">
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
                        <?php } ?>

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

        // Which config keys each type supports (from the field-type registry).
        var typeConfig = <?php echo json_encode($fieldTypeConfig); ?>;

        // Show the options field only for types that use options (DROPDOWN/RADIO),
        // the new-tab toggle only for URL, and each config row only for a type that
        // declares it — mirrored on load and on every type change.
        var cascadeBlock = document.getElementById('cf_cascade_block');
        function syncType() {
            var v = typeInput ? typeInput.value : '';
            var keys = typeConfig[v] || [];
            var usesOptions = (v === 'DROPDOWN' || v === 'RADIO');
            if (optionsDiv) { optionsDiv.style.display = usesOptions ? '' : 'none'; }
            if (fieldNewtab) { fieldNewtab.style.display = (v === 'URL') ? '' : 'none'; }
            if (cascadeBlock) { cascadeBlock.style.display = usesOptions ? '' : 'none'; }
            document.querySelectorAll('.cf-config-row').forEach(function (row) {
                var key = row.getAttribute('data-cfg-key');
                row.style.display = (keys.indexOf(key) !== -1) ? '' : 'none';
            });
        }
        if (typeInput) { typeInput.addEventListener('change', syncType); }
        syncType();

        // Show the cascade option-map textarea only once a parent field is picked.
        var cascadeParentSel = document.getElementById('cfg_cascade_parent');
        var cascadeMapRow = document.getElementById('cf_cascade_map_row');
        function syncCascade() {
            if (cascadeMapRow) {
                cascadeMapRow.style.display = (cascadeParentSel && cascadeParentSel.value !== '') ? '' : 'none';
            }
        }
        if (cascadeParentSel) { cascadeParentSel.addEventListener('change', syncCascade); }
        syncCascade();

        // A grouped field takes its categories from the group, so hide the per-field
        // category picker while a group is selected.
        var groupInput = document.getElementById('field_group');
        var catSelect = document.getElementById('field_cat_select');
        function syncGroup() {
            if (catSelect) {
                catSelect.style.display = (groupInput && groupInput.value !== '0' && groupInput.value !== '') ? 'none' : '';
            }
        }
        if (groupInput) { groupInput.addEventListener('change', syncGroup); }
        syncGroup();

        // Conditional-logic builder: reveal the condition row when an action is set,
        // hide the value box for the "is filled" operator.
        var ruleAction = document.getElementById('cf_rule_action');
        var ruleCondition = document.getElementById('cf_rule_condition');
        var ruleField = document.getElementById('cf_rule_field');
        var ruleOp = document.getElementById('cf_rule_op');
        var ruleValue = document.getElementById('cf_rule_value');
        var rulesHidden = document.getElementById('cfg_rules');

        function syncRule() {
            var on = ruleAction && ruleAction.value !== '';
            if (ruleCondition) { ruleCondition.style.display = on ? '' : 'none'; }
            if (ruleValue) { ruleValue.style.display = (ruleOp && ruleOp.value === 'filled') ? 'none' : ''; }
        }
        function serializeRule() {
            if (!rulesHidden) { return; }
            if (!ruleAction || ruleAction.value === '' || !ruleField || ruleField.value === '') {
                rulesHidden.value = '';
                return;
            }
            var cond = { field: ruleField.value, op: ruleOp ? ruleOp.value : 'eq' };
            if (cond.op !== 'filled') { cond.value = ruleValue ? ruleValue.value : ''; }
            var obj = {};
            obj[ruleAction.value] = cond;
            rulesHidden.value = JSON.stringify(obj);
        }
        if (ruleAction) { ruleAction.addEventListener('change', syncRule); }
        if (ruleOp) { ruleOp.addEventListener('change', syncRule); }
        syncRule();

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

                serializeRule();

                fetch(form.getAttribute('action'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new URLSearchParams(new FormData(form))
                }).then(function (r) {
                    return r.text();
                }).then(function (data) {
                    var ret;
                    // Hard JSON parse — never eval the response body. A non-JSON body
                    // means the save endpoint errored (e.g. a PHP warning corrupted it);
                    // surface that instead of executing whatever came back.
                    try { ret = JSON.parse(data); } catch (err) {
                        setJsMessage('error', '<?php echo osc_esc_js(__('Ajax error, try again.')); ?>');
                        return;
                    }
                    if (ret && ret.ok) {
                        // Legacy list label (if present)…
                        var label = document.getElementById('quick_edit_' + ret.field_id);
                        if (label) { label.textContent = ret.text; }
                        // …and every builder chip for this field (name + type badge).
                        document.querySelectorAll('.field-chip[data-field-id="' + ret.field_id + '"]').forEach(function (chip) {
                            var n = chip.querySelector('.chip-name');
                            if (n) { n.textContent = ret.text; }
                            if (ret.type_label) {
                                var t = chip.querySelector('.chip-type');
                                if (t) { t.textContent = ret.type_label; }
                            }
                        });
                        setJsMessage('ok', ret.ok);
                        var cl = document.querySelector('.content_list_<?php echo (int)$field['pk_i_id']; ?>');
                        if (cl) { cl.innerHTML = ''; }
                        // In the builder, close the editor slot after a save.
                        var slot = document.getElementById('field-editor-slot');
                        if (slot && slot.contains(document.getElementById('nedit_field_form'))) {
                            slot.innerHTML = '';
                            document.querySelectorAll('.field-chip.is-editing').forEach(function (el) { el.classList.remove('is-editing'); });
                        }
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