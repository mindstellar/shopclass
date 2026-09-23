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
 * Class CAdminEmails
 */
use mindstellar\admin\ListPaging;

class CAdminEmails extends AdminSecBaseModel
{
    //specific for this class
    private Page $emailManager;

    /**
     * Take the page manager the email templates are stored through.
     */
    public function __construct()
    {
        parent::__construct();

        //specific things for this class
        $this->emailManager = Page::newInstance();
        osc_run_hook('init_admin_emails');
    }

    //Business Layer...

    /**
     * Draw or save one email template, otherwise list them all.
     *
     * @return void
     */
    public function doModel()
    {
        parent::doModel();

        //specific things for this class
        switch ($this->action) {
            case 'edit':
                if (Params::getParam('id') == '') {
                    $this->redirectTo(osc_admin_base_url(true) . '?page=emails');
                }

                $form     = count(Session::newInstance()->_getForm());
                $keepForm = count(Session::newInstance()->_getKeepForm());
                if ($form == 0 || $form == $keepForm) {
                    Session::newInstance()->_dropKeepForm();
                }

                $this->_exportVariableToView('email', $this->emailManager->findByPrimaryKey(Params::getParam('id')));
                $this->doView('emails/frm.php');
                break;
            case 'edit_post':
                osc_csrf_check();
                $id = Params::getParam('id');

                $aFieldsDescription = array();
                $postParams         = Params::getParamsAsArray('', false);
                $not_empty          = false;
                foreach ($postParams as $k => $v) {
                    if (preg_match('|(.+?)#(.+)|', $k, $m)) {
                        if ($m[2] == 's_title' && $v != '') {
                            $not_empty = true;
                        }
                        $aFieldsDescription[$m[1]][$m[2]] = $v;
                    }
                }

                Session::newInstance()->_setForm('aFieldsDescription', $aFieldsDescription);

                // The internal name is how core finds a template to send, so it is never
                // renamed here.
                if (!$not_empty) {
                    $error = _m('The email couldn\'t be updated, at least one title should not be empty');
                    osc_add_flash_error_message($error, 'admin');
                    $this->_exportVariableToView('editorErrors', array('s_title' => $error));
                    $this->_exportVariableToView('email', $this->emailManager->findByPrimaryKey($id));
                    $this->doView('emails/frm.php');
                    break;
                }

                foreach ($aFieldsDescription as $k => $_data) {
                    $this->emailManager->updateDescription($id, $k, $_data['s_title'], $_data['s_text']);
                }
                Session::newInstance()->_clearVariables();
                osc_add_flash_ok_message(_m('The email/alert has been updated'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=emails');
                break;
            default:
                //-
                Params::setParam('iDisplayLength', ListPaging::length());
                $p_iPage = ListPaging::page();

                $prefLocale = osc_current_admin_locale();
                $emails     = $this->emailManager->listAll(1);

                // pagination
                $limit = ListPaging::length();
                $start = ListPaging::start($p_iPage, $limit);
                $count = count($emails);

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
                    $email = $emails[$i];

                    if (isset($email['locale'][$prefLocale]) && !empty($email['locale'][$prefLocale]['s_title'])) {
                        $title = $email['locale'][$prefLocale];
                    } else {
                        $title = current($email['locale']);
                    }
                    $options   = array();
                    $options[] = '<a href="' . osc_admin_base_url(true) . '?page=emails&amp;action=edit&amp;id='
                        . $email['pk_i_id'] . '">' . __('Edit') . '</a>';

                    $auxOptions = '<ul>' . PHP_EOL;
                    foreach ($options as $actual) {
                        $auxOptions .= '<li>' . $actual . '</li>' . PHP_EOL;
                    }
                    $actions = '<div class="actions">' . $auxOptions . '</div>' . PHP_EOL;

                    $row     = array();
                    $row[]   = $email['s_internal_name'] . $actions;
                    $row[]   = $title['s_title'];
                    $aData[] = $row;
                }
                // ----
                $array['iTotalRecords']        = $displayRecords;
                $array['iTotalDisplayRecords'] = count($emails);
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

                $this->_exportVariableToView('aEmails', $array);

                $this->doView('emails/index.php');
        }
    }

    //hopefully generic...

}

/* file end: ./oc-admin/CAdminEmails.php */
