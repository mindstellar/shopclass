<?php
if (!defined('ABS_PATH')) {
exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
/**
 * Another member's public page -- markup only.
 *
 * Reads the `user` and `items` view variables the controller exported. Nothing
 * here is the visitor's own account, so no account nav and no owner actions.
 */

$publicInfo = osc_user_info();
?>
<?php osc_show_flash_message(); ?>

<?php if ($publicInfo !== '') { ?>
    <div class="oe-panel"><?php echo $publicInfo; ?></div>
<?php } ?>

<p class="oe-meta">
    <?php if (osc_user_website() !== '') { ?>
        <a href="<?php echo osc_esc_html(osc_user_website()); ?>" rel="nofollow noopener"><?php
            echo osc_esc_html(osc_user_website()); ?></a>
    <?php } ?>
    <span><?php printf(
        osc_esc_html(_m('Member since %s')),
        osc_esc_html(osc_format_date(osc_user_regdate()))
    ); ?></span>
</p>

<h2><?php echo osc_esc_html(_m('Listings')); ?></h2>
<?php if (osc_count_items() === 0) { ?>
    <p class="oe-empty"><?php echo osc_esc_html(_m('Nothing published.')); ?></p>
<?php } else { ?>
    <ul class="oe-list">
        <?php $rowOwned = false;
        while (osc_has_items()) {
            require __DIR__ . '/parts/item-row.php';
        }
        unset($rowOwned); ?>
    </ul>
    <?php $publicPager = osc_pagination_items();
    if ($publicPager !== '') { ?>
        <nav class="oe-pager" aria-label="<?php echo osc_esc_html(_m('Pages')); ?>"><?php
            echo $publicPager; ?></nav>
    <?php } ?>
<?php } ?>
