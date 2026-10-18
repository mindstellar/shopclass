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

namespace mindstellar\widgets;

/**
 * The one sanitiser for a typed widget's config: the widgets screen and the customiser both
 * build a row's s_config through it.
 */
final class WidgetConfig
{
    /** Config key that hides a row without deleting it; osc_render_widget() skips it when false. */
    public const ENABLED = '_enabled';

    private function __construct()
    {
    }

    /**
     * Keep only the keys the type declares in 'fields', each sanitised for its field type.
     *
     * @param array<string,mixed> $type      a registered widget-type spec
     * @param array<mixed>        $posted    posted values, already purified by Params
     * @param array<mixed>        $postedRaw the same values unpurified, read only by 'image'
     *                                       fields and by 'code' fields of a super_admin type
     *
     * @return array<string,mixed> config keyed by field name
     */
    public static function fromPosted(array $type, array $posted, array $postedRaw): array
    {
        // A 'code' field on any type a moderator can reach degrades to purified text.
        $isSuperAdmin = ($type['capability'] ?? 'admin') === 'super_admin';

        $config = array();
        foreach ((array) ($type['fields'] ?? array()) as $field) {
            if (!is_array($field) || empty($field['name'])) {
                continue;
            }
            $name      = (string) $field['name'];
            $fieldType = $field['type'] ?? 'text';
            $default   = $field['default'] ?? null;
            $hasValue  = array_key_exists($name, $posted);
            $raw       = $hasValue ? $posted[$name] : null;

            switch ($fieldType) {
                case 'number':
                    $config[$name] = $hasValue ? (int) $raw : (int) $default;
                    break;
                case 'checkbox':
                    $config[$name] = ($hasValue && $raw !== '' && $raw !== '0') ? 1 : 0;
                    break;
                case 'select':
                    // Options may be a callable for a dynamic list, resolved before checking.
                    $allowed       = self::selectAllowedValues($field['options'] ?? array());
                    $value         = $hasValue && is_scalar($raw) ? (string) $raw : (string) $default;
                    $config[$name] = in_array($value, $allowed, true) ? $value : (string) $default;
                    break;
                case 'image':
                    // Read unpurified so a query string survives, but keep only an http(s) URL.
                    $hasRaw        = array_key_exists($name, $postedRaw);
                    $imageUrl      = $hasRaw && is_string($postedRaw[$name]) ? trim($postedRaw[$name]) : (string) $default;
                    $config[$name] = ($imageUrl !== '' && filter_var($imageUrl, FILTER_VALIDATE_URL)
                        && preg_match('#^https?://#i', $imageUrl)) ? $imageUrl : '';
                    break;
                case 'code':
                    if ($isSuperAdmin) {
                        $hasRaw        = array_key_exists($name, $postedRaw);
                        $rawValue      = $hasRaw ? $postedRaw[$name] : null;
                        $config[$name] = $hasRaw && is_string($rawValue) ? $rawValue : (string) $default;
                    } else {
                        $config[$name] = $hasValue && is_string($raw) ? $raw : (string) $default;
                    }
                    break;
                case 'textarea':
                case 'text':
                default:
                    $config[$name] = $hasValue && is_string($raw) ? $raw : (string) $default;
                    break;
            }
        }

        return $config;
    }

    /**
     * Whether a stored config is shown: only an explicit false hides it.
     *
     * @param array<string,mixed> $config
     */
    public static function enabled(array $config): bool
    {
        return !array_key_exists(self::ENABLED, $config) || $config[self::ENABLED] !== false;
    }

    /**
     * Allowed values of a select field: a value => label map, a flat list, or a list of
     * array('value' => …, 'label' => …) entries, or a callable returning one of those.
     *
     * @param mixed $options
     *
     * @return string[]
     */
    public static function selectAllowedValues($options): array
    {
        if (is_callable($options)) {
            $options = call_user_func($options);
        }
        $allowed = array();
        if (!is_array($options)) {
            return $allowed;
        }
        foreach ($options as $key => $option) {
            if (is_array($option)) {
                if (array_key_exists('value', $option) && is_scalar($option['value'])) {
                    $allowed[] = (string) $option['value'];
                }
                continue;
            }
            if (!is_int($key)) {
                $allowed[] = (string) $key;
            } elseif (is_scalar($option)) {
                $allowed[] = (string) $option;
            }
        }

        return $allowed;
    }
}
