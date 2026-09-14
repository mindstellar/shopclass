<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\form\store;

use Preference;

/**
 * The default store: one preference per field, under the page's own section.
 *
 * This is the behaviour the declarative settings page has always had, moved behind the
 * store interface unchanged. A page that declares no store gets it, so nothing already
 * registered has to be edited.
 *
 * A field declaring 'write_only' is written and never read back, so what the control shows
 * is its declared default rather than the stored value. A field declaring 'column' is
 * stored under that key rather than under its own name, which is what lets a control keep
 * the name its page's script already knows while the value goes on living under the key
 * every reader uses. A field declaring 'persist' => false is stored nowhere at all.
 *
 * @package mindstellar\admin\form\store
 */
final class PreferenceStore implements Store
{
    private string $section;

    /**
     * @param string $section the preference section every value is stored under
     */
    public function __construct(string $section)
    {
        $this->section = $section;
    }

    /**
     * The preference key one field is stored under: its 'column' when it declares one, its
     * own name otherwise. Public so the read and the write cannot disagree about it.
     *
     * @param string              $name
     * @param array<string,mixed> $field field spec
     *
     * @return string
     */
    public static function key(string $name, array $field): string
    {
        $key = $field['column'] ?? '';

        return is_string($key) && $key !== '' ? $key : $name;
    }

    /**
     * The stored preference for one declared field, or its declared default when nothing
     * has been saved. A translated field answers with one value per locale code.
     *
     * @inheritDoc
     *
     * @param string              $name  field name
     * @param array<string,mixed> $field field spec
     * @param int|string|null     $id    unused: preferences have no rows
     *
     * @return mixed
     */
    public function value(string $name, array $field, $id = null)
    {
        if (!empty($field['write_only'])) {
            // The preference is not read at all, so a stored secret cannot reach the page
            // even by accident. It is still written on save.
            return $field['default'] ?? (($field['type'] ?? 'text') === 'checkbox' ? false : '');
        }

        $key     = self::key($name, $field);
        $locales = osc_settings_field_locales($field);
        if ($locales !== array()) {
            // A translated field is not one value but one per locale, keyed by locale code,
            // so a caller reads osc_settings_value(...)[$code] and never has to know how the
            // preference key is spelled.
            $values = array();
            foreach ($locales as $code => $localeName) {
                $stored        = Preference::newInstance()->get($key . $code, $this->section);
                $values[$code] = ($stored === null || $stored === '')
                    ? (string)($field['default'] ?? '')
                    : $stored;
            }

            return $values;
        }

        $stored = Preference::newInstance()->get($key, $this->section);
        if ($stored === null || $stored === '') {
            // A checkbox saved as off stores '0', not '', so an empty read really is
            // "never saved" and the declared default is the right answer.
            return $field['default'] ?? (($field['type'] ?? 'text') === 'checkbox' ? false : '');
        }

        return osc_settings_cast($field['type'] ?? 'text', $stored);
    }

    /**
     * Every declared field's stored preference, keyed by field name.
     *
     * @inheritDoc
     *
     * @param array<string,array<string,mixed>> $fields declared fields, keyed by name
     * @param int|string|null                   $id     unused: preferences have no rows
     *
     * @return array<string,mixed>
     */
    public function load(array $fields, $id = null): array
    {
        $values = array();
        foreach ($fields as $name => $field) {
            $values[$name] = $this->value($name, $field, $id);
        }

        return $values;
    }

    /**
     * Write one preference per declared field and count the ones that actually changed.
     *
     * @inheritDoc
     *
     * @param array<string,array<string,mixed>>  $fields  declared fields, keyed by name
     * @param array<string,mixed>                $values  validated values, keyed by field name
     * @param array<string,array<string,string>> $locales per field, the locales it expands over
     * @param int|string|null                    $id      unused: preferences have no rows
     *
     * @return array{updated:int,id:null} preferences actually changed; no row key to report
     */
    public function save(array $fields, array $values, array $locales, $id = null): array
    {
        $updated = 0;
        foreach ($fields as $name => $field) {
            if ($field['type'] === 'custom' || !array_key_exists($name, $values)) {
                // Core does not know what a custom field submitted, so it does not pretend
                // to store it. The plugin owns the value the same way it owns the markup.
                continue;
            }
            $persist = $field['persist'] ?? null;
            if ($persist === false) {
                continue;
            }
            $key = self::key($name, $field);
            if (($locales[$name] ?? array()) !== array()) {
                // Core writes only what it can read back: a before_save listener that
                // replaced the per-locale array with a scalar has nothing to spread over
                // the locales, and the bare name is a key this page never reads.
                if (!is_array($values[$name])) {
                    continue;
                }
                foreach ($locales[$name] as $code => $localeName) {
                    $updated += $this->put($key . $code, (string)($values[$name][$code] ?? ''), 'STRING');
                }
                continue;
            }
            $value = $values[$name];
            if (is_callable($persist)) {
                $value = call_user_func($persist, $value, $values);
                if ($value === null) {
                    // "Leave it as it was", declared rather than special-cased inside here.
                    continue;
                }
            } elseif ($field['type'] === 'checkbox') {
                $value = $value ? '1' : '0';
            }
            $updated += $this->put($key, (string)$value, self::type($field));
        }

        // Preferences are keyed by name, not by row, so there is no primary key to hand
        // an after_save listener.
        return array('updated' => $updated, 'id' => null);
    }

    /**
     * Write a preference only when it differs from the one stored, and report whether it
     * changed. A preference page then counts real changes, the way a table-backed one
     * already does through its affected-row count -- so "nothing to update" can be said.
     *
     * A key with no row yet is always written, so a first save creates its rows. That is
     * why this asks the section for the key rather than for its value: get() answers ''
     * for a key that is absent and for one stored empty alike, so comparing values would
     * skip the write that creates the row.
     *
     * @param string $key
     * @param string $value
     * @param string $type the e_type column t_preference records
     *
     * @return int 1 when the preference was written, 0 when it already said this
     */
    private function put(string $key, string $value, string $type): int
    {
        $section = Preference::newInstance()->getSection($this->section);
        if (array_key_exists($key, $section) && (string)$section[$key] === $value) {
            return 0;
        }

        return osc_set_preference($key, $value, $this->section, $type) ? 1 : 0;
    }

    /**
     * The e_type column t_preference records beside the value. Nothing in core reads it, but
     * it is what a site owner sees looking at the table, and a hand-written screen that was
     * moved onto a declaration had already decided it. Derived from the field type so the
     * two front doors cannot disagree; a field deriving its own stored form through a
     * persist callable is STRING, because core does not know what came back.
     *
     * @param array<string,mixed> $field field spec
     *
     * @return string 'STRING', 'BOOLEAN' or 'INTEGER'
     */
    private static function type(array $field): string
    {
        if (isset($field['persist'])) {
            return 'STRING';
        }
        if (($field['type'] ?? 'text') === 'checkbox') {
            return 'BOOLEAN';
        }

        return ($field['type'] ?? 'text') === 'number' ? 'INTEGER' : 'STRING';
    }
}
