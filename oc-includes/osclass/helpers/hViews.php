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
 * Cache-safe item view counting.
 *
 * When a listing detail page is served from a full-page cache (see hHttpCache.php), PHP never
 * runs, so the render-time view increment in CWebItem is skipped and views undercount. This
 * counts instead from a tiny client beacon: the item page carries a script that POSTs to a
 * dedicated endpoint on load, and the endpoint does the increment. The beacon request is a POST,
 * so it is never cached and always executes, and being JS-gated it also stops crawlers that do
 * not run JavaScript from inflating the count.
 *
 * On by default so a fresh (cacheable) install counts views accurately without configuration;
 * a theme that already ships its own beacon, or a site that prefers render-time counting, turns
 * it off via the `item_view_beacon_enabled` filter or the `item_view_beacon` preference set to '0'.
 */

/**
 * Whether the client view beacon owns view counting. Default ON (unset preference = enabled).
 *
 * @return bool
 */
function osc_item_view_beacon_enabled()
{
    $pref    = osc_get_preference('item_view_beacon');
    $enabled = ($pref === '' || $pref === null) ? true : ((string)$pref !== '0');

    return (bool)osc_apply_filter('item_view_beacon_enabled', $enabled);
}

/**
 * The endpoint the beacon POSTs to for a given listing id.
 *
 * @param int $id
 *
 * @return string
 */
function osc_item_view_beacon_url($id)
{
    return osc_base_url(true) . '?page=item&action=view_beacon&id=' . (int)$id;
}

/**
 * Suppress the render-time view increment when the beacon owns counting, so a cached page and
 * the beacon never both count (and a no-JS bot is not counted at render while a human is counted
 * by the beacon). Filters `count_view_on_render`, whose value is otherwise osc_request_counts_as_view().
 *
 * @param bool  $countsAsView
 * @param mixed $item
 *
 * @return bool
 */
function osc_view_beacon_suppress_render_count($countsAsView, $item = null)
{
    return osc_item_view_beacon_enabled() ? false : $countsAsView;
}

osc_add_filter('count_view_on_render', 'osc_view_beacon_suppress_render_count');

/**
 * Emit the beacon <script> at the end of the listing detail page. CWebItem sets
 * $GLOBALS['osc_view_beacon_item_id'] only while rendering a public listing detail with the
 * beacon enabled, so this fires there and nowhere else. It is baked into the cached HTML, so it
 * runs for every visitor of the cached page and reports one view per real browser load.
 * sendBeacon is fire-and-forget and survives page unload; fetch(keepalive) is the fallback.
 *
 * @return void
 */
function osc_render_item_view_beacon()
{
    if (empty($GLOBALS['osc_view_beacon_item_id'])) {
        return;
    }
    $url = json_encode(
        osc_item_view_beacon_url((int)$GLOBALS['osc_view_beacon_item_id']),
        JSON_UNESCAPED_SLASHES
    );
    echo "\n<script>(function(){try{var u=" . $url . ";"
        . "if(navigator.sendBeacon){navigator.sendBeacon(u);}"
        . "else if(window.fetch){fetch(u,{method:'POST',keepalive:true,credentials:'same-origin'});}"
        . "}catch(e){}})();</script>\n";
}

osc_add_hook('after_html', 'osc_render_item_view_beacon');

/* file end: ./oc-includes/osclass/helpers/hViews.php */
