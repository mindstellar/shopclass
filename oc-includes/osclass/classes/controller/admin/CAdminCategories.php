<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
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
    private Category $categoryManager;

    /**
     * Take the category manager for the admin's own locale.
     */
    public function __construct()
    {
        parent::__construct();

        //specific things for this class
        $this->categoryManager = Category::getInstance(osc_current_admin_locale());
        osc_run_hook('init_admin_categories');
    }

    //Business Layer...

    /**
     * Dispatch the requested categories action, otherwise draw the category tree.
     *
     * @return void
     */
    public function doModel()
    {
        parent::doModel();

        //specific things for this class
        switch ($this->action) {
            case ('add_post_default'): // add default category and reorder parent categories
                osc_csrf_check();
                $default_locale                                = osc_language();
                $aFieldsDescription[$default_locale]['s_name'] = 'NEW CATEGORY, EDIT ME!';

                // Added before every other root category; the editor fires add_category.
                \mindstellar\category\CategoryService::make()->create(
                    null,
                    array('i_expiration_days' => 0, 'b_price_enabled' => 1),
                    $aFieldsDescription,
                    true,
                    true
                );

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
