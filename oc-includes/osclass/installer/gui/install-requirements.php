<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/**
 * Step 1 body: one line when the server is fine, only the failures when it is not.
 * Requirement labels and fixes hold our own markup (code, links), so they are printed as they are.
 *
 * @var array<int,array{requirement:string,fn:bool,solution:string}> $requirements
 * @var int $ins_fail_count Set here for the action bar.
 */

$ins_failed = array_values(array_filter($requirements, static fn (array $r): bool => !$r['fn']));
$ins_total = count($requirements);
$ins_fail_count = count($ins_failed);
$ins_pass_count = $ins_total - $ins_fail_count;

$ins_icon_check = '<svg class="ins-icon" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8.5l3 3 7-7"/></svg>';
$ins_icon_x = '<svg class="ins-icon" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4l8 8M12 4l-8 8"/></svg>';
$ins_icon_alert = '<svg class="ins-icon" viewBox="0 0 16 16" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 1.8l6.5 11.4h-13z"/><path d="M8 6.5v3M8 11.5v.1"/></svg>';

if ($ins_fail_count === 0) { ?>
    <div class="ins-verdict">
        <span class="ins-seal"><?php echo $ins_icon_check; ?></span>
        <div>
            <p class="ins-verdict-title"><?php echo osc_esc_html(__('Your server is ready')); ?></p>
            <p class="ins-verdict-text"><?php echo osc_esc_html(sprintf(_n('The %d check passed.', 'All %d checks passed.', $ins_total), $ins_total)); ?></p>
        </div>
    </div>
    <details class="ins-reqs-more">
        <summary>
            <span class="ins-when-closed"><?php echo osc_esc_html(sprintf(_n('Show %d check', 'Show all %d checks', $ins_total), $ins_total)); ?></span>
            <span class="ins-when-open"><?php echo osc_esc_html(__('Hide the checks')); ?></span>
        </summary>
        <ul class="ins-reqs">
            <?php foreach ($requirements as $ins_req) { ?>
                <li><?php echo $ins_icon_check; ?><span><?php echo $ins_req['requirement']; ?></span></li>
            <?php } ?>
        </ul>
    </details>
    <div class="ins-ready">
        <h2 class="ins-group"><?php echo osc_esc_html(__('Have these ready')); ?></h2>
        <ol>
            <li><span><b><?php echo osc_esc_html(__('Your database details.')); ?></b> <?php echo osc_esc_html(__('Name, username and password, from your hosting panel. Create an empty database there first if you don’t have one.')); ?></span></li>
            <li><span><b><?php echo osc_esc_html(__('An email address for the site.')); ?></b> <?php echo osc_esc_html(__('Your login is sent there.')); ?></span></li>
        </ol>
    </div>
<?php } else { ?>
    <div class="ins-verdict is-bad">
        <span class="ins-seal"><?php echo $ins_icon_x; ?></span>
        <div>
            <p class="ins-verdict-title"><?php echo osc_esc_html(sprintf(_n('%d thing needs fixing first', '%d things need fixing first', $ins_fail_count), $ins_fail_count)); ?></p>
            <p class="ins-verdict-text"><?php echo osc_esc_html(__('Fix what is listed below in your hosting panel, then check again. Nothing has been installed yet.')); ?></p>
        </div>
    </div>
    <ul class="ins-problems">
        <?php foreach ($ins_failed as $ins_req) { ?>
            <li>
                <?php echo $ins_icon_alert; ?>
                <div>
                    <p class="ins-problem-title"><?php echo $ins_req['requirement']; ?></p>
                    <?php if ($ins_req['solution'] !== '') { ?>
                        <p class="ins-problem-fix"><?php echo $ins_req['solution']; ?></p>
                    <?php } ?>
                </div>
            </li>
        <?php } ?>
    </ul>
    <?php if ($ins_pass_count > 0) { ?>
        <p class="ins-passed-line"><?php echo $ins_icon_check; ?><?php echo osc_esc_html(sprintf(_n('%d other check passed.', '%d other checks passed.', $ins_pass_count), $ins_pass_count)); ?></p>
    <?php } ?>
    <p class="ins-help"><a href="https://github.com/mindstellar/shopclass/discussions" target="_blank" rel="noopener" hreflang="en"><?php echo osc_esc_html(__('Need more help?')); ?></a></p>
<?php } ?>
