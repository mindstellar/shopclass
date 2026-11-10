<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The Notices screen (`?page=notices`): every notice of the signed-in admin, active or hidden.
 * Reached from the bell's "See all"; it has no menu entry.
 */
class CAdminNotices extends AdminSecBaseModel
{
    /**
     * Show the Active or Hidden tab; any other value shows Active.
     *
     * @return void
     */
    public function doModel()
    {
        parent::doModel();

        $view = osc_admin_notices();
        $tab  = Params::getParamString('tab') === 'hidden' ? 'hidden' : 'active';

        $this->_exportVariableToView('tab', $tab);
        $this->_exportVariableToView('active', $view->active);
        $this->_exportVariableToView('hidden', $view->hidden);
        $this->doView('notices/index.php');
    }
}
