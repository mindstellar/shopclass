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

/*
 * Page-builder block form. A typed-only widget form for the page editor's Add /
 * Edit block dialog: it offers registered widget types (never the legacy raw-HTML
 * path, which is unfiltered and page editing is moderator-reachable), so a block
 * can only ever be a purified, capability-gated widget type. Rendered inline in
 * the page editor's normal output, so the shutdown CSRF injector adds the token
 * for free. The form posts to the existing appearance add/edit actions with
 * page_builder_id, which return to the page editor.
 *
 * Inputs set by the includer:
 *   $pbf_location         string  widget location, e.g. "page.42"
 *   $pbf_page_builder_id  int     page id, for the return redirect
 *
 * The form renders in add mode; the Edit buttons repurpose it client-side
 * (see the page editor's pageBlockDialog* helpers).
 */

$pbf_location        = isset($pbf_location) ? (string)$pbf_location : '';
$pbf_page_builder_id = isset($pbf_page_builder_id) ? (int)$pbf_page_builder_id : 0;

// Registered types the current admin may place: hide super_admin-gated types
// from moderators, exactly as the appearance widget picker does.
$pbf_types      = array();
$pbf_isModer    = osc_is_moderator();
foreach (osc_widget_types() as $pbf_id => $pbf_spec) {
    if ($pbf_isModer && ($pbf_spec['capability'] ?? 'admin') === 'super_admin') {
        continue;
    }
    $pbf_types[$pbf_id] = $pbf_spec;
}
$pbf_firstType = '';
foreach ($pbf_types as $pbf_id => $pbf_spec) {
    $pbf_firstType = (string)$pbf_id;
    break;
}

/**
 * DOM id for a page-block config control, namespaced by type so ids stay unique
 * across the (hidden) per-type field groups.
 *
 * @param string $typeId
 * @param string $fieldName
 *
 * @return string
 */
function pbfFieldId($typeId, $fieldName)
{
    return 'pbf-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $typeId . '-' . $fieldName);
}

/**
 * Render one declared config field as a control named config[<name>]. Controls
 * for a type that is not the active one are disabled so they do not post until
 * the type is selected. Field types mirror what buildWidgetConfig accepts.
 *
 * @param string $typeId
 * @param array  $field
 * @param bool   $disabled
 *
 * @return void
 */
function pbfRenderField($typeId, $field, $disabled)
{
    $name = isset($field['name']) && is_string($field['name']) ? $field['name'] : '';
    if ($name === '') {
        return;
    }
    $label     = isset($field['label']) && is_string($field['label']) ? $field['label'] : $name;
    $fieldType = isset($field['type']) && is_string($field['type']) ? $field['type'] : 'text';
    $id        = pbfFieldId($typeId, $name);
    $inputName = 'config[' . $name . ']';
    $dis       = $disabled ? 'disabled="disabled"' : '';
    ?>
    <div class="mb-3">
        <?php if ($fieldType !== 'checkbox') { ?>
            <label for="<?php echo osc_esc_html($id); ?>"><?php echo osc_esc_html($label); ?></label>
        <?php } ?>
        <?php switch ($fieldType) {
            case 'checkbox': ?>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="<?php echo osc_esc_html($id); ?>"
                           name="<?php echo osc_esc_html($inputName); ?>" value="1" <?php echo $dis; ?>/>
                    <label class="form-check-label"
                           for="<?php echo osc_esc_html($id); ?>"><?php echo osc_esc_html($label); ?></label>
                </div>
                <?php break;
            case 'select': ?>
                <select id="<?php echo osc_esc_html($id); ?>" class="form-select form-select-sm"
                        name="<?php echo osc_esc_html($inputName); ?>" <?php echo $dis; ?>>
                    <?php foreach (widgetConfigSelectOptions($field['options'] ?? array()) as $opt) { ?>
                        <option value="<?php echo osc_esc_html($opt['value']); ?>">
                            <?php echo osc_esc_html($opt['label']); ?>
                        </option>
                    <?php } ?>
                </select>
                <?php break;
            case 'number': ?>
                <input type="number" id="<?php echo osc_esc_html($id); ?>" class="form-control form-control-sm"
                       name="<?php echo osc_esc_html($inputName); ?>" <?php echo $dis; ?>/>
                <?php break;
            case 'code': ?>
                <textarea id="<?php echo osc_esc_html($id); ?>" class="form-control widget-code-editor"
                          style="font-family:monospace" rows="8" spellcheck="false" autocomplete="off"
                          name="<?php echo osc_esc_html($inputName); ?>" <?php echo $dis; ?>></textarea>
                <?php break;
            case 'textarea': ?>
                <textarea id="<?php echo osc_esc_html($id); ?>" class="form-control" rows="6"
                          name="<?php echo osc_esc_html($inputName); ?>" <?php echo $dis; ?>></textarea>
                <?php break;
            case 'image': ?>
                <?php // Add-mode renders empty; the edit populate (pages/frm.php) fills
                      // the hidden input and syncs the preview. Wired by media-picker.php. ?>
                <div class="widget-image-field">
                    <input type="hidden" id="<?php echo osc_esc_html($id); ?>" class="widget-image-input"
                           name="<?php echo osc_esc_html($inputName); ?>" value="" <?php echo $dis; ?>/>
                    <div class="widget-image-preview" hidden><img src="" alt=""/></div>
                    <div class="widget-image-actions">
                        <button type="button" class="btn btn-secondary btn-sm widget-image-choose">
                            <?php _e('Choose image'); ?>
                        </button>
                        <button type="button" class="btn btn-link btn-sm widget-image-clear" hidden>
                            <?php _e('Remove'); ?>
                        </button>
                    </div>
                </div>
                <?php break;
            default: ?>
                <input type="text" id="<?php echo osc_esc_html($id); ?>" class="form-control form-control-sm"
                       name="<?php echo osc_esc_html($inputName); ?>" <?php echo $dis; ?>/>
                <?php break;
        } ?>
    </div>
    <?php
}
?>
<form name="page_block_form" id="pageBlockForm" action="<?php echo osc_admin_base_url(true); ?>" method="post">
    <input type="hidden" name="page" value="appearance"/>
    <input type="hidden" name="action" id="pbfAction" value="add_widget_post"/>
    <input type="hidden" name="id" id="pbfWidgetId" value=""/>
    <input type="hidden" name="location" value="<?php echo osc_esc_html($pbf_location); ?>"/>
    <input type="hidden" name="page_builder_id" value="<?php echo $pbf_page_builder_id; ?>"/>

    <?php if (count($pbf_types) === 0) { ?>
        <p class="page-blocks-empty">
            <?php _e('No widget types are available to you. Ask an administrator, or install a widget plugin.'); ?>
        </p>
    <?php } else { ?>
        <div class="mb-3">
            <label for="pbfDescription"><?php _e('Label (for your reference)'); ?></label>
            <input type="text" class="form-control form-control-sm" id="pbfDescription" name="description" required/>
        </div>
        <div class="mb-3">
            <label for="pbfType"><?php _e('Widget type'); ?></label>
            <select id="pbfType" name="s_type" class="form-select form-select-sm">
                <?php foreach ($pbf_types as $pbf_id => $pbf_spec) { ?>
                    <option value="<?php echo osc_esc_html($pbf_id); ?>">
                        <?php echo osc_esc_html($pbf_spec['label']); ?>
                    </option>
                <?php } ?>
            </select>
            <p class="page-field-hint" id="pbfTypeDescription"></p>
        </div>
        <div id="pbfFields">
            <?php foreach ($pbf_types as $pbf_id => $pbf_spec) {
                $pbf_fields = (isset($pbf_spec['fields']) && is_array($pbf_spec['fields'])) ? $pbf_spec['fields'] : array();
                if (empty($pbf_fields)) {
                    continue;
                }
                $pbf_active = ($pbf_id === $pbf_firstType);
                ?>
                <div class="pbf-type-group" data-type-id="<?php echo osc_esc_html($pbf_id); ?>"
                    <?php echo $pbf_active ? '' : 'hidden'; ?>>
                    <?php foreach ($pbf_fields as $pbf_field) {
                        pbfRenderField($pbf_id, $pbf_field, !$pbf_active);
                    } ?>
                </div>
            <?php } ?>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
            <button type="submit" class="btn btn-submit btn-sm" id="pbfSubmit"><?php _e('Add widget'); ?></button>
        </div>
    <?php } ?>
</form>
<script>
    (function () {
        'use strict';
        var form = document.getElementById('pageBlockForm');
        if (!form) { return; }
        var typeSelect = document.getElementById('pbfType');
        var fieldsWrap = document.getElementById('pbfFields');
        var descBox    = document.getElementById('pbfTypeDescription');
        var descriptions = <?php
            $pbf_desc = array();
            foreach ($pbf_types as $pbf_id => $pbf_spec) {
                $pbf_desc[$pbf_id] = isset($pbf_spec['description']) && is_string($pbf_spec['description'])
                    ? $pbf_spec['description'] : '';
            }
            echo json_encode($pbf_desc);
        ?>;

        function setGroupEnabled(group, on) {
            group.querySelectorAll('input, select, textarea').forEach(function (c) { c.disabled = !on; });
        }
        function applyType(typeId) {
            if (fieldsWrap) {
                fieldsWrap.querySelectorAll('[data-type-id]').forEach(function (g) {
                    var active = g.getAttribute('data-type-id') === typeId;
                    g.hidden = !active;
                    setGroupEnabled(g, active);
                });
            }
            if (descBox) { descBox.textContent = descriptions[typeId] || ''; }
        }
        if (typeSelect) {
            typeSelect.addEventListener('change', function () { applyType(typeSelect.value); });
            applyType(typeSelect.value);
        }

        // Exposed to the page editor so an Edit button can repurpose this add
        // form for a specific block, populating its type + config.
        window.pageBlockFormApplyType = applyType;
    })();
</script>
