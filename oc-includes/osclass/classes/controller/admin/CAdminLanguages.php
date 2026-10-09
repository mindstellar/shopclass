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
 * Class CAdminLanguages
 */
use mindstellar\admin\BulkAction;
use mindstellar\admin\ListPaging;
use mindstellar\language\LanguageService;
use mindstellar\language\LocaleStore;
use mindstellar\validation\ConflictException;

class CAdminLanguages extends AdminSecBaseModel
{
    private OSCLocale $localeManager;

    private LanguageService $languages;

    /**
     * Take the locale manager and the language service for this request.
     */
    public function __construct()
    {
        parent::__construct();

        $this->localeManager = OSCLocale::getInstance();
        $this->languages     = LanguageService::make();
        osc_run_hook('init_admin_languages');
    }

    /**
     * Business Layer...
     *
     * Dispatch the requested languages action: add, import from the translation
     * repository, edit, enable/disable, delete, otherwise the list.
     *
     * @return true|null true once an import has finished
     */
    public function doModel()
    {
        switch ($this->action) {
            case ('add'):                // caliing add view
                $this->doView('languages/add.php');
                break;
            case ('add_post'):           // adding a new language
                if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=languages')) {
                    break;
                }
                osc_csrf_check();
                $filePackage = Params::getFiles('package');

                if (isset($filePackage['size']) && $filePackage['size'] !== 0) {
                    $path   = osc_translations_path();
                    $status = osc_unzip_file($filePackage['tmp_name'], $path);
                    @unlink($filePackage['tmp_name']);
                } else {
                    $status = 3;
                }

                switch ($status) {
                    case (0):
                        $msg = _m('The translation folder is not writable');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (1):
                        if (osc_checkLocales()) {
                            $msg = _m('The language has been installed correctly');
                            osc_add_flash_ok_message($msg, 'admin');
                        } else {
                            $msg = _m('File uploaded but unable to activate the language');
                            osc_add_flash_error_message($msg, 'admin');
                        }
                        break;
                    case (2):
                        $msg = _m('The zip file is not valid');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (3):
                        $msg = _m('No file was uploaded');
                        osc_add_flash_warning_message($msg, 'admin');
                        $this->redirectTo(osc_admin_base_url(true) . '?page=languages&action=add');
                        break;
                    case (-1):
                    default:
                        $msg = _m('There was a problem adding the language');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                }

                osc_invalidate_locale_cache();
                $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                break;
            case ('import_locations'):
                osc_csrf_check();
                $languageToImport = Params::getParam('language');
                if ($languageToImport != '') {
                    if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=languages')) {
                        break;
                    }

                    $published = LanguageService::published();
                    // Without this the button looked broken wherever the server cannot reach
                    // the translation repository: the page just came back unchanged.
                    if ($published === null) {
                        osc_add_flash_error_message(
                            sprintf(
                                _m('Could not read the list of translations at %s. This server has to be able to reach it.'),
                                osc_get_i18n_repository_url()
                            ),
                            'admin'
                        );
                        $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                    }

                    if (isset($published[$languageToImport])) {
                        $mailJSON =
                            osc_file_get_contents(osc_get_i18n_repository_url('src/translations/' . $languageToImport . '/mail.json'));
                        if (!$this->languages->install($published[$languageToImport], $languageToImport, $mailJSON)) {
                            osc_add_flash_error_message(_m('There was a problem importing email templates'), 'admin');
                        }
                        $failed = LanguageService::downloadFiles($languageToImport);
                        if ($failed === null) {
                            osc_add_flash_error_message(sprintf(_m('Directory "%s" was not created'), osc_translations_path() . $languageToImport . '/'), 'admin');
                            $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                        }
                        // Clear this code from the pending-update list so the row's
                        // "Update" action disappears until the next version check.
                        $pending = json_decode(osc_get_preference('languages_to_update'), true);
                        if (is_array($pending) && ($k = array_search($languageToImport, $pending, true)) !== false) {
                            unset($pending[$k]);
                            osc_set_preference('languages_to_update', json_encode(array_values($pending)));
                            osc_set_preference('languages_update_count', count($pending));
                            osc_reset_preferences();
                        }
                        osc_invalidate_locale_cache();
                        if ($failed > 0) {
                            osc_add_flash_warning_message(
                                sprintf(_m('Language imported, but %d file(s) could not be downloaded.'), $failed),
                                'admin'
                            );
                        } else {
                            osc_add_flash_ok_message(_m('Language imported successfully'), 'admin');
                        }
                        $this->redirectTo(osc_admin_base_url(true) . '?page=languages');

                        return true;
                    }

                    osc_add_flash_error_message(
                        sprintf(_m('No published translation was found for %s.'), $languageToImport),
                        'admin'
                    );
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                break;
            case ('edit'):               // editing a language
                $sLocale = Params::getParam('id');
                if (!preg_match('/.{2}_.{2}/', $sLocale)) {
                    osc_add_flash_error_message(_m('Language id isn\'t in the correct format'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                }

                $aLocale = $this->localeManager->findByPrimaryKey($sLocale);

                if (count($aLocale) == 0) {
                    osc_add_flash_error_message(_m('Language id doesn\'t exist'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                }

                $this->_exportVariableToView('aLocale', $aLocale);
                $this->doView('languages/frm.php');
                break;
            case ('edit_post'):          // edit language post
                osc_csrf_check();
                $iUpdated               = 0;
                $languageCode           = Params::getParam('pk_c_code');
                $enabledWebstie         = Params::getParam('b_enabled');
                $enabledBackoffice      = Params::getParam('b_enabled_bo');
                $languageDirection      = Params::getParam('s_direction');
                $languageName           = Params::getParam('s_name');
                $languageShortName      = Params::getParam('s_short_name');
                $languageDescription    = Params::getParam('s_description');
                $languageCurrencyFormat = Params::getParam('s_currency_format');
                $languageDecPoint       = Params::getParam('s_dec_point');
                $languageNumDec         = Params::getParam('i_num_dec');
                $languageThousandsSep   = Params::getParam('s_thousands_sep');
                $languageDateFormat     = Params::getParam('s_date_format');
                $languageStopWords      = Params::getParam('s_stop_words');

                // formatting variables
                if (!preg_match('/.{2}_.{2}/', $languageCode)) {
                    osc_add_flash_error_message(_m('Language id isn\'t in the correct format'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                }
                $enabledWebstie    = ($enabledWebstie != '');
                $enabledBackoffice = ($enabledBackoffice != '');
                $languageName      = strip_tags($languageName);
                $languageName      = trim($languageName);

                $languageShortName = strip_tags($languageShortName);
                $languageShortName = trim($languageShortName);

                $languageDescription = strip_tags($languageDescription);
                $languageDescription = trim($languageDescription);

                $languageCurrencyFormat = strip_tags($languageCurrencyFormat);
                $languageCurrencyFormat = trim($languageCurrencyFormat);
                $languageDateFormat     = strip_tags($languageDateFormat);
                $languageDateFormat     = trim($languageDateFormat);
                $languageStopWords      = strip_tags($languageStopWords);
                $languageStopWords      = trim($languageStopWords);

                $msg = '';
                if (!osc_validate_text($languageName)) {
                    $msg .= _m('Language name field is required') . '<br/>';
                }
                if ($languageDirection !== 'ltr' && $languageDirection !== 'rtl') {
                    $msg .= _m('Language direction field is required') . '<br/>';
                }
                if (!osc_validate_text($languageShortName)) {
                    $msg .= _m('Language short name field is required') . '<br/>';
                }
                if (!osc_validate_text($languageDescription)) {
                    $msg .= _m('Language description field is required') . '<br/>';
                }
                if (!osc_validate_text($languageCurrencyFormat)) {
                    $msg .= _m('Currency format field is required') . '<br/>';
                }
                if (!osc_validate_int($languageNumDec)) {
                    $msg .= _m('Number of decimals must only contain numeric characters') . '<br/>';
                }
                if ($msg != '') {
                    osc_add_flash_error_message($msg, 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=languages&action=edit&id=' . $languageCode);
                }

                $array = array(
                    'b_enabled'         => $enabledWebstie,
                    'b_enabled_bo'      => $enabledBackoffice,
                    's_name'            => $languageName,
                    's_direction'       => $languageDirection,
                    's_short_name'      => $languageShortName,
                    's_description'     => $languageDescription,
                    's_currency_format' => $languageCurrencyFormat,
                    's_dec_point'       => $languageDecPoint,
                    'i_num_dec'         => $languageNumDec,
                    's_thousands_sep'   => $languageThousandsSep,
                    's_date_format'     => $languageDateFormat,
                    's_stop_words'      => $languageStopWords
                );

                $iUpdated = LocaleStore::update($languageCode, $array);
                osc_invalidate_locale_cache();
                if ($iUpdated > 0) {
                    osc_purge_page_cache('language');
                    osc_add_flash_ok_message(sprintf(_m('%s has been updated'), $languageShortName), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                break;
            case ('enable_selected'):
                osc_csrf_check();
                $msg      = _m('Selected languages have been enabled for the website');
                $iUpdated = 0;

                $id = Params::getParam('id');

                if (!is_array($id)) {
                    osc_add_flash_warning_message(_m("The language ids aren't in the correct format"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                }

                foreach ($id as $i) {
                    $iUpdated += $this->languages->enable((string) $i);
                }
                osc_invalidate_locale_cache();

                if ($iUpdated > 0) {
                    osc_purge_page_cache('language');
                    osc_add_flash_ok_message($msg, 'admin');
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                break;
            case ('disable_selected'):
                osc_csrf_check();
                $msg         = _m('Selected languages have been disabled for the website');
                $msg_warning = '';
                $iUpdated    = 0;

                $id = Params::getParam('id');

                if (!is_array($id)) {
                    osc_add_flash_warning_message(_m("The language ids aren't in the correct format"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                }

                foreach ($id as $i) {
                    try {
                        $iUpdated += $this->languages->disable((string) $i);
                    } catch (ConflictException $e) {
                        $msg_warning = $e->getMessage();
                    }
                }
                osc_invalidate_locale_cache();
                if ($iUpdated > 0) {
                    osc_purge_page_cache('language');
                }

                if ($msg_warning != '') {
                    if ($iUpdated > 0) {
                        osc_add_flash_warning_message($msg . '</p><p>' . $msg_warning, 'admin');
                    } else {
                        osc_add_flash_warning_message($msg_warning, 'admin');
                    }
                } else {
                    osc_add_flash_ok_message($msg, 'admin');
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                break;
            case ('enable_bo_selected'):
                osc_csrf_check();
                $msg      = _m('Selected languages have been enabled for the backoffice (oc-admin)');
                $iUpdated = 0;

                $id = Params::getParam('id');

                if (!is_array($id)) {
                    osc_add_flash_warning_message(_m("The language ids aren't in the correct format"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                }

                foreach ($id as $i) {
                    $iUpdated += $this->languages->enable((string) $i, true);
                }
                osc_invalidate_locale_cache();

                if ($iUpdated > 0) {
                    osc_add_flash_ok_message($msg, 'admin');
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                break;
            case ('disable_bo_selected'):
                osc_csrf_check();
                $msg         = _m('Selected languages have been disabled for the backoffice (oc-admin)');
                $msg_warning = '';
                $iUpdated    = 0;

                $id = Params::getParam('id');

                if (!is_array($id)) {
                    osc_add_flash_warning_message(_m("The language ids aren't in the correct format"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                }

                foreach ($id as $i) {
                    try {
                        $iUpdated += $this->languages->disable((string) $i, true);
                    } catch (ConflictException $e) {
                        $msg_warning = $e->getMessage();
                    }
                }
                osc_invalidate_locale_cache();

                if ($msg_warning != '') {
                    if ($iUpdated > 0) {
                        osc_add_flash_warning_message($msg . '</p><p>' . $msg_warning, 'admin');
                    } else {
                        osc_add_flash_warning_message($msg_warning, 'admin');
                    }
                } else {
                    osc_add_flash_ok_message($msg, 'admin');
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                break;
            case ('delete'):
                osc_csrf_check();
                if (is_array(Params::getParam('id'))) {
                    $adminLocale = (string) osc_current_admin_locale();
                    foreach (Params::getParam('id') as $code) {
                        $code = (string) $code;
                        switch ($this->languages->delete($code, $adminLocale)) {
                            case LanguageService::IS_CURRENT:
                                osc_add_flash_warning_message(
                                    _m('The current language can\'t be deleted. Please logout and login again with another language.'),
                                    'admin'
                                );
                                break;
                            case LanguageService::IS_DEFAULT:
                                osc_add_flash_error_message(
                                    sprintf(
                                        _m(
                                            "Directory '%s' couldn't be removed because it's the default language. <a href=\"%s\">Set another language</a> as default first and try again"
                                        ),
                                        $code,
                                        osc_admin_base_url(true) . '?page=settings'
                                    ),
                                    'admin'
                                );
                                break;
                            case LanguageService::DIR_KEPT:
                                osc_add_flash_error_message(sprintf(
                                    _m("Directory '%s' couldn't be removed"),
                                    $code
                                ), 'admin');
                                break;
                            case LanguageService::DELETED:
                                osc_add_flash_ok_message(
                                    sprintf(_m('Directory "%s" has been successfully removed'), $code),
                                    'admin'
                                );
                                break;
                            default:
                                osc_add_flash_error_message(
                                    sprintf(_m("Directory '%s' couldn't be removed;)"), $code),
                                    'admin'
                                );
                        }
                    }
                }
                osc_invalidate_locale_cache();
                $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                break;
            default:
                if (Params::getParam('checkUpdated') != '') {
                    osc_admin_toolbar_update_languages(true);
                }

                if (Params::getParam('action') != '') {
                    osc_run_hook('language_bulk_' . Params::getParam('action'), Params::getParam('id'));
                }

                // -----
                $limit = ListPaging::length();
                Params::setParam('iDisplayLength', $limit);
                $this->_exportVariableToView('iDisplayLength', $limit);

                $p_iPage = ListPaging::page();

                $aLanguages = OSCLocale::getInstance()->listAll();

                // pagination
                $start = ListPaging::start($p_iPage, $limit);
                $count = count($aLanguages);

                $displayRecords = $limit;
                if (($start + $limit) > $count) {
                    $displayRecords = ($start + $limit) - $count;
                }
                // ----
                $aLanguagesToUpdate = json_decode(osc_get_preference('languages_to_update'), true);
                $bLanguagesToUpdate = is_array($aLanguagesToUpdate);
                // ----
                $aData = array();
                $max   = ($start + $limit);
                if ($max > $count) {
                    $max = $count;
                }
                for ($i = $start; $i < $max; $i++) {
                    $l     = $aLanguages[$i];
                    $row   = array();
                    $row[] = '<input type="checkbox" name="id[]" value="' . $l['pk_c_code'] . '" />';

                    $options   = array();
                    if ($bLanguagesToUpdate && in_array($l['pk_c_code'], $aLanguagesToUpdate)) {
                        $options[] = '<a class="strong" href="' . osc_admin_base_url(true)
                                     . '?page=languages&amp;action=import_locations&amp;language=' . $l['pk_c_code']
                                     . '&amp;' . osc_csrf_token_url()
                                     . '">' . __('Update') . '</a>';
                    }
                    $options[] = '<a href="' . osc_admin_base_url(true) . '?page=languages&amp;action=edit&amp;id='
                                 . $l['pk_c_code']
                                 . '">' . __('Edit') . '</a>';
                    $options[] =
                        '<a href="' . osc_admin_base_url(true) . '?page=languages&amp;action=' . ($l['b_enabled'] == 1
                            ? 'disable_selected' : 'enable_selected') . '&amp;id[]=' . $l['pk_c_code'] . '&amp;'
                        . osc_csrf_token_url()
                        . '">' . ($l['b_enabled'] == 1 ? __('Disable (website)') : __('Enable (website)')) . '</a> ';
                    $options[] =
                        '<a href="' . osc_admin_base_url(true) . '?page=languages&amp;action=' . ($l['b_enabled_bo']
                                                                                                  == 1
                            ? 'disable_bo_selected' : 'enable_bo_selected') . '&amp;id[]=' . $l['pk_c_code'] . '&amp;'
                        . osc_csrf_token_url() . '">' . ($l['b_enabled_bo'] == 1 ? __('Disable (oc-admin)')
                            : __('Enable (oc-admin)'))
                        . '</a>';
                    $options[] = '<a onclick="return delete_dialog(\'' . $l['pk_c_code'] . '\');"  href="'
                                 . osc_admin_base_url(true)
                                 . '?page=languages&amp;action=delete&amp;id[]=' . $l['pk_c_code'] . '&amp;'
                                 . osc_csrf_token_url() . '">' . __(
                                     'Delete'
                                 ) . '</a>';

                    $auxOptions = '<ul>' . PHP_EOL;
                    foreach ($options as $actual) {
                        $auxOptions .= '<li>' . $actual . '</li>' . PHP_EOL;
                    }
                    $actions = '<div class="actions">' . $auxOptions . '</div>' . PHP_EOL;

                    $row[] = $l['s_name'] . $actions;
                    $row[] = $l['s_short_name'];
                    $row[] = $l['s_description'];
                    $row[] = ($l['b_enabled'] ? __('Yes') : __('No'));
                    $row[] = ($l['b_enabled_bo'] ? __('Yes') : __('No'));

                    $aData[] = $row;
                }
                // ----
                $array['iTotalRecords']        = $displayRecords;
                $array['iTotalDisplayRecords'] = count($aLanguages);
                $array['iDisplayLength']       = $limit;
                $array['aaData']               = $aData;

                $pastEnd = ListPaging::pastEnd($array, $p_iPage);
                if ($pastEnd !== null) {
                    $this->redirectTo($pastEnd);
                }

                $this->_exportVariableToView('aLanguages', $array);

                $bulk_options = BulkAction::options(
                    array(
                        'enable_selected' => __('Enable (Website)'),
                        'disable_selected' => __('Disable (Website)'),
                        'enable_bo_selected' => __('Enable (oc-admin)'),
                        'disable_bo_selected' => __('Disable (oc-admin)'),
                        'delete' => __('Delete')
                    ),
                    __('Are you sure you want to %s the selected languages?')
                );
                $bulk_options = osc_apply_filter('language_bulk_filter', $bulk_options);
                $this->_exportVariableToView('bulk_options', $bulk_options);

                $this->doView('languages/index.php');
                break;
        }

        return null;
    }
}

/* file end: ./oc-admin/CAdminLanguages.php */
