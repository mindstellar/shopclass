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
 * Reads an alert stored in the old format (SQL fragments, from Search::toJson()) back
 * into search values.
 *
 * Only the exact fragments core itself writes are accepted, each matched as a whole
 * string. Anything else -- a plugin's own condition, an extra table or join, a value
 * that does not unescape cleanly -- is not guessed at: the alert is held, with a reason.
 * Internally the first such thing throws \UnexpectedValueException with the reason.
 */
final class LegacyAlertParser
{
    /** Held: a key the old format never had. */
    public const HELD_KEY = 'unknown_key';

    /** Held: a condition that is not a core custom-field filter. */
    public const HELD_CONDITION = 'unknown_condition';

    /** Held: extra tables or joins, which only a plugin adds. */
    public const HELD_TABLES = 'extra_tables';

    /** Held: a location or user filter core does not write. */
    public const HELD_FILTER = 'unknown_filter';

    /** Held: a value of the wrong shape, or one that does not unescape cleanly. */
    public const HELD_VALUE = 'bad_value';

    /** Held: the custom field is now of a type the stored filter does not fit. */
    public const HELD_FIELD_TYPE = 'field_type_changed';

    /** Held: the stored search is not a JSON object. */
    public const HELD_NOT_JSON = 'not_json';

    /** Held: a v2 envelope that does not validate. */
    public const HELD_ENVELOPE = 'invalid_envelope';

    /** Held: converting the row failed. */
    public const HELD_ERROR = 'error';

    /** Every held reason parse() gives. */
    private const REASONS = array(
        self::HELD_KEY, self::HELD_CONDITION, self::HELD_TABLES, self::HELD_FILTER, self::HELD_VALUE,
        self::HELD_FIELD_TYPE,
    );

    /**
     * The body of a quoted SQL string: characters other than a quote or backslash, a
     * backslash escape, or a doubled quote. unquote() decides which escaping it used.
     */
    private const BODY = "(?:[^'\\\\]|\\\\.|'')*";

    /** Keys read and dropped: sort and paging are not part of an alert. */
    private const IGNORED_KEYS = array(
        'limit_init', 'order_column', 'order_direction', 'results_per_page', 'tables', 'withPattern',
    );

    /** Custom-field types each condition template is written for. */
    private const TEXT_TYPES   = array('TEXT', 'TEXTAREA', 'URL');
    private const CHOICE_TYPES = array('DROPDOWN', 'RADIO');

    /**
     * The search values of an old-format alert, or why it is held.
     *
     * The values use the search request's key names (sCategory, sCity, meta, ...) and are
     * not normalised yet; sCategory is the stored, already expanded, category list.
     *
     * @param array<string,mixed> $v1        the decoded s_search
     * @param callable|null       $fieldType fn(int $id): ?string, a custom field's e_type or
     *                                       null when it no longer exists; defaults to the Field model
     * @param string|null         $prefix    table prefix the fragments were written with
     *
     * @return array{values:array<string,mixed>|null,held:string|null}
     */
    public static function parse(array $v1, ?callable $fieldType = null, ?string $prefix = null): array
    {
        $prefix    = $prefix ?? DB_TABLE_PREFIX;
        $fieldType = $fieldType ?? static function (int $id): ?string {
            $field = \Field::newInstance()->findByPrimaryKey($id);

            return is_array($field) && isset($field['e_type']) ? (string)$field['e_type'] : null;
        };

        $known = array(
            'aCategories', 'cities', 'city_areas', 'countries', 'no_catched_conditions', 'no_catched_tables',
            'onlyPremium', 'price_max', 'price_min', 'regions', 'sPattern', 'tables_join', 'user_ids',
            'withPicture',
        );
        foreach (array_keys($v1) as $key) {
            if (!in_array($key, $known, true) && !in_array($key, self::IGNORED_KEYS, true)) {
                return self::held(self::HELD_KEY);
            }
        }
        foreach (array('no_catched_tables', 'tables_join') as $key) {
            if (isset($v1[$key]) && $v1[$key] !== array()) {
                return self::held(self::HELD_TABLES);
            }
        }

        try {
            $values = array(
                'sCategory' => self::categories($v1['aCategories'] ?? null),
                'sCityArea' => self::locations($v1['city_areas'] ?? null, $prefix, 'fk_i_city_area_id', 's_city_area'),
                'sCity'     => self::locations($v1['cities'] ?? null, $prefix, 'fk_i_city_id', 's_city'),
                'sRegion'   => self::locations($v1['regions'] ?? null, $prefix, 'fk_i_region_id', 's_region'),
                'sCountry'  => self::countries($v1['countries'] ?? null, $prefix),
                'sUser'     => self::users($v1['user_ids'] ?? null, $prefix),
                'sPattern'  => self::pattern($v1['sPattern'] ?? null),
                'bPic'      => self::flag($v1['withPicture'] ?? null),
                'bPremium'  => self::flag($v1['onlyPremium'] ?? null),
                'sPriceMin' => self::price($v1['price_min'] ?? null),
                'sPriceMax' => self::price($v1['price_max'] ?? null),
                'meta'      => self::meta($v1['no_catched_conditions'] ?? null, $prefix, $fieldType),
            );
        } catch (\UnexpectedValueException $e) {
            $reason = $e->getMessage();

            return self::held(in_array($reason, self::REASONS, true) ? $reason : self::HELD_ERROR);
        }

        return array('values' => $values, 'held' => null);
    }

    /**
     * The stored form an s_search value converts to: a canonical v2 envelope, or a held
     * marker. A row that already holds a v2 envelope is re-encoded, or held when invalid.
     *
     * Custom-field filters apply only inside categories that carry the field, as on the
     * search page. So a filter with no category at all (which core never wrote) holds
     * the row, and one whose field is no longer searchable there is dropped: replaying
     * it would drop it anyway.
     *
     * @param string|null   $sSearch    the t_alerts.s_search column
     * @param callable|null $fieldType  see parse()
     * @param callable|null $searchable fn(array $categoryIds): array, the searchable field
     *                                  ids there; defaults to the Field model
     *
     * @return array{json:string,held:string|null}
     */
    public static function convert(?string $sSearch, ?callable $fieldType = null, ?callable $searchable = null): array
    {
        $data = json_decode((string)$sSearch, true, 16);
        if (!is_array($data)) {
            return self::heldRow(self::HELD_NOT_JSON);
        }
        if (AlertEnvelope::isEnvelope($data)) {
            $params = AlertEnvelope::validateDecoded($data);

            return $params === null
                ? self::heldRow(self::HELD_ENVELOPE)
                : array('json' => AlertEnvelope::encode($params), 'held' => null);
        }

        $parsed = self::parse($data, $fieldType);
        if ($parsed['held'] !== null) {
            return self::heldRow($parsed['held']);
        }
        $values = $parsed['values'];
        if ($values['meta'] !== array()) {
            if ($values['sCategory'] === array()) {
                return self::heldRow(self::HELD_CONDITION);
            }
            $searchable = $searchable ?? static function (array $ids): array {
                return (array)\Field::newInstance()->findIDSearchableByCategories($ids);
            };
            $keep           = array_flip(array_map('intval', $searchable($values['sCategory'])));
            $values['meta'] = array_intersect_key($values['meta'], $keep);
        }
        $json = AlertEnvelope::fromValues($values, $values);
        if (AlertEnvelope::validate($json) === null) {
            return self::heldRow(self::HELD_VALUE);
        }

        return array('json' => $json, 'held' => null);
    }

    /**
     * @param string $reason
     *
     * @return array{values:null,held:string}
     */
    private static function held(string $reason): array
    {
        return array('values' => null, 'held' => $reason);
    }

    /**
     * @param string $reason
     *
     * @return array{json:string,held:string}
     */
    private static function heldRow(string $reason): array
    {
        return array('json' => AlertEnvelope::held($reason), 'held' => $reason);
    }

    /**
     * @param mixed $value
     *
     * @return array<int,int>
     */
    private static function categories($value): array
    {
        if ($value === null) {
            return array();
        }
        if (!is_array($value)) {
            throw new \UnexpectedValueException(self::HELD_VALUE);
        }
        $ids = array();
        foreach ($value as $id) {
            if (is_int($id) && $id > 0) {
                $ids[] = $id;
            } elseif (is_string($id) && preg_match('/^[1-9][0-9]{0,9}$/', $id)) {
                $ids[] = (int)$id;
            } else {
                throw new \UnexpectedValueException(self::HELD_VALUE);
            }
        }

        return $ids;
    }

    /**
     * City-area, city or region fragments from Search::addCityArea()/addCity()/addRegion():
     * `<prefix>t_item_location.<idColumn> = <int> ` or `<prefix>t_item_location.<nameColumn> LIKE '<name>' `.
     *
     * @param mixed  $value
     * @param string $prefix
     * @param string $idColumn
     * @param string $nameColumn
     *
     * @return array<int,int|string>
     */
    private static function locations($value, string $prefix, string $idColumn, string $nameColumn): array
    {
        $table = preg_quote($prefix . 't_item_location.', '/');
        $out   = array();
        foreach (self::fragments($value) as $fragment) {
            if (preg_match('/^' . $table . $idColumn . ' = (-?[0-9]{1,10}) $/D', $fragment, $m)) {
                $out[] = (int)$m[1];
            } elseif (preg_match('/^' . $table . $nameColumn . " LIKE '(" . self::BODY . ")' $/Ds", $fragment, $m)) {
                $name = self::name(self::unquote($m[1]));
                // A number is written as an id, so a numeric name is not core's.
                if (is_numeric($name)) {
                    throw new \UnexpectedValueException(self::HELD_FILTER);
                }
                $out[] = $name;
            } else {
                throw new \UnexpectedValueException(self::HELD_FILTER);
            }
        }

        return $out;
    }

    /**
     * Country fragments from Search::addCountry(): a two-character code, lower-cased
     * (`fk_c_country_code = 'us' `), or a name (`s_country LIKE 'Spain' `). A value that is a
     * number is written bare.
     *
     * @param mixed  $value
     * @param string $prefix
     *
     * @return array<int,string>
     */
    private static function countries($value, string $prefix): array
    {
        $table  = preg_quote($prefix . 't_item_location.', '/');
        $byCode = '/^' . $table . "fk_c_country_code = (?:'(" . self::BODY . ")'|([0-9]{2})) $/Ds";
        $byName = '/^' . $table . "s_country LIKE (?:'(" . self::BODY . ")'|([0-9]{1,10})) $/Ds";
        $out    = array();
        foreach (self::fragments($value) as $fragment) {
            if (preg_match($byCode, $fragment, $m)) {
                $code = isset($m[2]) && $m[2] !== '' ? $m[2] : self::name(self::unquote($m[1]));
                if (strlen($code) !== 2 || $code !== strtolower($code)) {
                    throw new \UnexpectedValueException(self::HELD_FILTER);
                }
                $out[] = $code;
            } elseif (preg_match($byName, $fragment, $m)) {
                $name = isset($m[2]) && $m[2] !== '' ? $m[2] : self::name(self::unquote($m[1]));
                if (strlen($name) === 2) {
                    throw new \UnexpectedValueException(self::HELD_FILTER);
                }
                $out[] = $name;
            } else {
                throw new \UnexpectedValueException(self::HELD_FILTER);
            }
        }

        return $out;
    }

    /**
     * user_ids from Search::fromUser(): null, one id, or a list of
     * `<prefix>t_item.fk_i_user_id = <int> ` fragments.
     *
     * @param mixed  $value
     * @param string $prefix
     *
     * @return array<int,int>
     */
    private static function users($value, string $prefix): array
    {
        if ($value === null) {
            return array();
        }
        if (is_int($value) || is_string($value)) {
            if (!preg_match('/^(0|[1-9][0-9]{0,9})$/', (string)$value)) {
                throw new \UnexpectedValueException(self::HELD_FILTER);
            }

            return array((int)$value);
        }
        $out    = array();
        $column = preg_quote($prefix . 't_item.fk_i_user_id', '/');
        foreach (self::fragments($value) as $fragment) {
            if (!preg_match('/^' . $column . ' = (-?[0-9]{1,10}) $/D', $fragment, $m)) {
                throw new \UnexpectedValueException(self::HELD_FILTER);
            }
            $out[] = (int)$m[1];
        }

        return $out;
    }

    /**
     * The pattern: stored raw, or (older rows) quoted and backslash-escaped, which
     * Search::setJsonAlert() strips the same way.
     *
     * @param mixed $value
     *
     * @return string
     */
    private static function pattern($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value)) {
            $value = (string)$value;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException(self::HELD_VALUE);
        }
        $len = strlen($value);
        if ($len >= 2 && $value[0] === "'" && $value[$len - 1] === "'") {
            $value = stripslashes(substr($value, 1, -1));
        }
        if (strpos($value, "\0") !== false) {
            throw new \UnexpectedValueException(self::HELD_VALUE);
        }

        return $value;
    }

    /**
     * withPicture/onlyPremium: toJson() writes them only as true.
     *
     * @param mixed $value
     *
     * @return int|null
     */
    private static function flag($value): ?int
    {
        if ($value === null) {
            return null;
        }
        if ($value === true || $value === 1 || $value === '1') {
            return 1;
        }
        throw new \UnexpectedValueException(self::HELD_VALUE);
    }

    /**
     * A price bound in currency units, truncated to a whole number as the old replay
     * (Search::priceRange()) did.
     *
     * @param mixed $value
     *
     * @return int
     */
    private static function price($value): int
    {
        if ($value === null) {
            return 0;
        }
        if (is_string($value) && is_numeric($value)) {
            $value = $value + 0;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && abs($value) < 1e12) {
            return (int)$value;
        }
        throw new \UnexpectedValueException(self::HELD_VALUE);
    }

    /**
     * Custom-field conditions, in the templates SearchBuilder::applyMeta() writes (and the
     * pre-quoting forms of the choice and number templates).
     *
     * @param mixed    $value
     * @param string   $prefix
     * @param callable $fieldType
     *
     * @return array<int,string|array<string,string>>
     */
    private static function meta($value, string $prefix, callable $fieldType): array
    {
        $item  = preg_quote($prefix . 't_item.pk_i_id', '/');
        $t     = preg_quote($prefix . 't_item_meta', '/');
        $sub   = 'SELECT fk_i_item_id FROM ' . $t . ' WHERE ' . $t . '\.fk_i_field_id = ([0-9]{1,10}) AND ';
        $int   = '(-?[0-9]{1,20})';
        $num   = '(-?[0-9]{1,20}(?:\.[0-9]{1,20})?(?:E[+-][0-9]{1,3})?)';
        $shape = array(
            'like'     => '/^' . $item . ' IN \(' . $sub . $t . "\\.s_value LIKE '(" . self::BODY . ")'\\)$/Ds",
            'quoted'   => '/^' . $item . ' IN \(' . $sub . $t . "\\.s_value = '(" . self::BODY . ")'\\)$/Ds",
            'bare'     => '/^' . $item . ' IN \(' . $sub . $t . '\.s_value = ([A-Za-z0-9_.+-]{1,255})\)$/D',
            'range'    => '/^' . $item . ' IN \(' . $sub . $t . '\.s_value >= ' . $num . ' AND ' . $t
                . '\.s_value <= ' . $num . '\)$/D',
            'interval' => '/^' . $item . ' IN \(select a\.fk_i_item_id from \(' . $sub . $int . ' >= ' . $t
                . "\\.s_value AND s_multi = 'from'\\) a where a\\.fk_i_item_id IN \\(" . $sub . $int . ' <= ' . $t
                . "\\.s_value AND s_multi = 'to'\\)\\)$/D",
        );

        $out  = array();
        $seen = array();
        foreach (self::fragments($value) as $fragment) {
            $matched = null;
            foreach ($shape as $name => $regex) {
                if (preg_match($regex, $fragment, $m)) {
                    $matched = $name;
                    break;
                }
            }
            if ($matched === null) {
                throw new \UnexpectedValueException(self::HELD_CONDITION);
            }
            $id = (int)$m[1];
            if ($id <= 0 || isset($seen[$id]) || ($matched === 'interval' && (int)$m[3] !== $id)) {
                throw new \UnexpectedValueException(self::HELD_CONDITION);
            }
            $seen[$id] = true;

            $type = $fieldType($id);
            if ($type === null) {
                // The field was deleted: its filter goes, the rest of the alert stays.
                continue;
            }
            $type = strtoupper($type);

            switch ($matched) {
                case 'like':
                    self::expectType($type, self::TEXT_TYPES);
                    $text = self::value(self::unquote($m[2]));
                    $len  = strlen($text);
                    if ($len < 3 || $text[0] !== '%' || $text[$len - 1] !== '%') {
                        throw new \UnexpectedValueException(self::HELD_VALUE);
                    }
                    $out[$id] = substr($text, 1, -1);
                    break;
                case 'quoted':
                    self::expectType($type, self::CHOICE_TYPES);
                    $out[$id] = self::value(self::unquote($m[2]));
                    break;
                case 'bare':
                    if ($type === 'CHECKBOX') {
                        if ($m[2] !== '1') {
                            throw new \UnexpectedValueException(self::HELD_VALUE);
                        }
                        $out[$id] = '1';
                        break;
                    }
                    self::expectType($type, self::CHOICE_TYPES);
                    $out[$id] = $m[2];
                    break;
                case 'range':
                    if ($type === 'DATE') {
                        $out[$id] = self::day($m[2], $m[3]);
                        break;
                    }
                    self::expectType($type, array('NUMBER'));
                    $out[$id] = array('from' => self::bound($m[2]), 'to' => self::bound($m[3]));
                    break;
                case 'interval':
                    self::expectType($type, array('DATEINTERVAL'));
                    $out[$id] = array('from' => self::bound($m[2]), 'to' => self::bound($m[4]));
                    break;
            }
        }

        return $out;
    }

    /**
     * A DATE filter's value: the stored start of day, when start and end are exactly the
     * day SearchBuilder computes for it in this site's timezone.
     *
     * @param string $start
     * @param string $end
     *
     * @return string
     */
    private static function day(string $start, string $end): string
    {
        if (!preg_match('/^-?[0-9]{1,12}$/', $start) || !preg_match('/^-?[0-9]{1,12}$/', $end)) {
            throw new \UnexpectedValueException(self::HELD_VALUE);
        }
        $ts = (int)$start;
        $y  = (int)date('Y', $ts);
        $mo = (int)date('n', $ts);
        $d  = (int)date('j', $ts);
        if (mktime(0, 0, 0, $mo, $d, $y) !== $ts || mktime(23, 59, 59, $mo, $d, $y) !== (int)$end) {
            throw new \UnexpectedValueException(self::HELD_VALUE);
        }

        return $start;
    }

    /**
     * A range bound. The builder skips a range whose bound is empty(), so a zero bound
     * would widen the alert: held instead.
     *
     * @param string $value
     *
     * @return string
     */
    private static function bound(string $value): string
    {
        if (empty($value) || !is_finite((float)$value)) {
            throw new \UnexpectedValueException(self::HELD_VALUE);
        }

        return $value;
    }

    /**
     * @param string        $type
     * @param array<string> $allowed
     *
     * @return void
     */
    private static function expectType(string $type, array $allowed): void
    {
        if (!in_array($type, $allowed, true)) {
            throw new \UnexpectedValueException(self::HELD_FIELD_TYPE);
        }
    }

    /**
     * A list of fragment strings; null is an empty list.
     *
     * @param mixed $value
     *
     * @return array<int,string>
     */
    private static function fragments($value): array
    {
        if ($value === null) {
            return array();
        }
        if (!is_array($value)) {
            throw new \UnexpectedValueException(self::HELD_VALUE);
        }
        foreach ($value as $fragment) {
            if (!is_string($fragment)) {
                throw new \UnexpectedValueException(self::HELD_VALUE);
            }
        }

        return array_values($value);
    }

    /**
     * Decode a quoted string's body. Backslash escaping (real_escape_string's \0 \n \r
     * \\ \' \" \Z) is tried first; then the doubled-quote form a server in
     * NO_BACKSLASH_ESCAPES mode writes, where a backslash is literal. A quote left
     * unescaped matches neither.
     *
     * @param string $body
     *
     * @return string
     */
    private static function unquote(string $body): string
    {
        if (preg_match('/^(?:[^\'\\\\]|\\\\[0nrZ\\\\\'"])*$/Ds', $body)) {
            return (string)preg_replace_callback('/\\\\(.)/s', static function (array $m): string {
                $map = array('0' => "\0", 'n' => "\n", 'r' => "\r", 'Z' => "\x1a");

                return $map[$m[1]] ?? $m[1];
            }, $body);
        }
        if (preg_match("/^(?:[^']|'')*$/Ds", $body)) {
            return str_replace("''", "'", $body);
        }
        throw new \UnexpectedValueException(self::HELD_VALUE);
    }

    /**
     * An unquoted value: no NUL, and at most the size a search value may be.
     *
     * @param string $value
     *
     * @return string
     */
    private static function value(string $value): string
    {
        if ($value === '' || strpos($value, "\0") !== false || strlen($value) > AlertEnvelope::MAX_VALUE_BYTES) {
            throw new \UnexpectedValueException(self::HELD_VALUE);
        }

        return $value;
    }

    /**
     * A location name: a value that is also trimmed, as the Search adders trim it, and not
     * a digit string the envelope would read back as a different number.
     *
     * @param string $value
     *
     * @return string
     */
    private static function name(string $value): string
    {
        $value = self::value($value);
        if (trim($value) !== $value || (ctype_digit($value) && (string)(int)$value !== $value)) {
            throw new \UnexpectedValueException(self::HELD_VALUE);
        }

        return $value;
    }
}
