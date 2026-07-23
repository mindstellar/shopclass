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
