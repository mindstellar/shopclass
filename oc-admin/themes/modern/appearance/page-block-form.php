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
// Layout blocks lead the list, so the first type shown is the first layout block.
uasort($pbf_types, static fn (array $a, array $b): int => (int) empty($a['builder']) <=> (int) empty($b['builder']));
$pbf_firstType = (string) array_key_first($pbf_types);

?>
<form name="page_block_form" id="pageBlockForm" action="<?php echo osc_admin_base_url(true); ?>" method="post">
    <input type="hidden" name="page" value="appearance"/>
    <input type="hidden" name="action" id="pbfAction" value="add_widget_post"/>
    <input type="hidden" name="id" id="pbfWidgetId" value=""/>
    <input type="hidden" name="location" value="<?php echo osc_esc_html($pbf_location); ?>"/>
    <input type="hidden" name="page_builder_id" value="<?php echo $pbf_page_builder_id; ?>"/>

    <?php if (count($pbf_types) === 0) { ?>
        <p class="page-blocks-empty">
            <?php _e('No block types are available to you. Ask an administrator, or install a plugin that adds blocks.'); ?>
        </p>
    <?php } else { ?>
        <?php
        // Layout blocks, then content blocks, then the raw-code escape hatch on its own.
        $pbf_group = static fn (array $spec): string => ($spec['capability'] ?? 'admin') === 'super_admin'
            ? 'advanced' : (!empty($spec['builder']) ? 'layout' : 'content');
        $pbf_groups = array('layout' => __('Layout'), 'content' => __('Content'), 'advanced' => __('Advanced'));
        $pbf_options = array();
        foreach ($pbf_types as $pbf_id => $pbf_spec) {
            $pbf_options[$pbf_groups[$pbf_group($pbf_spec)]][$pbf_id] = $pbf_spec['label'];
        }
        ?>
        <fieldset class="pbf-type-picker" aria-describedby="pbfTypeDescription">
            <legend class="form-sublabel"><?php _e('Block type'); ?></legend>
            <?php foreach ($pbf_groups as $pbf_key => $pbf_groupLabel) {
                $pbf_inGroup = array_filter($pbf_types, static fn (array $spec): bool => $pbf_group($spec) === $pbf_key);
                if ($pbf_inGroup === array()) {
                    continue;
                } ?>
                <p class="pbf-type-group-title"><?php echo osc_esc_html($pbf_groupLabel); ?></p>
                <div class="pbf-type-cards<?php echo $pbf_key === 'advanced' ? ' is-advanced' : ''; ?>">
                    <?php foreach ($pbf_inGroup as $pbf_id => $pbf_spec) {
                        $pbf_cardId = 'pbfPick-' . preg_replace('/[^a-z0-9_-]/i', '-', (string) $pbf_id); ?>
                        <input type="radio" class="pbf-type-radio" name="pbf_pick" id="<?php echo osc_esc_html($pbf_cardId); ?>"
                               value="<?php echo osc_esc_html((string) $pbf_id); ?>"<?php echo $pbf_id === $pbf_firstType ? ' checked' : ''; ?>/>
                        <label class="pbf-type-card" for="<?php echo osc_esc_html($pbf_cardId); ?>">
                            <i class="bi bi-<?php echo osc_esc_html((string) ($pbf_spec['icon'] ?? '') ?: 'square'); ?>" aria-hidden="true"></i>
                            <span class="pbf-type-card-name"><?php echo osc_esc_html($pbf_spec['label']); ?></span>
                        </label>
                    <?php } ?>
                </div>
            <?php } ?>
            <?php
            // The submitted value; the cards above set it, and the editor's scripts read it.
            osc_admin_select(array(
                'row'     => false,
                'id'      => 'pbfType',
                'name'    => 's_type',
                'options' => $pbf_options,
                'class'   => 'visually-hidden',
                'attrs'   => array('tabindex' => '-1', 'aria-hidden' => 'true'),
            )); ?>
            <p class="page-field-hint" id="pbfTypeDescription" role="note"></p>
        </fieldset>
        <div class="mb-3">
            <label class="form-sublabel" for="pbfDescription"><?php _e('Name in this list (optional)'); ?></label>
            <?php osc_admin_text(array(
                'row'         => false,
                'id'          => 'pbfDescription',
                'name'        => 'description',
                'placeholder' => __('Only you see this name'),
            )); ?>
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
                        osc_widget_config_field($pbf_id, $pbf_field, "", !$pbf_active);
                    } ?>
                </div>
            <?php } ?>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
            <button type="submit" class="btn btn-submit btn-sm" id="pbfSubmit"><?php _e('Add block'); ?></button>
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
        var advanced = <?php echo json_encode(array_keys(array_filter($pbf_types, static fn (array $spec): bool => ($spec['capability'] ?? 'admin') === 'super_admin'))); ?>;

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
            if (descBox) {
                descBox.textContent = descriptions[typeId] || '';
                descBox.classList.toggle('is-warning', advanced.indexOf(typeId) !== -1);
            }
            // The type cards show the same choice as the submitted select.
            form.querySelectorAll('.pbf-type-radio').forEach(function (r) { r.checked = r.value === typeId; });
        }
        if (typeSelect) {
            typeSelect.addEventListener('change', function () { applyType(typeSelect.value); });
            applyType(typeSelect.value);
        }
        form.querySelectorAll('.pbf-type-radio').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (!radio.checked || !typeSelect) { return; }
                typeSelect.value = radio.value;
                typeSelect.dispatchEvent(new Event('change', {bubbles: true}));
            });
        });

        // Exposed to the page editor so an Edit button can repurpose this add
        // form for a specific block, populating its type + config.
        window.pageBlockFormApplyType = applyType;

        // A field with data-show-name shows only while that field holds data-show-value.
        function applyShowIf() {
            form.querySelectorAll('[data-show-name]').forEach(function (field) {
                var group = field.closest('[data-type-id]');
                var name  = field.getAttribute('data-show-name');
                var on    = group ? group.querySelector('input[name="' + name + '"]:checked, select[name="' + name + '"]') : null;
                field.hidden = !on || on.value !== field.getAttribute('data-show-value');
            });
        }
        form.addEventListener('change', applyShowIf);
        window.pageBlockFormRefresh = applyShowIf;
        applyShowIf();

        // Sets a field's value, through its editor when it has one.
        window.pageBlockFormSetValue = function (el, value) {
            el.value = value;
            var editor = typeof tinymce !== 'undefined' ? tinymce.get(el.id) : null;
            if (!editor) { return; }
            // Text saved before the field was rich: blank lines become paragraphs, as on the page.
            if (value !== '' && !/<[a-z]/i.test(value)) {
                value = value.split(/\n\s*\n/).map(function (part) {
                    return '<p>' + part.trim().replace(/\n/g, '<br>') + '</p>';
                }).join('');
            }
            editor.setContent(value);
        };
    })();
</script>
