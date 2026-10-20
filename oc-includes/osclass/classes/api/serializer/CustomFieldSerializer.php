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

namespace mindstellar\api\serializer;

/**
 * Custom fields: their definitions (what a category asks for) and a listing's values.
 */
final class CustomFieldSerializer
{
    public const TYPES = ['text', 'number', 'textarea', 'dropdown', 'radio', 'checkbox', 'url', 'date', 'dateinterval'];

    /** @var array<int,array<string,mixed>> field id => decoded s_meta */
    private array $meta = [];

    /**
     * @param array<string,mixed> $field a t_meta_fields row, s_meta merged in
     *
     * @return array<string,mixed>
     */
    public function definition(array $field, string $locale): array
    {
        $options = Format::text($field['s_options'] ?? null);

        return [
            'id'         => Format::int($field['pk_i_id'] ?? 0),
            'slug'       => (string) ($field['s_slug'] ?? ''),
            'name'       => self::name($field, $locale),
            'type'       => strtolower((string) ($field['e_type'] ?? 'TEXT')),
            'required'   => Format::bool($field['b_required'] ?? 0),
            'searchable' => Format::bool($field['b_searchable'] ?? 0),
            'options'    => $options === null ? null : array_values(array_filter(array_map('trim', explode(',', $options)), static fn (string $o): bool => $o !== '')),
        ];
    }

    /**
     * A listing's values, one entry per field. A date interval is stored as two rows
     * (`s_multi` from/to) and comes out as one entry.
     *
     * @param array<int,array<string,mixed>> $rows value rows joined to their field
     *
     * @return array<int,array<string,mixed>>
     */
    public function values(array $rows, string $locale): array
    {
        $byField = [];
        foreach ($rows as $row) {
            $byField[Format::int($row['pk_i_id'] ?? 0)][] = $row;
        }
        $out = [];
        foreach ($byField as $id => $group) {
            $field = $group[0] + $this->meta($id, $group[0]['s_meta'] ?? null);
            $type  = strtolower((string) ($field['e_type'] ?? 'TEXT'));
            $out[] = [
                'id'    => $id,
                'slug'  => (string) ($field['s_slug'] ?? ''),
                'name'  => self::name($field, $locale),
                'type'  => $type,
                'value' => self::value($type, $group),
            ];
        }

        return $out;
    }

    /**
     * A field's decoded s_meta, read once per field however many listings carry it.
     *
     * @return array<string,mixed>
     */
    private function meta(int $id, mixed $json): array
    {
        if (!isset($this->meta[$id])) {
            $meta            = is_string($json) && $json !== '' ? json_decode($json, true) : null;
            $this->meta[$id] = is_array($meta) ? $meta : [];
        }

        return $this->meta[$id];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private static function value(string $type, array $rows): mixed
    {
        $raw = $rows[0]['s_value'] ?? null;

        return match ($type) {
            'checkbox'     => (string) $raw === '1',
            'number'       => is_numeric($raw) ? $raw + 0 : Format::text($raw),
            'date'         => Format::timestamp($raw),
            'dateinterval' => self::interval($rows),
            default        => Format::text($raw),
        };
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array{from:?string,to:?string}|null
     */
    private static function interval(array $rows): ?array
    {
        $ends = ['from' => null, 'to' => null];
        foreach ($rows as $row) {
            $multi = (string) ($row['s_multi'] ?? '');
            if (array_key_exists($multi, $ends)) {
                $ends[$multi] = Format::timestamp($row['s_value'] ?? null);
            }
        }

        return $ends['from'] === null && $ends['to'] === null ? null : $ends;
    }

    /**
     * @param array<string,mixed> $field
     */
    private static function name(array $field, string $locale): string
    {
        $name = $field['locale'][$locale]['s_name'] ?? null;

        return Format::plain(is_string($name) && $name !== '' ? $name : ($field['s_name'] ?? ''));
    }
}
