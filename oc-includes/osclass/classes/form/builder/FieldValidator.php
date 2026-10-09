<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\form\builder;

use mindstellar\utility\Sanitize;
use mindstellar\utility\Validate;

/**
 * Server-authoritative validation + sanitisation for custom-field values, shared by
 * the form builder and the listing form.
 *
 * It sanitises per field type, re-evaluates the conditional rules (a field hidden by
 * its show_when rule is dropped and never required; required_when overrides the static
 * flag), runs the field-type registry's validators (e.g. EMAIL format) and the per-type
 * format checks, and validates cascading-option membership. The client engine is UX
 * only; this is the authority.
 *
 * @package mindstellar\form\builder
 */
final class FieldValidator
{
    /**
     * Sanitise and validate a whole submission, dropping fields hidden by their rules.
     *
     * @param array<int,array<string,mixed>> $fields resolved + extended field rows (Field::findByGroup shape)
     * @param array<int|string,mixed>        $meta   raw posted values keyed by field id
     *
     * @return array{values: array<int,mixed>, errors: string[]}
     */
    public static function process(array $fields, array $meta): array
    {
        $clean = array();
        foreach ($fields as $f) {
            $clean[(int) $f['pk_i_id']] = self::sanitizeValue($f['e_type'], $meta[$f['pk_i_id']] ?? null);
        }
        $result = self::check($fields, $clean, $meta);

        $values = array();
        foreach ($fields as $f) {
            $id = (int) $f['pk_i_id'];
            if (array_key_exists($id, $result['values']) && self::hasValue($f['e_type'], $result['values'][$id])) {
                $values[$id] = $result['values'][$id];
            }
        }

        return array('values' => $values, 'errors' => array_column($result['errors'], 'message'));
    }

    /**
     * Validate sanitised values against their fields.
     *
     * @param array<int,array<string,mixed>> $fields     resolved field rows
     * @param array<int|string,mixed>        $values     sanitised values keyed by field id
     * @param array<int|string,mixed>|null   $ruleValues values the rules and cascades read, keyed by field id; $values when null
     *
     * @return array{values: array<int|string,mixed>, errors: array<int,array{field:int,code:string,message:string}>}
     *         $values without the fields hidden by their rules, and one error at most per field
     */
    public static function check(array $fields, array $values, ?array $ruleValues = null): array
    {
        $ruleValues ??= $values;
        $slugValues   = array();
        foreach ($fields as $f) {
            $slugValues[$f['s_slug']] = $ruleValues[$f['pk_i_id']] ?? null;
        }

        $errors = array();
        foreach ($fields as $f) {
            $id    = (int) $f['pk_i_id'];
            $rules = (isset($f['rules']) && is_array($f['rules'])) ? $f['rules'] : array();

            if (isset($rules['show_when']) && !self::evaluateCondition($rules['show_when'], $slugValues)) {
                unset($values[$id]);
                continue;
            }
            $required = !empty($f['b_required']);
            if (isset($rules['required_when'])) {
                $required = self::evaluateCondition($rules['required_when'], $slugValues);
            }

            $error = self::validateField($f, $values[$id] ?? null, $required, $slugValues);
            if ($error !== null) {
                $errors[] = array('field' => $id) + $error;
            }
        }

        return array('values' => $values, 'errors' => $errors);
    }

    /**
     * Evaluate one conditional-logic condition against the submitted values (keyed
     * by controlling field slug). Same semantics as the client engine and the item
     * form's server re-evaluation.
     *
     * @param mixed               $cond       Condition array, or anything else to mean "no condition"
     * @param array<string,mixed> $slugValues Submitted values keyed by field slug
     *
     * @return bool
     */
    public static function evaluateCondition($cond, array $slugValues): bool
    {
        if (!is_array($cond) || empty($cond['field'])) {
            return true;
        }
        $actual = $slugValues[$cond['field']] ?? '';
        if (is_array($actual)) {
            $actual = implode('', array_map('strval', $actual));
        }
        $expected = isset($cond['value']) ? (string) $cond['value'] : '';
        switch ($cond['op'] ?? 'eq') {
            case 'neq':
                return (string) $actual !== $expected;
            case 'filled':
                return trim((string) $actual) !== '';
            case 'gt':
                return is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected;
            case 'lt':
                return is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected;
            case 'eq':
            default:
                return (string) $actual === $expected;
        }
    }

    /**
     * Sanitise a raw posted value by its storage primitive. A date range end posted empty
     * stays '', so an edit clears it.
     *
     * @param string $eType
     * @param mixed  $value
     *
     * @return mixed
     */
    public static function sanitizeValue($eType, $value)
    {
        switch ($eType) {
            case 'DATEINTERVAL':
                $out = array();
                foreach (array('from', 'to') as $end) {
                    if (is_array($value) && isset($value[$end]) && is_scalar($value[$end])) {
                        $out[$end] = $value[$end] === '' ? '' : (int) $value[$end];
                    }
                }

                return $out;
            case 'DATE':
                return ($value === '' || $value === null) ? '' : (int) $value;
            case 'CHECKBOX':
                return (int) $value;
            case 'URL':
                return (new Sanitize())->websiteUrl((string) (is_scalar($value) ? $value : ''));
            default:
                return (new Sanitize())->html(is_scalar($value) ? (string) $value : '');
        }
    }

    /**
     * Whether a sanitised value should be stored.
     *
     * @param string $eType
     * @param mixed  $value
     *
     * @return bool
     */
    private static function hasValue($eType, $value): bool
    {
        if ($eType === 'DATEINTERVAL') {
            return is_array($value) && (!empty($value['from']) || !empty($value['to']));
        }
        if ($eType === 'CHECKBOX') {
            return true; // always record 0/1
        }

        return $value !== '' && $value !== null;
    }

    /**
     * Per-field validation: the field-type registry validator, then the storage primitive's checks.
     *
     * @param array<string,mixed> $f          One resolved field row
     * @param mixed               $value      The sanitised value
     * @param bool                $required
     * @param array<string,mixed> $slugValues Submitted values keyed by field slug
     *
     * @return array{code:string,message:string}|null
     */
    private static function validateField(array $f, $value, bool $required, array $slugValues): ?array
    {
        $name    = $f['s_name'];
        $set     = !(($value === '' || $value === null) || (is_array($value) && empty($value)));
        $invalid = array('code' => 'invalid', 'message' => sprintf(_m('%s is invalid.'), $name));
        $missing = $required ? array('code' => 'required', 'message' => sprintf(_m('%s is required.'), $name)) : null;

        // Registry-defined validators (e.g. EMAIL format) run first on scalar values.
        if ($set && !is_array($value)) {
            $typeSpec = osc_field_type(osc_field_resolve_type($f));
            if ($typeSpec !== null && is_callable($typeSpec['validate'])) {
                $typeError = call_user_func($typeSpec['validate'], $value, $f);
                if (is_string($typeError) && $typeError !== '') {
                    return array('code' => 'invalid', 'message' => $typeError);
                }
            }
        }

        switch ($f['e_type']) {
            case 'DATEINTERVAL':
                if ($set && !empty($value['from']) && !empty($value['to'])) {
                    return is_numeric($value['from']) && is_numeric($value['to']) ? null : $invalid;
                }

                return $missing;
            case 'CHECKBOX':
            case 'NUMBER':
            case 'DATE':
                if ($set && $value > 0) {
                    return is_numeric($value) ? null : $invalid;
                }

                return $missing;
            case 'RADIO':
            case 'DROPDOWN':
                if (!$set) {
                    return $missing;
                }
                if (!empty($f['cascade_map']) && is_array($f['cascade_map'])) {
                    $parentValue = $slugValues[$f['cascade_parent'] ?? ''] ?? '';
                    if (is_scalar($parentValue) && isset($f['cascade_map'][$parentValue])) {
                        $allowed = (array) $f['cascade_map'][$parentValue];
                    } else {
                        $allowed = array();
                        foreach ($f['cascade_map'] as $opts) {
                            $allowed = array_merge($allowed, (array) $opts);
                        }
                    }
                } else {
                    $allowed = explode(',', (string) ($f['s_options'] ?? ''));
                }

                return in_array($value, $allowed, false) ? null : $invalid;
            case 'URL':
                if ($set) {
                    return Validate::httpUrl($value) ? null : $invalid;
                }

                return $missing;
            default:
                return $set ? null : $missing;
        }
    }
}
