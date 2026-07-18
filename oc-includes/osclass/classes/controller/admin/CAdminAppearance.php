<?php if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * This file is part of Osclass (Mindstellar).
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
 * Class CAdminAppearance
 */
class CAdminAppearance extends AdminSecBaseModel
{
    //Business Layer...
    public function doModel()
    {
        parent::doModel();
        //specific things for this class
        switch ($this->action) {
            case ('add'):
                $this->doView('appearance/add.php');
                break;
            case ('add_post'):
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m("This action can't be done because it's a demo site"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=appearance');
                }
                osc_csrf_check();
                $filePackage = Params::getFiles('package');
                if (isset($filePackage['size']) && $filePackage['size'] !== 0) {
                    $path   = osc_themes_path();
                    $status = (int)osc_unzip_file($filePackage['tmp_name'], $path);
                    @unlink($filePackage['tmp_name']);
                } else {
                    $status = 3;
                }

                switch ($status) {
                    case (0):
                        $msg = _m('The theme folder is not writable');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (1):
                        $msg = _m('The theme has been installed correctly');
                        osc_add_flash_ok_message($msg, 'admin');
                        break;
                    case (2):
                        $msg = _m('The zip file is not valid');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (3):
                        $msg = _m('No file was uploaded');
                        osc_add_flash_error_message($msg, 'admin');
                        $this->redirectTo(osc_admin_base_url(true) . '?page=appearance&action=add');
                        break;
                    case (-1):
                    default:
                        $msg = _m('There was a problem adding the theme');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=appearance');
                break;
            case ('delete'):
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m("This action can't be done because it's a demo site"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=appearance');
                }
                osc_csrf_check();
                $theme = Params::getParam('webtheme');
                if ($theme != '') {
                    if ($theme != osc_current_web_theme()) {
                        if (file_exists(osc_content_path() . 'themes/' . $theme . '/functions.php')) {
                            include osc_content_path() . 'themes/' . $theme . '/functions.php';
                        }
                        osc_run_hook('theme_delete_' . $theme);
                        if (osc_deleteDir(osc_content_path() . 'themes/' . $theme . '/')) {
                            osc_add_flash_ok_message(_m('Theme removed successfully'), 'admin');
                        } else {
                            osc_add_flash_error_message(_m('There was a problem removing the theme'), 'admin');
                        }
                    } else {
                        osc_add_flash_error_message(_m('Current theme can not be deleted'), 'admin');
                    }
                } else {
                    osc_add_flash_error_message(_m('No theme selected'), 'admin');
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=appearance');
                break;
            /* widgets */
            case ('widgets'):
                $info = WebThemes::newInstance()->loadThemeInfo(osc_theme());

                $this->_exportVariableToView('info', $info);

                $this->doView('appearance/widgets.php');
                break;
            case ('add_widget'):
                $this->doView('appearance/add_widget.php');
                break;
            case ('edit_widget'):
                $id = Params::getParam('id');

                $widget = Widget::newInstance()->findByPrimaryKey($id);
                $this->_exportVariableToView('widget', $widget);

                $this->doView('appearance/add_widget.php');
                break;
            case ('delete_widget'):
                osc_csrf_check();
                Widget::newInstance()->delete(
                    array('pk_i_id' => Params::getParam('id'))
                );
                osc_add_flash_ok_message(_m('Widget removed correctly'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=appearance&action=widgets');
                break;
            case ('edit_widget_post'):
                osc_csrf_check();
                if (!osc_validate_text(Params::getParam('description'))) {
                    osc_add_flash_error_message(_m('Description field is required'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=appearance&action=widgets');
                }

                $res = Widget::newInstance()->update(
                    array(
                        's_description' => Params::getParam('description'),
                        's_content'     => Params::getParam('content', false, false)
                    ),
                    array('pk_i_id' => Params::getParam('id'))
                );

                if ($res) {
                    osc_add_flash_ok_message(_m('Widget updated correctly'), 'admin');
                } else {
                    osc_add_flash_error_message(_m('Widget cannot be updated correctly'), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=appearance&action=widgets');
                break;
            case ('add_widget_post'):
                osc_csrf_check();
                if (!osc_validate_text(Params::getParam('description'))) {
                    osc_add_flash_error_message(_m('Description field is required'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=appearance&action=widgets');
                }

                Widget::newInstance()->insert(
                    array(
                        's_location'    => Params::getParam('location'),
                        'e_kind'        => 'html',
                        's_description' => Params::getParam('description'),
                        's_content'     => Params::getParam('content', false, false)
                    )
                );
                osc_add_flash_ok_message(_m('Widget added correctly'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=appearance&action=widgets');
                break;
            /* /widget */
            case ('activate'):
                osc_csrf_check();
                osc_set_preference('theme', Params::getParam('theme'));
                osc_add_flash_ok_message(_m('Theme activated correctly'), 'admin');
                osc_run_hook('theme_activate', Params::getParam('theme'));
                $this->redirectTo(osc_admin_base_url(true) . '?page=appearance');
                break;
            case ('render'):
                if (Params::existParam('route')) {
                    $routes = Rewrite::newInstance()->getRoutes();
                    $rid    = Params::getParam('route');
                    $file   = '../';
                    if (isset($routes[$rid]['file'])) {
                        $file = $routes[$rid]['file'];
                    }
                } else {
                    // DEPRECATED: Disclosed path in URL is deprecated, use routes instead
                    // This will be REMOVED in 3.6
                    $file = Params::getParam('file');
                    // We pass the GET variables (in case we have somes)
                    if (preg_match('|(.+?)\?(.*)|', $file, $match)) {
                        $file = $match[1];
                        if (preg_match_all('|&([^=]+)=([^&]*)|', urldecode('&' . $match[2] . '&'), $get_vars)) {
                            for ($var_k = 0; $var_k < count($get_vars[1]); $var_k++) {
                                Params::setParam($get_vars[1][$var_k], $get_vars[2][$var_k]);
                            }
                        }
                    } else {
                        $file = Params::getParam('file');
                    }
                }

                if (strpos($file, '../') !== false
                    || strpos($file, '..\\') !== false
                    || !file_exists(osc_base_path() . $file)
                ) {
                    osc_add_flash_warning_message(__('Error loading theme custom file'), 'admin');
                }
                $this->_exportVariableToView('file', osc_base_path() . $file);
                $this->doView('appearance/view.php');
                break;
            default:
                if (Params::getParam('checkUpdated') != '') {
                    osc_admin_toolbar_update_themes(true);
                }

                $themes = WebThemes::newInstance()->getListThemes();

                //preparing variables for the view
                $this->_exportVariableToView('themes', $themes);

                $this->doView('appearance/index.php');
                break;
        }
    }

    //hopefully generic...

}

/* file end: ./oc-admin/CAdminAppearance.php */
