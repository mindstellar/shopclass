<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\language;

use mindstellar\exception\ConflictException;
use OSCLocale;
use Page;

/**
 * Language writes for the languages screen: edit, enable or disable for the website or
 * oc-admin, delete, and install from a translation manifest.
 */
final class LanguageService
{
    /** delete() outcomes. */
    public const DELETED    = 'deleted';
    public const DIR_KEPT   = 'dir_kept';
    public const FAILED     = 'failed';
    public const IS_DEFAULT = 'default';
    public const IS_CURRENT = 'current';

    /** Translation files a language is installed with, fetched from the i18n repository. */
    public const FILES = array('locale.json', 'mail.json', 'theme.po', 'core.po', 'messages.po', 'theme.mo', 'core.mo', 'messages.mo');

    public function __construct(private OSCLocale $locales, private Page $pages)
    {
    }

    public static function make(): self
    {
        return new self(OSCLocale::getInstance(), Page::getInstance());
    }

    /**
     * The languages the i18n repository publishes, keyed by locale code. Static, as the
     * installer calls it before there is a database.
     *
     * @param (callable(string): (string|false))|null $fetch reads a URL; osc_file_get_contents() when null
     *
     * @return array<string,array<string,mixed>>|null null when the list could not be read
     */
    public static function published(?callable $fetch = null): ?array
    {
        $list = json_decode((string) ($fetch ?? 'osc_file_get_contents')(osc_get_i18n_repository_url()), true);
        if (!is_array($list)) {
            return null;
        }
        $published = array();
        foreach ($list as $entry) {
            if (is_array($entry) && isset($entry['locale_code'])) {
                $published[(string) $entry['locale_code']] = $entry;
            }
        }

        return $published;
    }

    /**
     * The published languages in alphabetical order of their display name, locale-aware when
     * the intl extension is there.
     *
     * @param array<string,array<string,mixed>> $published published()'s answer
     *
     * @return array<int,array<string,mixed>>
     */
    public static function sortedByName(array $published): array
    {
        $collator = class_exists(\Collator::class) ? new \Collator('root') : null;
        $list     = array_values($published);
        usort($list, static function (array $a, array $b) use ($collator): int {
            $left  = (string) ($a['name'] ?? $a['locale_code'] ?? '');
            $right = (string) ($b['name'] ?? $b['locale_code'] ?? '');

            return $collator !== null ? (int) $collator->compare($left, $right) : strnatcasecmp($left, $right);
        });

        return $list;
    }

    /**
     * Download a language's translation files into its folder. Static, as the installer
     * calls it before there is a database.
     *
     * @param (callable(string): (string|false))|null $fetch reads a URL; osc_file_get_contents() when null
     *
     * @return int|null files that could not be downloaded, or null when the code is not a locale code
     *                  or the folder could not be made
     */
    public static function downloadFiles(string $code, ?callable $fetch = null): ?int
    {
        // The code names a folder, so only a locale code such as "en" or "en_US" is taken.
        if (!preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/D', $code)) {
            return null;
        }
        $fetch ??= 'osc_file_get_contents';
        $dir     = osc_translations_path() . $code . '/';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        $failed = 0;
        foreach (self::FILES as $file) {
            $body = $fetch(osc_get_i18n_repository_url('src/translations/' . $code . '/' . $file));
            if ($body && file_put_contents($dir . $file, $body) !== false) {
                continue;
            }
            $failed++;
        }

        return $failed;
    }

    /**
     * Install a language from the translation repository: its manifest entry and e-mail
     * templates, then its files. A language installed this way leaves the pending-update list.
     *
     * @param array<string,mixed>                     $manifest its entry in published()
     * @param (callable(string): (string|false))|null $fetch    reads a URL; osc_file_get_contents() when null
     *
     * @return array{saved:bool,mail:bool,failed:?int,kept:array<int,string>} whether the language's row was
     *         saved (nothing else is done when it was not), whether the e-mail templates were imported,
     *         downloadFiles()'s answer, and the e-mails that kept the admin's changes
     */
    public function importPublished(string $code, array $manifest, ?callable $fetch = null): array
    {
        if (!$this->saveLocale($manifest, $code)) {
            return array('saved' => false, 'mail' => false, 'failed' => 0, 'kept' => array());
        }
        $fetch ??= 'osc_file_get_contents';
        $mails   = $this->importMails($fetch(osc_get_i18n_repository_url('src/translations/' . $code . '/mail.json')));
        $failed  = self::downloadFiles($code, $fetch);
        if ($failed !== null) {
            $pending = osc_update_check_state('languages');
            if (($k = array_search($code, $pending['to_update'], true)) !== false) {
                unset($pending['to_update'][$k]);
                $pending['to_update'] = array_values($pending['to_update']);
                $pending['count']     = count($pending['to_update']);
                osc_update_check_save('languages', $pending);
            }
        }

        return array('saved' => true, 'mail' => $mails !== null, 'failed' => $failed, 'kept' => $mails['kept'] ?? array());
    }

    /**
     * Whether every language folder in a package zip is installed already; false for a zip
     * with none.
     */
    public function packageInstalled(string $zipFile): bool
    {
        $codes = array();
        $zip   = class_exists(\ZipArchive::class) ? new \ZipArchive() : null;
        if ($zip !== null && $zip->open($zipFile) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $top = strstr((string) $zip->getNameIndex($i), '/', true);
                if (is_string($top) && preg_match('/^[a-z]{2,3}_[A-Za-z]{2,4}$/', $top) === 1) {
                    $codes[$top] = true;
                }
            }
            $zip->close();
        }
        foreach (array_keys($codes) as $code) {
            if (!is_array($this->locales->findByPrimaryKey((string) $code))) {
                return false;
            }
        }

        return $codes !== array();
    }

    /**
     * The language the admin login offers: the admin default when it is on for oc-admin, else the
     * first language on for oc-admin.
     */
    public static function adminLoginLocale(): string
    {
        $enabled = array_column(osc_all_enabled_locales_for_admin(), 'pk_c_code');
        $default = (string) osc_admin_language();

        return in_array($default, $enabled, true) ? $default : (string) ($enabled[0] ?? $default);
    }

    /**
     * Turn a language on for the website, or for oc-admin. Its category names are filled in first.
     *
     * @return int rows changed
     */
    public function enable(string $code, bool $admin = false): int
    {
        osc_translate_categories($code);

        return LocaleStore::update($code, array($admin ? 'b_enabled_bo' : 'b_enabled' => 1));
    }

    /**
     * Make a language the default for the website, or for oc-admin. It must be on there.
     *
     * @throws ConflictException for a language that is missing or off where it would be the default
     */
    public function makeDefault(string $code, bool $admin = false): void
    {
        $locale = $this->locales->findByCode($code)[0] ?? null;
        if ($locale === null || (int) $locale[$admin ? 'b_enabled_bo' : 'b_enabled'] !== 1) {
            throw new ConflictException($admin
                ? _m('Turn the language on for oc-admin before you make it the admin default')
                : _m('Turn the language on for the website before you make it the default'));
        }
        osc_set_preference($admin ? 'admin_language' : 'language', $code);
    }

    /**
     * Turn a language off for the website, or for oc-admin.
     *
     * @return int rows changed
     * @throws ConflictException for the default language of the website or of oc-admin
     */
    public function disable(string $code, bool $admin = false): int
    {
        $default = $admin ? osc_admin_language() : osc_language();
        if ($default == $code) {
            throw new ConflictException(sprintf($admin
                ? _m("%s can't be disabled for oc-admin because it's the admin default language")
                : _m("%s can't be disabled because it's the default language"), $code));
        }

        return LocaleStore::update($code, array($admin ? 'b_enabled_bo' : 'b_enabled' => 0));
    }

    /**
     * Delete a language's rows and its translation folder. The language this admin is using
     * and the default language of the website or of oc-admin are refused.
     *
     * @return string one of the outcome constants
     */
    public function delete(string $code, string $adminLocale): string
    {
        if ($code === $adminLocale) {
            return self::IS_CURRENT;
        }
        if ($code === osc_language() || $code === osc_admin_language()) {
            return self::IS_DEFAULT;
        }
        if (!$this->locales->deleteLocale($code)) {
            return self::FAILED;
        }
        osc_purge_page_cache('language');

        return osc_deleteDir(osc_translations_path() . $code) ? self::DELETED : self::DIR_KEPT;
    }

    /**
     * Add or refresh a language from its manifest entry, with its e-mail templates.
     *
     * @param array<string,mixed> $manifest one entry of the translation repository's list
     * @param string|false        $mailJson the language's mail.json, or false when it was not fetched
     *
     * @return array{written:array<int,string>,kept:array<int,string>}|null the e-mails written and the
     *         ones that kept the admin's changes; null when the language's row could not be saved or its
     *         e-mail templates could not be imported
     */
    public function install(array $manifest, string $code, $mailJson): ?array
    {
        return $this->saveLocale($manifest, $code) ? $this->importMails($mailJson) : null;
    }

    /**
     * Add or refresh a language's t_locale row. A failed save goes to the activity log with the
     * database error, as the admin is only told that it failed.
     *
     * @param array<string,mixed> $manifest the language's locale.json, or its entry in published()
     */
    public function saveLocale(array $manifest, string $code = ''): bool
    {
        if ($this->locales->insertLocaleInfo($manifest, $code) !== false) {
            return true;
        }
        $code = $code !== '' ? $code : (string) ($manifest['locale_code'] ?? '');
        \Log::getInstance()->insertLog('language', 'save_failed', 0, $code . ': ' . $this->locales->getErrorDesc(), 'admin', 0);

        return false;
    }

    /**
     * @param string|false $mailJson the language's mail.json, or false when there is none
     *
     * @return array{written:array<int,string>,kept:array<int,string>}|null
     */
    private function importMails($mailJson): ?array
    {
        return $mailJson ? $this->pages->importEmailTemplates($mailJson) : array('written' => array(), 'kept' => array());
    }
}
