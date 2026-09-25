<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\search;

/**
 * The stored form of a saved search: the search values, never SQL.
 *
 * `{"v":2,"params":{...}}` with the params keys sorted, empty values dropped and every
 * value held to its shape. The string is canonical, because t_alerts.s_search is also
 * the key alerts are grouped and de-duplicated on: the same search must always produce
 * the same bytes.
 */
class AlertEnvelope
{
    public const VERSION = 2;

    /** Longest envelope accepted, in bytes. */
    public const MAX_BYTES = 8192;

    /** Longest single custom-field or plugin value, in bytes. */
    public const MAX_VALUE_BYTES = 255;

    /** Most entries in one plugin list. */
    public const MAX_LIST_ITEMS = 100;

    /** Core search keys, as the search page reads them. */
    public const CORE_KEYS = array(
        'bPic', 'bPremium', 'meta', 'sCategory', 'sCity', 'sCityArea', 'sCountry',
        'sLocale', 'sPattern', 'sPriceMax', 'sPriceMin', 'sRegion', 'sUser',
    );

    /** Keys of the old SQL-fragment format; never valid in an envelope. */
    public const LEGACY_KEYS = array(
        'aCategories', 'cities', 'city_areas', 'countries', 'limit_init', 'no_catched_conditions',
        'no_catched_tables', 'onlyPremium', 'order_column', 'order_direction', 'price_max', 'price_min',
        'regions', 'results_per_page', 'tables', 'tables_join', 'user_ids', 'withPattern', 'withPicture',
    );

    /**
     * The envelope for the search a request asked for.
     *
     * @param SearchCriteria      $criteria the page's criteria
     * @param array<string,mixed> $request  the request bag, handed to `alert_search_params`
     *
     * @return string
     */
    public static function build(SearchCriteria $criteria, array $request): string
    {
        $params = array(
            // With a custom-field filter the full list is kept: which fields are searchable
            // depends on every chosen category, not only the roots.
            'sCategory' => self::categoryIds($criteria->categories(), self::meta($criteria->meta()) === array()),
            'sCityArea' => $criteria->cityAreas(),
            'sCity'     => $criteria->cities(),
            'sRegion'   => $criteria->regions(),
            'sCountry'  => $criteria->countries(),
            'sUser'     => $criteria->users(),
            'sLocale'   => $criteria->locale(),
            'sPattern'  => $criteria->rawPattern(),
            'bPic'      => $criteria->withPicture(),
            'bPremium'  => $criteria->onlyPremium(),
            'sPriceMin' => $criteria->priceMin(),
            'sPriceMax' => $criteria->priceMax(),
            'meta'      => $criteria->meta(),
        );

        return self::encode(self::normalise($params, $request));
    }

    /**
     * The params of a stored envelope, or null when it is not a well-formed v2 envelope.
     *
     * Well-formed means canonical: normalising the params again gives the same bytes. So
     * an unknown key, a wrong shape, an unsorted list or an empty value all fail.
     *
     * @param string $json
     *
     * @return array<string,mixed>|null
     */
    public static function validate(string $json): ?array
    {
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || array_keys($data) !== array('v', 'params')
            || $data['v'] !== self::VERSION || !is_array($data['params'])
        ) {
            return null;
        }
        $params = $data['params'];
        foreach (array_keys($params) as $key) {
            if (in_array($key, self::LEGACY_KEYS, true)) {
                return null;
            }
        }

        return self::encode(self::normalise($params, $params)) === $json ? $params : null;
    }

    /**
     * The envelope inside a subscribe token (base64 of osc_encrypt_alert()), or null when
     * the token does not decrypt or does not hold a valid envelope.
     *
     * @param string $encoded
     *
     * @return string|null
     */
    public static function fromToken(string $encoded): ?string
    {
        $plain = (string)osc_decrypt_alert(base64_decode($encoded));

        return $plain !== '' && self::validate($plain) !== null ? $plain : null;
    }

    /**
     * validate() for an envelope that is already decoded. Looser than validate(): it
     * re-encodes, so it accepts non-canonical input; never use it as a gate on stored rows.
     *
     * @param mixed $data
     *
     * @return array<string,mixed>|null
     */
    public static function validateDecoded($data): ?array
    {
        if (!is_array($data) || array_keys($data) !== array('v', 'params')
            || $data['v'] !== self::VERSION || !is_array($data['params'])
        ) {
            return null;
        }

        return self::validate(self::encode($data['params']));
    }

    /**
     * Whether decoded s_search data is a v2 envelope (valid or not) rather than a v1 blob.
     *
     * @param mixed $data
     *
     * @return bool
     */
    public static function isEnvelope($data): bool
    {
        return is_array($data) && array_key_exists('v', $data);
    }

    /**
     * The canonical string for a params array. Callers pass normalised params.
     *
     * @param array<string,mixed> $params
     *
     * @return string '' when the params cannot be encoded
     */
    public static function encode(array $params): string
    {
        $json = json_encode(
            array('v' => self::VERSION, 'params' => (object)self::sortKeys($params)),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return $json === false ? '' : $json;
    }

    /**
     * The fields the old stored format carried, for code that reads a stored alert to
     * show it: `sPattern`, `aCategories` (ids), `city_areas`/`cities`/`regions`/`countries`
     * (names) and `price_min`/`price_max` (currency units), plus `withPicture`/
     * `onlyPremium` when set.
     *
     * @param array<string,mixed> $params validated params
     *
     * @return array<string,mixed>
     */
    public static function legacyFields(array $params): array
    {
        $names = static function (array $values, callable $lookup): array {
            $out = array();
            foreach ($values as $value) {
                $name  = $lookup($value);
                $out[] = is_string($name) && $name !== '' ? $name : (string)$value;
            }

            return $out;
        };
        $byId = static function ($model): callable {
            return static function ($value) use ($model) {
                if (!is_int($value)) {
                    return null;
                }
                $row = $model::newInstance()->findByPrimaryKey($value);

                return is_array($row) ? ($row['s_name'] ?? null) : null;
            };
        };

        $fields = array(
            'sPattern'    => (string)($params['sPattern'] ?? ''),
            'aCategories' => $params['sCategory'] ?? array(),
            'city_areas'  => $names($params['sCityArea'] ?? array(), $byId(\CityArea::class)),
            'cities'      => $names($params['sCity'] ?? array(), $byId(\City::class)),
            'regions'     => $names($params['sRegion'] ?? array(), $byId(\Region::class)),
            'countries'   => $names($params['sCountry'] ?? array(), static function ($value) {
                if (!is_string($value) || strlen($value) !== 2) {
                    return null;
                }
                $row = \Country::newInstance()->findByCode($value);

                return is_array($row) ? ($row['s_name'] ?? null) : null;
            }),
            'price_min'   => $params['sPriceMin'] ?? 0,
            'price_max'   => $params['sPriceMax'] ?? 0,
        );
        if (!empty($params['bPic'])) {
            $fields['withPicture'] = true;
        }
        if (!empty($params['bPremium'])) {
            $fields['onlyPremium'] = true;
        }

        return $fields;
    }

    /**
     * Category ids for the sCategory values a request carried (ids or slug paths), sorted
     * and unique. With $roots, reduced to the chosen roots: an id whose ancestor is also
     * chosen is dropped, since the ancestor's subtree already holds it. An unknown slug is
     * dropped, as the search page drops it; an unknown id is kept, as the page keeps it.
     *
     * @param array<int,mixed> $values
     * @param bool             $roots
     *
     * @return array<int,int>
     */
    public static function categoryIds(array $values, bool $roots = true): array
    {
        $ids = array();
        foreach ($values as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $id = (int)$value;
            } elseif (is_string($value) && trim($value, " /") !== '') {
                $path = explode('/', trim($value, " /"));
                $row  = \Category::newInstance()->findBySlug(end($path));
                $id   = is_array($row) ? (int)($row['pk_i_id'] ?? 0) : 0;
            } else {
                continue;
            }
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if (!$roots) {
            sort($ids);

            return array_values($ids);
        }

        $kept = array();
        foreach ($ids as $id) {
            $covered = false;
            $row     = \Category::newInstance()->findByPrimaryKey($id);
            // Bounded, so a parent loop in bad data cannot hang the page.
            for ($depth = 0; $depth < 32 && is_array($row) && !empty($row['fk_i_parent_id']); $depth++) {
                $parentId = (int)$row['fk_i_parent_id'];
                if (isset($ids[$parentId])) {
                    $covered = true;
                    break;
                }
                $row = \Category::newInstance()->findByPrimaryKey($parentId);
            }
            if (!$covered) {
                $kept[] = $id;
            }
        }
        sort($kept);

        return $kept;
    }

    /**
     * Hold every core key to its shape, drop empties, then add the plugin keys.
     *
     * @param array<string,mixed> $values  core values, loosely shaped
     * @param array<string,mixed> $request passed to `alert_search_params`
     *
     * @return array<string,mixed>
     */
    private static function normalise(array $values, array $request): array
    {
        $params = array(
            'sCategory' => self::idList($values['sCategory'] ?? array()),
            'sCityArea' => self::valueList($values['sCityArea'] ?? array()),
            'sCity'     => self::valueList($values['sCity'] ?? array()),
            'sRegion'   => self::valueList($values['sRegion'] ?? array()),
            'sCountry'  => self::valueList($values['sCountry'] ?? array()),
            'sUser'     => self::valueList($values['sUser'] ?? array()),
            'sLocale'   => self::localeList($values['sLocale'] ?? array()),
            'sPattern'  => is_string($values['sPattern'] ?? null) ? trim($values['sPattern']) : '',
            'bPic'      => self::flag($values['bPic'] ?? null),
            'bPremium'  => self::flag($values['bPremium'] ?? null),
            'sPriceMin' => self::price($values['sPriceMin'] ?? null),
            'sPriceMax' => self::price($values['sPriceMax'] ?? null),
            'meta'      => self::meta($values['meta'] ?? array()),
        );
        $params = array_filter($params, static fn ($v) => $v !== '' && $v !== array() && $v !== null);

        $out   = $params;
        $extra = osc_apply_filter('alert_search_params', $params, $request);
        if (is_array($extra)) {
            foreach ($extra as $key => $value) {
                if (!is_string($key) || isset($out[$key]) || !self::isPluginKey($key)) {
                    continue;
                }
                $value = self::pluginValue($value);
                if ($value !== null) {
                    $out[$key] = $value;
                }
            }
        }

        return self::sortKeys($out);
    }

    /**
     * @param mixed $values
     *
     * @return array<int,int>
     */
    private static function idList($values): array
    {
        $ids = array();
        foreach ((array)$values as $v) {
            if (is_int($v) && $v > 0) {
                $ids[$v] = $v;
            }
        }
        sort($ids);

        return array_values($ids);
    }

    /**
     * Location and user values: a digit string becomes an id, anything else a trimmed
     * name; unique and sorted.
     *
     * @param mixed $values
     *
     * @return array<int,int|string>
     */
    private static function valueList($values): array
    {
        $out = array();
        foreach (is_array($values) ? $values : array($values) as $v) {
            if (is_int($v)) {
                $v = (string)$v;
            }
            if (!is_string($v)) {
                continue;
            }
            $v = trim($v);
            if ($v === '' || strlen($v) > self::MAX_VALUE_BYTES) {
                continue;
            }
            $out[$v] = ctype_digit($v) ? (int)$v : $v;
        }
        ksort($out, SORT_STRING);

        return array_values($out);
    }

    /**
     * @param mixed $values
     *
     * @return array<int,string>
     */
    private static function localeList($values): array
    {
        $out = array();
        foreach (is_array($values) ? $values : array($values) as $v) {
            if (is_string($v) && preg_match('/^[A-Za-z]{2,3}_[A-Za-z]{2}$/', $v)) {
                $out[$v] = $v;
            }
        }
        ksort($out, SORT_STRING);

        return array_values($out);
    }

    /**
     * @param mixed $value
     *
     * @return int|null 1 when set, null to drop it
     */
    private static function flag($value): ?int
    {
        return ($value === true || $value === 1 || $value === '1') ? 1 : null;
    }

    /**
     * A price bound as the search applies it: a whole number, 0 meaning none.
     *
     * @param mixed $value
     *
     * @return int|null
     */
    private static function price($value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $price = (int)$value;

        return $price !== 0 ? $price : null;
    }

    /**
     * Custom-field values keyed by field id: a scalar, or a from/to pair.
     *
     * @param mixed $meta
     *
     * @return array<int,string|array<string,string>>
     */
    private static function meta($meta): array
    {
        $out = array();
        if (!is_array($meta)) {
            return $out;
        }
        foreach ($meta as $key => $value) {
            if (!is_int($key) && !(is_string($key) && ctype_digit($key))) {
                continue;
            }
            $key = (int)$key;
            if ($key <= 0) {
                continue;
            }
            if (is_array($value)) {
                $range = array();
                foreach (array('from', 'to') as $end) {
                    $v = self::metaScalar($value[$end] ?? null);
                    if ($v !== null) {
                        $range[$end] = $v;
                    }
                }
                if ($range !== array()) {
                    $out[$key] = $range;
                }
            } else {
                $v = self::metaScalar($value);
                if ($v !== null) {
                    $out[$key] = $v;
                }
            }
        }

        return $out;
    }

    /**
     * @param mixed $value
     *
     * @return string|null
     */
    private static function metaScalar($value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = (string)$value;

        return $value === '' || strlen($value) > self::MAX_VALUE_BYTES ? null : $value;
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    private static function isPluginKey(string $key): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $key) === 1
            && !in_array($key, self::CORE_KEYS, true)
            && !in_array($key, self::LEGACY_KEYS, true)
            && $key !== 'v' && $key !== 'params';
    }

    /**
     * A plugin value: a scalar, or a flat list of scalars, each within the size cap.
     *
     * @param mixed $value
     *
     * @return int|string|array<int,int|string>|null null when it does not fit
     */
    private static function pluginValue($value)
    {
        $scalar = static function ($v) {
            if (is_int($v)) {
                return $v;
            }
            if (is_string($v) && $v !== '' && strlen($v) <= self::MAX_VALUE_BYTES) {
                return $v;
            }

            return null;
        };
        if (!is_array($value)) {
            return $scalar($value);
        }
        if ($value === array() || count($value) > self::MAX_LIST_ITEMS
            || array_keys($value) !== range(0, count($value) - 1)
        ) {
            return null;
        }
        foreach ($value as $v) {
            if ($scalar($v) === null) {
                return null;
            }
        }

        return $value;
    }

    /**
     * Sort string keys (and the meta ids) recursively; lists keep their order.
     *
     * @param array<int|string,mixed> $data
     *
     * @return array<int|string,mixed>
     */
    private static function sortKeys(array $data): array
    {
        $isList = $data === array() || array_keys($data) === range(0, count($data) - 1);
        if (!$isList) {
            ksort($data, SORT_STRING);
        }
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::sortKeys($v);
            }
        }

        return $data;
    }
}
