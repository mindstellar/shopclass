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

use mindstellar\utility\Sanitize;
use mindstellar\utility\Utils;

/**
 * The one sanitiser for a typed widget's config: the widgets screen and the customiser both
 * build a row's s_config through it.
 */
final class WidgetConfig
{
    /** Config key that hides a row without deleting it; osc_render_widget() skips it when false. */
    public const ENABLED = '_enabled';

    /** Field types that may be declared with 'translate' => true. */
    private const TRANSLATED_TYPES = array('text', 'textarea', 'richtext');

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

            // A translated field stores one text per locale under its name plus the locale code,
            // and its plain name keeps the default language's text for readers that know no locale.
            $locales = self::locales($field);
            if ($locales !== array()) {
                $site = function_exists('osc_language') ? osc_language() : '';
                foreach (array_keys($locales) as $code) {
                    $key = $name . $code;
                    if (array_key_exists($key, $posted) || array_key_exists($key, $postedRaw)) {
                        $config[$key] = self::textValue($fieldType, $posted[$key] ?? null, $postedRaw[$key] ?? null, '');
                    }
                }
                $single        = $config[$name . $site] ?? '';
                $config[$name] = trim($single) !== ''
                    ? $single
                    : self::textValue($fieldType, $posted[$name] ?? null, $postedRaw[$name] ?? null, (string) $default);
                continue;
            }
            $hasValue  = array_key_exists($name, $posted);
            $raw       = $hasValue ? $posted[$name] : null;

            switch ($fieldType) {
                case 'note':
                    // Help text in the form only; nothing to store.
                    break;
                case 'number':
                    $config[$name] = $hasValue ? (int) $raw : (int) $default;
                    break;
                case 'checkbox':
                    $config[$name] = ($hasValue && $raw !== '' && $raw !== '0') ? 1 : 0;
                    break;
                case 'select':
                case 'choice':
                    // Options may be a callable for a dynamic list, resolved before checking.
                    $allowed       = self::selectAllowedValues($field['options'] ?? array());
                    $value         = $hasValue && is_scalar($raw) ? (string) $raw : (string) $default;
                    $config[$name] = in_array($value, $allowed, true) ? $value : (string) $default;
                    break;
                case 'link':
                    // Read unpurified so a query string survives; a web, mail or site address only.
                    $hasRaw        = array_key_exists($name, $postedRaw);
                    $config[$name] = self::linkValue($hasRaw && is_string($postedRaw[$name]) ? $postedRaw[$name] : (string) $default);
                    break;
                case 'image':
                    // Read unpurified so a query string survives; keep an http(s) URL or a site path.
                    $hasRaw        = array_key_exists($name, $postedRaw);
                    $imageUrl      = $hasRaw && is_string($postedRaw[$name]) ? trim($postedRaw[$name]) : (string) $default;
                    $config[$name] = self::imageValue($imageUrl);
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
                default:
                    $config[$name] = self::textValue(
                        $fieldType,
                        $raw,
                        array_key_exists($name, $postedRaw) ? $postedRaw[$name] : null,
                        (string) $default
                    );
                    break;
            }
        }

        return $config;
    }

    /**
     * The locales a translated field is edited in, code => name; none for any other field.
     *
     * @param array<string,mixed> $field
     *
     * @return array<string,string>
     */
    public static function locales(array $field): array
    {
        if (empty($field['translate']) || !in_array($field['type'] ?? 'text', self::TRANSLATED_TYPES, true)
            || !function_exists('osc_settings_field_locales')
        ) {
            return array();
        }

        return osc_settings_field_locales($field);
    }

    /**
     * A config with each translated field's plain name set to the text of $locale, else the
     * default language's, else the single value stored before the field was translated.
     *
     * @param array<string,mixed> $type   a registered widget-type spec
     * @param array<string,mixed> $config a stored config
     * @param string|null         $locale locale code; the language of the page being drawn when null
     *
     * @return array<string,mixed>
     */
    public static function resolve(array $type, array $config, ?string $locale = null): array
    {
        foreach (self::translatedNames($type) as $name) {
            $config[$name] = _osc_locale_text(
                static fn (string $code) => $config[$name . $code] ?? null,
                $locale
            );
        }

        return $config;
    }

    /**
     * A stored config as the editors show it: a translated field saved as one value opens with
     * that value in the default language, and its plain name is left out so it is not posted back.
     *
     * @param array<string,mixed> $type   a registered widget-type spec
     * @param array<string,mixed> $config a stored config
     *
     * @return array<string,mixed>
     */
    public static function forEditor(array $type, array $config): array
    {
        $site = function_exists('osc_language') ? osc_language() : '';
        foreach (self::translatedNames($type) as $name) {
            if ($site !== '' && !array_key_exists($name . $site, $config) && array_key_exists($name, $config)) {
                $config[$name . $site] = $config[$name];
            }
            unset($config[$name]);
        }

        return $config;
    }

    /**
     * Names of the type's translated fields.
     *
     * @param array<string,mixed> $type
     *
     * @return string[]
     */
    private static function translatedNames(array $type): array
    {
        $names = array();
        foreach ((array) ($type['fields'] ?? array()) as $field) {
            if (is_array($field) && !empty($field['name']) && self::locales($field) !== array()) {
                $names[] = (string) $field['name'];
            }
        }

        return $names;
    }

    /**
     * A text, textarea or richtext value as stored. Richtext reads the unpurified value and keeps
     * the markup the page editor allows; the others keep the purified value.
     *
     * @param mixed $purified
     * @param mixed $raw
     */
    private static function textValue(string $fieldType, $purified, $raw, string $default): string
    {
        if ($fieldType === 'richtext') {
            return (string) (new Sanitize())->richHtml(is_string($raw) ? $raw : $default);
        }

        return is_string($purified) ? $purified : $default;
    }

    /**
     * Whether a value is a path on this site; see Utils::isSitePath().
     */
    public static function isSitePath(string $value): bool
    {
        return Utils::isSitePath($value);
    }

    /**
     * An address under this site's base URL as a site path; see Utils::toSitePath().
     */
    public static function toSitePath(string $value): string
    {
        return Utils::toSitePath($value);
    }

    /**
     * A site path as an absolute address on this site's origin; see Utils::toAbsolute().
     */
    public static function toAbsolute(string $value): string
    {
        return Utils::toAbsolute($value);
    }

    /**
     * What an image field stores: a site path (own-site addresses are shortened), another
     * http(s) URL, or '' for anything else.
     */
    public static function imageValue(string $value): string
    {
        $value = trim($value);
        if (self::isSitePath($value)) {
            return $value;
        }
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $value)) {
            return self::toSitePath($value);
        }

        return '';
    }

    /**
     * A link a block may point to: what imageValue() keeps, or a mailto: address. Anything else is ''.
     */
    public static function linkValue(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^mailto:[^\s<>"]+@[^\s<>"]+$/i', $value)) {
            return $value;
        }

        return self::imageValue($value);
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
