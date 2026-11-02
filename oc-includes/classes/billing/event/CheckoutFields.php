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

namespace mindstellar\billing\event;

use mindstellar\event\Bridged;

/**
 * The extra text fields a checkout asks the buyer for, such as a tax number. Old filter:
 * `checkout_fields`, which gets and returns the array of fields. Fired while pricing, so a field can
 * depend on the cart's address.
 */
final class CheckoutFields extends CartEvent implements Bridged
{
    /** Most fields one checkout asks for; the rest are ignored. */
    public const MAX_FIELDS = 8;

    /** Longest value a field may allow. */
    public const MAX_VALUE = 100;

    /** @var mixed list of {id, label, required, max, help, invoice, autocomplete} */
    private $fields = [];

    public static function legacyNames(): array
    {
        return ['checkout_fields'];
    }

    /**
     * Ask for a field. A later call with the same id replaces the earlier one.
     *
     * @param string $id           [a-z0-9_]{1,32}
     * @param int    $max          longest value, 1 to MAX_VALUE
     * @param bool   $invoice      print the value on the invoice
     * @param string $autocomplete an HTML autocomplete token for the input, or empty
     *
     * @throws \InvalidArgumentException when the field breaks the rules of clean()
     */
    public function addField(string $id, string $label, bool $required = false, int $max = 64, string $help = '', bool $invoice = true, string $autocomplete = ''): void
    {
        $field = ['id' => $id, 'label' => $label, 'required' => $required, 'max' => $max, 'help' => $help, 'invoice' => $invoice, 'autocomplete' => $autocomplete];
        if (self::clean([$field]) === []) {
            throw new \InvalidArgumentException(sprintf('The checkout field "%s" is not well formed.', $id));
        }
        $kept = [];
        foreach (is_array($this->fields) ? $this->fields : [] as $old) {
            if (!is_array($old) || ($old['id'] ?? null) !== $id) {
                $kept[] = $old;
            }
        }
        $kept[]       = $field;
        $this->fields = $kept;
    }

    /**
     * The fields as the old filter sees and returns them.
     *
     * @return mixed
     */
    public function rawFields()
    {
        return $this->fields;
    }

    /**
     * The fields kept, at most MAX_FIELDS.
     *
     * @return list<array{id:string,label:string,required:bool,max:int,help:string,invoice:bool,autocomplete:string}>
     */
    public function fields(): array
    {
        return self::clean($this->fields);
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $this->fields = $fn($this->fields, $this->cart);
    }

    /**
     * The entries kept when well formed: an id of 1-32 of a-z, 0-9 and `_`, a plain label of 1-60
     * characters, a max of 1 to MAX_VALUE (default 64), a help of up to 200 and an autocomplete token
     * of up to 40 of a-z and `-`. A repeated id keeps its first entry; at most MAX_FIELDS are kept.
     *
     * @param mixed $fields
     *
     * @return list<array{id:string,label:string,required:bool,max:int,help:string,invoice:bool,autocomplete:string}>
     */
    public static function clean($fields): array
    {
        $out = [];
        foreach (is_array($fields) ? $fields : [] as $field) {
            if (!is_array($field) || count($out) >= self::MAX_FIELDS) {
                continue;
            }
            $id    = $field['id'] ?? null;
            $label = is_string($field['label'] ?? null) ? self::plain($field['label']) : '';
            $max   = $field['max'] ?? 64;
            $help  = is_string($field['help'] ?? null) ? self::plain($field['help']) : '';
            $auto  = $field['autocomplete'] ?? '';
            if (!is_string($id) || preg_match('/^[a-z0-9_]{1,32}$/', $id) !== 1 || isset($out[$id])
                || $label === '' || mb_strlen($label, 'UTF-8') > 60
                || !is_int($max) || $max < 1 || $max > self::MAX_VALUE
                || mb_strlen($help, 'UTF-8') > 200
                || !is_string($auto) || preg_match('/^[a-z-]{0,40}$/', $auto) !== 1) {
                continue;
            }
            $out[$id] = [
                'id'           => $id,
                'label'        => $label,
                'required'     => ($field['required'] ?? false) === true,
                'max'          => $max,
                'help'         => $help,
                'invoice'      => ($field['invoice'] ?? true) !== false,
                'autocomplete' => $auto,
            ];
        }

        return array_values($out);
    }

    /**
     * A buyer's value as text on one line, trimmed. Markup is kept as typed and escaped where it is shown.
     */
    public static function value(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
    }

    private static function plain(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));
    }
}
