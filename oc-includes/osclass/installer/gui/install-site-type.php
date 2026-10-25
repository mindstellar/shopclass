<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

global $install_nonce;

/**
 * @var array{available:bool, reason:string, plugins:list<array{slug:string, name:string, description:string, checked:bool}>} $offer
 * @var array{type:string, sample:bool, plugins:list<string>|null}                                                          $chosen
 */

$ins_shop     = $offer['available'] && $chosen['type'] === 'shop';
$ins_shop_off = !$offer['available'];
?>
<h1 class="ins-headline"><?php _e('What kind of site?'); ?></h1>
<p class="ins-body"><?php _e('You can change this later in the admin.'); ?></p>

<form action="install.php" method="post" id="ins-site-type-form">
    <input type="hidden" name="step" value="4" />
    <input type="hidden" name="install_nonce" value="<?php echo osc_esc_html((string) $install_nonce); ?>" />

    <fieldset class="ins-choices">
        <legend class="ins-visually-hidden"><?php _e('Site type'); ?></legend>
        <label class="ins-choice">
            <input type="radio" name="site_type" value="classifieds" <?php echo $ins_shop ? '' : 'checked'; ?> />
            <span class="ins-choice-body">
                <span class="ins-choice-title"><?php _e('Classifieds'); ?></span>
                <span class="ins-choice-text"><?php _e('Local buy and sell, jobs, property, cars, services'); ?></span>
            </span>
        </label>
        <label class="ins-choice<?php echo $ins_shop_off ? ' is-disabled' : ''; ?>">
            <input type="radio" name="site_type" value="shop" <?php echo $ins_shop ? 'checked' : ''; ?> <?php echo $ins_shop_off ? 'disabled aria-describedby="ins-shop-note"' : ''; ?> />
            <span class="ins-choice-body">
                <span class="ins-choice-title"><?php _e('Shop'); ?> <span class="ins-badge"><?php _e('New'); ?></span></span>
                <span class="ins-choice-text"><?php _e('A fashion or gift shop, a bakery, a craft or digital goods store'); ?></span>
            </span>
        </label>
    </fieldset>

    <?php if ($ins_shop_off) { ?>
        <p class="ins-help ins-choice-note" id="ins-shop-note">
            <?php echo $offer['reason'] === 'disabled'
                ? osc_esc_html(__('Package installs are turned off on this server. You can switch to shop mode later in the admin.'))
                : osc_esc_html(__('Needs internet. You can switch to shop mode later in the admin.')); ?>
        </p>
    <?php } elseif ($offer['plugins'] !== array()) { ?>
        <fieldset class="ins-plugin-list" id="ins-shop-plugins" <?php echo $ins_shop ? '' : 'hidden'; ?>>
            <legend class="ins-title"><?php _e('Shop plugins to add'); ?></legend>
            <?php foreach ($offer['plugins'] as $ins_plugin) {
                $ins_id      = 'ins-plugin-' . $ins_plugin['slug'];
                $ins_checked = $chosen['plugins'] === null ? $ins_plugin['checked'] : in_array($ins_plugin['slug'], $chosen['plugins'], true);
                ?>
                <label class="ins-check" for="<?php echo osc_esc_html($ins_id); ?>">
                    <input type="checkbox" id="<?php echo osc_esc_html($ins_id); ?>" name="shop_plugins[]" value="<?php echo osc_esc_html($ins_plugin['slug']); ?>" <?php echo $ins_checked ? 'checked' : ''; ?> />
                    <span>
                        <span class="ins-check-title"><?php echo osc_esc_html($ins_plugin['name']); ?></span>
                        <?php if ($ins_plugin['description'] !== '') { ?>
                            <span class="ins-help"><?php echo osc_esc_html($ins_plugin['description']); ?></span>
                        <?php } ?>
                    </span>
                </label>
            <?php } ?>
        </fieldset>
    <?php } ?>

    <label class="ins-check ins-sample-check" for="sample_data">
        <input type="checkbox" id="sample_data" name="sample_data" value="1" <?php echo $chosen['sample'] ? 'checked' : ''; ?> />
        <span>
            <span class="ins-check-title"><?php _e('Install sample data'); ?></span>
            <span class="ins-help"><?php _e('Example listings and form fields, so you can see how the site works.'); ?></span>
        </span>
    </label>

    <div class="ins-actions">
        <button type="submit" class="ins-btn ins-btn-primary"><?php _e('Continue'); ?></button>
    </div>
</form>
