<?php
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
 * Read every translation folder and return its locale descriptor, keyed by locale code.
 *
 * @return array<string,array<string,mixed>>
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
 * Insert or refresh a database row for every locale found on disk, importing its mail
 * templates the first time a locale is seen.
 *
 * @return bool False as soon as one locale row fails to insert, true otherwise
 * @see osc_importNewLocales() for the e-mails that kept the admin's changes
 */
function osc_checkLocales()
{
    return osc_importNewLocales() !== null;
}

/**
 * Same as osc_checkLocales(), returning the internal names of the e-mails that kept the admin's changes.
 *
 * @return array<int,string>|null null as soon as one locale row fails to insert
 */
function osc_importNewLocales(): ?array
{
    $kept    = array();
    $locales = osc_listLocales();

    foreach ($locales as $locale) {
        // if it's a demo, we don't import any data
        if (\mindstellar\security\Demo::active()) {
            return $kept;
        }

        $data = OSCLocale::getInstance()->findByPrimaryKey($locale['locale_code']);
        if (!is_array($data)) {
            if (!\mindstellar\language\LanguageService::make()->saveLocale($locale)) {
                return null;
            }

            // inserting e-mail translations
            $mailJsonPath = osc_translations_path() . $locale['locale_code'] . '/mail.json';
            if (file_exists($mailJsonPath)) {
                $mailJson = file_get_contents($mailJsonPath);
                if ($mailJson) {
                    $kept = array_merge($kept, Page::getInstance()->importEmailTemplates($mailJson)['kept'] ?? array());
                }
            }
        } else {
            OSCLocale::getInstance()->insertLocaleInfo($locale);
        }
    }

    return $kept;
}

/**
 * List the locale codes that have a folder under the translations path.
 *
 * @return array<int,string>
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
