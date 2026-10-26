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
 * Newer and older links under a paged list. Reads $pager: prev and next (a URL, or empty
 * when there is no such page) and class (extra classes, optional).
 */

/** @var array<string, string> $pager */
$pagerPrev  = (string) ($pager['prev'] ?? '');
$pagerNext  = (string) ($pager['next'] ?? '');
$pagerClass = (string) ($pager['class'] ?? '');
?>
                <div class="oe-pager<?php echo $pagerClass !== '' ? ' ' . osc_esc_html($pagerClass) : ''; ?>">
                    <span>
                        <?php if ($pagerPrev !== '') { ?>
                            <a href="<?php echo osc_esc_html($pagerPrev); ?>">
                                <?php echo osc_esc_html(_m('Newer')); ?>
                            </a>
                        <?php } ?>
                    </span>
                    <span>
                        <?php if ($pagerNext !== '') { ?>
                            <a href="<?php echo osc_esc_html($pagerNext); ?>">
                                <?php echo osc_esc_html(_m('Older')); ?>
                            </a>
                        <?php } ?>
                    </span>
                </div>
