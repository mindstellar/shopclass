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
 * Select options for the sample_widgets.category_listings "category" field.
 *
 * Flattens the enabled category tree into a value=>label option list, indenting
 * child categories so the hierarchy stays readable in the admin dropdown. An
 * empty first option means "any category". Returned in the ['value','label']
 * shape the registry select whitelist and the admin form both accept.
 *
 * @return array<int,array{value:string,label:string}>
 */
function sample_widgets_category_options()
{
    $options = array(
        array('value' => '', 'label' => '— Any category —'),
    );

    $tree = Category::newInstance()->toTree();
    if (is_array($tree)) {
        sample_widgets_flatten_categories($tree, $options, 0);
    }

    return $options;
}

/**
 * Recursively append a category tree to a flat option list.
 *
 * @param array $nodes   Category tree nodes (each with pk_i_id, s_name, categories).
 * @param array $options Option list to append to, by reference.
 * @param int   $depth   Current nesting depth, used for indentation.
 *
 * @return void
 */
function sample_widgets_flatten_categories(array $nodes, array &$options, $depth)
{
    foreach ($nodes as $node) {
        if (!isset($node['pk_i_id'])) {
            continue;
        }
        $prefix    = str_repeat('— ', $depth);
        $options[] = array(
            'value' => (string) $node['pk_i_id'],
            'label' => $prefix . (string) ($node['s_name'] ?? $node['pk_i_id']),
        );
        if (!empty($node['categories']) && is_array($node['categories'])) {
            sample_widgets_flatten_categories($node['categories'], $options, $depth + 1);
        }
    }
}

/**
 * Render callable for the sample_widgets.category_listings type.
 *
 * Reads the saved config (count, category) — the JSON round-trip the controller
 * stored in s_config — and lists the newest published listings from the chosen
 * category. With no category selected it lists across all categories. Every
 * dynamic value is escaped with osc_esc_html().
 *
 * @param array $config    Saved config: 'count' (int), 'category' (category id).
 * @param array $widgetRow The t_widget row being rendered.
 *
 * @return string
 */
function sample_widgets_render_category_listings(array $config = array(), array $widgetRow = array())
{
    $count = isset($config['count']) ? (int) $config['count'] : 5;
    if ($count < 1) {
        $count = 5;
    }
    if ($count > 50) {
        $count = 50;
    }

    $categoryId = isset($config['category']) ? (int) $config['category'] : 0;

    $search = new Search();
    if ($categoryId > 0) {
        $search->addCategory($categoryId);
    }
    $search->limit(0, $count);
    $items = $search->doSearch();

    if (!is_array($items) || count($items) === 0) {
        return '';
    }

    $html = '<div class="sample-widget sample-widget-category">'
        . '<ul class="sample-widget-list">';
    foreach ($items as $item) {
        $url   = osc_esc_html(osc_item_url_from_item($item));
        $title = osc_esc_html($item['s_title'] ?? '');
        $html  .= '<li><a href="' . $url . '">' . $title . '</a></li>';
    }
    $html .= '</ul></div>';

    return $html;
}
