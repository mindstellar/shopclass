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

/**
 * @return array
 */
function osc_listLocales()
{
    $languages = array();

    $codes = osc_listLanguageCodes();
    foreach ($codes as $code) {
        if (file_exists(osc_translations_path().$code.'/locale.json')) {
            $aInfo = json_decode(file_get_contents(osc_translations_path().$code.'/locale.json'), true);
            $languages[$code] = $aInfo;
            unset($aInfo);
        } else {
            $path   = osc_translations_path() . $code . '/index.php';
            $fxName = "locale_{$code}_info";
            if (file_exists($path)) {
                require_once $path;
                if (function_exists($fxName)) {
                    $languages[$code]                = $fxName();
                    $languages[$code]['locale_code'] = $code;
                }
            }
        }
    }

    return $languages;
}

/**
 * @return bool
 */
function osc_checkLocales()
{
    $locales = osc_listLocales();

    foreach ($locales as $locale) {
        // if it's a demo, we don't import any data
        if (defined('DEMO')) {
            return true;
        }

        $data = OSCLocale::newInstance()->findByPrimaryKey($locale['locale_code']);
        if (!is_array($data)) {
            $result = OSCLocale::newInstance()->insertLocaleInfo($locale);

            if ($result === false) {
                return false;
            }

            // if it's a demo, we don't import any sql
            if (defined('DEMO')) {
                return true;
            }

            // inserting e-mail translations
            if (file_exists(osc_translations_path() . $locale['locale_code'] . '/mail.json' )) {
                $mailJson = file_get_contents(osc_translations_path() . $locale['locale_code'] . '/mail.json' );
                if ($mailJson) {
                    Page::newInstance()->importEmailJsonTemplates($mailJson);
                }
            } else {
                // old templates
                $path = osc_translations_path() . $locale['locale_code'] . '/mail.sql';
                if (file_exists($path)) {
                    $sql    = file_get_contents($path);
                    $conn   = DBConnectionClass::newInstance();
                    $c_db   = $conn->getOsclassDb();
                    $comm   = new DBCommandClass($c_db);
                    $result = $comm->importSQL($sql);
                    if (!$result) {
                        return false;
                    }
                }
            }
        } else {
            OSCLocale::newInstance()->insertLocaleInfo($locale);
        }
    }

    return true;
}


/**
 * @return array
 */
function osc_listLanguageCodes()
{
    $codes = array();

    $dir = opendir(osc_translations_path());
    while ($file = readdir($dir)) {
        if (preg_match('/^[a-z_]+$/i', $file)) {
            $codes[] = $file;
        }
    }
    closedir($dir);

    return $codes;
}
