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
 * Item-moderation helpers: keyword blocklist accessors, and the enforcement and
 * reporting behaviour wired onto the item lifecycle hooks.
 *
 * @package    Shopclass
 * @subpackage Helpers
 */

/** Preference section the item-moderation settings live under. */
const OSC_MODERATION_SECTION = 'moderation';

/**
 * Is the keyword blocklist active?
 *
 * @return bool
 */
function osc_keyword_block_enabled()
{
    return osc_get_bool_preference('keyword_spam_enabled', OSC_MODERATION_SECTION);
}

/**
 * Should a keyword hit reject the listing at post time (hard block) rather than
 * quarantine it for review? Off by default; quarantine is the default behaviour.
 *
 * @return bool
 */
function osc_keyword_block_hard_block()
{
    return osc_get_bool_preference('keyword_spam_hard_block', OSC_MODERATION_SECTION);
}

/**
 * Is report-driven auto-blocking active?
 *
 * @return bool
 */
function osc_report_autoblock_enabled()
{
    return osc_get_bool_preference('report_autoblock', OSC_MODERATION_SECTION);
}

/**
 * Distinct-reporter count at which a listing is auto-disabled.
 *
 * @return int
 */
function osc_report_threshold()
{
    return (int)osc_get_preference('report_threshold', OSC_MODERATION_SECTION);
}

/**
 * Run the keyword filter over a freshly posted or edited listing and, on a hit,
 * quarantine it: flag it spam and disable it (both through ItemActions so the
 * lifecycle hooks fire and the search index stays in step), then record why in
 * the moderation log. Reversible — an admin re-enables it from the usual screen.
 *
 * @param array $item item row-shaped array as fired by posted_item / edited_item
 */
function osc_keyword_spam_enforce($item)
{
    if (!osc_keyword_block_enabled()) {
        return;
    }
    if (!is_array($item) || empty($item['pk_i_id'])) {
        return;
    }

    $hit = ItemSpamFilter::newInstance()->check($item);
    if ($hit === null) {
        return;
    }

    $itemId      = (int)$item['pk_i_id'];
    $itemActions = new ItemActions(true);
    $itemActions->spam($itemId, true);
    $itemActions->disable($itemId);

    ItemModerationLog::newInstance()->add($itemId, 'keyword', $hit['keyword'], $hit['field'], 'spam');
}

/**
 * Optional hard-block path (off by default): reject the listing before it is
 * inserted when it contains a blocked keyword. Wired onto the pre_item_add_error
 * filter, so returning a non-empty string aborts the insert.
 *
 * @param string $flash_error the accumulated validation error
 * @param array  $aItem       the submitted item data (locale-keyed title/desc)
 *
 * @return string
 */
function osc_keyword_spam_hard_block($flash_error, $aItem)
{
    if ($flash_error) {
        return $flash_error;
    }
    if (!osc_keyword_block_enabled() || !osc_keyword_block_hard_block()) {
        return $flash_error;
    }

    $title = isset($aItem['title']) && is_array($aItem['title']) ? implode(' ', $aItem['title']) : (string)($aItem['title'] ?? '');
    $desc  = isset($aItem['description']) && is_array($aItem['description'])
        ? implode(' ', $aItem['description'])
        : (string)($aItem['description'] ?? '');

    $hit = ItemSpamFilter::newInstance()->check(array(
        'pk_i_id'       => 0,
        's_title'       => $title,
        's_description' => $desc,
    ));

    if ($hit !== null) {
        return __('Your listing could not be posted. Please review its content and try again.');
    }

    return $flash_error;
}

/**
 * Record a report (deduplicated, one vote per reporter) whenever a listing is
 * marked, and auto-disable it once enough distinct people have reported it.
 *
 * Reacts to the item_marked action, which fires after core's own t_item_stats
 * counter has been incremented — that raw counter is left untouched, so the
 * stock reported-listings screen keeps working. The distinct-reporter count used
 * for the threshold comes from the deduplicated report log instead.
 *
 * @param int    $id
 * @param string $as report reason
 */
function osc_item_report_record($id, $as)
{
    $id = (int)$id;
    if ($id <= 0) {
        return;
    }

    ItemReport::newInstance()->log($id, $as);

    if (!osc_report_autoblock_enabled()) {
        return;
    }

    $threshold = osc_report_threshold();
    if ($threshold <= 0) {
        return;
    }

    $reporters = ItemReport::newInstance()->countReporters($id);
    if ($reporters < $threshold) {
        return;
    }

    $item = Item::newInstance()->findByPrimaryKey($id);
    // Already blocked (or gone): without this every further report would call
    // disable() again and re-fire disable_item.
    if (empty($item) || (int)$item['b_enabled'] === 0) {
        return;
    }

    // disable(), not spam(): an automated, reversible hide pending review — not a
    // judgement that the listing IS spam.
    $itemActions = new ItemActions(true);
    $itemActions->disable($id);

    ItemModerationLog::newInstance()->add($id, 'report_threshold', 'reports:' . $reporters, '', 'disable');
}

/**
 * Clear an item's reports when it is re-enabled, so the reports already on file
 * do not immediately auto-block it again.
 *
 * @param int $id
 */
function osc_item_report_clear($id)
{
    ItemReport::newInstance()->clear((int)$id);
}

osc_add_hook('posted_item', 'osc_keyword_spam_enforce', 8);
osc_add_hook('edited_item', 'osc_keyword_spam_enforce', 8);
osc_add_hook('pre_item_add_error', 'osc_keyword_spam_hard_block', 8);
osc_add_hook('item_marked', 'osc_item_report_record', 8);
osc_add_hook('enable_item', 'osc_item_report_clear', 8);
