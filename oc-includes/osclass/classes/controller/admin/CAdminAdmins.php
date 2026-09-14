<?php

if (!defined('ABS_PATH')) {
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

use mindstellar\admin\form\AdminAccountForm;

/**
 * Class CAdminAdmins
 */
class CAdminAdmins extends AdminSecBaseModel
{
    //specific for this class
    private Admin $adminManager;

    /**
     * Hold a moderator to their own account and take the admin manager for this request.
     */
    public function __construct()
    {
        parent::__construct();

        if ($this->isModerator()) {
            if (($this->action !== 'edit' && $this->action !== 'edit_post')
                || (Params::getParam('id') != ''
                    && Params::getParam('id') != osc_logged_admin_id())
            ) {
                osc_add_flash_error_message(_m("You don't have enough permissions"), 'admin');
                $this->redirectTo(osc_admin_base_url());
            }
        }

        //specific things for this class
        $this->adminManager = Admin::newInstance();
        osc_run_hook('init_admin_admins');
    }

    //Business Layer...

    /**
     * Dispatch the requested admins action: the add/edit form, its save, a batch delete,
     * or the paginated list.
     *
     * @return void
     */
    public function doModel()
    {
        parent::doModel();

        switch ($this->action) {
            case ('add'):
                $this->drawForm(null);
                break;
            case ('add_post'):
                if ($this->refusedByDemo()) {
                    break;
                }
                osc_csrf_check();
                $this->saveAdmin(null);
                break;
            case ('edit'):
                $adminId = $this->adminRowId(true);
                if ($adminId === null) {
                    break;
                }
                $this->drawForm($adminId);
                break;
            case ('edit_post'):
                if ($this->refusedByDemo()) {
                    break;
                }
                osc_csrf_check();
                $adminId = $this->adminRowId(false);
                if ($adminId === null) {
                    break;
                }
                $this->saveAdmin($adminId);
                break;
            case ('delete'):
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m("This action can't be done because it's a demo site"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=admins');
                }
                osc_csrf_check();
                // deleting and admin
                $isDeleted = false;
                $adminId   = Params::getParam('id');

                if (!is_array($adminId)) {
                    osc_add_flash_error_message(_m("The admin id isn't in the correct format"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=admins');
                }

                // Verification to avoid an administrator trying to remove to itself
                if (in_array(Session::newInstance()->_get('adminId'), $adminId)) {
                    osc_add_flash_error_message(
                        _m("The operation hasn't been completed. You're trying to remove yourself!"),
                        'admin'
                    );
                    $this->redirectTo(osc_admin_base_url(true) . '?page=admins');
                }

                $isDeleted = $this->adminManager->deleteBatch($adminId);

                if ($isDeleted) {
                    osc_add_flash_ok_message(_m('The admin has been deleted correctly'), 'admin');
                } else {
                    osc_add_flash_error_message(_m('The admin couldn\'t be deleted'), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=admins');
                break;
            default:
                if (Params::getParam('action') != '') {
                    osc_run_hook('admin_bulk_' . Params::getParam('action'), Params::getParam('id'));
                }

                if (Params::getParam('iDisplayLength') == '') {
                    Params::setParam('iDisplayLength', 10);
                }

                $p_iPage = 1;
                if (is_numeric(Params::getParam('iPage')) && Params::getParam('iPage') >= 1) {
                    $p_iPage = Params::getParam('iPage');
                }
                Params::setParam('iPage', $p_iPage);

                $admins = $this->adminManager->listAll();

                // pagination
                $start = ($p_iPage - 1) * Params::getParam('iDisplayLength');
                $limit = Params::getParam('iDisplayLength');
                $count = count($admins);

                $displayRecords = $limit;
                if (($start + $limit) > $count) {
                    $displayRecords = ($start + $limit) - $count;
                }
                // ----
                $aData = array();
                $max   = ($start + $limit);
                if ($max > $count) {
                    $max = $count;
                }
                for ($i = $start; $i < $max; $i++) {
                    $admin = $admins[$i];

                    $options    = array();
                    $options[]  =
                        '<a href="' . osc_admin_base_url(true) . '?page=admins&action=edit&amp;id=' . $admin['pk_i_id']
                        . '">' . __('Edit') . '</a>';
                    $options[]  = '<a onclick="return delete_dialog(\'' . $admin['pk_i_id'] . '\');" href="'
                        . osc_admin_base_url(true) . '?page=admins&action=delete&amp;id[]=' . $admin['pk_i_id'] . '">'
                        . __('Delete') . '</a>';
                    $auxOptions = '<ul>' . PHP_EOL;
                    foreach ($options as $actual) {
                        $auxOptions .= '<li>' . $actual . '</li>' . PHP_EOL;
                    }
                    $actions = '<div class="actions">' . $auxOptions . '</div>' . PHP_EOL;

                    $row   = array();
                    $row[] = '<input type="checkbox" name="id[]" value="' . $admin['pk_i_id'] . '" />';
                    $row[] = osc_esc_html($admin['s_username']) . $actions;
                    $row[] = osc_esc_html($admin['s_name']);
                    $row[] = osc_esc_html($admin['s_email']);

                    $aData[] = $row;
                }
                $array['iTotalRecords']        = $displayRecords;
                $array['iTotalDisplayRecords'] = count($admins);
                $array['iDisplayLength']       = $limit;
                $array['aaData']               = $aData;

                $page = Params::getParamInt('iPage');
                if (count($array['aaData']) == 0 && $page != 1) {
                    $total   = $array['iTotalDisplayRecords'];
                    $maxPage = ceil($total / (int)$array['iDisplayLength']);

                    $url = osc_admin_base_url(true) . '?' . Params::getServerParam('QUERY_STRING', false, false);

                    if ($maxPage == 0) {
                        $url = preg_replace('/&iPage=(\d)+/', '&iPage=1', $url);
                        $this->redirectTo($url);
                    }

                    if ($page > 1) {
                        $url = preg_replace('/&iPage=(\d)+/', '&iPage=' . $maxPage, $url);
                        $this->redirectTo($url);
                    }
                }

                $bulk_options = array(
                    array('value' => '', 'data-dialog-content' => '', 'label' => __('Bulk actions')),
                    array(
                        'value'               => 'delete',
                        'data-dialog-content' => sprintf(
                            __('Are you sure you want to %s the selected admins?'),
                            strtolower(__('Delete'))
                        ),
                        'label'               => __('Delete')
                    )
                );
                $bulk_options = osc_apply_filter('admin_bulk_filter', $bulk_options);
                $this->_exportVariableToView('bulk_options', $bulk_options);

                $this->_exportVariableToView('aAdmins', $array);
                // calling manage admins view
                $this->doView('admins/index.php');
                break;
        }
    }

    //hopefully generic...

    /**
     * Whether this install refuses the write outright. A demo site shows every screen and
     * saves none of them.
     *
     * @return bool
     */
    private function refusedByDemo()
    {
        if (!defined('DEMO')) {
            return false;
        }

        osc_add_flash_warning_message(_m("This action can't be done because it's a demo site"), 'admin');
        $this->redirectTo(osc_admin_base_url(true) . '?page=admins');

        return true;
    }

    /**
     * The administrator account this request is about, or null once the admin has been
     * sent away because it named none. The key is parsed rather than cast -- (int) reads
     * ' 12 ' and '12abc' as 12 -- and held to a row that is really there before anything
     * is written, so a key nobody chose cannot fall through to an insert.
     *
     * Only the drawn form falls back to the session, which is the "my profile" link with
     * no id on it; a submission names its row or is refused. Every row of t_admin is in
     * scope for a screen only an administrator can reach, and the moderator who can reach
     * it is held to their own id in the constructor.
     *
     * @param bool $draw the form is being drawn rather than saved
     *
     * @return int|null
     */
    private function adminRowId($draw)
    {
        $requested = Params::getParam('id');
        $id        = is_string($requested) && preg_match('/^[1-9][0-9]*$/', $requested) ? (int)$requested : 0;

        if ($id === 0 && $draw && $requested === '') {
            $id = osc_logged_admin_id();
        }

        if ($id > 0 && AdminAccountForm::row($id) !== array()) {
            return $id;
        }

        // Two wordings for one condition, kept because both are already translated and
        // each is the one its own path has always shown.
        osc_add_flash_error_message(
            $draw ? _m('There is no admin with this id') : _m("This admin doesn't exist"),
            'admin'
        );
        $this->redirectTo(osc_admin_base_url(true) . '?page=admins');

        return null;
    }

    /**
     * Draw the screen for one account: the stored values, or the ones a rejected save is
     * handing back to be corrected.
     *
     * @param int|null   $id
     * @param array|null $values
     *
     * @return void
     */
    private function drawForm($id, ?array $values = null)
    {
        $canSetType = $this->canSetType($id);
        $pageId     = AdminAccountForm::register($id !== null, $canSetType);

        // The row itself, for the admin_profile_form hook a plugin adds its own controls
        // through. Null while an account is being added, exactly as before.
        $this->_exportVariableToView('admin', $id === null ? null : AdminAccountForm::row($id));
        $this->_exportVariableToView('admin_form', AdminAccountForm::formVars(
            $id,
            $values ?? osc_settings_values($pageId, $id),
            $canSetType
        ));
        $this->doView('admins/frm.php');
    }

    /**
     * Store an account through its declaration -- inserting when $id is null and updating
     * the row it names otherwise. A rejected submission is drawn again with what was
     * typed still in it, rather than thrown away with a redirect.
     *
     * @param int|null $id
     *
     * @return void
     */
    private function saveAdmin($id)
    {
        $result = osc_settings_save(AdminAccountForm::register($id !== null, $this->canSetType($id)), $id);

        if ($result['errors'] !== array()) {
            foreach ($result['errors'] as $error) {
                osc_add_flash_warning_message($error, 'admin');
            }
            $this->drawForm($id, $result['values']);

            return;
        }

        if ($id === null) {
            // The plaintext password, which is what the welcome email carries and the only
            // reason it is still in hand here: the column took the hash.
            osc_run_hook('hook_email_new_admin', array(
                's_name'     => $result['values']['s_name'],
                's_username' => $result['values']['s_username'],
                's_password' => $result['values']['s_password'],
                's_email'    => $result['values']['s_email'],
            ));
            osc_add_flash_ok_message(_m('The admin has been added'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=admins');

            return;
        }

        osc_run_hook('admin_edit_completed', $id, $result['updated']);

        // An update that changed nothing affects no rows and is still a save: the store
        // throws when a write fails and refuses a key with no row behind it, so there is
        // nothing left for a zero to mean.
        osc_add_flash_ok_message(_m('The admin has been updated'), 'admin');

        if ($this->isModerator()) {
            $this->redirectTo(osc_admin_base_url(true));

            return;
        }
        $this->redirectTo(osc_admin_base_url(true) . '?page=admins');
    }

    /**
     * Whether the account type is this administrator's to change. Their own never is:
     * the field is not declared for it, so nothing can write the column either.
     *
     * @param int|null $id
     *
     * @return bool
     */
    private function canSetType($id)
    {
        return $id === null || $id !== osc_logged_admin_id();
    }
}

/* file end: ./oc-admin/CAdminAdmins.php */
