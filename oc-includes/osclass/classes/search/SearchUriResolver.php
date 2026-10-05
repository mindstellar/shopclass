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
 * Turns a friendly search URI into request params, a redirect or a 404.
 *
 * Pure: lookups come in as callables and the answer goes back as data, which the
 * search controller applies through Params.
 */
class SearchUriResolver
{
    /** @var string */
    private $searchSlug;

    /** @var string */
    private $baseUrl;

    /** @var callable(mixed): mixed */
    private $findRegion;

    /** @var callable(mixed): mixed */
    private $findCity;

    /** @var callable(string): array<string,mixed> */
    private $findCategory;

    /** @var callable(string): mixed */
    private $findCategoryBySlug;

    /** @var callable(string): ?string */
    private $slugRedirect;

    /**
     * @param string   $searchSlug         the rewrite_search_url preference
     * @param string   $baseUrl            osc_base_url()
     * @param callable $findRegion         region row by id, or a falsy value
     * @param callable $findCity           city row by id, or a falsy value
     * @param callable $findCategory       category row by slug or id, or an empty array
     * @param callable $findCategoryBySlug category row by slug only, or an empty array
     * @param callable $slugRedirect       URL a former category slug now lives at, or null
     */
    public function __construct(
        string $searchSlug,
        string $baseUrl,
        callable $findRegion,
        callable $findCity,
        callable $findCategory,
        callable $findCategoryBySlug,
        callable $slugRedirect
    ) {
        $this->searchSlug         = $searchSlug;
        $this->baseUrl            = $baseUrl;
        $this->findRegion         = $findRegion;
        $this->findCity           = $findCity;
        $this->findCategory       = $findCategory;
        $this->findCategoryBySlug = $findCategoryBySlug;
        $this->slugRedirect       = $slugRedirect;
    }

    /**
     * Resolve a request URI. Params ops are in the order Params must apply them, since
     * an unset-then-set moves a key to the end and that order reaches osc_search_url().
     *
     * @param string              $uri     REQUEST_URI with the install's base path stripped
     * @param array<string,mixed> $params  sCategory / sFeed as Params::getParam() reads them, present only when sent
     * @param bool                $rewrite whether friendly URLs are on
     *
     * @return array{uri:string, params:array<int,array<int,mixed>>, exports:array<string,mixed>,
     *               redirect:?array{url:string,code:?int}, notFound:bool}
     */
    public function resolve(string $uri, array $params, bool $rewrite): array
    {
        $out = array('uri' => $uri, 'params' => array(), 'exports' => array(), 'redirect' => null, 'notFound' => false);

        if (stripos($uri, 'index.php') === 0) {
            return $out;
        }
        $uri        = rtrim($uri, '/');
        $out['uri'] = $uri;

        if ($uri === $this->searchSlug
            || stripos($uri, $this->searchSlug . '/') !== false
            || !$rewrite
            || array_key_exists('sFeed', $params)
        ) {
            return $out;
        }

        if (!is_numeric($uri)) {
            // Drop any query string, then a trailing page number.
            $uri        = preg_replace('/(\/?)\?.*$/', '', $uri);
            $out['uri'] = $uri;
            $searchUri  = preg_replace('|/\d+$|', '', $uri);

            $out['exports']['search_uri'] = $searchUri;
            $page                         = preg_replace('|.*/(\d+)$|', '$01', $uri);
            if (is_numeric($page) && $page > 0) {
                $out['params'][] = array('set', 'iPage', $page);
                if ($page == 1) {
                    $out['redirect'] = array('url' => $this->baseUrl . $searchUri, 'code' => null);

                    return $out;
                }
            }
        } else {
            $searchUri = $uri;
        }

        // Only the last segment names the place or category.
        $searchUri = preg_replace('|.*?/|', '', $searchUri);
        if (preg_match('|-r(\d+)$|', $searchUri, $r)) {
            $region = ($this->findRegion)($r[1]);
            if (!$region) {
                $out['notFound'] = true;

                return $out;
            }

            return $this->place($out, 'sRegion', $region['pk_i_id'], '|(.*?)_.*?-r\d+|', $searchUri);
        }
        if (preg_match('|-c(\d+)$|', $searchUri, $c)) {
            $city = ($this->findCity)($c[1]);
            if (!$city) {
                $out['notFound'] = true;

                return $out;
            }

            return $this->place($out, 'sCity', $city['pk_i_id'], '|(.*?)_.*?-c\d+|', $searchUri);
        }
        if (array_key_exists('sCategory', $params)) {
            $categorySlug = $params['sCategory'];
            if (strpos($categorySlug, '/') !== false) {
                $tmp          = explode('/', preg_replace('|/$|', '', $categorySlug));
                $categorySlug = $tmp[count($tmp) - 1];
            }
            $out['params'][] = array('set', 'sCategory', $categorySlug);
            if (empty(($this->findCategory)($categorySlug))) {
                return $this->missingCategory($out, $categorySlug);
            }

            return $out;
        }
        if ($searchUri !== $this->searchSlug) {
            // A bare /search route with query-string params is left for the canonical
            // redirect, not resolved as a category called 'search'.
            if (count(($this->findCategoryBySlug)($searchUri)) === 0) {
                return $this->missingCategory($out, $searchUri);
            }
            $out['params'][] = array('set', 'sCategory', $searchUri);
        }

        return $out;
    }

    /**
     * Friendly search params ("/region,7/pattern,bike/meta4,red") as Params ops, in order.
     * Empty when the path holds none.
     *
     * @param string                             $path   '/' followed by the sParams request value
     * @param array<int,array{0:mixed,1:string}> $names  [rewrite preference value, param name], in match order
     * @param mixed                              $meta   the raw 'meta' request value
     * @param callable(mixed): mixed             $purify what Params::getParamArray() runs over a value
     *
     * @return array<int,array{0:string,1:mixed}> [param, value] pairs for Params::setParam()
     */
    public static function decodeFriendlyParams(string $path, array $names, $meta, callable $purify): array
    {
        if (!preg_match_all('|/([^,]+),([^/]*)|', $path, $m)) {
            return array();
        }

        $ops = array();
        $l   = count($m[0]);
        for ($k = 0; $k < $l; $k++) {
            $key   = $m[1][$k];
            $value = $m[2][$k];
            $named = false;
            foreach ($names as $name) {
                if ($key == $name[0]) {
                    $key   = $name[1];
                    $named = true;
                    break;
                }
            }
            if (!$named && preg_match("/meta(\d+)-?(.*)?/", $key, $results)) {
                // Custom fields: meta[id] = value, or meta[id][key] = value.
                $array_r = is_array($meta) ? $purify($meta) : array();
                if ($results[2] == '') {
                    $array_r[$results[1]] = $value;
                } else {
                    $array_r[$results[1]][$results[2]] = $value;
                }
                $key   = 'meta';
                $value = $array_r;
            }
            if ($key === 'meta') {
                $meta = $value;
            }
            $ops[] = array($key, $value);
        }

        return $ops;
    }

    /**
     * A region or city URI: the place id, plus the category slug in front of it.
     *
     * @param array<string,mixed> $out
     * @param string              $param
     * @param mixed               $id
     * @param string              $categoryPattern
     * @param string              $searchUri
     *
     * @return array<string,mixed>
     */
    private function place(array $out, string $param, $id, string $categoryPattern, string $searchUri): array
    {
        $out['params'][] = array('set', $param, $id);
        $out['params'][] = array('unset', 'sCategory');
        if (preg_match($categoryPattern, $searchUri, $match)) {
            $out['params'][] = array('set', 'sCategory', $match[1]);
        }

        return $out;
    }

    /**
     * A slug that names no category: 301 when it is a former slug, otherwise 404.
     *
     * @param array<string,mixed> $out
     * @param mixed               $slug
     *
     * @return array<string,mixed>
     */
    private function missingCategory(array $out, $slug): array
    {
        $url = ($this->slugRedirect)($slug);
        if ($url !== null) {
            $out['redirect'] = array('url' => $url, 'code' => 301);
        } else {
            $out['notFound'] = true;
        }

        return $out;
    }
}
