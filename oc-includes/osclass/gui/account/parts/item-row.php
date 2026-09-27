<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
/**
 * One listing in an account list. Included from inside a `while (osc_has_items())`
 * loop, so it reads the current item through the osc_item_* helpers.
 *
 * $rowOwned (bool, default true) -- show the owner's status badges and actions.
 * $rowContext (string) -- passed to the listing_row_* filters.
 *
 * Badges are ['label', 'class']; meta entries are ['text'] with an optional 'url' or
 * 'datetime'; actions are ['label', 'url', 'class', 'confirm'], plus 'method' => 'post'
 * with 'fields' for a form, and 'group' => 'promote' for the paid upgrades line.
 */

$rowOwned   = isset($rowOwned) ? (bool) $rowOwned : true;
$rowContext = isset($rowContext) ? (string) $rowContext : ($rowOwned ? 'user_items' : 'public_profile');
$rowItem    = osc_item();

$rowBadges = array();
if ($rowOwned) {
    // The first true state is the one the seller has to act on.
    if (!osc_item_is_enabled()) {
        $rowBadges['status'] = array('label' => _m('Blocked'), 'class' => 'cancelled');
    } elseif (osc_item_is_spam()) {
        $rowBadges['status'] = array('label' => _m('Marked as spam'), 'class' => 'cancelled');
    } elseif (!osc_item_is_active()) {
        $rowBadges['status'] = array('label' => _m('Awaiting validation'), 'class' => 'pending');
    } elseif (osc_item_is_expired()) {
        $rowBadges['status'] = array('label' => _m('Expired'), 'class' => 'refunded');
    } else {
        $rowBadges['status'] = array('label' => _m('Published'), 'class' => 'paid');
    }
    if (osc_item_is_premium()) {
        $rowBadges['premium'] = array('label' => _m('Featured'), 'class' => 'paid');
    }
    if (osc_item_is_highlighted()) {
        $rowBadges['highlight'] = array('label' => _m('Highlighted'), 'class' => 'paid');
    }
    if (osc_item_is_urgent()) {
        $rowBadges['urgent'] = array('label' => _m('Urgent'), 'class' => 'pending');
    }
}
$rowBadges = (array) osc_apply_filter('listing_row_badges', $rowBadges, $rowItem, $rowContext);

$rowMeta = array(
    'category' => array('text' => (string) osc_item_category()),
    'date'     => array(
        'text'     => (string) osc_format_date(osc_item_pub_date()),
        'datetime' => date('Y-m-d', (int) strtotime((string) osc_item_pub_date())),
    ),
);
if ($rowOwned && osc_item_views_enabled()) {
    $rowViews         = (int) osc_item_views();
    $rowMeta['views'] = array('text' => sprintf(_mn('%d view', '%d views', $rowViews), $rowViews));
}
$rowMeta = (array) osc_apply_filter('listing_row_meta', $rowMeta, $rowItem, $rowContext);

$rowActions = array();
if ($rowOwned) {
    $rowActions['edit']   = array('label' => _m('Edit'), 'url' => osc_item_edit_url());
    $rowActions['delete'] = array(
        'label'   => _m('Delete'),
        'url'     => osc_item_delete_url(),
        'class'   => 'oe-danger-link',
        'confirm' => sprintf(_m('Delete "%s"? This cannot be undone.'), osc_item_title()),
    );
}
if ($rowOwned && $rowContext === 'user_items') {
    foreach (osc_item_upgrade_offers($rowItem) as $offer) {
        $rowActions['upgrade_' . $offer['feature']] = array(
            'label'  => $offer['credits'] > 0
                ? sprintf(_mn('%1$s (%2$d credit)', '%1$s (%2$d credits)', $offer['credits']), $offer['label'], $offer['credits'])
                : sprintf(_m('%s (free)'), $offer['label']),
            'url'    => osc_item_upgrade_url((int) osc_item_id(), $offer['feature']),
            'method' => 'post',
            'group'  => 'promote',
        );
    }
}
$rowActions = (array) osc_apply_filter('listing_row_actions', $rowActions, $rowItem, $rowContext);

?>
<li class="oe-list-item">
    <?php if (osc_images_enabled_at_items() && osc_has_item_resources()) { ?>
        <img class="oe-thumb" src="<?php echo osc_esc_html(osc_resource_thumbnail_url()); ?>" alt=""
             width="88" height="73" loading="lazy" decoding="async">
    <?php } else { ?>
        <span class="oe-thumb oe-thumb-empty"><?php echo osc_esc_html(_m('No photo')); ?></span>
    <?php } ?>

    <div class="oe-list-body">
        <h3><a href="<?php echo osc_esc_html(osc_item_url()); ?>"<?php echo $rowOwned ? '' : ' rel="ugc"'; ?>><?php
            echo osc_esc_html(osc_item_title()); ?></a></h3>
        <p class="oe-meta">
            <?php foreach ($rowBadges as $badge) {
                if (!is_array($badge) || !isset($badge['label'])) {
                    continue;
                } ?>
                <span class="oe-badge <?php echo osc_esc_html((string) ($badge['class'] ?? '')); ?>"><?php
                    echo osc_esc_html((string) $badge['label']); ?></span>
            <?php }
            foreach ($rowMeta as $meta) {
                if (!is_array($meta) || !isset($meta['text'])) {
                    continue;
                }
                if (!empty($meta['datetime'])) { ?>
                    <time datetime="<?php echo osc_esc_html((string) $meta['datetime']); ?>"><?php
                        echo osc_esc_html((string) $meta['text']); ?></time>
                <?php } elseif (!empty($meta['url'])) { ?>
                    <a href="<?php echo osc_esc_html((string) $meta['url']); ?>"><?php
                        echo osc_esc_html((string) $meta['text']); ?></a>
                <?php } else { ?>
                    <span><?php echo osc_esc_html((string) $meta['text']); ?></span>
                <?php }
            } ?>
        </p>
        <?php require __DIR__ . '/row-actions.php'; ?>
    </div>

    <p class="oe-price"><?php echo osc_esc_html(osc_item_formatted_price()); ?></p>
</li>
