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
 * Render callable for the sample_widgets.recent_listings type.
 *
 * Queries the newest published listings via the core Search model. A fresh
 * Search defaults to enabled, active, non-spam, non-expired items ordered by
 * publish date descending, so this is the standard "latest listings" set. Every
 * dynamic value is escaped with osc_esc_html(); the URL comes from the
 * view-independent osc_item_url_from_item() helper so rendering in a header or
 * footer never disturbs a page's own item loop.
 *
 * @param array $config    Ignored — this type takes no configuration.
 * @param array $widgetRow The t_widget row being rendered.
 *
 * @return string
 */
function sample_widgets_render_recent_listings(array $config = array(), array $widgetRow = array())
{
    $limit  = 5;
    $search = new Search();
    $search->limit(0, $limit);
    $items = $search->doSearch();

    if (!is_array($items) || count($items) === 0) {
        return '';
    }

    $html = '<div class="sample-widget sample-widget-recent">'
        . '<ul class="sample-widget-list">';
    foreach ($items as $item) {
        $url   = osc_esc_html(osc_item_url_from_item($item));
        $title = osc_esc_html($item['s_title'] ?? '');
        $html  .= '<li><a href="' . $url . '">' . $title . '</a></li>';
    }
    $html .= '</ul></div>';

    return $html;
}
