<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Keyword-based spam detection for listings.
 *
 * Reads the operator's blocklist from t_keyword_block (via the KeywordBlock
 * model), compiles it into a cached regex per scope and answers one question:
 * does this listing contain a blocked keyword, and if so which one. It records
 * the match; it does not act on it — the caller decides what to do (quarantine,
 * hard-block).
 *
 * The matcher is a whole-word match, not a substring match. A bare substring
 * filter blocks a large slice of an ordinary catalogue by accident: `sex` inside
 * `unisex`, `anal` inside `analyst`/`analysis`/`canal`, `ugg` inside `luggage`,
 * `hips` inside `dealerships`, `condom` inside `condominium`. So each keyword is
 * anchored with a lookaround on whichever side actually carries a word character,
 * which is what makes it work for every shape of real keyword — `patch.com`,
 * `https://`, `t-mobile`, `call girl` — where a plain `\b` would demand a word
 * character next to a symbol and never fire. A keyword may opt back INTO substring
 * matching (b_substring), for the cases where catching the fragment is the point
 * (`testosterone` inside a longer word). Substring is opt-in because it is the
 * dangerous mode and the default has to be the safe one.
 *
 * A keyword shorter than MIN_KEYWORD_LENGTH is refused. A one- or two-character
 * keyword matches a large part of the catalogue, and it is almost always a stray
 * comma inside a longer term (a chemical name, a CAS number) that got imported as
 * its own keyword, not a keyword anyone meant to add.
 */
class ItemSpamFilter
{
    /** Below this length a keyword is refused. See cleanKeywordList(). */
    public const MIN_KEYWORD_LENGTH = 3;

    /** @var ItemSpamFilter */
    private static $instance;

    /**
     * Keywords bucketed by scope, in the engine's string form (a substring
     * keyword is wrapped in `*`). Lazily loaded from t_keyword_block.
     *
     * @var array<string, string[]>|null
     */
    private $keywordsByScope;

    /**
     * Compiled regex patterns, keyed by md5 of the keyword set.
     *
     * @var array<string, string>
     */
    private $compiledRegexPatterns = array();

    /**
     * @return ItemSpamFilter
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Check an item for a blocked keyword.
     *
     * The `all` scope is checked against both title and description; `title` and
     * `description` only against their own field; `meta` against each meta value
     * (loaded on demand).
     *
     * @param array $item item row-shaped array (pk_i_id, s_title, s_description)
     *
     * @return array{keyword:string,scope:string,field:string}|null the match, or
     *               null when the item is clean
     */
    public function check($item)
    {
        $buckets = $this->loadKeywords();

        $title = isset($item['s_title']) ? (string)$item['s_title'] : '';
        $desc  = isset($item['s_description']) ? (string)$item['s_description'] : '';
        $itemId = isset($item['pk_i_id']) ? (int)$item['pk_i_id'] : 0;

        $matched = $this->matchIn(array_merge($buckets['title'], $buckets['all']), $title);
        if ($matched !== null) {
            return array('keyword' => $matched, 'scope' => 'title', 'field' => 'title');
        }

        $matched = $this->matchIn(array_merge($buckets['description'], $buckets['all']), $desc);
        if ($matched !== null) {
            return array('keyword' => $matched, 'scope' => 'description', 'field' => 'description');
        }

        if (!empty($buckets['meta']) && $itemId > 0) {
            $metaFields = Item::newInstance()->metaFields($itemId);
            if (!empty($metaFields)) {
                foreach ($metaFields as $metaField) {
                    if (!isset($metaField['s_value']) || $metaField['s_value'] === '') {
                        continue;
                    }
                    $value = $metaField['s_value'];
                    if (is_array($value)) {
                        $value = implode(' ', $value);
                    }
                    $matched = $this->matchIn($buckets['meta'], (string)$value);
                    if ($matched !== null) {
                        $field = isset($metaField['s_name']) ? substr((string)$metaField['s_name'], 0, 20) : 'meta';

                        return array('keyword' => $matched, 'scope' => 'meta', 'field' => $field);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Run one compiled keyword set against one piece of text.
     *
     * @param string[] $keywords engine-form keyword strings
     * @param string   $text
     *
     * @return string|null the text that matched (the fired keyword), or null
     */
    private function matchIn(array $keywords, $text)
    {
        if (empty($keywords) || $text === '') {
            return null;
        }

        // Scrub invalid UTF-8. preg_match() with the /u flag returns false (not
        // an exception) on malformed input, which would silently skip the match
        // and let a keyword slip through mixed with junk bytes. Dropping the bad
        // bytes keeps the filter effective regardless of what upstream stored.
        if (preg_match('//u', $text) !== 1) {
            $text = (string) @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        }

        $pattern = $this->getCompiledPattern($keywords);

        try {
            $matches = array();
            if (preg_match($pattern, $text, $matches)) {
                return isset($matches[1]) ? $matches[1] : '';
            }
        } catch (\Exception $e) {
            error_log('ItemSpamFilter regex error: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Load and bucket the blocklist by scope. Each row becomes an engine-form
     * keyword string: substring keywords are wrapped in `*` so getCompiledPattern
     * turns them back into substring alternatives.
     *
     * @return array<string, string[]>
     */
    private function loadKeywords()
    {
        if ($this->keywordsByScope !== null) {
            return $this->keywordsByScope;
        }

        $buckets = array('title' => array(), 'description' => array(), 'all' => array(), 'meta' => array());

        foreach (KeywordBlock::newInstance()->listAll() as $row) {
            $bare = trim(str_replace('*', '', (string)$row['s_keyword']));
            if (mb_strlen($bare) < self::MIN_KEYWORD_LENGTH) {
                continue;
            }

            $scope = isset($row['s_scope']) ? (string)$row['s_scope'] : 'all';
            if (!isset($buckets[$scope])) {
                $scope = 'all';
            }

            $buckets[$scope][] = ((int)$row['b_substring'] === 1) ? ('*' . $bare . '*') : $bare;
        }

        $this->keywordsByScope = $buckets;

        return $buckets;
    }

    /**
     * Compile an array of keywords into a single cached regex.
     *
     * Ported verbatim from the classifieds theme's battle-tested matcher. The
     * asymmetric lookarounds and the empty-set `/(?!)/` guard are the crown jewel
     * of this feature — do not simplify them.
     *
     * @param string[] $keywords engine-form keyword strings
     *
     * @return string a PCRE pattern
     */
    private function getCompiledPattern($keywords)
    {
        $key = md5(implode(',', $keywords));

        if (isset($this->compiledRegexPatterns[$key])) {
            return $this->compiledRegexPatterns[$key];
        }

        $parts = array();

        foreach ($keywords as $keyword) {
            $substring = (strpos($keyword, '*') !== false);
            $bare      = trim(str_replace('*', '', $keyword));

            if ($bare === '') {
                continue;
            }

            $quoted = preg_quote($bare, '/');

            if ($substring) {
                $parts[] = $quoted;
                continue;
            }

            // Anchor only on the side that actually has a word character, so a
            // keyword ending in a symbol (https://) or containing one (t-mobile,
            // call girl) still matches — where a plain \b would demand a word
            // character next to the symbol and never fire.
            $left  = preg_match('/^\w/u', $bare) ? '(?<!\w)' : '';
            $right = preg_match('/\w$/u', $bare) ? '(?!\w)' : '';

            $parts[] = $left . $quoted . $right;
        }

        // An empty keyword set must match NOTHING. '/()/' — what imploding an
        // empty array gives you — matches the empty string, i.e. every listing.
        $pattern = empty($parts)
            ? '/(?!)/'
            : '/(' . implode('|', $parts) . ')/iu';

        $this->compiledRegexPatterns[$key] = $pattern;

        return $pattern;
    }

    /**
     * Split and sanitise a comma-separated keyword list.
     *
     * Splits on EVERY comma, then applies the same hard floor the runtime uses: a
     * keyword whose bare form (asterisk markers removed) is shorter than
     * MIN_KEYWORD_LENGTH is dropped, and duplicates are collapsed. Kept as a
     * static helper so an operator's old comma-list preferences can be imported
     * into t_keyword_block.
     *
     * @param string $keywordString comma-separated keywords
     *
     * @return string[] cleaned keyword list (engine form, `*` markers preserved)
     */
    public static function cleanKeywordList($keywordString)
    {
        $keywords = array_filter(array_map('trim', explode(',', strtolower((string)$keywordString))));

        $kept = array();
        foreach ($keywords as $keyword) {
            $bare = trim(str_replace('*', '', $keyword));
            if ($bare === '') {
                continue;
            }
            if (mb_strlen($bare) < self::MIN_KEYWORD_LENGTH) {
                continue;
            }
            $kept[$keyword] = $keyword;
        }

        return array_values($kept);
    }

    /**
     * Import a comma-separated keyword list into t_keyword_block.
     *
     * A keyword wrapped in asterisks is stored as a substring keyword (b_substring
     * = 1) with the markers stripped; every other keyword is stored as a
     * whole-word keyword under the given scope. Already-present keywords in the
     * same scope are skipped so the import is idempotent.
     *
     * @param string $keywordString comma-separated keywords
     * @param string $scope         one of title|description|all|meta
     *
     * @return int number of keywords inserted
     */
    public static function importCommaList($keywordString, $scope = 'all')
    {
        $validScopes = array('title', 'description', 'all', 'meta');
        if (!in_array($scope, $validScopes, true)) {
            $scope = 'all';
        }

        $model    = KeywordBlock::newInstance();
        $existing = array();
        foreach ($model->listAll() as $row) {
            $existing[$row['s_scope'] . '|' . mb_strtolower((string)$row['s_keyword'])] = true;
        }

        $inserted = 0;
        foreach (self::cleanKeywordList($keywordString) as $keyword) {
            $substring = (strpos($keyword, '*') !== false) ? 1 : 0;
            $bare      = trim(str_replace('*', '', $keyword));
            if ($bare === '') {
                continue;
            }
            if (isset($existing[$scope . '|' . $bare])) {
                continue;
            }

            $ok = $model->insert(array(
                's_keyword'   => $bare,
                's_scope'     => $scope,
                'b_substring' => $substring,
                'dt_date'     => date('Y-m-d H:i:s'),
            ));

            if ($ok) {
                $existing[$scope . '|' . $bare] = true;
                $inserted++;
            }
        }

        return $inserted;
    }
}
