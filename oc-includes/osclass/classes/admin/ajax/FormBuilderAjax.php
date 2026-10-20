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
use FieldGroup;
use mindstellar\form\builder\FormService;
use mindstellar\model\FormSubmission;
use mindstellar\utility\AjaxResponse;
use Params;

/**
 * The form builder: field groups (forms), their field lists and their submissions.
 */
final class FormBuilderAjax extends AjaxHandler
{
    public function addGroup(): void
    {
        $newId = FieldGroup::getInstance()->insertGroup(__('New field group'));
        if ($newId) {
            AjaxResponse::json(array('error' => 0, 'group_id' => $newId, 'group_name' => __('New field group')));
        } else {
            AjaxResponse::json(array('error' => 1));
        }
    }

    public function groupPost(): void
    {
        $groupManager = FieldGroup::getInstance();
        $groupId      = Params::getParamInt('id');
        $name         = trim((string) Params::getParam('group_name'));
        $error        = 0;
        if ($groupId <= 0 || $name === '') {
            $error = 1;
        } else {
            $slugParam = trim((string) Params::getParam('group_slug'));
            $existing  = $groupManager->findByPrimaryKey($groupId);
            $slug      = $slugParam !== '' ? $slugParam : (isset($existing['s_slug']) ? $existing['s_slug'] : '');
            // regenerate a unique slug only when it is empty or being changed
            if ($slug === '' || (isset($existing['s_slug']) && $slug !== $existing['s_slug'])) {
                $slug = $groupManager->uniqueSlug($slug !== '' ? $slug : $name);
            }
            $res = $groupManager->update(
                array('s_name' => $name, 's_slug' => $slug),
                array('pk_i_id' => $groupId)
            );
            if (is_bool($res) && !$res) {
                $error = 1;
            }
            // rewrite the group's category assignments
            $groupManager->cleanCategoriesFromGroup($groupId);
            $aCategories = Params::getParam('categories');
            if (is_array($aCategories) && count($aCategories) > 0) {
                $groupManager->insertCategories($groupId, $aCategories);
            }
            // "Available as a block" flag, read by the core.form picker
            $groupManager->setMeta($groupId, 'placeable', Params::getParam('group_placeable') == '1' ? 1 : '');
        }
        if ($error) {
            AjaxResponse::json(array('error' => __('An error occurred while saving the group')));
        } else {
            AjaxResponse::json(array('ok' => __('Saved'), 'group_id' => $groupId, 'text' => $name));
        }
    }

    public function deleteGroup(): void
    {
        // A row count, not "did not throw": an id that matched nothing reports 0, and
        // calling that a success left the deleted form in the list until a reload.
        $res = FieldGroup::getInstance()->deleteByPrimaryKey(Params::getParamInt('id'));
        if ($res > 0) {
            AjaxResponse::json(array('ok' => __('The field group has been deleted')));
        } else {
            AjaxResponse::json(array('error' => __('An error occurred while deleting')));
        }
    }

    public function groupIframe(): void
    {
        $groupId  = Params::getParamInt('id');
        $selected = FieldGroup::getInstance()->categories($groupId);
        $this->controller->_exportVariableToView('selected', $selected);
        $this->controller->_exportVariableToView('group', FieldGroup::getInstance()->findByPrimaryKey($groupId));
        $this->controller->_exportVariableToView('categories', Category::getInstance()->toTreeAll());
        $this->controller->doView('fields/group_iframe.php');
    }

    /** The builder posts a form's whole ordered field list after each drag. */
    public function setFields(): void
    {
        $formId = Params::getParamInt('form_id');
        $ids    = json_decode((string) Params::getParam('fields'), true);
        if ($formId <= 0 || !is_array($ids)) {
            AjaxResponse::json(array('error' => __('Invalid request')));

            return;
        }
        if ((new FormService())->setFormFields($formId, $ids)) {
            AjaxResponse::json(array('ok' => __('Saved')));
        } else {
            AjaxResponse::json(array('error' => __('An error occurred while saving the form')));
        }
    }

    /** Gathers fields that sit on categories but in no form into forms. Reversible. */
    public function migrateLooseFields(): void
    {
        $result = (new FormService())->migrateLooseFields();
        if ($result['forms'] === 0) {
            AjaxResponse::json(array('ok' => __('There were no fields to move.')));
        } else {
            AjaxResponse::json(array('ok' => sprintf(
                __('Moved %1$d fields into %2$d forms.'),
                $result['fields'],
                $result['forms']
            )));
        }
    }

    public function submissionStatus(): void
    {
        $ok = (new FormSubmission())->setStatus(Params::getParamInt('id'), (string) Params::getParam('status'));
        AjaxResponse::json($ok ? array('ok' => __('Saved')) : array('error' => __('An error occurred')));
    }

    public function submissionDelete(): void
    {
        $ok = (new FormSubmission())->delete(Params::getParamInt('id'));
        AjaxResponse::json($ok ? array('ok' => __('The submission has been deleted')) : array('error' => __('An error occurred while deleting')));
    }

    public function submissionsPurge(): void
    {
        $res = (new FormSubmission())->deleteByForm(Params::getParamInt('form_id'));
        AjaxResponse::json($res !== false ? array('ok' => __('All submissions for this form have been deleted')) : array('error' => __('An error occurred while deleting')));
    }
}
