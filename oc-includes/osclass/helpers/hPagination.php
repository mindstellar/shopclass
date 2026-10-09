<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Helper Pagination
 *
 * @package    Shopclass
 * @subpackage Helpers
 * @author     Shopclass
 */

/**
 * Gets the pagination links of search pagination
 *
 * @return string pagination links
 */
function osc_search_pagination()
{
    $params = array();
    if (View::getInstance()->_exists('search_uri')) { // CANONICAL URL
        $params['url']       = osc_base_url() . View::getInstance()->_get('search_uri') . '/{PAGE}';
        $params['first_url'] = osc_base_url() . View::getInstance()->_get('search_uri');
    } else {
        $params['first_url'] = osc_update_search_url(array('iPage' => null));
    }
    $pagination = new Pagination($params);

    return $pagination->doPagination();
}

/**
 * Gets the pagination links of comments pagination
 *
 * @return string pagination links
 */
function osc_comments_pagination()
{
    if ((osc_comments_per_page() == 0) || (osc_item_comments_page() === 'all')
        || (osc_item_total_comments() <= osc_comments_per_page())
    ) {
        return '';
    }

    $params     = array(
        'total'    => ceil(osc_item_total_comments() / osc_comments_per_page())
        ,
        'selected' => osc_item_comments_page()
        ,
        'url'      => osc_item_comments_url('{PAGE}')
    );
    $pagination = new Pagination($params);

    return $pagination->doPagination();
}

/**
 * Gets the pagination links of the user's or public profile's listings
 *
 * @param array<string,mixed> $extraParams Merged over the computed Pagination parameters
 * @param string|bool         $field       Sort field carried into the page links
 *
 * @return string pagination links
 */
function osc_pagination_items($extraParams = array(), $field = false)
{
    if (osc_is_public_profile()) {
        $url       = osc_user_list_items_pub_profile_url('{PAGE}', $field);
        $first_url = osc_user_public_profile_url();
    } elseif (osc_is_list_items()) {
        $url       = osc_user_list_items_url('{PAGE}', $field);
        $first_url = osc_user_list_items_url();
    } else {
        // Neither context matched: fall back to the current search URL so $url and
        // $first_url are always defined (was an undefined-variable notice + broken links).
        $url       = osc_update_search_url(array('iPage' => '{PAGE}'));
        $first_url = osc_update_search_url(array('iPage' => null));
    }

    $params = array(
        'total'     => osc_search_total_pages(),
        'selected'  => osc_search_page(),
        'url'       => $url,
        'first_url' => $first_url
    );

    if (is_array($extraParams) && !empty($extraParams)) {
        foreach ($extraParams as $key => $value) {
            $params[$key] = $value;
        }
    }
    $pagination = new Pagination($params);

    return $pagination->doPagination();
}

/**
 * Gets generic pagination links
 *
 * Keys of $params:
 *          'total' => number of total pages (default osc_search_total_pages())
 *          'selected' => number of the page selected (starting at 0) (default osc_search_page())
 *          'class_first' => css class for the first link (default 'searchPaginationFirst')
 *          'class_last' => css class for the last link (default 'searchPaginationLast')
 *          'class_prev' => css class for the prev link (default 'searchPaginationPrev')
 *          'class_next' => css class for the next link (default 'searchPaginationNext')
 *          'text_first' => text for the first link ('<<', 'First', ...) (default '&laquo;')
 *          'text_prev' => text for the prev link ('<', 'Previous', ...) (default '&lt;')
 *          'text_next' => text for the next link ('>', 'Next', ...) (default '&gt;')
 *          'text_last' => text for the last link ('>>', 'Last', ...) (default '&raquo;')
 *          'class_selected' => css class for the selected link (default 'searchPaginationSelected')
 *          'class_non_selected' => css class for non selected links (default 'searchPaginationNonSelected')
 *          'delimiter' => delimiter between links (default " ")
 *          'force_limits' => Always show the first/last links even if you're already on first/last page (default
 *          false)
 *          'sides' => How many pages to show (default 2)
 *          'url' => Format of the URL of the links, put "{PAGE}" on the page variable. Example
 *          http://www.example.com/index.php?page=search&amp;sCategory=2&amp;iPage={PAGE} (default
 *          osc_update_search_url(array('iPage' => '{PAGE}'))
 *          'first_url' => Format of the FIRST URL of the links, if you want to avoid to have the page variable
 *          "{PAGE}" on the page variable. Example
 *          http://www.example.com/index.php?page=search&amp;sCategory=2&amp; (default
 *          osc_update_search_url(array('iPage' => null))
 *
 * @param array<string,mixed>|null $params
 *
 * @return string pagination links
 */
function osc_pagination($params = null)
{
    $pagination = new Pagination($params);

    return $pagination->doPagination();
}

/**
 * Print the admin list pager: the range line, a "go to page" form and the page links.
 * osc_admin_pagination() and osc_admin_pager() both draw through this.
 *
 * @param array<string,mixed> $aData iTotalDisplayRecords, iDisplayLength, optional iPage (default the
 *                                   request's), base_url (default this request without its page),
 *                                   iTotalRecords, iShown or aRows/aaData, and show_range to print the range line
 *
 * @return void
 */
function osc_show_pagination_admin($aData)
{
    $perPage    = max(1, (int) ($aData['iDisplayLength'] ?? 0));
    $pageActual = max(1, (int) ($aData['iPage'] ?? \mindstellar\admin\ListPaging::page()));
    $filtered   = (int) ($aData['iTotalDisplayRecords'] ?? 0);
    $pageTotal  = (int) ceil($filtered / $perPage);
    $urlActual  = isset($aData['base_url'])
        ? (string) $aData['base_url']
        : osc_admin_base_url(true) . '?' . Params::getServerParam('QUERY_STRING', false, false);
    // Old links may carry pageNum; the page always goes out as iPage.
    $urlActual  = rtrim((string) preg_replace('/(?<=[?&])(iPage|pageNum)=[^&]*(&|$)/', '', $urlActual), '&');
    $hidden     = array();
    foreach (explode('&', (string) parse_url($urlActual, PHP_URL_QUERY)) as $pair) {
        if ($pair !== '') {
            $kv       = explode('=', $pair, 2);
            $hidden[] = array(urldecode($kv[0]), urldecode($kv[1] ?? ''));
        }
    }
    $shown = isset($aData['iShown'])
        ? (int) $aData['iShown']
        : count($aData['aRows'] ?? $aData['aaData'] ?? array());
    $from  = (($pageActual - 1) * $perPage) + 1;
    $total = isset($aData['iTotalRecords']) ? (int) $aData['iTotalRecords'] : null;
    ?>
    <div class="has-pagination">
        <?php osc_run_hook('before_show_pagination_admin');
        // An empty list has no range to state; the empty row inside the table says it.
        if (!empty($aData['show_range']) && $shown > 0) {
            echo '<ul class="showing-results"><li><span>'
                . osc_pagination_showing($from, $from + $shown - 1, $filtered, $total)
                . '</span></li></ul>';
        }
        if ($pageTotal > 1) { ?>
            <form method="get" action="<?php echo osc_esc_html(strtok($urlActual, '?')); ?>" style="display:inline;" nocsrf>
                <?php foreach ($hidden as [$key, $value]) { ?>
                    <input type="hidden" name="<?php echo osc_esc_html($key); ?>"
                           value="<?php echo osc_esc_html($value); ?>"/>
                <?php } ?>
                <ul>
                    <li>
                        <span class="list-first"><?php _e('Page'); ?></span>
                    </li>
                    <li class="pagination-input">
                        <input id="gotoPage" type="text" name="iPage" value="<?php echo $pageActual; ?>"/>
                        <button type="submit"><?php _e('Go!'); ?></button>
                    </li>
                </ul>
            </form>
            <?php
            $pagination = new Pagination(array(
                'total'    => $pageTotal,
                'selected' => $pageActual - 1,
                'url'      => $urlActual . '&iPage={PAGE}',
                'sides'    => 5
            ));
            echo $pagination->doPagination();
        }
        osc_run_hook('after_show_pagination_admin');
        ?>
    </div>
    <?php
}

/**
 * The "Showing x to y of z results" line under a paginated table.
 *
 * @param int      $from
 * @param int      $to
 * @param int      $filtered
 * @param int|null $total    Unfiltered total, when it differs from $filtered
 *
 * @return string
 */
function osc_pagination_showing($from, $to, $filtered, $total = null)
{
    if ($to == 0 || $filtered == 0) {
        $from = $to = $filtered = 0;
    }
    if ($total != null && $total > $filtered) {
        return sprintf(
            __('Showing %s to %s of %s results (filtered from %s total results)'),
            $from,
            $to,
            $filtered,
            $total
        );
    }

    return sprintf(__('Showing %s to %s of %s results'), $from, $to, $filtered);
}

?>