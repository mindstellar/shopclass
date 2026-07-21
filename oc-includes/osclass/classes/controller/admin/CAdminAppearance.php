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
                $this->redirectTo($this->widgetReturnUrl());
                break;
            case ('edit_widget_post'):
                osc_csrf_check();
                if (!osc_validate_text(Params::getParam('description'))) {
                    osc_add_flash_error_message(_m('Description field is required'), 'admin');
                    $this->redirectTo($this->widgetReturnUrl());
                }

                $type = $this->resolveWidgetType(Params::getParam('s_type'));

                if ($type !== null) {
                    $res = Widget::newInstance()->update(
                        array(
                            's_description' => Params::getParam('description'),
                            's_content'     => '',
                            's_type'        => $type['id'],
                            's_config'      => json_encode($this->buildWidgetConfig($type))
                        ),
                        array('pk_i_id' => Params::getParam('id'))
                    );
                } else {
                    $res = Widget::newInstance()->update(
                        array(
                            's_description' => Params::getParam('description'),
                            's_content'     => Params::getParam('content', false, false)
                        ),
                        array('pk_i_id' => Params::getParam('id'))
                    );
                }

                if ($res) {
                    osc_add_flash_ok_message(_m('Widget updated correctly'), 'admin');
                } else {
                    osc_add_flash_error_message(_m('Widget cannot be updated correctly'), 'admin');
                }
                $this->redirectTo($this->widgetReturnUrl());
                break;
            case ('add_widget_post'):
                osc_csrf_check();
                if (!osc_validate_text(Params::getParam('description'))) {
                    osc_add_flash_error_message(_m('Description field is required'), 'admin');
                    $this->redirectTo($this->widgetReturnUrl());
                }

                $location = Params::getParam('location');
                $type     = $this->resolveWidgetType(Params::getParam('s_type'));

                if ($type !== null) {
                    Widget::newInstance()->insert(
                        array(
                            's_location'    => $location,
                            'e_kind'        => 'html',
                            's_description' => Params::getParam('description'),
                            's_content'     => '',
                            's_type'        => $type['id'],
                            's_config'      => json_encode($this->buildWidgetConfig($type)),
                            'i_order'       => Widget::newInstance()->getNextOrder($location)
                        )
                    );
                } else {
                    Widget::newInstance()->insert(
                        array(
                            's_location'    => $location,
                            'e_kind'        => 'html',
                            's_description' => Params::getParam('description'),
                            's_content'     => Params::getParam('content', false, false),
                            'i_order'       => Widget::newInstance()->getNextOrder($location)
                        )
                    );
                }
                osc_add_flash_ok_message(_m('Widget added correctly'), 'admin');
                $this->redirectTo($this->widgetReturnUrl());
                break;
            case ('reorder_widgets_post'):
                // JSON endpoint: flagging the request as AJAX makes the CSRF check
                // emit a JSON error and exit on failure instead of flash+redirect.
                if (!defined('IS_AJAX')) {
                    define('IS_AJAX', true);
                }
                osc_csrf_check();

                $location = Params::getParam('location');
                $ids      = Params::getParam('ids');
                if (!is_array($ids)) {
                    $ids = array();
                }
                // Integer-validate the id list, dropping any non-numeric value.
                $ids = array_values(array_map('intval', array_filter($ids, 'is_numeric')));

                // Defence in depth: only reorder ids that currently belong to this
                // location, so a forged post cannot move widgets from elsewhere.
                $validIds = array();
                foreach (Widget::newInstance()->findByLocation($location) as $widget) {
                    $validIds[(int) $widget['pk_i_id']] = true;
                }
                $ids = array_values(array_filter($ids, static function ($id) use ($validIds) {
                    return isset($validIds[$id]);
                }));

                $ok = Widget::newInstance()->reorder($ids);

                header('Content-Type: application/json');
                echo json_encode(array('error' => $ok ? 0 : 1));
                exit;
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

    /**
     * Where a widget add/edit/delete returns to. A widget managed from a static
     * page's block canvas carries page_builder_id, so the action returns to that
     * page editor instead of the appearance widgets screen. The id is rebuilt
     * server-side into a fixed internal URL (never a caller-supplied redirect),
     * so it cannot be turned into an open redirect.
     *
     * @return string
     */
    private function widgetReturnUrl()
    {
        $pageBuilderId = (int) Params::getParam('page_builder_id');
        if ($pageBuilderId > 0) {
            return osc_admin_base_url(true) . '?page=pages&action=edit&id=' . $pageBuilderId;
        }

        return osc_admin_base_url(true) . '?page=appearance&action=widgets';
    }

    /**
     * Resolve a posted s_type into a registered widget-type spec, enforcing the
     * type's capability. Returns null for the legacy (no type) path so the
     * caller falls back to stored-content behaviour.
     *
     * An s_type that is non-empty but not registered, or a super_admin type
     * requested by a moderator, is rejected here with a flash error + redirect
     * (redirectTo exits) so a typed save can never silently degrade to a
     * mislabelled legacy row or bypass the capability gate.
     *
     * @param string $sType Posted s_type value.
     *
     * @return array|null The registered type spec, or null for the legacy path.
     */
    private function resolveWidgetType($sType)
    {
        if ($sType === '' || $sType === null) {
            return null;
        }

        $type = \mindstellar\widgets\WidgetRegistry::instance()->get($sType);
        if ($type === null) {
            osc_add_flash_error_message(_m('Unknown widget type'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=appearance&action=widgets');
        }

        if ($type['capability'] === 'super_admin' && osc_is_moderator()) {
            osc_add_flash_error_message(_m('You are not allowed to add this widget type'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=appearance&action=widgets');
        }

        return $type;
    }

    /**
     * Build the config array for a typed widget from the posted config[] values,
     * keeping only the keys the type declares in 'fields' and sanitising each
     * value per its declared field type. Unknown posted keys are dropped.
     *
     * @param array $type A registered widget-type spec.
     *
     * @return array Sanitised config keyed by field name.
     */
    private function buildWidgetConfig($type)
    {
        // Default purification (HTMLPurifier) already applied to each value.
        $posted = Params::getParam('config');
        if (!is_array($posted)) {
            $posted = array();
        }

        // Unpurified copy of the same posted values, used only by 'code' fields
        // on a super_admin-gated type so their raw HTML/JS survives. Fetched
        // fully raw (no xss_check, no html_encode, no quotes_encode).
        $postedRaw = Params::getParam('config', false, false, false);
        if (!is_array($postedRaw)) {
            $postedRaw = array();
        }

        // Whether this widget type may store raw code at all. A 'code' field on
        // any non-super_admin type falls back to purified text (defence in
        // depth: a moderator-reachable type must never persist raw markup).
        $isSuperAdmin = ($type['capability'] ?? 'admin') === 'super_admin';

        $config = array();
        foreach ($type['fields'] as $field) {
            if (empty($field['name'])) {
                continue;
            }
            $name      = $field['name'];
            $fieldType = $field['type'] ?? 'text';
            $default   = $field['default'] ?? null;
            $hasValue  = array_key_exists($name, $posted);
            $raw       = $hasValue ? $posted[$name] : null;

            switch ($fieldType) {
                case 'number':
                    $config[$name] = $hasValue ? (int) $raw : (int) $default;
                    break;
                case 'checkbox':
                    $config[$name] = ($hasValue && $raw !== '' && $raw !== '0') ? 1 : 0;
                    break;
                case 'select':
                    $options = isset($field['options']) && is_array($field['options'])
                        ? $field['options'] : array();
                    $allowed = $this->selectAllowedValues($options);
                    $value   = $hasValue && is_scalar($raw) ? (string) $raw : (string) $default;
                    $config[$name] = in_array($value, $allowed, true) ? $value : (string) $default;
                    break;
                case 'code':
                    // Raw HTML/JS, stored unfiltered — but ONLY for a
                    // super_admin-gated type. For any other type a 'code' field
                    // degrades to purified text so raw markup can never reach
                    // storage from a moderator-reachable path (stored-XSS guard).
                    if ($isSuperAdmin) {
                        $hasRaw     = array_key_exists($name, $postedRaw);
                        $rawValue   = $hasRaw ? $postedRaw[$name] : null;
                        $config[$name] = $hasRaw && is_string($rawValue) ? $rawValue : (string) $default;
                    } else {
                        $config[$name] = $hasValue && is_string($raw) ? $raw : (string) $default;
                    }
                    break;
                case 'textarea':
                case 'text':
                default:
                    $config[$name] = $hasValue && is_string($raw) ? $raw : (string) $default;
                    break;
            }
        }

        return $config;
    }

    /**
     * Whitelist of acceptable string values for a select field, tolerant of the
     * common option shapes: an associative value=>label map (keys are the
     * values), a flat list of scalar values, or a list of
     * ['value'=>..,'label'=>..] entries.
     *
     * @param array $options Declared field options.
     *
     * @return string[] Allowed values as strings.
     */
    private function selectAllowedValues($options)
    {
        $allowed = array();
        foreach ($options as $key => $option) {
            if (is_array($option)) {
                if (array_key_exists('value', $option) && is_scalar($option['value'])) {
                    $allowed[] = (string) $option['value'];
                }
                continue;
            }
            if (!is_int($key)) {
                // Associative map: the key is the option value.
                $allowed[] = (string) $key;
            } elseif (is_scalar($option)) {
                // Flat list: the entry itself is the option value.
                $allowed[] = (string) $option;
            }
        }

        return $allowed;
    }

}

/* file end: ./oc-admin/CAdminAppearance.php */
