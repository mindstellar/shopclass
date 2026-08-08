<?php if (!defined('OC_ADMIN')) {
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
 * Browse / Updates market tabs (docs/MARKET.md §8.2), shared by plugins/index.php
 * and appearance/index.php so both surfaces stay one design. Every function takes
 * $type as either 'plugin' or 'theme' and reads only the row shapes the controllers'
 * buildMarketViewData() exports ($aMarketBrowse, $aMarketUpdates, $aMarketMeta) --
 * no controller state is assumed beyond that contract.
 */

/**
 * Maps a Compatibility::evaluate() status to one of the admin's existing
 * .status-* badge classes (table.scss / definitions.scss) so a compatibility
 * verdict looks and reads like every other status in the admin.
 *
 * @param string $status Compatibility::OK / UNTESTED / INCOMPATIBLE / UNDECLARED
 *
 * @return string
 */
function osc_market_compat_class($status)
{
    switch ($status) {
        case 'ok':
            return 'active';
        case 'untested':
            return 'untested';
        case 'incompatible':
            return 'blocked';
        default:
            return 'undeclared';
    }
}

/**
 * Hue (0-359) derived from a hash of the slug -- the same function
 * appearance/index.php uses for its own placeholder tiles, so a browse grid of
 * unillustrated packages reads consistently with the installed-themes grid.
 *
 * @param string $slug
 *
 * @return int
 */
function osc_market_thumb_hue($slug)
{
    return crc32($slug) % 360;
}

/**
 * Artwork for a Browse-tab row (not installed locally). Prefers the catalog's own
 * icon URL when the row carries one; otherwise falls back to the real
 * osc_plugin_icon_url()/osc_theme_screenshot_url() helper, which for a package with
 * nothing on disk resolves to the bundled placeholder SVG -- the same placeholder
 * the Installed/Appearance screens already render, never a second one invented here.
 *
 * @param string      $type       'plugin' or 'theme'
 * @param string      $slug
 * @param string|null $catalogArt row's `icon` field
 *
 * @return array{src:string, has:bool}
 */
function osc_market_browse_art($type, $slug, $catalogArt)
{
    if (is_string($catalogArt) && $catalogArt !== '') {
        return array('src' => $catalogArt, 'has' => true);
    }

    $src = $type === 'theme' ? osc_theme_screenshot_url($slug) : osc_plugin_icon_url($slug);

    return array('src' => $src, 'has' => false);
}

/**
 * Artwork for an Updates-tab row: the package is installed, so this reads the same
 * local-disk helpers the Installed/Themes screens already use.
 *
 * @param string $type 'plugin' or 'theme'
 * @param string $slug
 *
 * @return array{src:string, has:bool}
 */
function osc_market_installed_art($type, $slug)
{
    $has = $type === 'theme' ? osc_theme_has_screenshot($slug) : osc_plugin_has_icon($slug);
    $src = $type === 'theme' ? osc_theme_screenshot_url($slug) : osc_plugin_icon_url($slug);

    return array('src' => $src, 'has' => $has);
}

/**
 * The osc-thumb tile markup shared with the Appearance grid: real art, or the
 * hash-tinted placeholder with the package's initial overlaid.
 *
 * @param array  $art  {src, has} from osc_market_browse_art()/osc_market_installed_art()
 * @param string $slug
 * @param string $name
 */
function osc_market_render_thumb($art, $slug, $name)
{
    ?>
    <div class="osc-thumb market-card-thumb<?php echo $art['has'] ? '' : ' osc-thumb--fallback'; ?>"
         <?php if (!$art['has']) : ?>style="--osc-thumb-hue: <?php echo (int) osc_market_thumb_hue($slug); ?>"<?php endif; ?>>
        <img src="<?php echo osc_esc_html($art['src']); ?>"
             alt="" width="400" height="300" loading="lazy"/>
        <?php if (!$art['has']) : ?>
            <span class="osc-thumb-letter" aria-hidden="true"><?php echo osc_esc_html(mb_strtoupper(mb_substr($name, 0, 1))); ?></span>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * The compatibility badge -- tint, shape and word (The Three Signal Rule) -- reusing
 * the admin's existing .osc-status component instead of a market-specific one. The word
 * is the package's published supported range ("Works with 6.0 – 6.1", "6.0 and newer"),
 * never a verdict against this install; the tint still comes from the locally-evaluated
 * status so an incompatible or untested package still reads differently at a glance.
 *
 * @param array $compat {status, blocked, reason, badge}
 */
function osc_market_render_compat_badge($compat)
{
    $class = osc_market_compat_class($compat['status'] ?? 'undeclared');
    ?>
    <div class="market-card-status status-<?php echo osc_esc_html($class); ?>">
        <span class="osc-status"><?php echo osc_esc_html($compat['badge'] ?? ''); ?></span>
    </div>
    <?php
}

/**
 * A muted, informational note for a package that hasn't been tested with the running core
 * yet -- distinct from osc_market_render_action()'s blocked-reason paragraph, which only
 * ever appears when the button is actually disabled. Untested never disables anything
 * (Compatibility::evaluate() returns blocked:false for it), so this must never read as a
 * warning -- just a fact the owner might want before installing.
 *
 * @param array $compat row's `compat`
 */
function osc_market_render_untested_note($compat)
{
    if (($compat['status'] ?? '') !== 'untested') {
        return;
    }
    ?>
    <p class="market-card-note">
        <?php echo osc_esc_html(__("Not tested with your Shopclass version yet. Installing and updating still work as normal.")); ?>
    </p>
    <?php
}

/**
 * Bytes -> a short human-readable size ("340 KB", "1.2 MB"). No core helper for this
 * exists yet; kept local since only the Updates tab needs it.
 *
 * @param int $bytes
 *
 * @return string
 */
function osc_market_format_size($bytes)
{
    $bytes = max(0, (int) $bytes);
    if ($bytes === 0) {
        return '';
    }
    $units = array('B', 'KB', 'MB', 'GB');
    $i     = 0;
    $value = (float) $bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }

    return ($i === 0 ? (string) $bytes : number_format($value, $value < 10 ? 1 : 0)) . ' ' . $units[$i];
}

/**
 * A raw GitHub release-asset download count -> a compact string ("1.2k", "3.4M"), the same
 * rounding as osc_market_format_size() and mirrored in market.js's formatCount() so a
 * server-rendered card and a fetched detail dialog read identically. Empty string for
 * 0/negative -- callers render nothing rather than "0 downloads", since no published
 * catalog carries this field yet (docs/MARKET.md §8.2 frozen field names) and it may stay
 * 0 or absent on any given package for a long time after that.
 *
 * @param int $count
 *
 * @return string
 */
function osc_market_format_downloads($count)
{
    $count = max(0, (int) $count);
    if ($count === 0) {
        return '';
    }
    if ($count < 1000) {
        return (string) $count;
    }
    $units = array('B' => 1000000000, 'M' => 1000000, 'k' => 1000);
    foreach ($units as $suffix => $threshold) {
        if ($count >= $threshold) {
            $value = $count / $threshold;

            return ($value < 10 ? number_format($value, 1) : (string) round($value)) . $suffix;
        }
    }

    return (string) $count;
}

/**
 * One reason a primary action can't be taken right now, checked in a fixed order so
 * the owner always sees the most useful explanation first. Returns '' when the
 * action is available.
 *
 * @param array $meta   $aMarketMeta
 * @param array $compat row's `compat`
 *
 * @return string
 */
function osc_market_blocked_reason($meta, $compat)
{
    if (!empty($compat['blocked'])) {
        return (string) $compat['reason'];
    }
    if (empty($meta['writable'])) {
        return __("This directory isn't writable, so packages can't be installed from here.");
    }
    if (!empty($meta['disabled'])) {
        return __('In-app installs are disabled on this deployment.');
    }

    return '';
}

/**
 * The primary action for a card: Install / Update to X / a disabled button carrying
 * the reason it is blocked. Shared between the card and (via the same markup, cloned
 * by JS) the detail dialog.
 *
 * @param array  $row     browse or update row
 * @param array  $meta    $aMarketMeta
 * @param string $mode    'install' or 'update'
 * @param string $label   button label when the action is available
 */
function osc_market_render_action($row, $meta, $mode, $label)
{
    $reason = osc_market_blocked_reason($meta, $row['compat']);
    if ($reason !== '') {
        ?>
        <button type="button" class="btn btn-sm btn-secondary market-card-action" disabled>
            <?php echo osc_esc_html($mode === 'update' ? __("Can't update") : __("Can't install")); ?>
        </button>
        <p class="market-card-reason"><?php echo osc_esc_html($reason); ?></p>
        <?php
        return;
    }
    $version = $mode === 'update' ? $row['new_version'] : $row['version'];
    ?>
    <button type="button" class="btn btn-sm btn-primary market-card-action market-action-btn"
            data-market-action="<?php echo osc_esc_html($mode); ?>"
            data-market-slug="<?php echo osc_esc_html($row['slug']); ?>"
            data-market-version="<?php echo osc_esc_html($version); ?>">
        <?php echo osc_esc_html($label); ?>
    </button>
    <?php
}

/**
 * The .callout banner for catalog state: never fetched, fetch failed, not writable,
 * or in-app installs disabled. Several can be true at once; each gets its own line.
 *
 * @param array  $meta $aMarketMeta
 * @param string $type 'plugin' or 'theme'
 */
function osc_market_render_meta_notices($meta, $type)
{
    $noun = $type === 'theme' ? __('themes') : __('plugins');

    if (!empty($meta['disabled'])) {
        ?>
        <div class="callout-warning callout-block">
            <?php echo osc_esc_html(__('In-app updates are disabled on this deployment. Install and update by deploying a new image or file set; this screen is read-only.')); ?>
        </div>
        <?php
    }

    if (empty($meta['writable'])) {
        ?>
        <div class="callout-warning callout-block">
            <?php echo osc_esc_html(sprintf(__("The %s directory isn't writable, so packages can't be installed from here. Make it writable and reload this page."), $noun)); ?>
        </div>
        <?php
    }

    if (!empty($meta['error'])) {
        ?>
        <div class="callout-danger callout-block">
            <?php
            if (!empty($meta['catalog_available'])) {
                echo osc_esc_html(sprintf(__('Could not refresh the catalog: %s. Showing the last successful results.'), $meta['error']));
            } else {
                echo osc_esc_html(sprintf(__('Could not reach the catalog: %s.'), $meta['error']));
            }
            ?>
        </div>
        <?php
    } elseif (empty($meta['catalog_available'])) {
        ?>
        <div class="callout-info callout-block">
            <?php echo osc_esc_html(sprintf(__("You haven't checked for %s yet. Check now to browse what's available."), $noun)); ?>
        </div>
        <?php
    }
}

/**
 * The Browse tab: a toolbar (search / category / sort, all client-side over the
 * already-rendered cards) and a card grid, one card per catalog package not
 * currently installed.
 *
 * @param array  $rows $aMarketBrowse
 * @param array  $meta $aMarketMeta
 * @param string $type 'plugin' or 'theme'
 */
function osc_market_render_browse($rows, $meta, $type)
{
    osc_market_render_meta_notices($meta, $type);

    if ($meta['last_checked']) {
        ?>
        <p class="market-last-checked">
            <?php echo osc_esc_html(sprintf(__('Last checked %s.'), osc_format_date(date('Y-m-d H:i:s', $meta['last_checked'])))); ?>
        </p>
        <?php
    }
    ?>
    <div class="market-toolbar">
        <label class="market-search-label">
            <span class="visually-hidden"><?php _e('Search'); ?></span>
            <input type="search" class="form-control form-control-sm market-search"
                   placeholder="<?php echo osc_esc_html($type === 'theme' ? __('Search themes…') : __('Search plugins…')); ?>">
        </label>
        <label class="market-filter-label">
            <span class="visually-hidden"><?php _e('Category'); ?></span>
            <select class="form-select form-select-sm market-filter-category">
                <option value=""><?php _e('All categories'); ?></option>
                <?php foreach ((array) ($meta['categories'] ?? array()) as $category) : ?>
                    <option value="<?php echo osc_esc_html($category); ?>"><?php echo osc_esc_html($category); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="market-sort-label">
            <span class="visually-hidden"><?php _e('Sort'); ?></span>
            <select class="form-select form-select-sm market-sort">
                <option value="updated-desc" selected><?php _e('Recently updated'); ?></option>
                <option value="downloads-desc"><?php _e('Most downloaded'); ?></option>
                <option value="name-asc"><?php _e('Name (A–Z)'); ?></option>
                <option value="name-desc"><?php _e('Name (Z–A)'); ?></option>
                <option value="author-asc"><?php _e('Author (A–Z)'); ?></option>
            </select>
        </label>
        <span class="market-downloads-hint" tabindex="0"
              title="<?php echo osc_esc_html(__("Downloads are GitHub's cumulative count of release-asset downloads — includes CI, mirrors, bots and repeat downloads. Not an install count.")); ?>">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <span class="visually-hidden"><?php _e('What "downloads" means'); ?></span>
        </span>
        <button type="button" class="btn btn-sm btn-secondary market-refresh-btn">
            <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
            <?php _e('Check now'); ?>
        </button>
    </div>
    <?php if (empty($rows)) : ?>
        <p class="market-empty">
            <?php echo osc_esc_html(!empty($meta['catalog_available'])
                ? ($type === 'theme' ? __('No themes are available right now.') : __('No plugins are available right now.'))
                : ''); ?>
        </p>
    <?php else : ?>
        <div class="market-grid row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xl-4">
            <?php foreach ($rows as $row) :
                $art = osc_market_browse_art($type, $row['slug'], $row['icon']); ?>
                <div class="col market-grid-item">
                    <div class="market-card card" data-market-item="<?php echo osc_esc_html(json_encode($row)); ?>">
                        <button type="button" class="market-card-thumb-btn" data-market-open-detail
                                aria-label="<?php echo osc_esc_html(sprintf(__('View details for %s'), $row['name'])); ?>">
                            <?php osc_market_render_thumb($art, $row['slug'], $row['name']); ?>
                        </button>
                        <div class="card-body market-card-body">
                            <?php osc_market_render_compat_badge($row['compat']); ?>
                            <h3 class="market-card-title">
                                <button type="button" class="market-card-title-btn" data-market-open-detail>
                                    <?php echo osc_esc_html($row['name']); ?>
                                </button>
                            </h3>
                            <p class="market-card-author">
                                <?php echo osc_esc_html(sprintf(__('by %s'), $row['author'])); ?>
                                <?php $downloads = osc_market_format_downloads($row['downloads'] ?? 0); ?>
                                <?php if ($downloads !== '') : ?>
                                    <span class="market-card-downloads"
                                          title="<?php echo osc_esc_html(__("GitHub's cumulative release-asset downloads — includes CI, mirrors, bots and repeat downloads. Not an install count.")); ?>">
                                        · <?php echo osc_esc_html(sprintf(__('%s downloads'), $downloads)); ?>
                                    </span>
                                <?php endif; ?>
                            </p>
                            <p class="market-card-desc"><?php echo osc_esc_html($row['short_description']); ?></p>
                            <?php osc_market_render_untested_note($row['compat']); ?>
                            <div class="market-card-actions">
                                <?php osc_market_render_action($row, $meta, 'install', __('Install')); ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <p class="market-no-results" hidden><?php _e('No packages match your search.'); ?></p>
    <?php
}

/**
 * The Updates tab: an "Update all" action and one row per installed package with a
 * pending, compatible update.
 *
 * @param array  $rows $aMarketUpdates
 * @param array  $meta $aMarketMeta
 * @param string $type 'plugin' or 'theme'
 */
function osc_market_render_updates($rows, $meta, $type)
{
    osc_market_render_meta_notices($meta, $type);

    $updatable = array_filter($rows, static function ($row) use ($meta) {
        return osc_market_blocked_reason($meta, $row['compat']) === '';
    });
    ?>
    <div class="market-toolbar market-updates-toolbar">
        <?php if (!empty($rows)) : ?>
            <button type="button" class="btn btn-sm btn-primary market-update-all"
                    <?php echo empty($updatable) ? 'disabled' : ''; ?>>
                <?php echo osc_esc_html(sprintf(__('Update all (%d)'), count($updatable))); ?>
            </button>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-secondary market-refresh-btn">
            <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
            <?php _e('Check now'); ?>
        </button>
    </div>
    <?php if (empty($rows)) : ?>
        <p class="market-empty">
            <?php echo osc_esc_html($type === 'theme' ? __('Every installed theme is up to date.') : __('Every installed plugin is up to date.')); ?>
        </p>
    <?php else : ?>
        <ul class="market-updates-list">
            <?php foreach ($rows as $row) :
                $art = osc_market_installed_art($type, $row['slug']); ?>
                <li class="market-update-item" data-market-item="<?php echo osc_esc_html(json_encode($row)); ?>"
                    data-market-slug="<?php echo osc_esc_html($row['slug']); ?>">
                    <button type="button" class="market-card-thumb-btn market-update-thumb-btn" data-market-open-detail
                            aria-label="<?php echo osc_esc_html(sprintf(__('View details for %s'), $row['name'])); ?>">
                        <?php osc_market_render_thumb($art, $row['slug'], $row['name']); ?>
                    </button>
                    <div class="market-update-body">
                        <?php osc_market_render_compat_badge($row['compat']); ?>
                        <h3 class="market-update-title">
                            <button type="button" class="market-card-title-btn" data-market-open-detail>
                                <?php echo osc_esc_html($row['name']); ?>
                            </button>
                        </h3>
                        <p class="market-update-versions">
                            <?php echo osc_esc_html(sprintf(
                                __('%1$s → %2$s'),
                                $row['installed_version'],
                                $row['new_version']
                            )); ?>
                            <?php $size = osc_market_format_size($row['size'] ?? 0); ?>
                            <?php if ($size !== '') : ?>
                                <span class="market-update-size"><?php echo osc_esc_html($size); ?></span>
                            <?php endif; ?>
                        </p>
                        <?php osc_market_render_untested_note($row['compat']); ?>
                    </div>
                    <div class="market-update-actions">
                        <?php osc_market_render_action($row, $meta, 'update', sprintf(__('Update to %s'), $row['new_version'])); ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php
}

/**
 * The detail dialog skeleton -- a native <dialog>, populated client-side by market.js.
 * One per page; the Browse and Updates grids share it. Opening it does two things:
 * market.js first clones the identity fields it already has from the clicked card's
 * data-market-item JSON (name, author, short description, compat, tags) so the dialog
 * never opens blank, then fetches action=market_detail for the rest -- screenshots, the
 * sanitised README, the per-version compatibility table and the repo/issue links -- which
 * the frozen browse/update row contract (docs/MARKET.md §8.2) deliberately does not carry.
 * A loading state covers the gap; a failure leaves the identity fields visible with an
 * inline error rather than a blank dialog.
 *
 * @param string $type 'plugin' or 'theme'
 */
function osc_market_render_detail_dialog($type)
{
    ?>
    <dialog id="marketDetailDialog" class="osc-dialog osc-dialog-wide market-detail-dialog">
        <div class="osc-dialog-body market-detail-body">
            <button type="button" class="btn-close market-detail-close" data-osc-dialog-close
                    aria-label="<?php echo osc_esc_html(__('Close')); ?>"></button>
            <div class="market-detail-thumb"></div>
            <div class="market-detail-main">
                <div class="market-detail-status"></div>
                <p class="osc-dialog-title market-detail-title"></p>
                <p class="market-detail-author"></p>
                <p class="market-detail-desc"></p>
                <p class="market-detail-note"></p>
                <p class="market-detail-reason"></p>
                <dl class="market-detail-meta">
                    <div class="market-detail-version-row">
                        <dt><?php _e('Version'); ?></dt>
                        <dd class="market-detail-version"></dd>
                    </div>
                    <div class="market-detail-downloads-row" hidden>
                        <dt><?php _e('Downloads'); ?></dt>
                        <dd class="market-detail-downloads"
                            title="<?php echo osc_esc_html(__("GitHub's cumulative release-asset downloads — includes CI, mirrors, bots and repeat downloads. Not an install count.")); ?>"></dd>
                    </div>
                </dl>
                <ul class="market-detail-tags"></ul>
            </div>
        </div>
        <div class="market-detail-screens" hidden>
            <div class="market-detail-screens-strip" role="group"
                 aria-label="<?php echo osc_esc_html(__('Screenshots')); ?>" tabindex="0">
                <button type="button" class="market-detail-screens-prev"
                        aria-label="<?php echo osc_esc_html(__('Previous screenshot')); ?>">
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                </button>
                <div class="market-detail-screens-view"></div>
                <button type="button" class="market-detail-screens-next"
                        aria-label="<?php echo osc_esc_html(__('Next screenshot')); ?>">
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </button>
            </div>
            <p class="market-detail-screens-caption"></p>
        </div>
        <div class="market-detail-body-loading">
            <i class="bi bi-arrow-repeat is-spinning" aria-hidden="true"></i>
            <?php _e('Loading details…'); ?>
        </div>
        <p class="market-detail-body-error" hidden></p>
        <div class="market-detail-readme"></div>
        <div class="market-detail-versions" hidden>
            <h3 class="market-detail-section-title"><?php _e('Versions'); ?></h3>
            <div class="market-detail-versions-scroll">
                <table class="table market-detail-versions-table">
                    <thead>
                    <tr>
                        <th scope="col"><?php _e('Version'); ?></th>
                        <th scope="col"><?php _e('Requires'); ?></th>
                        <th scope="col"><?php _e('Requires PHP'); ?></th>
                        <th scope="col"><?php _e('Tested up to'); ?></th>
                        <th scope="col"><?php _e('Size'); ?></th>
                        <th scope="col"><?php _e('Downloads'); ?></th>
                        <th scope="col"><?php _e('Compatibility'); ?></th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <ul class="market-detail-links" hidden></ul>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Close'); ?></button>
            <span class="market-detail-actions"></span>
        </div>
    </dialog>
    <?php
}

/**
 * JSON config + copy handed to market.js via data-i18n / data-* on .market-app, so
 * the script stays static and cacheable (the categories.js convention).
 *
 * @param string $type 'plugin' or 'theme'
 *
 * @return array<string, string>
 */
function osc_market_i18n($type)
{
    return array(
        'installing'       => __('Installing…'),
        'updating'         => __('Updating…'),
        'installed'        => __('Installed'),
        'ajaxError'        => __('Something went wrong. Please try again.'),
        'updateAllRunning' => __('Updating…'),
        'updateAllDone'    => $type === 'theme' ? __('Themes updated.') : __('Plugins updated.'),
        'checking'         => __('Checking…'),
        'noResults'        => __('No packages match your search.'),
        'byAuthor'         => __('by %s'),
        'linkHomepage'     => __('Homepage'),
        'linkRepo'         => __('Repository'),
        'linkIssues'       => __('Issue tracker'),
        'linkDocs'         => __('Documentation'),
        'detailError'      => __("Couldn't load details for this package."),
        'downloadsCount'   => __('%s downloads'),
    );
}
