<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\fields\FieldTypeRegistry;

/**
 * Custom-field helpers: the field-type registry facade and the field-group
 * accessors. Field types describe the inputs the field builder offers; field
 * groups are reusable, category-assigned sets of fields rendered as form sections.
 */

/**
 * Register a custom-field type so the field builder can offer it. Thin wrapper over
 * FieldTypeRegistry::register().
 *
 * See FieldTypeRegistry::register() for the $spec shape.
 *
 * @param string $id
 * @param array  $spec
 *
 * @return void
 */
function osc_register_field_type($id, $spec)
{
    FieldTypeRegistry::instance()->register($id, $spec);
}


/**
 * All registered field types, keyed by id.
 *
 * @return array
 */
function osc_field_types()
{
    return FieldTypeRegistry::instance()->all();
}


/**
 * The spec for a registered field type, or null when it is not registered.
 *
 * @param string $id
 *
 * @return array|null
 */
function osc_field_type($id)
{
    return FieldTypeRegistry::instance()->get($id);
}


/**
 * The storage primitive (t_meta_fields.e_type value) a field type stores as.
 *
 * @param string $id
 *
 * @return string
 */
function osc_field_type_storage($id)
{
    return FieldTypeRegistry::instance()->storageOf($id);
}


/**
 * Resolve a field row's real type id. New/plugin types persist their identity in
 * s_meta ('type') while e_type holds the storage primitive; built-in types store
 * their id directly in e_type. extendField() merges s_meta into the row, so a
 * persisted 'type' surfaces as $field['type'].
 *
 * @param array $field a field row (post extendField merge) or a raw row.
 *
 * @return string
 */
function osc_field_resolve_type($field)
{
    if (!empty($field['type']) && is_string($field['type'])) {
        return $field['type'];
    }
    if (isset($field['s_meta']) && $field['s_meta'] !== '') {
        $meta = json_decode($field['s_meta'], true);
        if (is_array($meta) && !empty($meta['type'])) {
            return $meta['type'];
        }
    }

    return $field['e_type'] ?? 'TEXT';
}


/*
 * ---------------------------------------------------------------------------
 * Built-in field types.
 *
 * The nine historical ENUM types keep their exact spelling as their id and store
 * as themselves, so every existing field, theme and plugin sees no change. Each
 * declares the s_meta config keys the builder exposes for it (placeholder, help
 * text, numeric bounds, …). Two convenience text-backed types (EMAIL, PHONE) show
 * that the registry adds types with no ENUM/schema change.
 * ---------------------------------------------------------------------------
 */

osc_register_field_type('TEXT', array(
    'label'  => 'Text',
    'group'  => 'Basic',
    'icon'   => 'input-cursor-text',
    'config' => array('placeholder', 'help_text', 'default', 'maxlength', 'pattern'),
));

osc_register_field_type('NUMBER', array(
    'label'      => 'Number',
    'group'      => 'Basic',
    'icon'       => 'hash',
    'input_type' => 'number',
    'config'     => array('placeholder', 'help_text', 'default', 'min', 'max', 'step'),
));

osc_register_field_type('TEXTAREA', array(
    'label'  => 'Text area',
    'group'  => 'Basic',
    'icon'   => 'textarea-resize',
    'config' => array('placeholder', 'help_text', 'default', 'rows'),
));

osc_register_field_type('DROPDOWN', array(
    'label'       => 'Dropdown',
    'group'       => 'Choice',
    'icon'        => 'menu-button-wide',
    'has_options' => true,
    'config'      => array('help_text'),
));

osc_register_field_type('RADIO', array(
    'label'       => 'Radio buttons',
    'group'       => 'Choice',
    'icon'        => 'ui-radios',
    'has_options' => true,
    'config'      => array('help_text'),
));

osc_register_field_type('CHECKBOX', array(
    'label'  => 'Checkbox',
    'group'  => 'Choice',
    'icon'   => 'check-square',
    'config' => array('help_text'),
));

osc_register_field_type('URL', array(
    'label'      => 'URL',
    'group'      => 'Basic',
    'icon'       => 'link-45deg',
    'input_type' => 'url',
    'config'     => array('placeholder', 'help_text', 'b_new_tab'),
));

osc_register_field_type('DATE', array(
    'label'  => 'Date',
    'group'  => 'Date',
    'icon'   => 'calendar-date',
    'config' => array('help_text'),
));

osc_register_field_type('DATEINTERVAL', array(
    'label'  => 'Date range',
    'group'  => 'Date',
    'icon'   => 'calendar-range',
    'config' => array('help_text'),
));

osc_register_field_type('EMAIL', array(
    'label'    => 'Email',
    'group'    => 'Basic',
    'icon'       => 'envelope',
    'storage'    => 'TEXT',
    'input_type' => 'email',
    'config'     => array('placeholder', 'help_text'),
    'validate' => static function ($value, $field) {
        if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return sprintf(__('%s is invalid.'), $field['s_name']);
        }

        return null;
    },
));

osc_register_field_type('PHONE', array(
    'label'   => 'Phone',
    'group'   => 'Basic',
    'icon'       => 'telephone',
    'storage'    => 'TEXT',
    'input_type' => 'tel',
    'config'     => array('placeholder', 'help_text'),
));

// A text field whose suggestions come from the core custom-field autocomplete
// endpoint (distinct existing values of the field). The field must be searchable
// for the endpoint to expose its values. The shared oscAutocomplete widget wires
// itself from the data- attributes FieldForm emits; themes style .osc-ac-list.
osc_register_field_type('AUTOCOMPLETE', array(
    'label'   => 'Autocomplete',
    'group'   => 'Advanced',
    'icon'       => 'search',
    'storage'    => 'TEXT',
    'config'     => array('placeholder', 'help_text', 'default', 'min_length'),
));


/*
 * ---------------------------------------------------------------------------
 * Field groups (reusable forms).
 * ---------------------------------------------------------------------------
 */

/**
 * All field groups.
 *
 * @return array
 */
function osc_get_field_groups()
{
    return FieldGroup::newInstance()->listAll();
}


/**
 * The groups (with their inherited fields) that apply to a category, honouring the
 * same category inheritance as loose fields.
 *
 * @param int $categoryId
 *
 * @return array each element: group row with a 'fields' array.
 */
function osc_get_category_field_groups($categoryId)
{
    return FieldGroup::newInstance()->findByCategory($categoryId);
}


/**
 * Whether a custom field is visible for an item under its conditional-logic show_when
 * rule. Reuses the same evaluator as the save path (FieldValidator::evaluateCondition),
 * so display and persistence never diverge.
 *
 * Note: a field hidden at post time is already dropped by the save-time re-evaluation
 * (ItemActions / FieldValidator), so it has no stored value and never reaches display.
 * This helper only matters for the residual case of a value stored before a show_when
 * rule was later added or edited — a theme can call it to hide such a stale value.
 *
 * @param array          $field a field row (rules under 'rules' post-extendField, or raw s_meta)
 * @param array|int|null $item  item row, item id, or null for the current item
 *
 * @return bool
 */
function osc_field_is_visible($field, $item = null)
{
    // Conditional rules live in the field's s_meta; Field::extendField() merges them onto
    // the row as 'rules'. Accept either a merged row or a raw one.
    $rules = array();
    if (isset($field['rules']) && is_array($field['rules'])) {
        $rules = $field['rules'];
    } elseif (isset($field['s_meta']) && $field['s_meta'] !== '') {
        $decoded = json_decode($field['s_meta'], true);
        if (is_array($decoded) && isset($decoded['rules']) && is_array($decoded['rules'])) {
            $rules = $decoded['rules'];
        }
    }
    if (empty($rules['show_when'])) {
        return true;
    }

    if ($item === null) {
        $itemId = (int) osc_item_id();
    } elseif (is_array($item)) {
        $itemId = (int) ($item['pk_i_id'] ?? 0);
    } else {
        $itemId = (int) $item;
    }

    // Map the item's stored custom-field values by slug so a rule referencing a sibling
    // field can be resolved — the same shape FieldValidator evaluates against.
    $slugValues = array();
    foreach (Item::newInstance()->metaFields($itemId) as $row) {
        if (isset($row['s_slug'])) {
            $slugValues[$row['s_slug']] = $row['s_value'] ?? null;
        }
    }

    return \mindstellar\forms\FieldValidator::evaluateCondition($rules['show_when'], $slugValues);
}
