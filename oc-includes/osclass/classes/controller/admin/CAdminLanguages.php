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
 * Class CAdminLanguages
 */
class CAdminLanguages extends AdminSecBaseModel
{
    //specific for this class
    private $localeManager;

    public function __construct()
    {
        parent::__construct();

        //specific things for this class
        $this->localeManager = OSCLocale::newInstance();
        osc_run_hook('init_admin_languages');
    }

    /**
     * Business Layer...
     *
     * @return bool
     */
    public function doModel()
    {
        switch ($this->action) {
            case ('add'):                // caliing add view
                $this->doView('languages/add.php');
                break;
            case ('add_post'):           // adding a new language
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m("This action can't be done because it's a demo site"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
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

                $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                break;
            case('import_locations'):
                $languageToImport = Params::getParam('language');
                if ($languageToImport != '') {
                    if (defined('DEMO')) {
                        osc_add_flash_warning_message(_m("This action can't be done because it's a demo site"), 'admin');
                        $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                    }

                    $url  = osc_get_i18n_repository_url();
                    $json = json_decode(osc_file_get_contents($url), true);

                    /* example json
                        [ {
                        "locale_code": "en_US",
                        "name": "English (US)",
                        "short_name": "English",
                        "description": "American english translation",
                        "direction": "ltr",
                        "version": "1.0.0",
                        "author_name": "navjottomer",
                        "author_url": "https://github.com/navjottomer",
                        "currency_format": "{NUMBER} {CURRENCY}",
                        "date_format": "m/d/Y",
                        "stop_words": "i,a,about,an,are,as,at,be,by,com,for,from,how,in,is,it,of,on,or,that,the,this,to,was,what,when,where,who,will,with,the",
                        "mail_json": "en_US/mail.json"
                        },  {
                            ... more locales
                        }]
                    */
                    foreach ($json as $l) {
                        if (isset($l['locale_code']) && $l['locale_code'] === $languageToImport) {
                            $importedLocale = $l;
                            break;
                        }
                    }
                    if (isset($importedLocale)) {
                        OSCLocale::newInstance()->insertLocaleInfo($importedLocale, $languageToImport);
                        // inserting e-mail translations get mail.json from github
                        $mailJSON =
                            osc_file_get_contents(osc_get_i18n_repository_url('src/translations/' . $languageToImport . '/mail.json'));
                        $this->importEmailJson($mailJSON);
                        // Get themes.po,themes.mo, core.po, core.mo, messages.po, messages.mo from github and save to local
                        $uploadDir = osc_translations_path() . $languageToImport;
                        $uploadDir .= '/';
                        // check if the folder exists and create it if not
                        if (!file_exists($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                            osc_add_flash_error_message(sprintf(_m('Directory "%s" was not created'), $uploadDir));
                            $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                        }
                        $poFiles = array(
                            'theme.po',
                            'core.po',
                            'messages.po'
                        );
                        $moFiles = array(
                            'theme.mo',
                            'core.mo',
                            'messages.mo'
                        );
                        foreach ($poFiles as $poFile) {
                            $poFileFrom = osc_get_i18n_repository_url('src/translations/' . $languageToImport . '/' . $poFile);
                            $poFileTo   = $uploadDir . $poFile;
                            $poFile     = osc_file_get_contents($poFileFrom);
                            if ($poFile) {
                                file_put_contents($poFileTo, $poFile);
                            }
                        }
                        foreach ($moFiles as $moFile) {
                            $moFileFrom = osc_get_i18n_repository_url('src/translations/' . $languageToImport . '/' . $moFile);
                            $moFileTo   = $uploadDir . $moFile;
                            $moFile     = osc_file_get_contents($moFileFrom);
                            if ($moFile) {
                                file_put_contents($moFileTo, $moFile);
                            }
                        }
                        osc_add_flash_ok_message(_m('Language imported successfully'), 'admin');
                        $this->redirectTo(osc_admin_base_url(true) . '?page=languages');

                        return true;
                    }
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

                $iUpdated = $this->localeManager->update($array, array('pk_c_code' => $languageCode));
                if ($iUpdated > 0) {
                    osc_add_flash_ok_message(sprintf(_m('%s has been updated'), $languageShortName), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                break;
            case ('enable_selected'):
                osc_csrf_check();
                $msg      = _m('Selected languages have been enabled for the website');
                $iUpdated = 0;
                $aValues  = array('b_enabled' => 1);

                $id = Params::getParam('id');

                if (!is_array($id)) {
                    osc_add_flash_warning_message(_m("The language ids aren't in the correct format"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                }

                foreach ($id as $i) {
                    osc_translate_categories($i);
                    $iUpdated += $this->localeManager->update($aValues, array('pk_c_code' => $i));
                }

                if ($iUpdated > 0) {
                    osc_add_flash_ok_message($msg, 'admin');
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                break;
            case ('disable_selected'):
                osc_csrf_check();
                $msg         = _m('Selected languages have been disabled for the website');
                $msg_warning = '';
                $iUpdated    = 0;
                $aValues     = array('b_enabled' => 0);

                $id = Params::getParam('id');

                if (!is_array($id)) {
                    osc_add_flash_warning_message(_m("The language ids aren't in the correct format"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                }

                foreach ($id as $i) {
                    if (osc_language() == $i) {
                        $msg_warning =
                            sprintf(_m("%s can't be disabled because it's the default language"), osc_language());
                        continue;
                    }
                    $iUpdated += $this->localeManager->update($aValues, array('pk_c_code' => $i));
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
                $aValues  = array('b_enabled_bo' => 1);

                $id = Params::getParam('id');

                if (!is_array($id)) {
                    osc_add_flash_warning_message(_m("The language ids aren't in the correct format"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                }

                foreach ($id as $i) {
                    osc_translate_categories($i);
                    $iUpdated += $this->localeManager->update($aValues, array('pk_c_code' => $i));
                }

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
                $aValues     = array('b_enabled_bo' => 0);

                $id = Params::getParam('id');

                if (!is_array($id)) {
                    osc_add_flash_warning_message(_m("The language ids aren't in the correct format"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=languages');
                }

                foreach ($id as $i) {
                    if (osc_language() == $i) {
                        $msg_warning =
                            sprintf(_m("%s can't be disabled because it's the default language"), osc_language());
                        continue;
                    }
                    $iUpdated += $this->localeManager->update($aValues, array('pk_c_code' => $i));
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
            case ('delete'):
                osc_csrf_check();
                if (is_array(Params::getParam('id'))) {
                    $default_lang = osc_language();
                    foreach (Params::getParam('id') as $code) {
                        $isDefaultLanguage = ($default_lang === $code);
                        $isCurrentLanguage = (osc_current_admin_locale() === $code);
                        if ($isDefaultLanguage || $isCurrentLanguage) {
                            if ($isCurrentLanguage) {
                                osc_add_flash_warning_message(_m('The current language can\'t be deleted. Please logout and login again with another language.'),
                                                              'admin');
                            } else {
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
                            }
                        } elseif ($this->localeManager->deleteLocale($code)) {
                            if (!osc_deleteDir(osc_translations_path() . $code)) {
                                osc_add_flash_error_message(sprintf(
                                                                _m("Directory '%s' couldn't be removed"),
                                                                $code
                                                            ), 'admin');
                            } else {
                                osc_add_flash_ok_message(
                                    sprintf(_m('Directory "%s" has been successfully removed'), $code),
                                    'admin'
                                );
                            }
                        } else {
                            osc_add_flash_error_message(
                                sprintf(_m("Directory '%s' couldn't be removed;)"), $code),
                                'admin'
                            );
                        }
                    }
                }
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
                if (Params::getParam('iDisplayLength') == '') {
                    Params::setParam('iDisplayLength', 10);
                }
                // ?
                $this->_exportVariableToView('iDisplayLength', Params::getParam('iDisplayLength'));

                $p_iPage = 1;
                if (is_numeric(Params::getParam('iPage')) && Params::getParam('iPage') >= 1) {
                    $p_iPage = Params::getParam('iPage');
                }
                Params::setParam('iPage', $p_iPage);

                $aLanguages = OSCLocale::newInstance()->listAll();

                // pagination
                $start = ($p_iPage - 1) * Params::getParam('iDisplayLength');
                $limit = Params::getParam('iDisplayLength');
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

                    $sUpdate = '';
                    // get languages to update from t_preference
                    if ($bLanguagesToUpdate && in_array($l['pk_c_code'], $aLanguagesToUpdate)) {
                        $sUpdate =
                            '<a class="btn-market-update btn-market-popup" href="#' . htmlentities($l['pk_c_code'])
                            . '">' . __(
                                'Update here'
                            ) . '</a>';
                    }

                    $row[] = $l['s_name'] . $sUpdate . $actions;
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

                $page = (int)Params::getParam('iPage');
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

                $this->_exportVariableToView('aLanguages', $array);

                $bulk_options = array(
                    array('value' => '', 'data-dialog-content' => '', 'label' => __('Bulk actions')),
                    array(
                        'value'               => 'enable_selected',
                        'data-dialog-content' => sprintf(
                            __('Are you sure you want to %s the selected languages?'),
                            strtolower(__('Enable (Website)'))
                        ),
                        'label'               => __('Enable (Website)')
                    ),
                    array(
                        'value'               => 'disable_selected',
                        'data-dialog-content' => sprintf(
                            __('Are you sure you want to %s the selected languages?'),
                            strtolower(__('Disable (Website)'))
                        ),
                        'label'               => __('Disable (Website)')
                    ),
                    array(
                        'value'               => 'enable_bo_selected',
                        'data-dialog-content' => sprintf(
                            __('Are you sure you want to %s the selected languages?'),
                            strtolower(__('Enable (oc-admin)'))
                        ),
                        'label'               => __('Enable (oc-admin)')
                    ),
                    array(
                        'value'               => 'disable_bo_selected',
                        'data-dialog-content' => sprintf(
                            __('Are you sure you want to %s the selected languages?'),
                            strtolower(__('Disable (oc-admin)'))
                        ),
                        'label'               => __('Disable (oc-admin)')
                    ),
                    array(
                        'value'               => 'delete',
                        'data-dialog-content' => sprintf(
                            __('Are you sure you want to %s the selected languages?'),
                            strtolower(__('Delete'))
                        ),
                        'label'               => __('Delete')
                    )
                );
                $bulk_options = osc_apply_filter('language_bulk_filter', $bulk_options);
                $this->_exportVariableToView('bulk_options', $bulk_options);

                $this->doView('languages/index.php');
                break;
        }
    }

    /**
     * @param $mailJSON
     */
    private function importEmailJson($mailJSON)
    {
        if ($mailJSON) {
            $mailImported = Page::newInstance()->importEmailJsonTemplates($mailJSON);
            if (!$mailImported) {
                osc_add_flash_error_message(_m('There was a problem importing email templates'), 'admin');
            }
        }
    }
}

/* file end: ./oc-admin/CAdminLanguages.php */
