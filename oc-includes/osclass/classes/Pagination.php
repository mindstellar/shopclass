<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Class Pagination
 */
class Pagination
{
    protected $total;
    protected $selected;
    protected $class_first;
    protected $class_last;
    protected $class_prev;
    protected $class_next;
    protected $text_first;
    protected $text_last;
    protected $text_prev;
    protected $text_next;
    protected $class_selected;
    protected $class_non_selected;
    protected $delimiter;
    protected $force_limits;
    protected $sides;
    protected $url;
    protected $firstUrl;
    protected $nofollow;
    protected $listClass;

    /**
     * Pagination constructor.
     *
     * @param null $params
     *
     */
    public function __construct($params = null)
    {
        $this->total              = isset($params['total']) ? $params['total'] + 1 : osc_search_total_pages() + 1;
        $this->selected           = isset($params['selected']) ? $params['selected'] + 1 : osc_search_page() + 1;
        $this->class_first        = $params['class_first'] ?? 'searchPaginationFirst';
        $this->class_last         = $params['class_last'] ?? 'searchPaginationLast';
        $this->class_prev         = $params['class_prev'] ?? 'searchPaginationPrev';
        $this->class_next         = $params['class_next'] ?? 'searchPaginationNext';
        $this->text_first         = $params['text_first'] ?? '&laquo;';
        $this->text_last          = $params['text_last'] ?? '&raquo;';
        $this->text_prev          = $params['text_prev'] ?? '&lt;';
        $this->text_next          = $params['text_next'] ?? '&gt;';
        $this->class_selected     = $params['class_selected'] ?? 'searchPaginationSelected';
        $this->class_non_selected = $params['class_non_selected'] ?? 'searchPaginationNonSelected';
        $this->delimiter          = $params['delimiter'] ?? ' ';
        $this->force_limits       = isset($params['force_limits']) && $params['force_limits'];
        $this->sides              = $params['sides'] ?? 2;
        $this->url                = $params['url'] ?? osc_update_search_url(array('iPage' => '{PAGE}'));
        $this->firstUrl           = $params['first_url'] ?? $this->url;
        $this->nofollow           = $params['nofollow'] ?? false;
        $this->listClass          = $params['list_class'] ?? false;

        // Clamp the selected page into range (internal 1-based, valid up to total-1)
        // so an out-of-range ?iPage= can't render a bogus page number.
        $this->selected = max(1, min((int) $this->selected, max(1, (int) $this->total - 1)));
    }

    /**
     * @return string
     */
    public function doPagination()
    {
        if ($this->total > 1) {
            $links = $this->get_links();
            // Landmark + label on the existing <ul> (no wrapper element, so theme CSS
            // targeting the list is unaffected) for screen-reader navigation.
            $nav = ' role="navigation" aria-label="' . osc_esc_html(__('Pagination')) . '"';
            if ($this->listClass !== false) {
                return '<ul class="' . osc_esc_html($this->listClass) . '"' . $nav . '>'
                    . implode($this->delimiter, $links) . '</ul>';
            }

            return '<ul' . $nav . '>' . implode($this->delimiter, $links) . '</ul>';
        }

        return '';
    }

    /**
     * @return array
     */
    public function get_links()
    {
        $pages = $this->get_pages();

        // Build the items in display order first, then mark the true first/last —
        // computed as locals so instance state is never mutated (the object stays
        // reusable, and `list-last` actually lands on the final item).
        $items = array();
        if (isset($pages['first'])) {
            $items[] = array('text' => $this->text_first, 'href' => $this->pageUrl(1),
                'class' => $this->class_first, 'label' => __('First page'));
        }
        if (isset($pages['prev'])) {
            $items[] = array('text' => $this->text_prev, 'href' => $this->pageUrl($pages['prev']),
                'class' => $this->class_prev, 'label' => __('Previous page'));
        }
        foreach ($pages['pages'] as $p) {
            if ($p == $this->selected) {
                $items[] = array('text' => $p, 'current' => true, 'class' => $this->class_selected);
            } else {
                $items[] = array('text' => $p, 'href' => $this->pageUrl($p),
                    'class' => $this->class_non_selected, 'label' => sprintf(__('Page %s'), $p));
            }
        }
        if (isset($pages['next'])) {
            $items[] = array('text' => $this->text_next, 'href' => $this->pageUrl($pages['next']),
                'class' => $this->class_next, 'label' => __('Next page'));
        }
        if (isset($pages['last'])) {
            $items[] = array('text' => $this->text_last, 'href' => $this->pageUrl($pages['last']),
                'class' => $this->class_last, 'label' => __('Last page'));
        }

        $links = array();
        $count = count($items);
        foreach ($items as $i => $it) {
            $class = $it['class'];
            if ($i === 0) {
                $class .= ' list-first';
            }
            if ($i === $count - 1) {
                $class .= ' list-last';
            }
            if (!empty($it['current'])) {
                $links[] = $this->createSpanTag($it['text'], array('class' => $class, 'aria-current' => 'page'));
                continue;
            }
            $attrs = array('class' => $class, 'href' => $it['href']);
            if (isset($it['label'])) {
                $attrs['aria-label'] = $it['label'];
            }
            if ($this->nofollow) {
                $attrs['rel'] = 'nofollow';
            }
            $links[] = $this->createATag($it['text'], $attrs);
        }

        return $links;
    }

    /**
     * Build the href for a page: page 1 uses the (page-token-stripped) first URL,
     * every other page substitutes {PAGE} into the URL template.
     *
     * @param int|string $p
     *
     * @return string
     */
    protected function pageUrl($p)
    {
        if ((int) $p === 1) {
            return str_replace(array(urlencode('{PAGE}'), '{PAGE}'), '', $this->firstUrl);
        }

        return str_replace(array(urlencode('{PAGE}'), '{PAGE}'), array($p, $p), $this->url);
    }

    /**
     * @return array
     */
    public function get_pages()
    {
        // $this->total is stored as pageCount + 1; pass the real page count.
        return self::computePages((int) $this->total - 1, (int) $this->selected, (int) $this->sides, (bool) $this->force_limits);
    }

    /**
     * @param null $params
     *
     * @return array
     */
    public function get_raw_pages($params = null)
    {
        return self::computeRawPages((int) $this->total - 1, (int) $this->selected, (int) $this->sides);
    }

    /**
     * Pure page-window computation (no globals, no HTML) — testable in isolation.
     * Returns the sliding window of page numbers around $selected plus first/prev/
     * next/last boundary markers (prev/next are '' at the ends).
     *
     * @param int $pageCount total number of pages
     * @param int $selected  the current page, 1-based (clamped into range)
     * @param int $sides     how many pages to show on each side of $selected
     *
     * @return array{first:int, prev:int|string, pages:int[], next:int|string, last:int}
     */
    public static function computeRawPages($pageCount, $selected, $sides = 2)
    {
        $pageCount = max(1, (int) $pageCount);
        $selected  = max(1, min((int) $selected, $pageCount));
        $sides     = max(0, (int) $sides);

        $pages          = array('pages' => array());
        $pages['first'] = 1;
        $pages['prev']  = $selected > 1 ? $selected - 1 : '';

        for ($p = $selected - $sides; $p < $selected; $p++) {
            if ($p >= 1) {
                $pages['pages'][] = $p;
            }
        }
        $pages['pages'][] = $selected;
        for ($p = $selected + 1; $p <= $selected + $sides; $p++) {
            if ($p <= $pageCount) {
                $pages['pages'][] = $p;
            }
        }

        $pages['next'] = $selected < $pageCount ? $selected + 1 : '';
        $pages['last'] = $pageCount;

        return $pages;
    }

    /**
     * Pure computation of the final page set: the raw window with the boundary
     * markers trimmed — first/last dropped when they coincide with the window edges
     * (unless $forceLimits), and empty prev/next removed.
     *
     * @param int  $pageCount
     * @param int  $selected    1-based
     * @param int  $sides
     * @param bool $forceLimits keep first/last even at the window edges
     *
     * @return array
     */
    public static function computePages($pageCount, $selected, $sides = 2, $forceLimits = false)
    {
        $pages = self::computeRawPages($pageCount, $selected, $sides);

        if (!$forceLimits) {
            if ($pages['first'] == $pages['pages'][0]) {
                unset($pages['first']);
            }
            if ($pages['last'] == $pages['pages'][count($pages['pages']) - 1]) {
                unset($pages['last']);
            }
        }
        if ($pages['prev'] === '') {
            unset($pages['prev']);
        }
        if ($pages['next'] === '') {
            unset($pages['next']);
        }

        return $pages;
    }

    /**
     * @param $text
     * @param $attrs
     *
     * @return string
     */
    protected function createATag($text, $attrs)
    {
        return $this->wrapTag('a', $text, $attrs);
    }

    /**
     * @param $text
     * @param $attrs
     *
     * @return string
     */
    protected function createSpanTag($text, $attrs)
    {
        return $this->wrapTag('span', $text, $attrs);
    }

    /**
     * Render one `<li>`-wrapped element with escaped attribute values.
     *
     * @param string $tag   'a' | 'span'
     * @param string $text  inner text (page number or an arrow glyph)
     * @param array  $attrs attribute name => value (values are escaped)
     *
     * @return string
     */
    protected function wrapTag($tag, $text, array $attrs)
    {
        $att = array();
        foreach ($attrs as $k => $v) {
            $att[] = $k . '="' . osc_esc_html($v) . '"';
        }

        return '<li><' . $tag . ' ' . implode(' ', $att) . '>' . $text . '</' . $tag . '></li>';
    }
}

/* file end: ./oc-includes/osclass/classes/Pagination.php */
