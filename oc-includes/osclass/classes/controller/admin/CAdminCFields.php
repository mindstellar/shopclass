<?php if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
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

/**
 * Class CAdminCFields
 */
class CAdminCFields extends AdminSecBaseModel
{
    //specific for this class
    private $fieldManager;

    public function __construct()
    {
        parent::__construct();

        //specific things for this class
        $this->fieldManager = Field::newInstance();
        osc_run_hook('init_admin_fields');
    }

    //Business Layer...
    public function doModel()
    {
        parent::doModel();

        //specific things for this class
        switch ($this->action) {
            default:
                $categories = Category::newInstance()->toTreeAll();
                $selected   = array();
                foreach ($categories as $c) {
                    $selected[] = $c['pk_i_id'];
                    foreach ($c['categories'] as $cc) {
                        $selected[] = $cc['pk_i_id'];
                    }
                }
                $this->_exportVariableToView('categories', $categories);
                $this->_exportVariableToView('default_selected', $selected);

                // Field palette (all definitions) + forms with their ordered field ids,
                // for the two-pane drag-and-drop builder.
                $allFields = $this->fieldManager->listAll();
                $service   = new \mindstellar\forms\FormService();
                $forms     = FieldGroup::newInstance()->listAll();
                foreach ($forms as &$form) {
                    $form['field_ids'] = $service->formFieldIds((int)$form['pk_i_id']);
                }
                unset($form);

                $this->_exportVariableToView('fields', $allFields);
                $this->_exportVariableToView('groups', $forms);
                $this->_exportVariableToView('placed_field_ids', $service->placedFieldIds());
                $this->doView('fields/index.php');
                break;
        }
    }

    //hopefully generic...

}

/* file end: ./oc-admin/CAdminCFields.php */
