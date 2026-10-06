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

namespace mindstellar\search\query;

/**
 * The keyword filter and the description locales it searches.
 *
 * Every word becomes a required prefix term, a quoted phrase a required phrase and
 * -word an exclusion, in FULLTEXT BOOLEAN MODE. When every term is shorter than the
 * FULLTEXT minimum token size it matches by substring instead.
 */
final class PatternFilter
{
    private bool $active = false;
    /** @var mixed the pattern as given */
    private $given = null;
    private ?string $raw = null;
    /** @var array<string,string> */
    private array $locales = array();

    /**
     * @param mixed $pattern
     *
     * @return void
     */
    public function set($pattern): void
    {
        $this->active = true;
        $this->given  = $pattern;
        $this->raw    = trim((string)$pattern);
    }

    /**
     * @return void
     */
    public function clear(): void
    {
        $this->active = false;
        $this->given  = null;
        $this->raw    = null;
    }

    /**
     * @return bool
     */
    public function active(): bool
    {
        return $this->active;
    }

    /**
     * The pattern as toJson() records it: trimmed, unescaped, null when unset.
     *
     * @return string|null
     */
    public function recorded(): ?string
    {
        return $this->raw;
    }

    /**
     * Keep only locale-code shapes (en_US): the codes reach SQL.
     *
     * @param mixed $locales
     *
     * @return void
     */
    public function addLocale($locales): void
    {
        foreach ((array)$locales as $locale) {
            if (is_string($locale) && preg_match('/^[A-Za-z]{2,3}_[A-Za-z]{2}$/', $locale)) {
                $this->locales[$locale] = $locale;
            }
        }
    }

    /**
     * Search this locale when none was asked for.
     *
     * @param mixed $locale
     *
     * @return void
     */
    public function defaultLocale($locale): void
    {
        if ($this->locales === array()) {
            $this->locales[(string)$locale] = (string)$locale;
        }
    }

    /**
     * @return array<string,string>
     */
    public function locales(): array
    {
        return $this->locales;
    }

    /**
     * The condition matching any of the locales on the description alias d.
     *
     * @return array{0:string,1:array<int,string>}
     */
    public function localeCondition(): array
    {
        $parts = array_fill(0, count($this->locales), 'd.fk_c_locale_code LIKE ?');

        return array('( ' . implode(' OR ', $parts) . ' )', array_values($this->locales));
    }

    /**
     * Whether FULLTEXT can match it: at least one phrase or one indexable word.
     *
     * @return bool
     */
    public function fullTextUsable(): bool
    {
        if ($this->raw === null || $this->raw === '') {
            return true;
        }
        $phrases = array();
        $words = $this->terms($phrases);
        if ($phrases !== array()) {
            return true;
        }
        $min = defined('OSC_FT_MIN_WORD_LEN') ? max(1, (int)OSC_FT_MIN_WORD_LEN) : 3;
        foreach ($words as $w) {
            if (!$w['neg'] && $this->length($w['text']) >= $min) {
                return true;
            }
        }

        return false;
    }

    /**
     * The BOOLEAN MODE query, bound as a parameter.
     *
     * @return int|float|string|null
     */
    public function booleanQuery()
    {
        $phrases = array();
        $words  = $this->terms($phrases);
        $tokens = array();
        foreach ($phrases as $phrase) {
            $tokens[] = '+"' . $phrase . '"';
        }
        foreach ($words as $w) {
            $tokens[] = $w['neg'] ? '-' . $w['text'] : '+' . $w['text'] . '*';
        }
        if ($tokens === array()) {
            return SqlValue::bind($this->given);
        }

        return implode(' ', $tokens);
    }

    /**
     * MATCH on title and description.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    public function matchCondition(): array
    {
        return array('MATCH(d.s_description, d.s_title) AGAINST(? IN BOOLEAN MODE)', array($this->booleanQuery()));
    }

    /**
     * The relevance column: a title hit counts double.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    public function relevanceSelect(): array
    {
        $q = $this->booleanQuery();

        return array(
            '(2 * MATCH(d.s_title) AGAINST(? IN BOOLEAN MODE) + MATCH(d.s_description, d.s_title) AGAINST(? IN BOOLEAN MODE)) as relevance',
            array($q, $q),
        );
    }

    /**
     * The substring fallback: every term in the title or the description.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    public function likeCondition(): array
    {
        $phrases = array();
        $words = $this->terms($phrases);
        $terms = $phrases;
        foreach ($words as $w) {
            if (!$w['neg']) {
                $terms[] = $w['text'];
            }
        }
        if ($terms === array()) {
            return $this->matchCondition();
        }

        $clauses = array();
        $params  = array();
        foreach ($terms as $term) {
            $like      = '%' . str_replace(array('%', '_'), array('\%', '\_'), $term) . '%';
            $clauses[] = '(d.s_title LIKE ? OR d.s_description LIKE ?)';
            $params[]  = $like;
            $params[]  = $like;
        }

        return array('(' . implode(' AND ', $clauses) . ')', $params);
    }

    /**
     * Loose words (with a leading '-' kept as an exclusion flag) and quoted phrases,
     * with the BOOLEAN MODE operator characters stripped.
     *
     * @param string[] $phrases
     *
     * @return array<int,array{neg:bool,text:string}>
     */
    private function terms(array &$phrases): array
    {
        $phrases = array();
        $raw     = (string)$this->raw;

        if (preg_match_all('/"([^"]+)"/u', $raw, $m)) {
            foreach ($m[1] as $phrase) {
                $phrase = trim((string)preg_replace('/[+\-*"()~<>@]/u', ' ', $phrase));
                $phrase = (string)preg_replace('/\s+/u', ' ', $phrase);
                if ($phrase !== '') {
                    $phrases[] = $phrase;
                }
            }
            $raw = (string)preg_replace('/"[^"]+"/u', ' ', $raw);
        }

        $words = array();
        foreach ((array)preg_split('/\s+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $word = (string)$word;
            $neg  = ($word[0] === '-');
            $text = (string)preg_replace('/[+\-*"()~<>@]/u', '', $word);
            if ($text !== '') {
                $words[] = array('neg' => $neg, 'text' => $text);
            }
        }

        return $words;
    }

    /**
     * @param string $s
     *
     * @return int
     */
    private function length(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }
}
