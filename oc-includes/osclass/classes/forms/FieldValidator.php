<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\forms;

use mindstellar\utility\Sanitize;

/**
 * Server-authoritative validation + sanitisation for a form submission.
 *
 * Given a form's field definitions and the raw posted meta values, it sanitises
 * per field type, re-evaluates the conditional rules (a field hidden by its
 * show_when rule is dropped and never required; required_when overrides the static
 * flag), runs the field-type registry's validators (e.g. EMAIL format) and the
 * per-type format checks, validates cascading-option membership, and returns the
 * clean values plus any error messages.
 *
 * It deliberately mirrors the item form's validation (ItemActions) rather than
 * sharing its private methods, so the item write path — the compatibility contract
 * — is untouched. The client engine is UX only; this is the authority.
 *
 * @package mindstellar\forms
 */
final class FieldValidator
{
    /**
     * @param array $fields resolved + extended field rows (Field::findByGroup shape)
     * @param array $meta   raw posted values keyed by field id
     *
     * @return array{values: array<int,mixed>, errors: string[]}
     */
    public static function process(array $fields, array $meta): array
    {
        $sanitize = new Sanitize();

        // slug => raw value, for evaluating rules that reference a sibling field.
        $slugValues = array();
        foreach ($fields as $f) {
            $slugValues[$f['s_slug']] = $meta[$f['pk_i_id']] ?? null;
        }

        $values = array();
        $errors = array();

        foreach ($fields as $f) {
            $id    = (int) $f['pk_i_id'];
            $eType = $f['e_type'];
            $rules = (isset($f['rules']) && is_array($f['rules'])) ? $f['rules'] : array();

            // Hidden by its show_when rule: not part of this submission.
            if (isset($rules['show_when']) && !self::evaluateCondition($rules['show_when'], $slugValues)) {
                continue;
            }
            $required = !empty($f['b_required']);
            if (isset($rules['required_when'])) {
                $required = self::evaluateCondition($rules['required_when'], $slugValues);
            }

            $value = self::sanitize($eType, $meta[$id] ?? null, $sanitize);

            $error = self::validateField($f, $value, $required, $slugValues);
            if ($error !== null) {
                $errors[] = $error;
            }

            if (self::hasValue($eType, $value)) {
                $values[$id] = $value;
            }
        }

        return array('values' => $values, 'errors' => $errors);
    }

    /**
     * Evaluate one conditional-logic condition against the submitted values (keyed
     * by controlling field slug). Same semantics as the client engine and the item
     * form's server re-evaluation.
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
     * Sanitise a raw posted value by its storage primitive.
     *
     * @param string $eType
     * @param mixed  $value
     *
     * @return mixed
     */
    private static function sanitize($eType, $value, Sanitize $sanitize)
    {
        switch ($eType) {
            case 'DATEINTERVAL':
                $out = array();
                if (is_array($value)) {
                    if (isset($value['from']) && $value['from'] !== '') {
                        $out['from'] = (int) $value['from'];
                    }
                    if (isset($value['to']) && $value['to'] !== '') {
                        $out['to'] = (int) $value['to'];
                    }
                }

                return $out;
            case 'DATE':
                return ($value === '' || $value === null) ? '' : (int) $value;
            case 'CHECKBOX':
                return (int) $value;
            case 'URL':
                return $sanitize->websiteUrl((string) (is_scalar($value) ? $value : ''));
            default:
                return $sanitize->html(is_scalar($value) ? (string) $value : '');
        }
    }

    /**
     * Whether a sanitised value should be stored.
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
     * Per-field validation. Returns an error message or null. Mirrors
     * ItemActions::validateMetaFields plus the field-type registry validators.
     *
     * @return string|null
     */
    private static function validateField(array $f, $value, bool $required, array $slugValues): ?string
    {
        $name  = $f['s_name'];
        $eType = $f['e_type'];
        $set   = !(($value === '' || $value === null) || (is_array($value) && empty($value)));

        // Registry-defined validators (e.g. EMAIL format) run first on scalar values.
        if ($set && !is_array($value)) {
            $typeSpec = osc_field_type(osc_field_resolve_type($f));
            if ($typeSpec !== null && is_callable($typeSpec['validate'])) {
                $typeError = call_user_func($typeSpec['validate'], $value, $f);
                if (is_string($typeError) && $typeError !== '') {
                    return $typeError;
                }
            }
        }

        switch ($eType) {
            case 'DATEINTERVAL':
                if ($set) {
                    if (!empty($value['from']) && !empty($value['to'])) {
                        if (!is_numeric($value['from']) || !is_numeric($value['to'])) {
                            return sprintf(__('%s is invalid.'), $name);
                        }
                    } elseif ($required) {
                        return sprintf(__('%s is required.'), $name);
                    }
                } elseif ($required) {
                    return sprintf(__('%s is required.'), $name);
                }
                break;
            case 'CHECKBOX':
            case 'NUMBER':
            case 'DATE':
                if ($set && $value > 0) {
                    if (!is_numeric($value)) {
                        return sprintf(__('%s is invalid.'), $name);
                    }
                } elseif ($required) {
                    return sprintf(__('%s is required.'), $name);
                }
                break;
            case 'RADIO':
            case 'DROPDOWN':
                if ($set) {
                    if (!empty($f['cascade_map']) && is_array($f['cascade_map'])) {
                        $parentValue = $slugValues[$f['cascade_parent'] ?? ''] ?? '';
                        if (isset($f['cascade_map'][$parentValue])) {
                            $allowed = $f['cascade_map'][$parentValue];
                        } else {
                            $allowed = array();
                            foreach ($f['cascade_map'] as $opts) {
                                $allowed = array_merge($allowed, (array) $opts);
                            }
                        }
                        if (!in_array($value, $allowed, false)) {
                            return sprintf(__('%s is invalid.'), $name);
                        }
                    } elseif (!in_array($value, explode(',', (string) ($f['s_options'] ?? '')), false)) {
                        return sprintf(__('%s is invalid.'), $name);
                    }
                } elseif ($required) {
                    return sprintf(__('%s is required.'), $name);
                }
                break;
            case 'URL':
                if ($set) {
                    if (!filter_var($value, FILTER_VALIDATE_URL) || !osc_validate_url($value)) {
                        return sprintf(__('%s is invalid.'), $name);
                    }
                } elseif ($required) {
                    return sprintf(__('%s is required.'), $name);
                }
                break;
            case 'TEXTAREA':
            case 'TEXT':
            default:
                if ($required && !$set) {
                    return sprintf(__('%s is required.'), $name);
                }
                break;
        }

        return null;
    }
}
