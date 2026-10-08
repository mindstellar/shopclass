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

    public function __construct(private OSCLocale $locales, private Page $pages)
    {
    }

    public static function make(): self
    {
        return new self(OSCLocale::getInstance(), Page::getInstance());
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
