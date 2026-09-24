<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\settings;

use InvalidArgumentException;
use mindstellar\admin\form\store\TableStore;

/**
 * Class SettingsPageRegistry
 *
 * Registry of declared admin settings pages. A page is a spec -- a title, a menu to
 * appear under, and groups of fields -- and core does the rest: the page shell, the
 * form and its CSRF token, sanitisation and validation per field type, persistence,
 * the flash message and the save row.
 *
 * The field primitives in hAdminUi.php gave an author one way to draw a field. They
 * did not stop anyone re-inventing the page around them -- a form, a save handler, a
 * CSRF check, a validation block -- and that re-invention is where the security bugs
 * live. This is the layer that removes the need for it.
 *
 * Deliberately mirrors mindstellar\fields\FieldTypeRegistry and
 * mindstellar\pages\PageTemplateRegistry: one spec array, one register(), the same
 * optional sanitize/validate callables, so there is one mental model for all three.
 *
 * @package mindstellar\settings
 */
final class SettingsPageRegistry
{
    /** Field types a declared page may use. Anything else is a spec error, not a fallback. */
    public const FIELD_TYPES = array(
        'text',
        'email',
        'url',
        'tel',
        'number',
        'color',
        'secret',
        'textarea',
        'select',
        'radio',
        'checkbox',
        'hidden',
        'image',
        'richtext',
        'custom',
    );

    /**
     * The types whose value is free text the admin types, so it is reduced to plain text on
     * save unless the field declares 'purify' => false. email and url are narrowed by their
     * own filters, a number is cast, and a secret may legitimately hold anything.
     */
    public const PURIFIED_TYPES = array(
        'text',
        'textarea',
        'tel',
        'color',
        'hidden',
        // Not reduced to plain text like the rest: a body is markup, so it is sanitized
        // rather than stripped, and 'purify' => false still stores it raw.
        'richtext',
    );

    /** Appended to an image field's name to name its "Remove image" box. */
    public const IMAGE_REMOVE_SUFFIX = '_remove';

    /** Anything used as a table, column or key name. Matches what QueryBuilder will accept. */
    private const IDENTIFIER = '/^[A-Za-z0-9_]+$/';

    /**
     * A preference key: an identifier, optionally with the "@scope" suffix core's own keys
     * already use ('maxLatestItems@home'). t_preference takes any string, so without this a
     * declaration could write whatever it liked into the page's section.
     */
    private const PREFERENCE_KEY = '/^[A-Za-z0-9_]+(@[A-Za-z0-9_]+)?$/';

    /** Core menu sections a page may ask to appear under. */
    public const MENUS = array(
        'settings',
        'plugins',
        'appearance',
        'tools',
        'items',
        'users',
        'pages',
        'stats',
    );

    private static ?SettingsPageRegistry $instance = null;

    /** @var array<string,array> normalised page specs, keyed by id */
    private array $pages = array();

    /** @var array<string,int> ids a second registration tried to claim */
    private array $conflicts = array();

    /** Whether admin_menu_init has already placed the declared pages in the menu. */
    private bool $menuReady = false;

    /**
     * Singleton: obtain the registry through instance().
     */
    private function __construct()
    {
    }

    /**
     * Shared registry instance, created on first use.
     *
     * @return self
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Record that the admin menu has been built, or ask whether it has.
     *
     * @param bool|null $ready true to record it; null to read it
     *
     * @return bool
     */
    public function menuReady(?bool $ready = null): bool
    {
        if ($ready !== null) {
            $this->menuReady = $ready;
        }

        return $this->menuReady;
    }

    /**
     * Register a settings page.
     *
     * $spec keys:
     *   'title'      => string    Page heading and browser title. Required.
     *   'menu'       => string    One of MENUS, or '' for a page with no menu entry
     *                             (reached from a link the plugin puts somewhere else).
     *                             Defaults to 'plugins'.
     *   'menu_title' => string    Menu label; defaults to 'title'.
     *   'section'    => string    Preference section the values are stored in.
     *                             Defaults to the page id, which is what keeps one
     *                             plugin's keys out of core's 'osclass' section.
     *   'capability' => string    'administrator' (default) or 'moderator'.
     *   'store'      => string|array Where the values live. 'preference' (the default)
     *                             writes one preference per field under 'section'.
     *                             array('table' => 't_ban_rule', 'pk' => 'pk_i_id') writes
     *                             one row of that table -- unprefixed, core applies
     *                             DB_TABLE_PREFIX. Rows are addressed by an integer key,
     *                             and the key comes from the controller rather than from
     *                             the request: no key inserts a row, and a key that is not
     *                             a positive integer is refused. The generic settings
     *                             controller therefore does not serve a table-backed page
     *                             -- one needs a controller that supplies a row id it has
     *                             validated for this admin.
     *                             A table store may add 'locale_table', 'locale_fk' and
     *                             'locale_column' (default 'fk_c_locale_code'), which is
     *                             where translated fields go: one row per locale, keyed by
     *                             the entity's id and the locale code. Without it a
     *                             translated field is refused, because a column holds one
     *                             value and not one per locale.
     *   'help'       => string    Help-box body for the "?" beside the page title.
     *   'intro'      => string    Explanatory paragraph above the first group.
     *   'groups'     => array[]   array('title' =>, 'intro' =>, 'fields' => array[]).
     *   'fields'     => array[]   Sugar for a single untitled group.
     *   'validate'   => callable callable(array $values, string $pageId, $id):
     *                             string|string[]|null returning the error, or the errors, a
     *                             rule spanning more than one field found. $id is the row
     *                             being saved, null on an insert and on a preference page,
     *                             so "that name is taken" can exclude the row being edited.
     *                             Runs after every field has been checked, so a page reports
     *                             all its problems at once. A field discarded with its
     *                             'depends' master off is not in $values at all.
     *   'after_save' => callable callable(array $values, $id): void, run once after a
     *                             successful save, never on a rejected one. An alternative
     *                             to hooking 'admin_form_after_save' when the effect only
     *                             belongs to this one page; it runs after that hook, not
     *                             before it.
     *
     * A field spec is what osc_admin_field() takes, plus:
     *   'default'  => mixed     Value used until something is saved.
     *   'required' => bool      Rejected as empty on save.
     *   'sanitize' => callable  callable(mixed $value): mixed, run before validation. The
     *                           value it receives is trimmed, and on a purified type its
     *                           tags are already gone, so a callback never has to strip
     *                           them itself.
     *   'validate' => callable  callable(mixed $value, array $field): ?string returning
     *                           an error message, or null when the value is good.
     *   'collect'  => callable  callable(array $field): mixed -- how the submission becomes
     *                           this field's value, for a control core cannot read by name:
     *                           one posted as an array, or one a plugin contributes keys to.
     *                           It replaces the read, the trim and the purify, so the
     *                           declaration owns the sanitising; a 'sanitize' beside it
     *                           still runs on what it returns.
     *   'depends'  => string    Another field on this page -- not a custom or translated
     *                           one, and not a cycle. While that field is off, this one is
     *                           hidden, is not required, and its submitted value is
     *                           discarded rather than stored.
     *   'depends_value' => string|string[] With 'depends' only: the field is on while the
     *                           master's value is one of these, instead of while it is on.
     *                           Only for a select or radio master, and only its option keys.
     *   'translate' => bool     text, textarea and richtext only: one control per enabled
     *                           locale, each stored under the field name plus the locale
     *                           code -- or, on a table store with a locale table, in that
     *                           table's column of the same name, one row per locale.
     *   'locales'  => array     With 'translate': the locales to expand over, as code =>
     *                           name, instead of every enabled one. The save reads the same
     *                           key the render does, so a screen drawn for the back-office
     *                           locales saves those.
     *   'purify'   => bool      text, textarea, tel and color only: false stores the value
     *                           as submitted apart from the trim every field gets, for a
     *                           field that holds markup or code on purpose. Defaults to
     *                           true -- every tag out, contents and all, which is what a
     *                           hand-written screen reading the same field through
     *                           Params::getParam() has always stored. This governs what is
     *                           stripped, not what is escaped: a stored value is still
     *                           printed through osc_esc_html() or osc_esc_js().
     *   'column'   => string    The key this field is stored under, when it is not the
     *                           field's own name: a column on a table store, a preference
     *                           name on a preference one. It is what lets a control keep
     *                           the name its own page's script already knows while the
     *                           value goes on living under the key every reader uses.
     *   'persist'  => false|callable What the field's key takes.
     *                           false is a field that is stored nowhere -- a confirmation
     *                           box, a re-authentication box, a control another field is
     *                           derived from -- collected and validated like any other and
     *                           never written. A callable(mixed $value, array $values)
     *                           returns the value to write, and null from it writes
     *                           nothing, so "blank means unchanged" is declared rather
     *                           than special-cased. FormSpec::WRITE_NULL writes NULL on a
     *                           table store and an empty string on a preference. It says nothing about what the control
     *                           shows; that is 'write_only'.
     *   'image'    fields store the t_resource id of an uploaded image. They take 'required',
     *              'depends', 'depends_value', 'help', 'column' and 'max_kb', live on a
     *              preference page only, cannot be a 'depends' master, and refuse 'default',
     *              'sanitize', 'validate', 'persist' and 'write_only'.
     *   'max_kb'   => int       image only: the largest file accepted, in KB. Defaults to the
     *                           site's upload limit (osc_max_size_kb()).
     *   'write_only' => bool    Whether the control shows what is stored. false (the
     *                           default) reads the stored value back into the control;
     *                           true never reads it and draws the declared default, for a
     *                           value whose stored form is not the one that was typed --
     *                           a password hash -- or that has no stored form at all.
     *                           Required on a 'secret', where the type alone does not say
     *                           which of the two it is: an API key is meant to be read
     *                           back and edited, a password never is.
     *
     * @param string $id   Namespaced slug, [a-z0-9_.-]{1,60}. Usually the plugin's own.
     * @param array  $spec Page specification (see above).
     *
     * @throws InvalidArgumentException on an invalid id or spec.
     */
    public function register(string $id, array $spec): void
    {
        if (!self::isValidId($id)) {
            throw new InvalidArgumentException('SettingsPageRegistry: invalid page id "' . $id . '"');
        }

        // Two plugins claiming one id: the first keeps it. Replacing it would take the other
        // plugin's page off the menu *and* point its saved values at a section it no longer
        // reads, both silently. Throwing is not the answer either -- plugins are included
        // unguarded from oc-load.php, so an exception here white-screens the whole site,
        // front end included, over a name collision. See conflicts().
        if (isset($this->pages[$id])) {
            $this->conflicts[$id] = ($this->conflicts[$id] ?? 0) + 1;

            return;
        }
        if (empty($spec['title']) || !is_string($spec['title'])) {
            throw new InvalidArgumentException('SettingsPageRegistry: page "' . $id . '" needs a string title');
        }

        $menu = (string)($spec['menu'] ?? 'plugins');
        if ($menu !== '' && !in_array($menu, self::MENUS, true)) {
            throw new InvalidArgumentException(
                'SettingsPageRegistry: page "' . $id . '" menu "' . $menu . '" is not a core menu section'
            );
        }

        $groups = $spec['groups'] ?? array();
        if (isset($spec['fields'])) {
            array_unshift($groups, array('fields' => $spec['fields']));
        }
        if ($groups === array()) {
            throw new InvalidArgumentException('SettingsPageRegistry: page "' . $id . '" declares no fields');
        }
        if (isset($spec['after_save']) && !is_callable($spec['after_save'])) {
            throw new InvalidArgumentException('SettingsPageRegistry: page "' . $id . '" after_save must be callable');
        }
        if (isset($spec['validate']) && !is_callable($spec['validate'])) {
            throw new InvalidArgumentException('SettingsPageRegistry: page "' . $id . '" validate must be callable');
        }

        $store = $this->normaliseStore($id, $spec['store'] ?? 'preference');

        $this->pages[$id] = array(
            'id'         => $id,
            'title'      => $spec['title'],
            'menu'       => $menu,
            'menu_title' => isset($spec['menu_title']) && is_string($spec['menu_title'])
                ? $spec['menu_title']
                : $spec['title'],
            'section'    => isset($spec['section']) && is_string($spec['section']) && $spec['section'] !== ''
                ? $spec['section']
                : $id,
            'store'      => $store,
            'capability' => ($spec['capability'] ?? '') === 'moderator' ? 'moderator' : 'administrator',
            'help'       => isset($spec['help']) && is_string($spec['help']) ? $spec['help'] : '',
            'intro'      => isset($spec['intro']) && is_string($spec['intro']) ? $spec['intro'] : '',
            'groups'     => $this->normaliseGroups($id, $groups, $store),
            'validate'   => isset($spec['validate']) && is_callable($spec['validate']) ? $spec['validate'] : null,
            'after_save' => isset($spec['after_save']) && is_callable($spec['after_save']) ? $spec['after_save'] : null,
        );
    }

    /**
     * The spec for a registered page, or null when the id is not registered (e.g. its
     * plugin is deactivated -- which is exactly when a bookmarked URL is still requested).
     *
     * @param string $id
     *
     * @return array<string,mixed>|null
     */
    public function get(string $id): ?array
    {
        return $this->pages[$id] ?? null;
    }

    /**
     * All registered pages, keyed by id, in registration order.
     *
     * @return array<string,array>
     */
    public function all(): array
    {
        return $this->pages;
    }

    /**
     * Every field on a page, flattened out of its groups and keyed by name. The save
     * path walks this rather than the groups: what a field is grouped with is a layout
     * decision and has nothing to do with what gets stored.
     *
     * @param string $id
     *
     * @return array<string,array>
     */
    public function fields(string $id): array
    {
        $page = $this->get($id);
        if ($page === null) {
            return array();
        }

        $fields = array();
        foreach ($page['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                $fields[$field['name']] = $field;
            }
        }

        return $fields;
    }

    /**
     * Ids more than one registration tried to claim, and how many times. Empty on a healthy
     * install; non-empty means a plugin's settings page is not the one on screen.
     *
     * @return array<string,int>
     */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * Whether $id is a well-formed page id.
     *
     * @param string $id
     *
     * @return bool
     */
    public static function isValidId(string $id): bool
    {
        return (bool)preg_match('/^[a-z0-9_.-]{1,60}$/', $id);
    }

    /**
     * Normalise the page's 'store' into array('type' => 'preference') or
     * array('type' => 'table', 'table' =>, 'pk' =>), with the locale table a table store
     * may add.
     *
     * A store nobody implements has to be refused here: accepted, it would fall back to
     * preferences and the page would look saved while its table stayed empty.
     *
     * @param string $id
     * @param mixed  $store
     *
     * @return array<string,string>
     * @throws InvalidArgumentException on a store core does not implement, or a
     *         table/pk that is not an identifier.
     */
    private function normaliseStore(string $id, $store): array
    {
        $prefix = 'SettingsPageRegistry: page "' . $id . '" store ';

        if (is_string($store)) {
            if ($store !== 'preference') {
                throw new InvalidArgumentException($prefix . '"' . $store . '" is not a store core has');
            }

            return array('type' => 'preference');
        }
        if (!is_array($store)) {
            throw new InvalidArgumentException(
                $prefix . 'must be "preference" or an array naming a table and its pk'
            );
        }

        foreach (array('table', 'pk') as $key) {
            if (!isset($store[$key]) || !is_string($store[$key]) || $store[$key] === '') {
                throw new InvalidArgumentException($prefix . 'needs a ' . $key);
            }
            if (!preg_match(self::IDENTIFIER, $store[$key])) {
                throw new InvalidArgumentException(
                    $prefix . $key . ' "' . $store[$key] . '" is not an identifier'
                );
            }
        }

        $normalised = array('type' => 'table', 'table' => $store['table'], 'pk' => $store['pk']);

        if (!isset($store['locale_table'])) {
            return $normalised;
        }

        // Half a locale binding is worse than none: the table alone has nothing to key a
        // row by, so it would write every locale over one row.
        if (!isset($store['locale_fk'])) {
            throw new InvalidArgumentException($prefix . 'needs a locale_fk beside its locale_table');
        }
        $locale = array(
            'locale_table'  => $store['locale_table'],
            'locale_fk'     => $store['locale_fk'],
            'locale_column' => $store['locale_column'] ?? 'fk_c_locale_code',
        );
        foreach ($locale as $key => $value) {
            if (!is_string($value) || $value === '' || !preg_match(self::IDENTIFIER, $value)) {
                throw new InvalidArgumentException(
                    $prefix . $key . ' "' . (is_string($value) ? $value : gettype($value)) . '" is not an identifier'
                );
            }
        }

        return $normalised + $locale;
    }

    /**
     * Hold one field against the store the page writes through.
     *
     * Every refusal here is something that registers cleanly and then goes wrong at save
     * time: a field mapped onto the primary key is the store overwriting the row it is
     * addressing, and a translated field has one value per locale where a column holds one.
     *
     * 'column' and 'persist' apply to either store -- a preference has a key the same way a
     * row has a column, and a control that is stored nowhere is stored nowhere either way.
     * Both kinds of key are held to a shape; only the rules after that are about columns,
     * because only a table has any.
     *
     * @param string               $id
     * @param array<string,mixed>  $field
     * @param string               $type Normalised field type.
     * @param array<string,string> $store Output of normaliseStore().
     *
     * @throws InvalidArgumentException when the field cannot be stored through this store.
     */
    private function checkFieldStorage(string $id, array $field, string $type, array $store): void
    {
        $prefix = 'SettingsPageRegistry: page "' . $id . '" field "' . $field['name'] . '" ';
        $table  = $store['type'] === 'table';

        if (array_key_exists('persist', $field) && $field['persist'] !== false && !is_callable($field['persist'])) {
            throw new InvalidArgumentException($prefix . 'persist must be false or a callable');
        }
        if (isset($field['column']) && (!is_string($field['column']) || $field['column'] === '')) {
            throw new InvalidArgumentException($prefix . 'column must name a key');
        }
        // A field that is stored nowhere has no key to hold to the rules below: it is never
        // read and never written, so what it would have been called does not arise.
        if ($type === 'custom' || (($field['persist'] ?? null) === false)) {
            return;
        }
        if (!$table) {
            if (isset($field['column']) && !preg_match(self::PREFERENCE_KEY, $field['column'])) {
                throw new InvalidArgumentException(
                    $prefix . 'maps to "' . $field['column'] . '", which is not a preference key'
                );
            }

            return;
        }

        $column = TableStore::column($field['name'], $field);
        if (!preg_match(self::IDENTIFIER, $column)) {
            throw new InvalidArgumentException($prefix . 'maps to "' . $column . '", which is not an identifier');
        }
        if ($column === $store['pk']) {
            throw new InvalidArgumentException(
                $prefix . 'maps to "' . $column . '", the primary key the store addresses the row by'
            );
        }
        if (!empty($field['translate'])) {
            if (!isset($store['locale_table'])) {
                throw new InvalidArgumentException(
                    $prefix . 'cannot be translated on a table store: a column holds one value, not one per locale'
                );
            }
            // Per locale there is a value, so a rule handed one value has nothing to say
            // about which. Refused rather than quietly skipped, because a persist that is
            // never called writes the raw value it was declared to replace.
            if (array_key_exists('persist', $field)) {
                throw new InvalidArgumentException(
                    $prefix . 'is translated, so it cannot declare persist: the locale table takes one value per locale'
                );
            }
        }
    }

    /**
     * Hold an image field to the keys that mean something for a stored upload.
     *
     * A default, a sanitize or validate callable and a persist rule all describe a value
     * the admin types; an image is a file core stores and replaces, so each is refused
     * rather than silently ignored.
     *
     * @param string               $id
     * @param array<string,mixed>  $field
     * @param array<string,string> $store Output of normaliseStore().
     *
     * @throws InvalidArgumentException on a key an image field cannot honour.
     */
    private function checkImageField(string $id, array $field, array $store): void
    {
        $prefix = 'SettingsPageRegistry: page "' . $id . '" field "' . $field['name'] . '" ';
        if ($store['type'] !== 'preference') {
            throw new InvalidArgumentException($prefix . 'is an image, which only a preference page stores');
        }
        foreach (array('default', 'sanitize', 'validate', 'persist', 'write_only') as $key) {
            if (array_key_exists($key, $field)) {
                throw new InvalidArgumentException($prefix . 'is an image and cannot set ' . $key);
            }
        }
        if (array_key_exists('max_kb', $field) && (!is_int($field['max_kb']) || $field['max_kb'] < 1)) {
            throw new InvalidArgumentException($prefix . 'max_kb must be a positive integer');
        }
    }

    /**
     * Hold a field's depends_value to its shape: set beside a 'depends', and a non-empty
     * string or a non-empty list of them.
     *
     * @param string              $id
     * @param array<string,mixed> $field
     *
     * @throws InvalidArgumentException on a depends_value that could never match.
     */
    private function checkDependsValue(string $id, array $field): void
    {
        $prefix = 'SettingsPageRegistry: page "' . $id . '" field "' . $field['name'] . '" depends_value ';
        if (!isset($field['depends'])) {
            throw new InvalidArgumentException($prefix . 'needs a depends naming the field it is compared with');
        }

        $values = $field['depends_value'];
        if (is_string($values)) {
            $values = array($values);
        }
        if (!is_array($values) || $values === array()) {
            throw new InvalidArgumentException($prefix . 'must be a string or a list of strings');
        }
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException($prefix . 'must hold only non-empty strings');
            }
        }
    }

    /**
     * Check the shape of every group and field up front, so a typo in a spec is an
     * exception at registration time rather than a silently missing field on a page.
     *
     * @param string                         $id
     * @param array<int,array<string,mixed>> $groups
     * @param array<string,string>           $store Output of normaliseStore().
     *
     * @return array<int,array<string,mixed>> normalised groups, each array{title:string,
     *         intro:string, fields:array<int,array<string,mixed>>}
     * @throws InvalidArgumentException on a malformed group, field, depends chain or cycle.
     */
    private function normaliseGroups(string $id, array $groups, array $store): array
    {
        $out          = array();
        $seen         = array();
        $depends      = array();
        $dependsValue = array();

        foreach ($groups as $group) {
            if (!is_array($group) || !isset($group['fields']) || !is_array($group['fields'])) {
                throw new InvalidArgumentException('SettingsPageRegistry: page "' . $id . '" has a group with no fields');
            }

            $fields = array();
            foreach ($group['fields'] as $field) {
                if (!is_array($field) || empty($field['name']) || !is_string($field['name'])) {
                    throw new InvalidArgumentException(
                        'SettingsPageRegistry: page "' . $id . '" has a field with no name'
                    );
                }

                $type = (string)($field['type'] ?? 'text');
                if (!in_array($type, self::FIELD_TYPES, true)) {
                    throw new InvalidArgumentException(
                        'SettingsPageRegistry: page "' . $id . '" field "' . $field['name']
                        . '" has unknown type "' . $type . '"'
                    );
                }
                if (in_array($type, array('select', 'radio'), true)
                    && (empty($field['options']) || !is_array($field['options']))
                ) {
                    throw new InvalidArgumentException(
                        'SettingsPageRegistry: page "' . $id . '" field "' . $field['name'] . '" needs options'
                    );
                }
                if ($type === 'custom' && (!isset($field['render']) || !is_callable($field['render']))) {
                    throw new InvalidArgumentException(
                        'SettingsPageRegistry: page "' . $id . '" field "' . $field['name']
                        . '" is custom and needs a render callable'
                    );
                }
                foreach (array('sanitize', 'validate', 'collect') as $cb) {
                    if (isset($field[$cb]) && !is_callable($field[$cb])) {
                        throw new InvalidArgumentException(
                            'SettingsPageRegistry: page "' . $id . '" field "' . $field['name']
                            . '" ' . $cb . ' must be callable'
                        );
                    }
                }
                if (isset($field['translate']) && $field['translate']
                    && !in_array($type, array('text', 'textarea', 'richtext'), true)
                ) {
                    throw new InvalidArgumentException(
                        'SettingsPageRegistry: page "' . $id . '" field "' . $field['name']
                        . '" cannot be translated: only text, textarea and richtext expand over locales'
                    );
                }
                if (isset($field['purify'])) {
                    if (!is_bool($field['purify'])) {
                        throw new InvalidArgumentException(
                            'SettingsPageRegistry: page "' . $id . '" field "' . $field['name']
                            . '" purify must be true or false'
                        );
                    }
                    // Set on a type nothing purifies, it reads as a decision that was taken
                    // and is not: a secret or a select would be stored raw either way.
                    if (!in_array($type, self::PURIFIED_TYPES, true)) {
                        throw new InvalidArgumentException(
                            'SettingsPageRegistry: page "' . $id . '" field "' . $field['name']
                            . '" cannot set purify: only ' . implode(', ', self::PURIFIED_TYPES)
                            . ' are purified'
                        );
                    }
                }
                if (array_key_exists('write_only', $field)) {
                    if (!is_bool($field['write_only'])) {
                        throw new InvalidArgumentException(
                            'SettingsPageRegistry: page "' . $id . '" field "' . $field['name']
                            . '" write_only must be true or false'
                        );
                    }
                    if ($type === 'custom') {
                        throw new InvalidArgumentException(
                            'SettingsPageRegistry: page "' . $id . '" field "' . $field['name']
                            . '" cannot set write_only: core neither reads nor writes a custom field'
                        );
                    }
                } elseif ($type === 'secret') {
                    // A stored secret is either one the admin has to be able to read back
                    // and edit -- an API key -- or one they must never see again, and the
                    // type does not say which. Omitting it is how a password hash ends up
                    // drawn into the page, so it is not a thing that can be omitted.
                    throw new InvalidArgumentException(
                        'SettingsPageRegistry: page "' . $id . '" field "' . $field['name']
                        . '" is a secret and must declare write_only'
                    );
                }
                if ($type === 'image') {
                    $this->checkImageField($id, $field, $store);
                } elseif (array_key_exists('max_kb', $field)) {
                    throw new InvalidArgumentException(
                        'SettingsPageRegistry: page "' . $id . '" field "' . $field['name']
                        . '" cannot set max_kb: only an image field takes a file'
                    );
                }
                $this->checkFieldStorage($id, $field, $type, $store);
                if (isset($field['depends'])) {
                    if (!is_string($field['depends']) || $field['depends'] === '') {
                        throw new InvalidArgumentException(
                            'SettingsPageRegistry: page "' . $id . '" field "' . $field['name']
                            . '" depends must name a field'
                        );
                    }
                    $depends[$field['name']] = $field['depends'];
                }
                if (array_key_exists('depends_value', $field)) {
                    $this->checkDependsValue($id, $field);
                    $dependsValue[$field['name']] = array_map('strval', (array)$field['depends_value']);
                }
                if (isset($seen[$field['name']])) {
                    throw new InvalidArgumentException(
                        'SettingsPageRegistry: page "' . $id . '" declares "' . $field['name'] . '" twice'
                    );
                }

                $seen[$field['name']] = array(
                    'type'      => $type,
                    'translate' => !empty($field['translate']),
                    'options'   => is_array($field['options'] ?? null) ? array_map('strval', array_keys($field['options'])) : array(),
                );
                $field['type']        = $type;
                $fields[]             = $field;
            }

            $out[] = array(
                'title'  => isset($group['title']) && is_string($group['title']) ? $group['title'] : '',
                'intro'  => isset($group['intro']) && is_string($group['intro']) ? $group['intro'] : '',
                'fields' => $fields,
            );
        }

        // Checked once every group is in: a master may be declared in a later group than
        // the field depending on it. Every refusal here is a relationship that would
        // register cleanly and then discard the dependent's value on every save.
        foreach ($depends as $name => $master) {
            $prefix = 'SettingsPageRegistry: page "' . $id . '" field "' . $name . '" depends on ';
            if (!isset($seen[$master])) {
                throw new InvalidArgumentException(
                    $prefix . '"' . $master . '", which the page does not declare'
                );
            }
            if ($seen[$master]['type'] === 'custom') {
                throw new InvalidArgumentException(
                    $prefix . 'custom field "' . $master . '", whose value core never reads'
                );
            }
            if ($seen[$master]['type'] === 'image') {
                throw new InvalidArgumentException(
                    $prefix . 'image field "' . $master . '", which is not a switch'
                );
            }
            if ($seen[$master]['translate']) {
                throw new InvalidArgumentException(
                    $prefix . 'translated field "' . $master . '", which has one value per locale'
                );
            }
            if (!isset($dependsValue[$name])) {
                continue;
            }
            // Only a choice list stores a value the browser holds verbatim. A number or a text
            // box is rewritten on save, so the row shown and the value kept could disagree.
            if (!in_array($seen[$master]['type'], array('select', 'radio'), true)) {
                throw new InvalidArgumentException(
                    $prefix . $seen[$master]['type'] . ' "' . $master
                    . '" with a depends_value: only a select or radio master takes one'
                );
            }
            // A value the master never offers is a row that never shows.
            foreach ($dependsValue[$name] as $value) {
                if (!in_array($value, $seen[$master]['options'], true)) {
                    throw new InvalidArgumentException(
                        $prefix . '"' . $master . '" with a depends_value "' . $value
                        . '", which is not one of its options'
                    );
                }
            }
        }

        // An image field posts its "Remove image" box under a second name, which no other
        // field may take.
        foreach ($seen as $name => $info) {
            if ($info['type'] !== 'image') {
                continue;
            }
            $remove = $name . self::IMAGE_REMOVE_SUFFIX;
            if (isset($seen[$remove])) {
                throw new InvalidArgumentException(
                    'SettingsPageRegistry: page "' . $id . '" field "' . $remove
                    . '" clashes with the remove box of image field "' . $name . '"'
                );
            }
        }

        foreach ($depends as $name => $master) {
            $path  = array($name);
            $chain = array($name => true);
            for ($at = $master; ; $at = $depends[$at]) {
                $path[] = $at;
                if (isset($chain[$at])) {
                    throw new InvalidArgumentException(
                        'SettingsPageRegistry: page "' . $id . '" has a depends cycle: ' . implode(' -> ', $path)
                    );
                }
                $chain[$at] = true;
                if (!isset($depends[$at])) {
                    break;
                }
            }
        }

        return $out;
    }
}
