<?php

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

use mindstellar\utility\Validate;

/**
 * Class CWebPage
 */
class CWebPage extends BaseModel
{
    public $pageManager;

    public function __construct()
    {
        parent::__construct();

        $this->pageManager = Page::newInstance();
        osc_run_hook('init_page');
    }

    public function doModel()
    {
        $id   = Params::getParam('id');
        $page = false;

        if (is_numeric($id)) {
            $page = $this->pageManager->findByPrimaryKey($id);
        } else {
            $page = $this->pageManager->findByInternalName(Params::getParam('slug'));
        }

        // page not found
        if ($page == false) {
            $this->do404();

            return;
        }

        // this page shouldn't be shown (i.e.: e-mail templates)
        if ($page['b_indelible'] == 1) {
            $this->do404();

            return;
        }

        $kwords = array('{WEB_URL}', '{WEB_TITLE}');
        $rwords = array(osc_base_url(), osc_page_title());
        foreach ($page['locale'] as $k => $v) {
            $page['locale'][$k]['s_title'] = str_ireplace(
                $kwords,
                $rwords,
                osc_apply_filter('email_description', $v['s_title'])
            );
            $page['locale'][$k]['s_text']  =
                str_ireplace($kwords, $rwords, osc_apply_filter('email_description', $v['s_text']));
        }

        // export $page content to View
        $this->_exportVariableToView('page', $page);
        if (Params::getParam('lang') && (new Validate())->localeCode(Params::getParam('lang'))) {
            Session::newInstance()->_set('userLocale', Params::getParam('lang'));
        }

        $meta = json_decode($page['s_meta'], true);

        // load the right template file
        if (file_exists(osc_themes_path() . osc_theme() . '/page-' . $page['s_internal_name']
            . '.php')
        ) {
            $this->doView('page-' . $page['s_internal_name'] . '.php');
        } elseif (isset($meta['template'])
            && file_exists(osc_themes_path() . osc_theme() . '/' . $meta['template'])
        ) {
            $this->doView($meta['template']);
        } elseif (isset($meta['template'])
            && file_exists(osc_plugins_path() . '/' . $meta['template'])
        ) {
            osc_run_hook('before_html');
            require osc_plugins_path() . '/' . $meta['template'];
            Session::newInstance()->_clearVariables();
            osc_run_hook('after_html');
        } else {
            $this->doView('page.php');
        }
    }

    /**
     * @param $file
     *
     * @return void
     */
    public function doView($file)
    {
        osc_run_hook('before_html');
        osc_current_web_theme_path($file);
        Session::newInstance()->_clearVariables();
        osc_run_hook('after_html');
    }
}

/* file end: ./CWebPage.php */
