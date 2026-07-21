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
 * Form inputs for the keyword blocklist admin screen. BanRuleForm-shaped.
 */
class KeywordBlockForm extends Form
{
    /**
     * @param array|null $keyword
     */
    public static function primary_input_hidden($keyword = null)
    {
        parent::generic_input_hidden('id', (isset($keyword['pk_i_id']) ? $keyword['pk_i_id'] : ''));
    }

    /**
     * @param array|null $keyword
     */
    public static function keyword_text($keyword = null)
    {
        parent::generic_input_text('s_keyword', isset($keyword['s_keyword']) ? $keyword['s_keyword'] : '');
    }

    /**
     * @param array|null $keyword
     */
    public static function scope_select($keyword = null)
    {
        $current = isset($keyword['s_scope']) ? $keyword['s_scope'] : 'all';
        $items   = array(
            array('k' => 'title', 'v' => __('Title only')),
            array('k' => 'description', 'v' => __('Description only')),
            array('k' => 'all', 'v' => __('Title and description')),
            array('k' => 'meta', 'v' => __('Custom fields')),
        );

        parent::generic_select('s_scope', $items, 'k', 'v', __('Where to match'), $current);
    }

    /**
     * @param array|null $keyword
     */
    public static function substring_checkbox($keyword = null)
    {
        $checked = isset($keyword['b_substring']) && (int)$keyword['b_substring'] === 1;
        parent::generic_input_checkbox('b_substring', '1', $checked);
    }
}
