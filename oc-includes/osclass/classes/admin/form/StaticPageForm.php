<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\form;

use Page;
use Params;
use WebThemes;

/**
 * The static-page editor's save, declared once: the row it writes, the locale table its
 * title and body live in, the three rules that can refuse it, and which control each
 * refusal belongs to.
 *
 * Declared per request rather than at boot, because the declaration is about one page: the
 * row being edited decides whether the internal name and the footer link may be written at
 * all, and whether the reserved-name rule applies. Its labels are also translated, which a
 * boot-time declaration would do before the locale is known.
 *
 * The screen itself is still hand-drawn -- it carries the page builder and a widget canvas
 * that no field spec holds -- so this declares the write half only. The posted names are
 * therefore the ones the view has always emitted, including the locale pattern
 * '<code>#s_title'.
 *
 * @package mindstellar\admin\form
 */
final class StaticPageForm
{
    public const PAGE_ID = 'core.page';

    /** Unprefixed; the store applies DB_TABLE_PREFIX. */
    public const TABLE = 't_pages';

    public const PK = 'pk_i_id';

    public const LOCALE_TABLE = 't_pages_description';

    public const LOCALE_FK = 'fk_i_pages_id';

    /** The row being edited, as the caller names it, or null when one is being added. */
    private static $id = null;

    /** An indelible page keeps its internal name and its footer link, whatever is posted. */
    private static bool $indelible = false;

    /** The internal name the row holds now, so only a rename meets the reserved-name rule. */
    private static string $currentName = '';

    /** @var array<string,mixed> the control the last refusal belongs to, and its message */
    private static array $failure = array();

    /**
     * Declare the page for the row that is about to be saved.
     *
     * @param int|string|null $id the row being edited, or null when one is being added
     *
     * @return string the page id, so a caller can register and use it in one line
     */
    public static function register($id = null): string
    {
        self::$id          = $id;
        self::$indelible   = false;
        self::$currentName = '';
        self::$failure     = array();

        if ($id !== null) {
            $row               = Page::newInstance()->findByPrimaryKey($id);
            self::$indelible   = isset($row['b_indelible']) && $row['b_indelible'] == 1;
            self::$currentName = (string)($row['s_internal_name'] ?? '');
        }

        if (osc_settings_page(self::PAGE_ID) !== null) {
            return self::PAGE_ID;
        }

        $locales = array();
        foreach (osc_get_admin_locales() as $locale) {
            $locales[(string)$locale['pk_c_code']] = (string)$locale['s_name'];
        }

        osc_admin_form(self::PAGE_ID)
            ->title(__('Page'))
            // Reached from the pages list, not from a menu of its own.
            ->menu('')
            ->store(self::TABLE, self::PK)
            ->translateTable(self::LOCALE_TABLE, self::LOCALE_FK)
            ->onValidate(static fn (array $values, string $pageId, $id) => self::refuse($values))
            // Stored as typed: a page body is markup the administrator wrote, and a title
            // that went through the tag stripper would come back shorter than it went in.
            ->text('s_title', __('Title'))
                ->translate()
                ->purify(false)
                ->set('translate_name', '%s#s_title')
                ->set('locales', $locales)
            ->richtext('s_text', __('Body'))
                ->translate()
                ->purify(false)
                ->set('translate_name', '%s#s_text')
                ->set('locales', $locales)
            ->text('s_internal_name', __('Internal name'))
                ->sanitize(static fn ($value) => osc_sanitizeString($value))
                ->persist(static fn ($value) => self::$indelible ? null : $value)
            ->checkbox('b_link', __('Show a link in the footer'))
                ->persist(static fn ($value) => self::$indelible ? null : ($value ? 1 : 0))
            // Whatever a plugin's page_meta fields posted, beside the template select: the
            // blob is one request key holding an array core cannot know the shape of.
            ->hidden('s_meta')
                ->set('collect', static fn () => json_encode(Params::getParam('meta')))
            ->hidden('dt_mod_date')
                ->writeOnly()
                ->persist(static fn () => date('Y-m-d H:i:s'))
            // Three columns a row is born with and never edited afterwards.
            ->hidden('dt_pub_date')
                ->writeOnly()
                ->persist(static fn () => self::$id === null ? date('Y-m-d H:i:s') : null)
            ->hidden('b_indelible')
                ->writeOnly()
                ->persist(static fn () => self::$id === null ? '0' : null)
            ->hidden('i_order')
                ->writeOnly()
                ->persist(static fn () => self::$id === null ? self::nextOrder() : null)
            ->register();

        return self::PAGE_ID;
    }

    /**
     * The control the last refusal belongs to, its message, and which rule it was.
     *
     * The rule is named because the screen remembers a submitted internal name only once
     * the name itself has passed: a name that was refused must not come back on the next
     * empty form.
     *
     * @return array<string,mixed> array('field' =>, 'message' =>, 'rule' =>), or empty
     */
    public static function failure(): array
    {
        return self::$failure;
    }

    /**
     * The message for every locale whose title came back empty. A page needs one title,
     * and saying so on each empty tab is how the administrator finds which to fill.
     *
     * @param array<string,string> $titles locale code => submitted title
     *
     * @return array<string,string> locale code => message, plus one summary line
     */
    public static function emptyTitles(array $titles): array
    {
        $errors = array();
        foreach (osc_get_admin_locales() as $locale) {
            $code = $locale['pk_c_code'];
            if (trim((string)($titles[$code] ?? '')) === '') {
                $errors[$code] = sprintf(
                    _m('%s: a page needs a title in at least one language'),
                    $locale['s_name']
                );
            }
        }

        if ($errors !== array()) {
            // One rule, one line in the summary: every tab is marked, but a title in any
            // single language satisfies it, so this is not one fault per language.
            $errors['summary'] = _m('A page needs a title in at least one language.');
        }

        return $errors;
    }

    /**
     * The first rule this submission breaks, or null when it breaks none.
     *
     * One message at a time, in the order the screen has always applied them: a page with
     * no name and no title has one thing to fix first, and the two orders differ because
     * adding a page checks the name is free before asking for a title while editing one
     * checks the title first.
     *
     * @param array<string,mixed> $values validated values, keyed by field name
     *
     * @return string|null
     */
    private static function refuse(array $values): ?string
    {
        $name   = (string)($values['s_internal_name'] ?? '');
        $titles = is_array($values['s_title'] ?? null) ? $values['s_title'] : array();
        $adding = self::$id === null;

        if ($name === '') {
            return self::fail('s_internal_name', 'empty', _m('You have to set an internal name'));
        }

        // Core's view vocabulary grows between releases, so a page can hold a name that was
        // free when it was created and is reserved now. Only a rename has to clear the
        // reserved set; keeping the old name leaves the page editable.
        if (($adding || $name !== self::$currentName) && !WebThemes::newInstance()->isValidPage($name)) {
            return self::fail('s_internal_name', 'reserved', _m('You have to set a different internal name'));
        }

        if ($adding && isset(Page::newInstance()->findByInternalName($name)['pk_i_id'])) {
            return self::fail(
                's_internal_name',
                'taken',
                _m("Oops! That internal name is already in use. We can't make the changes")
            );
        }

        if (self::titled($titles) === false) {
            return self::fail('s_title', 'untitled', $adding
                ? _m("The page couldn't be added, at least one title should not be empty")
                : _m("The page couldn't be updated, at least one title should not be empty"));
        }

        if (!$adding && Page::newInstance()->internalNameExists(self::$id, $name)) {
            return self::fail('s_internal_name', 'taken', _m("You can't repeat internal name"));
        }

        return null;
    }

    /** Whether any locale carries a title. One is enough; the page is one record. */
    private static function titled(array $titles): bool
    {
        foreach ($titles as $title) {
            if (trim((string)$title) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Record which control a refusal belongs to, and hand the message to the save path.
     *
     * @param string $field
     * @param string $rule
     * @param string $message
     *
     * @return string
     */
    private static function fail(string $field, string $rule, string $message): string
    {
        self::$failure = array('field' => $field, 'rule' => $rule, 'message' => $message);

        return $message;
    }

    /**
     * The order a new page takes: after every page there is. An empty table has none, and
     * the first page then takes zero.
     *
     * @return int
     */
    private static function nextOrder(): int
    {
        $order = osc_db_scalar('SELECT MAX(i_order) AS o FROM ' . DB_TABLE_PREFIX . self::TABLE);

        return $order === null ? 0 : (int)$order + 1;
    }
}
