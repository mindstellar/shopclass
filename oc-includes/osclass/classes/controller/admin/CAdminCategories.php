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
 * Class CAdminCategories
 */
class CAdminCategories extends AdminSecBaseModel
{
    //specific for this class
    private $categoryManager;

    public function __construct()
    {
        parent::__construct();

        //specific things for this class
        $this->categoryManager = Category::newInstance(osc_current_admin_locale());
        osc_run_hook('init_admin_categories');
    }

    //Business Layer...
    public function doModel()
    {
        parent::doModel();

        //specific things for this class
        switch ($this->action) {
            case ('add_post_default'): // add default category and reorder parent categories
                osc_csrf_check();
                $fields['fk_i_parent_id']    = null;
                $fields['i_expiration_days'] = 0;
                $fields['i_position']        = 0;
                $fields['b_enabled']         = 1;
                $fields['b_price_enabled']   = 1;

                $default_locale                                = osc_language();
                $aFieldsDescription[$default_locale]['s_name'] = 'NEW CATEGORY, EDIT ME!';

                $categoryId = $this->categoryManager->insert($fields, $aFieldsDescription);

                // reorder parent categories. NEW category first
                $rootCategories = $this->categoryManager->findRootCategories();
                foreach ($rootCategories as $cat) {
                    $order = $cat['i_position'];
                    $order++;
                    $this->categoryManager->updateOrder($cat['pk_i_id'], $order);
                }
                $this->categoryManager->updateOrder($categoryId, '0');

                osc_run_hook('add_category', (int)($categoryId));

                $this->redirectTo(osc_admin_base_url(true) . '?page=categories');
                break;
            default:                //
                $this->_exportVariableToView('categories', $this->categoryManager->toTreeAll());
                $this->doView('categories/index.php');
        }
    }

    //hopefully generic...

}

/* file end: ./oc-admin/CAdminCategories.php */
