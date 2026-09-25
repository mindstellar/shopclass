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
 * A listing search, normalised out of a request bag.
 *
 * fromRequest() does exactly the splitting/coercion CWebSearch::doModel() used to do
 * inline (sCategory/sCityArea/sCity/sRegion/sCountry comma-split, sUser/sLocale
 * split-or-stay-empty, sPattern through strip_tags()+trim()+the `search_pattern`
 * filter, bPic/bPremium loose-== 1). Feed it Params::getParamsAsArray() — purified
 * the same way Params::getParam() purifies one key, so the values it reads back out
 * are byte-identical to what the old inline code read.
 */
class SearchCriteria
{
    /** @var array<int,mixed> */
    private $categories;

    /** @var array<int,mixed> */
    private $cityAreas;

    /** @var array<int,mixed> */
    private $cities;

    /** @var array<int,mixed> */
    private $regions;

    /** @var array<int,mixed> */
    private $countries;

    /** @var array<int,mixed>|string */
    private $users;

    /** @var array<int,mixed>|string */
    private $locale;

    /** @var string */
    private $pattern;

    /** @var bool */
    private $withPicture;

    /** @var bool */
    private $onlyPremium;

    /** @var mixed */
    private $priceMin;

    /** @var mixed */
    private $priceMax;

    /** @var array<int|string,mixed>|string */
    private $meta;

    /**
     * @param array<int,mixed>        $categories
     * @param array<int,mixed>        $cityAreas
     * @param array<int,mixed>        $cities
     * @param array<int,mixed>        $regions
     * @param array<int,mixed>        $countries
     * @param array<int,mixed>|string $users
     * @param array<int,mixed>|string $locale
     * @param string                  $pattern
     * @param bool                    $withPicture
     * @param bool                    $onlyPremium
     * @param mixed                   $priceMin
     * @param mixed                   $priceMax
     * @param array<int|string,mixed>|string $meta
     */
    private function __construct(
        array $categories,
        array $cityAreas,
        array $cities,
        array $regions,
        array $countries,
        $users,
        $locale,
        string $pattern,
        bool $withPicture,
        bool $onlyPremium,
        $priceMin,
        $priceMax,
        $meta
    ) {
        $this->categories  = $categories;
        $this->cityAreas   = $cityAreas;
        $this->cities      = $cities;
        $this->regions     = $regions;
        $this->countries   = $countries;
        $this->users       = $users;
        $this->locale      = $locale;
        $this->pattern     = $pattern;
        $this->withPicture = $withPicture;
        $this->onlyPremium = $onlyPremium;
        $this->priceMin    = $priceMin;
        $this->priceMax    = $priceMax;
        $this->meta        = $meta;
    }

    /**
     * Build from a purified request bag — feed it Params::getParamsAsArray(), not $_GET/$_POST.
     *
     * @param array<string,mixed> $params
     *
     * @return self
     */
    public static function fromRequest(array $params): self
    {
        return new self(
            self::splitOrKeep($params['sCategory'] ?? ''),
            self::splitOrKeep($params['sCityArea'] ?? ''),
            self::splitOrKeep($params['sCity'] ?? ''),
            self::splitOrKeep($params['sRegion'] ?? ''),
            self::splitOrKeep($params['sCountry'] ?? ''),
            self::splitOrKeepScalarEmpty($params['sUser'] ?? ''),
            self::splitOrKeepScalarEmpty($params['sLocale'] ?? ''),
            osc_apply_filter('search_pattern', trim(strip_tags(self::scalar($params['sPattern'] ?? '')))),
            ($params['bPic'] ?? '') == 1,
            ($params['bPremium'] ?? '') == 1,
            self::scalar($params['sPriceMin'] ?? ''),
            self::scalar($params['sPriceMax'] ?? ''),
            is_array($params['meta'] ?? null) ? $params['meta'] : ''
        );
    }

    /**
     * sCategory/sCityArea/sCity/sRegion/sCountry: an array is kept as-is, a non-empty
     * scalar is comma-split, an empty one becomes an empty array.
     *
     * @param mixed $value
     *
     * @return array<int,mixed>
     */
    private static function splitOrKeep($value): array
    {
        if (is_array($value)) {
            return self::scalars($value);
        }
        if ($value == '') {
            return array();
        }

        return explode(',', $value);
    }

    /**
     * sUser/sLocale: an array is kept as-is, a non-empty scalar is comma-split, an
     * empty one stays the empty string — not an empty array, since fromUser()/addLocale()
     * both branch on that.
     *
     * @param mixed $value
     *
     * @return array<int,mixed>|string
     */
    private static function splitOrKeepScalarEmpty($value)
    {
        if (is_array($value)) {
            $value = array_filter(self::scalars($value), static fn ($v) => $v !== '');

            return $value === array() ? '' : $value;
        }
        if ($value == '') {
            return '';
        }

        return explode(',', $value);
    }

    /**
     * The scalar entries of a list, so a nested array cannot reach the Search mutators.
     *
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private static function scalars(array $values): array
    {
        return array_filter($values, 'is_scalar');
    }

    /**
     * A single-valued param, or '' when it arrived as an array.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private static function scalar($value)
    {
        return is_array($value) ? '' : $value;
    }

    /** @return array<int,mixed> */
    public function categories(): array
    {
        return $this->categories;
    }

    /** @return array<int,mixed> */
    public function cityAreas(): array
    {
        return $this->cityAreas;
    }

    /** @return array<int,mixed> */
    public function cities(): array
    {
        return $this->cities;
    }

    /** @return array<int,mixed> */
    public function regions(): array
    {
        return $this->regions;
    }

    /** @return array<int,mixed> */
    public function countries(): array
    {
        return $this->countries;
    }

    /** @return array<int,mixed>|string */
    public function users()
    {
        return $this->users;
    }

    /** @return array<int,mixed>|string */
    public function locale()
    {
        return $this->locale;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    public function hasPattern(): bool
    {
        return $this->pattern !== '';
    }

    public function withPicture(): bool
    {
        return $this->withPicture;
    }

    public function onlyPremium(): bool
    {
        return $this->onlyPremium;
    }

    /** @return mixed */
    public function priceMin()
    {
        return $this->priceMin;
    }

    /** @return mixed */
    public function priceMax()
    {
        return $this->priceMax;
    }

    /** @return array<int|string,mixed>|string */
    public function meta()
    {
        return $this->meta;
    }
}
