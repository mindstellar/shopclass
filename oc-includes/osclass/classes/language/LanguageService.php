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

use mindstellar\validation\ConflictException;
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
    public const FILES = array('theme.po', 'core.po', 'messages.po', 'theme.mo', 'core.mo', 'messages.mo');

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
     * @return array{mail:bool,failed:?int} whether the e-mail templates were imported, and downloadFiles()'s answer
     */
    public function importPublished(string $code, array $manifest, ?callable $fetch = null): array
    {
        $fetch ??= 'osc_file_get_contents';
        $mail    = $this->install($manifest, $code, $fetch(osc_get_i18n_repository_url('src/translations/' . $code . '/mail.json')));
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

        return array('mail' => $mail, 'failed' => $failed);
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
     * Turn a language off for the website, or for oc-admin.
     *
     * @return int rows changed
     * @throws ConflictException for the site's default language
     */
    public function disable(string $code, bool $admin = false): int
    {
        if (osc_language() == $code) {
            throw new ConflictException(
                sprintf(_m("%s can't be disabled because it's the default language"), osc_language())
            );
        }

        return LocaleStore::update($code, array($admin ? 'b_enabled_bo' : 'b_enabled' => 0));
    }

    /**
     * Delete a language's rows and its translation folder. The language this admin is using
     * and the default language are refused.
     *
     * @return string one of the outcome constants
     */
    public function delete(string $code, string $adminLocale): string
    {
        if ($code === $adminLocale) {
            return self::IS_CURRENT;
        }
        if ($code === osc_language()) {
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
     * @return bool false when the e-mail templates could not be imported
     */
    public function install(array $manifest, string $code, $mailJson): bool
    {
        $this->locales->insertLocaleInfo($manifest, $code);

        return !$mailJson || $this->pages->importEmailJsonTemplates($mailJson);
    }
}
