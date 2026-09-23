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
 * Class CAdminPages
 */
use mindstellar\admin\form\StaticPageForm;
use mindstellar\admin\ListPaging;

class CAdminPages extends AdminSecBaseModel
{
    //specific for this class
    private $pageManager;

    /**
     * Take the page manager for this request.
     */
    public function __construct()
    {
        parent::__construct();

        //specific things for this class
        $this->pageManager = Page::newInstance();
        osc_run_hook('init_admin_pages');
    }

    //Business Layer...

    /**
     * Dispatch the requested static-pages action: add, edit, their saves and delete,
     * otherwise the list.
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
                    $this->redirectTo(osc_admin_base_url(true) . '?page=pages');
                }

                $form     = count(Session::newInstance()->_getForm());
                $keepForm = count(Session::newInstance()->_getKeepForm());
                if ($form == 0 || $form == $keepForm) {
                    Session::newInstance()->_dropKeepForm();
                }

                $templates = osc_apply_filter('page_templates', WebThemes::newInstance()->getAvailableTemplates());
                $this->_exportVariableToView('templates', $templates);
                $this->_exportVariableToView('registeredTemplates', osc_page_templates());
                $this->_exportVariableToView('page', $this->pageManager->findByPrimaryKey(Params::getParam('id')));
                $this->doView('pages/frm.php');
                break;
            case 'edit_post':
                osc_csrf_check();
                $this->savePage(Params::getParam('id'));

                return;
            case 'add':
                $form     = count(Session::newInstance()->_getForm());
                $keepForm = count(Session::newInstance()->_getKeepForm());
                if ($form == 0 || $form == $keepForm) {
                    Session::newInstance()->_dropKeepForm();
                }

                $templates = osc_apply_filter('page_templates', WebThemes::newInstance()->getAvailableTemplates());
                $this->_exportVariableToView('templates', $templates);
                $this->_exportVariableToView('registeredTemplates', osc_page_templates());
                $this->_exportVariableToView('page', array());
                $this->doView('pages/frm.php');
                break;
            case 'add_post':
                osc_csrf_check();
                $this->savePage(null);

                return;
            case 'delete':
                osc_csrf_check();
                $id                    = Params::getParam('id');
                $page_deleted_correcty = 0;
                $page_deleted_error    = 0;
                $page_indelible        = 0;

                if (!is_array($id)) {
                    $id = array($id);
                }

                foreach ($id as $_id) {
                    $result = (int)$this->pageManager->deleteByPrimaryKey($_id);
                    switch ($result) {
                        case -1:
                            $page_indelible++;
                            break;
                        case 0:
                            $page_deleted_error++;
                            break;
                        case 1:
                            $page_deleted_correcty++;
                            // Remove any page-builder blocks placed on this page.
                            Widget::newInstance()->delete(
                                array('s_location' => 'page.' . (int)$_id)
                            );
                            // Remove page-owned images (editor uploads) — files and
                            // rows — so they don't wait for the daily orphan sweep.
                            (new \mindstellar\storage\ResourceUploader())
                                ->deleteByOwner(\mindstellar\model\Resource::OWNER_PAGE, (int)$_id);
                    }
                }

                if ($page_indelible > 0) {
                    if ($page_indelible == 1) {
                        osc_add_flash_error_message(_m("One page can't be deleted because it is indelible"), 'admin');
                    } else {
                        osc_add_flash_error_message(sprintf(
                            _m("%s pages couldn't be deleted because they are indelible"),
                            $page_indelible
                        ), 'admin');
                    }
                }
                if ($page_deleted_error > 0) {
                    if ($page_deleted_error == 1) {
                        osc_add_flash_error_message(_m("One page couldn't be deleted"), 'admin');
                    } else {
                        osc_add_flash_error_message(
                            sprintf(_m("%s pages couldn't be deleted"), $page_deleted_error),
                            'admin'
                        );
                    }
                }
                if ($page_deleted_correcty > 0) {
                    if ($page_deleted_correcty == 1) {
                        osc_add_flash_ok_message(_m('One page has been deleted correctly'), 'admin');
                    } else {
                        osc_add_flash_ok_message(sprintf(
                            _m('%s pages have been deleted correctly'),
                            $page_deleted_correcty
                        ), 'admin');
                    }
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=pages');
                break;
            default:
                if (Params::getParam('action') != '') {
                    osc_run_hook('page_bulk_' . Params::getParam('action'), Params::getParam('id'));
                }

                require_once osc_lib_path() . 'osclass/classes/datatables/PagesDataTable.php';

                ListPaging::rememberedLength();
                $this->_exportVariableToView('iDisplayLength', Params::getParam('iDisplayLength'));

                // Table header order by related
                if (Params::getParam('sort') == '') {
                    Params::setParam('sort', 'date');
                }
                if (Params::getParam('direction') == '') {
                    Params::setParam('direction', 'desc');
                }

                $page = ListPaging::page();

                $params = Params::getParamsAsArray();

                $pagesDataTable = new PagesDataTable();
                $pagesDataTable->table($params);
                $aData = $pagesDataTable->getData();

                if (count($aData['aRows']) == 0 && $page != 1) {
                    $total   = (int)$aData['iTotalDisplayRecords'];
                    $maxPage = ceil($total / (int)$aData['iDisplayLength']);

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

                $this->_exportVariableToView('aData', $aData);
                $this->_exportVariableToView('aRawRows', $pagesDataTable->rawRows());

                $bulk_options = array(
                    array('value' => '', 'data-dialog-content' => '', 'label' => __('Bulk actions')),
                    array(
                        'value'               => 'delete',
                        'data-dialog-content' => sprintf(
                            __('Are you sure you want to %s the selected pages?'),
                            strtolower(__('Delete'))
                        ),
                        'label'               => __('Delete')
                    )
                );
                $bulk_options = osc_apply_filter('page_bulk_filter', $bulk_options);
                $this->_exportVariableToView('bulk_options', $bulk_options);

                $this->doView('pages/index.php');
                break;
        }
    }

    //hopefully generic...

    /**
     * Save the page the editor posted, through the form it is declared as.
     *
     * The declaration owns the whole write: which request keys are read, how each is
     * sanitised, the rules that can refuse it, the row and the per-locale rows it lands
     * in. What is left here is the screen around it -- the message, the submission kept
     * for a redraw, and where the administrator goes next.
     *
     * @param int|string|null $id The page being edited, null when one is being added
     *
     * @return void
     */
    private function savePage($id)
    {
        $formId = StaticPageForm::register($id);
        $result = osc_settings_save($formId, $id);

        $values = $result['values'];
        $titles = is_array($values['s_title'] ?? null) ? $values['s_title'] : array();
        $bodies = is_array($values['s_text'] ?? null) ? $values['s_text'] : array();

        // The form the view redraws from: one entry per locale, in the shape the screen
        // has always read it back out of.
        $submitted = array();
        foreach ($titles as $code => $title) {
            $submitted[$code] = array('s_title' => $title, 's_text' => $bodies[$code] ?? '');
        }
        Session::newInstance()->_setForm('aFieldsDescription', $submitted);

        $name    = (string)($values['s_internal_name'] ?? '');
        $link    = empty($values['b_link']) ? 0 : 1;
        $failure = StaticPageForm::failure();

        if ($result['errors'] !== array()) {
            // The name is remembered only once it has passed: a refused one must not come
            // back on the next form the administrator opens.
            if (!in_array($failure['rule'] ?? '', array('empty', 'reserved'), true)) {
                Session::newInstance()->_setForm('s_internal_name', $name);
            }

            foreach ($result['errors'] as $error) {
                osc_add_flash_error_message($error, 'admin');
            }
            $field  = $failure['field'] ?? 's_internal_name';
            $errors = array($field => ($field === 's_title'
                ? StaticPageForm::emptyTitles($titles)
                : ($failure['message'] ?? $result['errors'][0])));
            $this->drawForm($id, $name, $link, $errors);

            return;
        }

        Session::newInstance()->_setForm('s_internal_name', $name);
        if ($id !== null) {
            osc_run_hook('edit_page', $id);
        }
        Session::newInstance()->_clearVariables();
        osc_add_flash_ok_message(
            $id === null ? _m('The page has been added') : _m('The page has been updated'),
            'admin'
        );
        $this->redirectTo(osc_admin_base_url(true) . '?page=pages');
    }

    /**
     * Draw the page form again over a rejected save, with what was submitted still in it
     * rather than thrown away with a redirect. The per-locale titles and bodies are
     * already in the session form; the rest is handed over here.
     *
     * @param int|string|null      $id     The page being edited, null when adding
     * @param string               $name   The submitted internal name
     * @param int                  $link   The submitted footer-link choice
     * @param array<string,mixed>  $errors field name => message, or locale code => message
     *
     * @return void
     */
    private function drawForm($id, $name, $link, array $errors)
    {
        $page = $id === null ? array() : $this->pageManager->findByPrimaryKey($id);
        // What was typed wins over what is stored: a rejected save is still the
        // administrator's work in progress.
        $page['s_internal_name'] = $name;
        $page['b_link']          = $link;
        $page['s_meta']          = json_encode(Params::getParam('meta'));

        $templates = osc_apply_filter('page_templates', WebThemes::newInstance()->getAvailableTemplates());
        $this->_exportVariableToView('templates', $templates);
        $this->_exportVariableToView('registeredTemplates', osc_page_templates());
        $this->_exportVariableToView('page', $page);
        $this->_exportVariableToView('editorErrors', $errors);
        $this->doView('pages/frm.php');
    }
}

/* file end: ./oc-admin/CAdminPages.php */
