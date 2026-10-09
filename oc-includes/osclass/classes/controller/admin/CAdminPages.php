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
use mindstellar\admin\BulkAction;
use mindstellar\admin\form\StaticPageForm;
use mindstellar\admin\ListPaging;
use mindstellar\pages\PageService;

class CAdminPages extends AdminSecBaseModel
{
    /** Each action and the method that answers it; any other action goes to pages(). */
    private const ACTIONS = array(
        'edit'      => 'editForm',
        'edit_post' => 'editPost',
        'add'       => 'addForm',
        'add_post'  => 'addPost',
        'delete'    => 'deletePages',
    );

    //specific for this class
    private $pageManager;

    /**
     * Take the page manager for this request.
     */
    public function __construct()
    {
        parent::__construct();

        //specific things for this class
        $this->pageManager = Page::getInstance();
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

        $method = is_string($this->action) ? (self::ACTIONS[$this->action] ?? 'pages') : 'pages';

        $this->$method();
    }

    /**
     * The page editor.
     */
    private function editForm(): void
    {
        if (Params::getParam('id') == '') {
            $this->redirectTo(osc_admin_base_url(true) . '?page=pages');
        }

        $form     = count(Session::getInstance()->_getForm());
        $keepForm = count(Session::getInstance()->_getKeepForm());
        if ($form == 0 || $form == $keepForm) {
            Session::getInstance()->_dropKeepForm();
        }

        $templates = osc_apply_filter('page_templates', WebThemes::getInstance()->getAvailableTemplates());
        $this->_exportVariableToView('templates', $templates);
        $this->_exportVariableToView('registeredTemplates', osc_page_templates());
        $this->_exportVariableToView('page', $this->pageManager->findByPrimaryKey(Params::getParam('id')));
        $this->doView('pages/frm.php');
    }

    /**
     * Save the page editor.
     */
    private function editPost(): void
    {
        osc_csrf_check();
        $this->savePage(Params::getParam('id'));
    }

    /**
     * The form for a new page.
     */
    private function addForm(): void
    {
        $form     = count(Session::getInstance()->_getForm());
        $keepForm = count(Session::getInstance()->_getKeepForm());
        if ($form == 0 || $form == $keepForm) {
            Session::getInstance()->_dropKeepForm();
        }

        $templates = osc_apply_filter('page_templates', WebThemes::getInstance()->getAvailableTemplates());
        $this->_exportVariableToView('templates', $templates);
        $this->_exportVariableToView('registeredTemplates', osc_page_templates());
        $this->_exportVariableToView('page', array());
        $this->doView('pages/frm.php');
    }

    /**
     * Create a page from the form.
     */
    private function addPost(): void
    {
        osc_csrf_check();
        $this->savePage(null);
    }

    /**
     * Delete the selected pages.
     */
    private function deletePages(): void
    {
        osc_csrf_check();
        $id                    = Params::getParam('id');
        $page_deleted_correcty = 0;
        $page_deleted_error    = 0;
        $page_indelible        = 0;

        if (!is_array($id)) {
            $id = array($id);
        }

        $pageService = PageService::make();
        foreach ($id as $_id) {
            // A malformed id (an array, or not a number) is an error, not page (int) 1.
            $result = is_scalar($_id) && ctype_digit((string) $_id) ? (int) $pageService->delete((int) $_id) : 0;
            switch ($result) {
                case -1:
                    $page_indelible++;
                    break;
                case 0:
                    $page_deleted_error++;
                    break;
                case 1:
                    $page_deleted_correcty++;
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
    }

    /**
     * The pages table.
     */
    private function pages(): void
    {
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

        $pastEnd = ListPaging::pastEnd($aData, (int) $page);
        if ($pastEnd !== null) {
            $this->redirectTo($pastEnd);
        }

        $this->_exportVariableToView('aData', $aData);
        $this->_exportVariableToView('aRawRows', $pagesDataTable->rawRows());

        $bulk_options = BulkAction::options(
            array(
                'delete' => __('Delete')
            ),
            __('Are you sure you want to %s the selected pages?')
        );
        $bulk_options = osc_apply_filter('page_bulk_filter', $bulk_options);
        $this->_exportVariableToView('bulk_options', $bulk_options);

        $this->doView('pages/index.php');
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
        // The form store writes the page tables directly, not through the Page model.
        \mindstellar\cache\CacheGroup::invalidate('page');

        $values = $result['values'];
        $titles = is_array($values['s_title'] ?? null) ? $values['s_title'] : array();
        $bodies = is_array($values['s_text'] ?? null) ? $values['s_text'] : array();

        // The form the view redraws from: one entry per locale, in the shape the screen
        // has always read it back out of.
        $submitted = array();
        foreach ($titles as $code => $title) {
            $submitted[$code] = array('s_title' => $title, 's_text' => $bodies[$code] ?? '');
        }
        Session::getInstance()->_setForm('aFieldsDescription', $submitted);

        $name    = (string)($values['s_internal_name'] ?? '');
        $link    = empty($values['b_link']) ? 0 : 1;
        $failure = StaticPageForm::failure();

        if ($result['errors'] !== array()) {
            // The name is remembered only once it has passed: a refused one must not come
            // back on the next form the administrator opens.
            if (!in_array($failure['rule'] ?? '', array('empty', 'reserved'), true)) {
                Session::getInstance()->_setForm('s_internal_name', $name);
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

        Session::getInstance()->_setForm('s_internal_name', $name);
        if ($id !== null) {
            osc_run_hook('edit_page', $id);
        } else {
            osc_purge_page_cache('page');
        }
        Session::getInstance()->_clearVariables();
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

        $templates = osc_apply_filter('page_templates', WebThemes::getInstance()->getAvailableTemplates());
        $this->_exportVariableToView('templates', $templates);
        $this->_exportVariableToView('registeredTemplates', osc_page_templates());
        $this->_exportVariableToView('page', $page);
        $this->_exportVariableToView('editorErrors', $errors);
        $this->doView('pages/frm.php');
    }
}

/* file end: ./oc-admin/CAdminPages.php */
