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
 * Class CAdminAppearance
 */
use mindstellar\market\Catalog;
use mindstellar\market\PluginDependencies;
use mindstellar\security\PluginAjaxFile;
use mindstellar\utility\AjaxResponse;
use mindstellar\widgets\Customiser;
use mindstellar\widgets\WidgetConfig;
use mindstellar\widgets\WidgetStore;

class CAdminAppearance extends AdminSecBaseModel
{
    use \mindstellar\base\ActionMap;

    /** Each action and the method that answers it; any other action goes to themes(). */
    private const ACTIONS = array(
        'add'                  => 'addForm',
        'add_post'             => 'addPost',
        'delete'               => 'deleteTheme',
        'widgets'              => 'widgets',
        'add_widget'           => 'addWidgetForm',
        'edit_widget'          => 'editWidgetForm',
        'delete_widget'        => 'deleteWidget',
        'edit_widget_post'     => 'editWidgetPost',
        'add_widget_post'      => 'addWidgetPost',
        'widget_create_post'   => 'createWidgetPost',
        'widget_move_post'     => 'moveWidgetPost',
        'reorder_widgets_post' => 'reorderWidgetsPost',
        'customise_draft_post'   => 'customiseDraftPost',
        'customise_publish_post' => 'customisePublishPost',
        'customise_discard_post' => 'customiseDiscardPost',
        'activate'             => 'activateTheme',
        'dependencies'         => 'dependencies',
        'render'               => 'render',
    );

    //Business Layer...

    /**
     * Dispatch the requested appearance action: theme install/delete/activate, the market
     * browser, and the whole widget CRUD and reorder surface.
     *
     * @return void
     */
    public function doModel()
    {
        parent::doModel();
        $method = $this->actionMethod('themes');

        $this->$method();
    }

    /**
     * The form to upload a theme package.
     */
    private function addForm(): void
    {
        $this->doView('appearance/add.php');
    }

    /**
     * Install an uploaded theme package.
     */
    private function addPost(): void
    {
        if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=appearance')) {
            return;
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
    }

    /**
     * Delete a theme's files.
     */
    private function deleteTheme(): void
    {
        if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=appearance')) {
            return;
        }
        osc_csrf_check();
        $theme   = Params::getParamString('webtheme');
        $themes  = WebThemes::getInstance();
        // The name decides which directory is included and then deleted, so it is
        // matched against the installed themes rather than trusted.
        $known   = $themes->getListThemes();
        // A theme another one extends: deleting it leaves that child rendering on
        // the default theme, which is a broken site nobody asked for.
        $needed  = array();
        foreach ($known as $other) {
            $info = $themes->loadThemeInfo($other);
            if (is_array($info) && !empty($info['template']) && $info['template'] === $theme
                && $other !== $theme
            ) {
                $needed[] = $other;
            }
        }

        if ($theme === '') {
            osc_add_flash_error_message(_m('No theme selected'), 'admin');
        } elseif (!in_array($theme, $known, true)) {
            osc_add_flash_error_message(_m('That theme is not installed'), 'admin');
        } elseif ($theme === osc_current_web_theme()) {
            osc_add_flash_error_message(_m('Current theme can not be deleted'), 'admin');
        } elseif ($needed !== array()) {
            osc_add_flash_error_message(
                sprintf(
                    _m('"%1$s" extends this theme. Delete it first, or switch it to '
                       . 'another parent.'),
                    implode('", "', $needed)
                ),
                'admin'
            );
        } else {
            if (file_exists(osc_content_path() . 'themes/' . $theme . '/functions.php')) {
                include osc_content_path() . 'themes/' . $theme . '/functions.php';
            }
            osc_run_hook('theme_delete_' . $theme);
            if (osc_deleteDir(osc_content_path() . 'themes/' . $theme . '/')) {
                osc_add_flash_ok_message(_m('Theme removed successfully'), 'admin');
            } else {
                osc_add_flash_error_message(_m('There was a problem removing the theme'), 'admin');
            }
        }

        $this->redirectTo(osc_admin_base_url(true) . '?page=appearance');
    }

    /**
     * The widgets screen.
     */
    private function widgets(): void
    {
        $info = WebThemes::getInstance()->loadThemeInfo(osc_theme());

        $this->_exportVariableToView('info', $info);

        $this->doView('appearance/widgets.php');
    }

    /**
     * The form for a new widget.
     */
    private function addWidgetForm(): void
    {
        $this->doView('appearance/add_widget.php');
    }

    /**
     * The widget editor.
     */
    private function editWidgetForm(): void
    {
        try {
            $widget = WidgetStore::find(Params::getParamInt('id')) ?? false;
        } catch (Throwable $e) {
            $widget = false;
        }
        $this->_exportVariableToView('widget', $widget);

        $this->doView('appearance/add_widget.php');
    }

    /**
     * Delete a widget.
     */
    private function deleteWidget(): void
    {
        osc_csrf_check();
        $widgetId = Params::getParamInt('id');
        osc_run_hook('before_delete_widget', $widgetId);
        try {
            WidgetStore::delete($widgetId);
        } catch (Throwable $e) {
            // A failed delete still flashes success, as the legacy model did.
        }
        osc_run_hook('after_delete_widget', $widgetId);
        osc_add_flash_ok_message(_m('Widget removed correctly'), 'admin');
        $this->redirectTo($this->widgetReturnUrl());
    }

    /**
     * Save the widget editor.
     */
    private function editWidgetPost(): void
    {
        osc_csrf_check();
        if (!osc_validate_text(Params::getParam('description'))) {
            osc_add_flash_error_message(_m('Description field is required'), 'admin');
            $this->redirectTo($this->widgetReturnUrl());
        }

        $type = $this->resolveWidgetType(Params::getParam('s_type'));

        if ($type !== null) {
            $config = $this->buildWidgetConfig($type);
            // A row hidden in the customiser stays hidden when edited here.
            try {
                $current = WidgetStore::find(Params::getParamInt('id'));
            } catch (Throwable $e) {
                $current = null;
            }
            $stored = $current !== null ? json_decode((string) $current['s_config'], true) : null;
            if (is_array($stored) && array_key_exists(WidgetConfig::ENABLED, $stored)) {
                $config[WidgetConfig::ENABLED] = $stored[WidgetConfig::ENABLED] !== false;
            }
            $values = array(
                's_description' => Params::getParam('description'),
                's_content'     => '',
                's_type'        => $type['id'],
                's_config'      => json_encode($config)
            );
        } else {
            $values = array(
                's_description' => Params::getParam('description'),
                's_content'     => Params::getParam('content', false, false)
            );
        }

        // Saving without changing any value affects 0 rows, which is still success.
        try {
            WidgetStore::update(Params::getParamInt('id'), $values);
            $res = true;
        } catch (Throwable $e) {
            $res = false;
        }
        if ($res) {
            osc_purge_page_cache('widget');
            osc_add_flash_ok_message(_m('Widget updated correctly'), 'admin');
        } else {
            osc_add_flash_error_message(_m('Widget cannot be updated correctly'), 'admin');
        }
        $this->redirectTo($this->widgetReturnUrl());
    }

    /**
     * Create a widget from the form.
     */
    private function addWidgetPost(): void
    {
        osc_csrf_check();
        if (!osc_validate_text(Params::getParam('description'))) {
            osc_add_flash_error_message(_m('Description field is required'), 'admin');
            $this->redirectTo($this->widgetReturnUrl());
        }

        $location = Params::getParam('location');
        $type     = $this->resolveWidgetType(Params::getParam('s_type'));

        $row = array(
            's_location'    => $location,
            'e_kind'        => 'html',
            's_description' => Params::getParam('description'),
            's_content'     => Params::getParam('content', false, false),
        );
        if ($type !== null) {
            $row['s_content'] = '';
            $row['s_type']    = $type['id'];
            $row['s_config']  = json_encode($this->buildWidgetConfig($type));
        }
        try {
            $row['i_order'] = WidgetStore::nextOrder((string)$location);
            WidgetStore::add($row);
        } catch (Throwable $e) {
            // A failed insert still flashes success, as the legacy model did.
        }
        osc_purge_page_cache('widget');
        osc_add_flash_ok_message(_m('Widget added correctly'), 'admin');
        $this->redirectTo($this->widgetReturnUrl());
    }

    /**
     * Create a widget dropped from the palette, answered in JSON.
     */
    private function createWidgetPost(): void
    {
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
        );
        if ($type !== null) {
            $row['s_type']   = $type['id'];
            $row['s_config'] = json_encode($this->buildWidgetConfig($type));
        }
        // add() returns the new id; the builder needs it to open the editor.
        try {
            $row['i_order'] = WidgetStore::nextOrder((string)$location);
            $newId          = WidgetStore::add($row);
        } catch (Throwable $e) {
            $newId = 0;
        }
        if ($newId > 0) {
            osc_purge_page_cache('widget');
        }

        AjaxResponse::json($newId > 0
            ? array(
                'error'       => 0,
                'id'          => (int)$newId,
                'description' => $label,
                'type_label'  => $type !== null ? $type['label'] : __('Custom HTML'),
                'location'    => $location
            )
            : array('error' => 1));
        exit;
    }

    /**
     * Move a widget to another section, answered in JSON.
     */
    private function moveWidgetPost(): void
    {
        // Reorder, and move between sections. reorder_widgets_post refuses ids
        // that do not already belong to the location — correct for a pure
        // reorder, but it is exactly what has to change for a cross-section
        // drag, so the move is its own endpoint with its own validation.
        if (!defined('IS_AJAX')) {
            define('IS_AJAX', true);
        }
        osc_csrf_check();

        $location = Params::getParam('location');
        $moved    = Params::getParamInt('id');
        $ids      = Params::getParamArray('ids');

        // The section must be one the active theme actually offers, so a
        // forged post cannot invent a location.
        $locations = osc_widget_locations();
        $ok        = false;

        try {
            $widgetRow = $moved > 0 ? WidgetStore::find($moved) : null;
            if ($widgetRow !== null && is_string($location) && isset($locations[$location])) {
                WidgetStore::moveTo($moved, $location);
                $ok = WidgetStore::reorderWithin($location, $ids);
                osc_purge_page_cache('widget');
            }
        } catch (Throwable $e) {
            $ok = false;
        }

        AjaxResponse::json(array('error' => $ok ? 0 : 1));
        exit;
    }

    /**
     * Save the order of a section's widgets, answered in JSON.
     */
    private function reorderWidgetsPost(): void
    {
        // JSON endpoint: flagging the request as AJAX makes the CSRF check
        // emit a JSON error and exit on failure instead of flash+redirect.
        if (!defined('IS_AJAX')) {
            define('IS_AJAX', true);
        }
        osc_csrf_check();

        $location = Params::getParam('location');
        $ids      = Params::getParamArray('ids');

        $ok = WidgetStore::reorderWithin((string) $location, $ids);
        if ($ok) {
            osc_purge_page_cache('widget');
        }

        AjaxResponse::json(array('error' => $ok ? 0 : 1));
        exit;
    }

    /**
     * Save the customiser's draft, answered in JSON.
     */
    private function customiseDraftPost(): void
    {
        $customiser = $this->customiser();
        if ($customiser === null) {
            return;
        }
        $result = $customiser->saveDraft(
            Params::getParamArray('locations'),
            Params::getParamArray('locations', false, false, false),
            Params::getParamArray('settings', false, false, false)
        );
        if ($result['status'] !== 200) {
            AjaxResponse::json(array('error' => 1, 'errors' => $result['errors']), $result['status']);

            return;
        }

        AjaxResponse::json(array(
            'error'       => 0,
            'saved_at'    => $result['saved_at'],
            'preview_url' => osc_customiser_preview_url(),
        ));
    }

    /**
     * Publish the customiser's draft, answered in JSON.
     */
    private function customisePublishPost(): void
    {
        $customiser = $this->customiser();
        if ($customiser === null) {
            return;
        }
        $result = $customiser->publish();
        if ($result['status'] === 200) {
            osc_purge_page_cache('widget');
        }

        AjaxResponse::json(array('error' => $result['status'] === 200 ? 0 : 1, 'errors' => $result['errors']), $result['status']);
    }

    /**
     * Throw the customiser's draft away, answered in JSON.
     */
    private function customiseDiscardPost(): void
    {
        $customiser = $this->customiser();
        if ($customiser === null) {
            return;
        }
        try {
            $customiser->discard();
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }

        AjaxResponse::json(array('error' => $ok ? 0 : 1), $ok ? 200 : 500);
    }

    /**
     * The customiser for this admin, after the checks every customiser action shares: a valid
     * CSRF token, an administrator, and a theme that declares one. Null once refused.
     */
    private function customiser(): ?Customiser
    {
        if (!defined('IS_AJAX')) {
            define('IS_AJAX', true);
        }
        osc_csrf_check();
        if (osc_is_moderator()) {
            AjaxResponse::json(array('error' => 1, 'errors' => array(_m('Only an administrator can customise the theme.'))), 403);

            return null;
        }
        $customiser = Customiser::forCurrentAdmin();
        if ($customiser === null) {
            AjaxResponse::json(array('error' => 1, 'errors' => array(_m('This theme has nothing to customise.'))), 404);
        }

        return $customiser;
    }

    /**
     * Make a theme the site's theme. A theme whose plugins are not ready goes to the plugins screen first.
     */
    private function activateTheme(): void
    {
        osc_csrf_check();
        $back  = osc_admin_base_url(true) . '?page=appearance';
        $theme = Params::getParamString('theme');
        // Only an installed theme, the same rule theme:activate applies on the CLI.
        $requires = PluginDependencies::themeRequirements($theme);
        if ($requires === null) {
            osc_add_flash_error_message(_m('That theme is not installed.'), 'admin');
            $this->redirectTo($back);
            return;
        }
        $deps = PluginDependencies::forSite();
        if ($theme === osc_theme()) {
            if ($deps->missingRequired($requires) === array()) {
                osc_add_flash_ok_message(_m('The plugins this theme needs are active.'), 'admin');
            }
            $this->redirectTo($back);
            return;
        }
        if ($deps->activateTheme($theme, $requires, Params::getParamInt('plugins_seen') === 1) === 'held') {
            $this->redirectTo($back . '&action=dependencies&theme=' . urlencode($theme));
            return;
        }
        osc_add_flash_ok_message(_m('Theme activated correctly'), 'admin');
        $this->redirectTo($back);
    }

    /**
     * The plugins a theme asks for, with one button that installs and turns them all on.
     */
    private function dependencies(): void
    {
        $theme    = Params::getParamString('theme');
        $requires = PluginDependencies::themeRequirements($theme);
        if ($requires === null) {
            osc_add_flash_error_message(_m('That theme is not installed.'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=appearance');
            return;
        }
        Catalog::forPlugins()->refreshIfDue();

        $info = WebThemes::getInstance()->loadThemeInfo($theme);
        $this->_exportVariableToView('theme', $theme);
        $this->_exportVariableToView('themeName', is_array($info) && $info['name'] !== '' ? ucfirst((string) $info['name']) : $theme);
        $this->_exportVariableToView('isActiveTheme', $theme === osc_theme());
        $this->_exportVariableToView('dependencies', PluginDependencies::forSite()->resolve($requires));
        $this->doView('appearance/dependencies.php');
    }

    /**
     * Render a theme's admin page file.
     */
    private function render(): void
    {
        if (Params::existParam('route')) {
            $routes = Rewrite::getInstance()->getRoutes();
            $rid    = Params::getParamString('route');
            $file   = '../';
            if (isset($routes[$rid]['file'])) {
                $file = $routes[$rid]['file'];
            }
        } else {
            // DEPRECATED: Disclosed path in URL is deprecated, use routes instead
            // This will be REMOVED in 3.6
            $file = Params::getParamString('file');
            // We pass the GET variables (in case we have somes)
            if (preg_match('|(.+?)\?(.*)|', $file, $match)) {
                $file = $match[1];
                if (preg_match_all('|&([^=]+)=([^&]*)|', urldecode('&' . $match[2] . '&'), $get_vars)) {
                    for ($var_k = 0; $var_k < count($get_vars[1]); $var_k++) {
                        Params::setParam($get_vars[1][$var_k], $get_vars[2][$var_k]);
                    }
                }
            } else {
                $file = Params::getParamString('file');
            }
        }

        // Only a .php file inside the themes or plugins folder may be rendered.
        $resolved = PluginAjaxFile::resolveWithin(
            (string) $file,
            osc_base_path(),
            array(osc_themes_path(), osc_plugins_path())
        );
        if ($resolved === null) {
            osc_add_flash_warning_message(__('Error loading theme custom file'), 'admin');
        }
        $this->_exportVariableToView('file', $resolved);
        $this->doView('appearance/view.php');
    }

    /**
     * The themes screen.
     */
    private function themes(): void
    {
        if (Params::getParam('checkUpdated') != '') {
            osc_admin_toolbar_update_themes(true);
        }

        $themes = WebThemes::getInstance()->getListThemes();

        //preparing variables for the view
        $this->_exportVariableToView('themes', $themes);

        list($aMarketBrowse, $aMarketUpdates, $aMarketMeta) = $this->buildMarketViewData();
        $this->_exportVariableToView('aMarketBrowse', $aMarketBrowse);
        $this->_exportVariableToView('aMarketUpdates', $aMarketUpdates);
        $this->_exportVariableToView('aMarketMeta', $aMarketMeta);

        $this->doView('appearance/index.php');
    }
    //hopefully generic...

    /**
     * Browse / Updates / Meta data sets the appearance view renders (docs/MARKET.md §8.2):
     * catalog themes not yet installed, installed themes with a pending update, and the
     * surrounding metadata (last check, write access, disabled state). Reads the cached
     * catalog only -- it never forces a live fetch on page render.
     *
     * @return array{0: array, 1: array, 2: array} [$browse, $updates, $meta]
     */
    private function buildMarketViewData()
    {
        $catalog      = \mindstellar\market\Catalog::forThemes();
        $packageIndex = \mindstellar\market\PackageIndex::forThemes();

        $index   = $catalog->index();
        $updates = $catalog->updates();

        $browse = array();
        foreach ($packageIndex->available() as $slug => $row) {
            $browse[] = array(
                'slug'              => $row['slug'],
                'name'              => $row['name'],
                'short_description' => $row['short_description'],
                'author'            => $row['author'],
                'version'           => $row['version'],
                'icon'              => $row['icon'],
                'categories'        => $row['categories'],
                'tags'              => $row['tags'],
                'updated_at'        => is_string($row['updated_at'] ?? null) ? $row['updated_at'] : '',
                'downloads'         => is_int($row['downloads'] ?? null) ? $row['downloads'] : 0,
                'compat'            => array(
                    'status'  => $row['compatibility']['status'],
                    'blocked' => $row['compatibility']['blocked'],
                    'reason'  => $row['compatibility']['reason'],
                    // One fact about this install, from the status already decided above:
                    // what it needs, how far it was tested, or the version it works with.
                    'badge'   => \mindstellar\market\Compatibility::verdictLabel(
                        $row['compatibility']['status'],
                        $row['offer'] !== array() ? $row['offer'] : array(
                            'requires'     => is_string($row['requires_min'] ?? null) ? $row['requires_min'] : '',
                            'tested_up_to' => is_string($row['tested_max'] ?? null) ? $row['tested_max'] : '',
                        )
                    ),
                ),
            );
        }

        $marketUpdates = array();
        foreach ($packageIndex->installed() as $slug => $row) {
            if ($row['update'] === null) {
                continue;
            }
            $update     = $row['update'];
            $compatInfo = array(
                'requires'     => $update['requires'] ?? '',
                'requires_php' => $update['requires_php'] ?? '',
                'tested_up_to' => $update['tested'] ?? '',
            );
            $verdict         = \mindstellar\market\Compatibility::evaluate($compatInfo);
            $marketUpdates[] = array(
                'slug'              => $row['slug'],
                'name'              => $row['name'],
                'installed_version' => $row['version'],
                'new_version'       => $update['version'],
                'size'              => $update['size'] ?? 0,
                'compat'            => array(
                    'status'  => $verdict['status'],
                    'blocked' => $verdict['blocked'],
                    'reason'  => $verdict['reason'],
                    // What this specific update asks of this install.
                    'badge'   => \mindstellar\market\Compatibility::verdictLabel(
                        $verdict['status'],
                        $compatInfo
                    ),
                ),
            );
        }

        $categories = array();
        foreach ($index as $row) {
            foreach ((array) ($row['categories'] ?? array()) as $category) {
                if (is_string($category) && $category !== '') {
                    $categories[$category] = true;
                }
            }
        }
        $categories = array_keys($categories);
        sort($categories);

        $meta = array(
            'last_checked'      => $catalog->lastChecked(),
            'error'             => $catalog->lastError(),
            'writable'          => is_writable(osc_themes_path()),
            'disabled'          => osc_market_changes_blocked(),
            'categories'        => $categories,
            'catalog_available' => $index !== array() || $updates !== array(),
        );

        // The toolbar count is saved once a day; recount when it disagrees with this list,
        // so the header drawn next shows the same number as the Updates tab.
        if ((int) osc_update_check_state('themes')['count'] !== count($marketUpdates)) {
            osc_admin_toolbar_update_themes(true);
        }

        return array($browse, $marketUpdates, $meta);
    }

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
        $pageBuilderId = Params::getParamInt('page_builder_id');
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
     * @param string|null $sType Posted s_type value.
     *
     * @return array<string,mixed>|null The registered type spec, or null for the legacy path.
     */
    private function resolveWidgetType($sType)
    {
        if ($sType === '' || $sType === null) {
            return null;
        }

        $type = \mindstellar\widgets\WidgetRegistry::getInstance()->get($sType);
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
     * The sanitised config for a typed widget from the posted config[] values.
     *
     * @param array<string,mixed> $type A registered widget-type spec.
     *
     * @return array<string,mixed> Sanitised config keyed by field name.
     */
    private function buildWidgetConfig($type)
    {
        $posted    = Params::getParam('config');
        $postedRaw = Params::getParam('config', false, false, false);

        return WidgetConfig::fromPosted($type, is_array($posted) ? $posted : array(), is_array($postedRaw) ? $postedRaw : array());
    }

}

/* file end: ./oc-admin/CAdminAppearance.php */
