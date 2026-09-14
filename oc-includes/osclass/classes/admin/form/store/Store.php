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

/**
 * Where a declared admin form's values come from and go to.
 *
 * osc_settings_save() collects, sanitises and validates a submission without knowing
 * what it is being saved into; the store is the only part that does. That split is what
 * lets the same pipeline serve a preference page and a table-backed entity screen, and
 * it is why nothing above here has to learn that entities exist.
 *
 * A row is addressed by an integer key. A store with rows takes the key from its caller
 * and never from the request, and refuses anything that is not a positive integer rather
 * than coercing it -- so a char or compound primary key is not something to bind to.
 *
 * @package mindstellar\admin\form\store
 */
interface Store
{
    /**
     * The stored value of one declared field, or the field's declared default when
     * nothing has been saved for it yet.
     *
     * @param string              $name  field name
     * @param array<string,mixed> $field field spec
     * @param int|string|null     $id    the row, for a store that has rows: a positive
     *                                   integer, or the decimal string of one
     *
     * @return mixed
     * @throws StoreException when the key is not a positive integer
     */
    public function value(string $name, array $field, $id = null);

    /**
     * Every declared field's stored value, keyed by field name.
     *
     * @param array<string,array<string,mixed>> $fields declared fields, keyed by name
     * @param int|string|null                   $id     the row, for a store that has rows
     *
     * @return array<string,mixed>
     * @throws StoreException when the key is not a positive integer
     */
    public function load(array $fields, $id = null): array;

    /**
     * Write the validated values.
     *
     * Called only once every field has passed, so it never has to answer for a partial
     * submission. It walks the declared fields rather than the values it was handed, so
     * a before_save listener cannot introduce a key the page never declared.
     *
     * @param array<string,array<string,mixed>>  $fields  declared fields, keyed by name
     * @param array<string,mixed>                $values  validated values, keyed by field name
     * @param array<string,array<string,string>> $locales per field, the locales it expands over
     * @param int|string|null                    $id      the row, for a store that has rows
     *
     * @return array{updated:int,id:int|string|null} rows or values actually changed, and the
     *                                               key of the row written (null when the
     *                                               store has no rows)
     * @throws StoreException when the key is not a positive integer, or names a row that is
     *                        gone
     */
    public function save(array $fields, array $values, array $locales, $id = null): array;
}
