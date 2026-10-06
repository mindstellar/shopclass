<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\admin\ajax;

use Category;
use Field;
use FieldGroup;
use mindstellar\fields\FieldService;
use mindstellar\fields\FieldSlug;
use mindstellar\fields\FieldTypeRegistry;
use mindstellar\form\builder\FormService;
use mindstellar\utility\AjaxResponse;
use mindstellar\validation\RefusedException;
use Params;
use RuntimeException;

/**
 * The custom field editor: the edit frame, save, add, delete and order.
 */
final class FieldAjax extends AjaxHandler
{
    public function editIframe(): void
    {
        $selected = Field::getInstance()->categories(Params::getParamInt('id'));
        if ($selected == null) {
            $selected = array();
        }
        $this->controller->_exportVariableToView('selected', $selected);
        $this->controller->_exportVariableToView('field', Field::getInstance()->findByPrimaryKey(Params::getParamInt('id')));
        $this->controller->_exportVariableToView('categories', Category::getInstance()->toTreeAll());
        // Sibling fields feed the conditional-visibility "controlling field" picker.
        $this->controller->_exportVariableToView('allFields', Field::getInstance()->listAll());
        // Groups fill the membership dropdown (Ungrouped + each group).
        $this->controller->_exportVariableToView('allGroups', FieldGroup::getInstance()->listAll());
        // The editor warns when a save changes the field in more than one form.
        $this->controller->_exportVariableToView(
            'form_count',
            (new FormService())->formCountForField(Params::getParamInt('id'))
        );
        $this->controller->doView('fields/iframe.php');
    }

    public function editPost(): void
    {
        $error = 0;
        // Builder mode edits the field definition only; form membership and category
        // placement are absent from its post and must not be cleared here.
        $builderMode  = Params::getParam('builder') == '1';
        $fieldService = FieldService::make(osc_language());

        if (!$fieldService->nameTaken(Params::getParamString('s_name'), Params::getParamInt('id'))) {
            // remove categories from a field (definition-only saves keep them)
            if (!$builderMode) {
                Field::getInstance()->cleanCategoriesFromField(Params::getParamInt('id'));
            }
            // @phpstan-ignore equal.alwaysTrue (kept as a guard for the steps below)
            if ($error == 0) {
                $slug = FieldSlug::unique(
                    Params::getParam('field_slug') != '' ? Params::getParam('field_slug') : Params::getParam('s_name'),
                    Params::getParamInt('id')
                );
                // prepare multi locale data
                $currentAdminLocale = osc_current_admin_locale();
                $aMetaNames         = Params::getParamArray('meta_s_name');
                $metaLocale         = array();
                foreach ($aMetaNames as $k => $v) {
                    if (!empty($v)) {
                        $metaLocale[$k] = array(
                            's_name' => trim($v),
                        );
                    }
                }

                $metaOptions = Params::getParamString('s_options');

                // trim all csv options
                if (!empty($metaOptions)) {
                    $tmpValues = explode(',', $metaOptions);
                    foreach ($tmpValues as $k => $v) {
                        $tmpValues[$k] = trim($v);
                    }
                    $metaOptions = implode(',', $tmpValues);
                    unset($tmpValues);
                }

                // A registry type (e.g. EMAIL) stores as a primitive: e_type holds the
                // primitive and a non-primitive type keeps its real id in s_meta['type'].
                $chosenType   = Params::getParam('field_type');
                $storageType  = osc_field_type_storage($chosenType);
                $realTypeMeta = in_array($chosenType, FieldTypeRegistry::STORAGE_PRIMITIVES, true)
                    ? '' : $chosenType;

                // Group membership is only touched by the single-group editor, not builder mode.
                $updateData = array(
                    's_name'       => $aMetaNames[$currentAdminLocale],
                    'e_type'       => $storageType,
                    's_slug'       => $slug,
                    'b_required'   => Params::getParam('field_required') == '1' ? 1 : 0,
                    'b_searchable' => Params::getParam('field_searchable') == '1' ? 1 : 0,
                    's_options'    => $metaOptions,
                );
                if (!$builderMode) {
                    $groupId = Params::getParamInt('field_group');
                    $updateData['fk_i_group_id'] = $groupId > 0 ? $groupId : null;
                }
                $res = Field::getInstance()->update($updateData, array('pk_i_id' => Params::getParam('id')));
                // Keep the link table in sync with the single-group editor; the builder
                // manages links by drag and drop.
                if (!$builderMode) {
                    FieldGroup::getInstance()->setFieldSingleGroup(Params::getParamInt('id'), $groupId);
                }
                Field::getInstance()->updateJsonMeta(Params::getParamInt('id'), 'type', $realTypeMeta);
                Field::getInstance()->updateJsonMeta(Params::getParamInt('id'), 'b_new_tab', Params::getParam('b_new_tab'));
                Field::getInstance()->updateJsonMeta(Params::getParamInt('id'), 'locale', $metaLocale);
                self::persistConfig(Params::getParamInt('id'), $chosenType);
                if (is_bool($res) && !$res) {
                    $error = 1;
                }
            }
            // builder mode does not manage per-field categories
            if ($error == 0 && !$builderMode) {
                $aCategories = Params::getParam('categories');
                if (is_array($aCategories) && count($aCategories) > 0) {
                    $res = Field::getInstance()->insertCategories(Params::getParamInt('id'), $aCategories);
                    if (!$res) {
                        $error = 1;
                    }
                }
            }
            if ($error == 1) {
                $message = __('An error occurred while updating');
            }
        } else {
            $error   = 1;
            $message = __('Sorry, you already have a field with that name');
        }

        if ($error) {
            $result = array('error' => $message);
        } else {
            $typeSpec = osc_field_type($chosenType);
            $result   = array(
                'ok'         => __('Saved'),
                'text'       => $aMetaNames[$currentAdminLocale],
                'field_id'   => Params::getParam('id'),
                'type_label' => $typeSpec !== null ? __($typeSpec['label']) : $chosenType,
            );
        }

        AjaxResponse::json($result);
    }

    public function delete(): void
    {
        try {
            FieldService::make(osc_language())->delete(Params::getParamInt('id'));
            $result = array('ok' => __('The custom field has been deleted'));
        } catch (RefusedException | RuntimeException $e) {
            $result = array('error' => __('An error occurred while deleting'));
        }

        AjaxResponse::json($result);
    }

    public function add(): void
    {
        $s_name  = __('NEW custom field');
        $slug    = FieldSlug::unique($s_name);
        $fieldId = Field::getInstance()->insertField($s_name, 'TEXT', $slug, 0, '', array());
        if ($fieldId) {
            AjaxResponse::json(array(
                'error'      => 0,
                'field_id'   => $fieldId,
                'field_name' => $s_name
            ));
        } else {
            AjaxResponse::json(array('error' => 1));
        }
    }

    public function order(): void
    {
        $aIds  = json_decode(Params::getParam('list'), true);
        $error = 0;

        $fieldManager = Field::getInstance();
        foreach ($aIds as $pos => $field) {
            $res = $fieldManager->update(
                array(
                    'i_position' => $pos
                ),
                array('pk_i_id' => $field)
            );
            if (is_bool($res) && !$res) {
                $error = 1;
            }
        }

        AjaxResponse::json(self::orderResult($error));
    }

    /**
     * Save a field's per-type config (placeholder, help text, bounds, rules, ...) into its
     * s_meta. Only keys the type declares are read; an empty value clears the key.
     *
     * @param string $typeId the chosen, possibly non-primitive, field type id
     */
    private static function persistConfig(int $fieldId, mixed $typeId): void
    {
        $spec = osc_field_type($typeId);
        if ($spec === null || empty($spec['config'])) {
            return;
        }
        $field = Field::getInstance();
        foreach ($spec['config'] as $key) {
            // b_new_tab has its own checkbox, saved by the caller.
            if ($key === 'b_new_tab') {
                continue;
            }
            $value = Params::getParam('cfg_' . $key);
            // numeric config keys stay numeric; everything else is a trimmed string.
            if (in_array($key, array('min', 'max', 'step', 'rows', 'maxlength'), true)) {
                // @phpstan-ignore binaryOp.invalid (a numeric string is expected here)
                $value = ($value === '' || $value === null) ? '' : $value + 0;
            } elseif (is_string($value)) {
                $value = trim($value);
            }
            $field->updateJsonMeta($fieldId, $key, $value);
        }

        // Conditional-visibility rules, posted as a JSON string by the builder.
        $rules = Params::getParam('cfg_rules');
        if (is_string($rules) && $rules !== '') {
            $decoded = json_decode($rules, true);
            $field->updateJsonMeta($fieldId, 'rules', is_array($decoded) ? $decoded : '');
        } else {
            $field->updateJsonMeta($fieldId, 'rules', '');
        }

        // Cascading options: cfg_cascade_map holds one "Parent: opt, opt" line per parent
        // value, parsed into a { parentValue: [opts] } map.
        $cascadeParent = trim((string) Params::getParam('cfg_cascade_parent'));
        // cascade_parent is a field slug; anything not slug-shaped could reach rendered markup.
        if ($cascadeParent !== '' && !preg_match('/^[a-z0-9_-]+$/', $cascadeParent)) {
            $cascadeParent = '';
        }
        $cascadeText = (string) Params::getParam('cfg_cascade_map');
        if ($cascadeParent !== '' && trim($cascadeText) !== '') {
            $map = array();
            foreach (preg_split('/\r\n|\r|\n/', $cascadeText) as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, ':') === false) {
                    continue;
                }
                list($key, $vals) = explode(':', $line, 2);
                $key  = trim($key);
                $opts = array_values(array_filter(
                    array_map('trim', explode(',', $vals)),
                    static function ($v) {
                        return $v !== '';
                    }
                ));
                if ($key !== '' && $opts) {
                    $map[$key] = $opts;
                }
            }
            $field->updateJsonMeta($fieldId, 'cascade_parent', $map ? $cascadeParent : '');
            $field->updateJsonMeta($fieldId, 'cascade_map', $map ?: '');
        } else {
            $field->updateJsonMeta($fieldId, 'cascade_parent', '');
            $field->updateJsonMeta($fieldId, 'cascade_map', '');
        }
    }
}
