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

                // update() returns affectedRows(), and false only on a query error.
                // Saving a widget without changing any value affects 0 rows, which is
                // success with nothing to do — not a failure.
                if ($res !== false) {
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
            case ('widget_create_post'):
                // Dropping a type from the palette creates a widget of that type in
                // that section. JSON, so the builder can insert the row without a
                // page load; the caller opens its editor straight away because a
                // fresh widget has no configuration yet.
                if (!defined('IS_AJAX')) {
                    define('IS_AJAX', true);
                }
                osc_csrf_check();

                $location = Params::getParam('location');
                $type     = $this->resolveWidgetType(Params::getParam('s_type'));
                $label    = $type !== null ? $type['label'] : __('Custom HTML');

                $row = array(
                    's_location'    => $location,
                    'e_kind'        => 'html',
                    's_description' => $label,
                    's_content'     => '',
                    'i_order'       => Widget::newInstance()->getNextOrder($location)
                );
                if ($type !== null) {
                    $row['s_type']   = $type['id'];
                    $row['s_config'] = json_encode($this->buildWidgetConfig($type));
                }
                // New code goes through the query builder, which returns the new
                // AUTO_INCREMENT id directly. (The legacy DAO's insert() reports only
                // success, and returning that handed the builder id "1" — a dragged-in
                // widget then adopted widget 1's row and could overwrite it.)
                try {
                    $newId = osc_db_table(DB_TABLE_PREFIX . 't_widget')->insert($row);
                } catch (Throwable $e) {
                    $newId = 0;
                }

                header('Content-Type: application/json');
                echo json_encode($newId > 0
                    ? array(
                        'error'       => 0,
                        'id'          => (int)$newId,
                        'description' => $label,
                        'type_label'  => $type !== null ? $type['label'] : __('Custom HTML'),
                        'location'    => $location
                    )
                    : array('error' => 1));
                exit;
            case ('widget_move_post'):
                // Reorder, and move between sections. reorder_widgets_post refuses ids
                // that do not already belong to the location — correct for a pure
                // reorder, but it is exactly what has to change for a cross-section
                // drag, so the move is its own endpoint with its own validation.
                if (!defined('IS_AJAX')) {
                    define('IS_AJAX', true);
                }
                osc_csrf_check();

                $location = Params::getParam('location');
                $moved    = (int)Params::getParam('id');
                $ids      = Params::getParam('ids');
                if (!is_array($ids)) {
                    $ids = array();
                }
                $ids = array_values(array_map('intval', array_filter($ids, 'is_numeric')));

                // The section must be one the active theme actually declares, so a
                // forged post cannot invent a location.
                $info       = WebThemes::newInstance()->loadThemeInfo(osc_theme());
                $locations  = isset($info['locations']) && is_array($info['locations']) ? $info['locations'] : array();
                $widgetRow  = $moved > 0 ? Widget::newInstance()->findByPrimaryKey($moved) : null;
                $ok         = false;

                if ($widgetRow !== null && in_array($location, $locations, true)) {
                    osc_db_table(DB_TABLE_PREFIX . 't_widget')
                        ->where('pk_i_id', $moved)
                        ->update(array('s_location' => $location));
                    // Only ids that live in the target section after the move.
                    $validIds = array();
                    foreach (Widget::newInstance()->findByLocation($location) as $widget) {
                        $validIds[(int)$widget['pk_i_id']] = true;
                    }
                    $ids = array_values(array_filter($ids, static function ($id) use ($validIds) {
                        return isset($validIds[$id]);
                    }));
                    $ok = Widget::newInstance()->reorder($ids);
                }

                header('Content-Type: application/json');
                echo json_encode(array('error' => $ok ? 0 : 1));
                exit;
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
                    // Pass the spec through untouched: options may be a callable for a
                    // dynamic list (core.form's picker is 'osc_form_widget_options').
                    // Guarding with is_array() here stripped the callable before
                    // selectAllowedValues() could resolve it, so nothing validated and
                    // every posted value fell back to the default — the chosen form was
                    // silently discarded on save.
                    $allowed = $this->selectAllowedValues($field['options'] ?? array());
                    $value   = $hasValue && is_scalar($raw) ? (string) $raw : (string) $default;
                    $config[$name] = in_array($value, $allowed, true) ? $value : (string) $default;
                    break;
                case 'image':
                    // A media URL from the picker. Read the unpurified value (so a
                    // query string on an offloaded URL survives) but store only an
                    // http(s) URL — anything else, incl. javascript: dressed up as a
                    // URL, is dropped. The value only ever lands in an <img src>.
                    $hasRaw   = array_key_exists($name, $postedRaw);
                    $imageUrl = $hasRaw && is_string($postedRaw[$name]) ? trim($postedRaw[$name]) : (string) $default;
                    $config[$name] = ($imageUrl !== '' && filter_var($imageUrl, FILTER_VALIDATE_URL)
                        && preg_match('#^https?://#i', $imageUrl)) ? $imageUrl : '';
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
        // A widget type may supply select options as a callable (dynamic list, e.g.
        // the core.form picker); resolve it before validating the posted value.
        if (is_callable($options)) {
            $options = call_user_func($options);
        }
        $allowed = array();
        if (!is_array($options)) {
            return $allowed;
        }
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
