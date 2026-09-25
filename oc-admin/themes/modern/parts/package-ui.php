<?php if (!defined('OC_ADMIN')) {
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
 * One package presentation shared by the Plugins and Appearance screens: the row used by
 * their installed lists and the Updates tab, the artwork box, the state badge and the action
 * strip. The two screens keep their own words, tabs and URLs — only the shapes are shared,
 * so a plugin and a theme no longer read as two different applications.
 *
 * Art and state reuse `.osc-thumb` and `.osc-status`; nothing here introduces a second
 * vocabulary for either.
 */

if (!function_exists('osc_package_state')) {
    /**
     * The state badge: tint, glyph and word, as `.osc-status` inside its `.status-*` parent.
     *
     * @param string $state One of active, disabled, uninstalled, update, incompatible, live
     * @param string $word  The visible word; a default is used when empty
     *
     * @return void
     */
    function osc_package_state($state, $word = '')
    {
        $words = array(
            'active'       => __('Active'),
            'disabled'     => __('Disabled'),
            'uninstalled'  => __('Not installed'),
            'update'       => __('Update ready'),
            'incompatible' => __('Not compatible'),
            'live'         => __('Live'),
        );
        $state = isset($words[$state]) ? $state : 'uninstalled';
        $word  = $word !== '' ? $word : $words[$state];
        ?>
        <span class="osc-pkg-state status-<?php echo osc_esc_html($state); ?>">
            <span class="osc-status"><?php echo osc_esc_html($word); ?></span>
        </span>
        <?php
    }
}

if (!function_exists('osc_package_art')) {
    /**
     * The artwork box. Art is fitted, never cropped: a plugin icon is square and a theme
     * screenshot is wide, and both have to survive the same box.
     *
     * @param array  $art  {src:string, has:bool} from osc_market_browse_art()/osc_market_installed_art()
     * @param string $slug Used only for the placeholder tint
     * @param string $name Its initial is the placeholder glyph
     * @param string $size 'row' or 'wide'
     * @param bool   $opensDetail draw it as the button that opens the package's detail dialog
     *
     * @return void
     */
    function osc_package_art($art, $slug, $name, $size = 'row', $opensDetail = false)
    {
        $has = !empty($art['has']);
        $tag = $opensDetail ? 'button' : 'div';
        ?>
        <<?php echo $tag; ?> class="osc-pkg-art osc-pkg-art--<?php echo osc_esc_html($size); ?><?php
            echo $opensDetail ? ' osc-pkg-art--button' : ''; ?>"<?php if ($opensDetail) : ?>
            type="button" data-market-open-detail
            aria-label="<?php echo osc_esc_html(sprintf(__('Details: %s'), $name)); ?>"<?php endif; ?>>
            <div class="osc-thumb<?php echo $has ? '' : ' osc-thumb--fallback'; ?>"
                 style="--osc-thumb-hue: <?php echo (int) osc_market_thumb_hue($slug); ?>">
                <?php if ($has) : ?>
                    <img src="<?php echo osc_esc_html($art['src']); ?>" alt="" loading="lazy"
                         onerror="oscThumbFailed(this)"/>
                <?php endif; ?>
                <span class="osc-thumb-letter" aria-hidden="true"><?php
                    echo osc_esc_html(mb_strtoupper(mb_substr($name, 0, 1))); ?></span>
            </div>
        </<?php echo $tag; ?>>
        <?php
    }
}

if (!function_exists('osc_package_actions')) {
    /**
     * The action strip. One primary action, then quiet links, then anything destructive —
     * separated from the rest by a rule and a gap, never sitting beside a routine action.
     *
     * @param array $actions {primary?: array{label,url,attrs?}, links?: array<int,string>,
     *                        danger?: array<int,string>}
     *
     * @return void
     */
    function osc_package_actions($actions)
    {
        $primary = isset($actions['primary']) ? $actions['primary'] : null;
        $links   = isset($actions['links']) ? array_filter($actions['links']) : array();
        $danger  = isset($actions['danger']) ? array_filter($actions['danger']) : array();

        if ($primary === null && !$links && !$danger) {
            return;
        }
        ?>
        <div class="osc-pkg-actions">
            <?php if ($primary !== null) : ?>
                <a class="btn btn-sm <?php echo osc_esc_html(isset($primary['variant']) ? $primary['variant'] : 'btn-primary'); ?>"
                   href="<?php echo osc_esc_html($primary['url']); ?>"
                    <?php echo isset($primary['attrs']) ? $primary['attrs'] : ''; ?>>
                    <?php echo osc_esc_html($primary['label']); ?>
                </a>
            <?php endif; ?>
            <?php if ($links) : ?>
                <ul class="osc-pkg-links">
                    <?php foreach ($links as $link) : ?>
                        <li><?php echo $link; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($danger) : ?>
                <ul class="osc-pkg-danger">
                    <?php foreach ($danger as $link) : ?>
                        <li><?php echo $link; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('osc_package_row')) {
    /**
     * One installed plugin or theme.
     *
     * @param array $pkg {art:array, slug:string, name:string, state:string, state_word?:string,
     *                    meta?:array<int,string>, description?:string, note?:string,
     *                    note_variant?:string, actions?:array, size?:string, class?:string,
     *                    detail?:array} detail: what the market detail dialog shows before it
     *                    loads the rest -- name, author, version, short_description, tags.
     *                    With it, the art and a Details link open that dialog.
     *
     * @return void
     */
    function osc_package_row($pkg)
    {
        $meta   = isset($pkg['meta']) ? array_filter($pkg['meta']) : array();
        $size   = isset($pkg['size']) ? $pkg['size'] : 'row';
        $detail = isset($pkg['detail']) && is_array($pkg['detail']) ? $pkg['detail'] + array('slug' => $pkg['slug']) : null;
        if ($detail !== null) {
            $pkg['actions']['links'] = array_merge(
                array('<a href="#" data-market-open-detail>' . osc_esc_html(__('Details')) . '</a>'),
                $pkg['actions']['links'] ?? array()
            );
        }
        ?>
        <li class="osc-pkg osc-pkg--<?php echo osc_esc_html($size); ?> <?php
            echo osc_esc_html(isset($pkg['class']) ? $pkg['class'] : ''); ?>"<?php if ($detail !== null) : ?>
            data-market-item="<?php echo htmlspecialchars((string) json_encode($detail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES); ?>"<?php endif; ?>>
            <?php osc_package_art($pkg['art'], $pkg['slug'], $pkg['name'], $size, $detail !== null); ?>
            <div class="osc-pkg-main">
                <div class="osc-pkg-head">
                    <h3 class="osc-pkg-name"><?php echo osc_esc_html($pkg['name']); ?></h3>
                    <?php osc_package_state($pkg['state'], isset($pkg['state_word']) ? $pkg['state_word'] : ''); ?>
                </div>
                <?php if ($meta) : ?>
                    <p class="osc-pkg-meta"><?php echo implode(' <span class="osc-pkg-sep">·</span> ', $meta); ?></p>
                <?php endif; ?>
                <?php if (!empty($pkg['description'])) : ?>
                    <p class="osc-pkg-desc"><?php echo osc_esc_html($pkg['description']); ?></p>
                <?php endif; ?>
                <?php if (!empty($pkg['note'])) : ?>
                    <p class="osc-pkg-note osc-pkg-note--<?php
                        echo osc_esc_html(isset($pkg['note_variant']) ? $pkg['note_variant'] : 'info'); ?>">
                        <?php echo $pkg['note']; ?>
                    </p>
                <?php endif; ?>
            </div>
            <?php osc_package_actions(isset($pkg['actions']) ? $pkg['actions'] : array()); ?>
        </li>
        <?php
    }
}

if (!function_exists('osc_package_list_open')) {
    /**
     * Opens the list a row belongs to.
     *
     * @param string $class
     *
     * @return void
     */
    function osc_package_list_open($class = '')
    {
        echo '<ul class="osc-pkg-list ' . osc_esc_html($class) . '">';
    }
}

if (!function_exists('osc_package_list_close')) {
    /**
     * @return void
     */
    function osc_package_list_close()
    {
        echo '</ul>';
    }
}
