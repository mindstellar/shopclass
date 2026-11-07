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

use mindstellar\install\ShopSetup;

require_once __DIR__ . '/fields.php';

global $install_nonce;

/**
 * @var array{available:bool, reason:string, plugins:list<array{slug:string, name:string, description:string, checked:bool}>} $offer
 * @var array{type:string, sample:bool, plugins:list<string>|null}                                                          $chosen
 */

$ins_shop     = $offer['available'] && $chosen['type'] === 'shop';
$ins_shop_off = !$offer['available'];

$ins_texts = [
    'shipping'      => __('Rates by zone and weight'),
    'tax'           => __('Sales tax and VAT rules'),
    'promotions'    => __('Coupons and sale prices'),
    'shop-emails'   => __('Order and shipping e-mails'),
    'digital-goods' => __('Sell downloads'),
    'analytics'     => __('Sales reports'),
    'stripe'        => __('Cards, Apple Pay, Google Pay'),
    'paypal'        => __('PayPal and cards'),
    'razorpay'      => __('UPI and cards, India'),
];
$ins_addons = [];
$ins_pay    = [];
foreach ($offer['plugins'] as $ins_plugin) {
    if (in_array($ins_plugin['slug'], ShopSetup::PAYMENTS, true)) {
        $ins_pay[] = $ins_plugin;
    } else {
        $ins_addons[] = $ins_plugin;
    }
}

$ins_is_checked = static fn (array $plugin): bool => $chosen['plugins'] === null ? (bool) $plugin['checked'] : in_array($plugin['slug'], $chosen['plugins'], true);
$ins_total      = count($offer['plugins']);
$ins_picked     = count(array_filter($offer['plugins'], $ins_is_checked));
/* translators: 1: add-ons selected, 2: all add-ons on offer */
$ins_count_tpl = _n('%1$s of %2$s add-on selected', '%1$s of %2$s add-ons selected', $ins_total);

$ins_addon_box = static function (array $plugin) use ($ins_is_checked, $ins_texts): void {
    $id      = 'ins-plugin-' . $plugin['slug'];
    $checked = $ins_is_checked($plugin);
    $text    = $ins_texts[$plugin['slug']] ?? $plugin['description'];
    install_check(array(
        'id'      => $id,
        'name'    => 'shop_plugins[]',
        'value'   => (string) $plugin['slug'],
        'title'   => (string) $plugin['name'],
        'help'    => (string) $text,
        'checked' => $checked,
    ));
};
?>
<form action="install.php" method="post" id="ins-site-type-form" class="ins-stage">
    <input type="hidden" name="step" value="4" />
    <input type="hidden" name="install_nonce" value="<?php echo osc_esc_html((string) $install_nonce); ?>" />

    <div class="ins-stage-body">
        <h1 class="ins-headline"><?php _e('What are you building?'); ?></h1>
        <p class="ins-body"><?php _e('Pick the closest fit. You can switch later in the admin.'); ?></p>

        <fieldset class="ins-choices ins-types">
            <legend class="ins-visually-hidden"><?php _e('Site type'); ?></legend>
            <label class="ins-choice">
                <input type="radio" name="site_type" value="classifieds" <?php echo $ins_shop ? '' : 'checked'; ?> />
                <span class="ins-choice-glyph" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" focusable="false" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="4" width="17" height="16" rx="2" /><path d="M3.5 9h17M8 13h3M8 16h6M14.5 13h2" /></svg></span>
                <span class="ins-choice-body">
                    <span class="ins-choice-title"><?php _e('Classifieds'); ?></span>
                    <span class="ins-choice-text"><?php _e('Visitors post their own ads: buy and sell, jobs, homes, cars.'); ?></span>
                </span>
                <span class="ins-choice-tick" aria-hidden="true"><svg viewBox="0 0 16 16" width="12" height="12" focusable="false" fill="none" stroke="currentColor" stroke-width="2.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 8.5l3 3 6-6.5" /></svg></span>
            </label>
            <label class="ins-choice<?php echo $ins_shop_off ? ' is-disabled' : ''; ?>">
                <input type="radio" name="site_type" value="shop" <?php echo $ins_shop ? 'checked' : ''; ?> <?php echo $ins_shop_off ? 'disabled aria-describedby="ins-shop-note"' : ''; ?> />
                <span class="ins-choice-glyph" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" focusable="false" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h14l-1 12H6z" /><path d="M9 8V6.5a3 3 0 016 0V8" /></svg></span>
                <span class="ins-choice-body">
                    <span class="ins-choice-title"><?php _e('Shop'); ?></span>
                    <span class="ins-choice-text"><?php _e('You sell your own products, with a cart and checkout.'); ?></span>
                </span>
                <span class="ins-choice-tick" aria-hidden="true"><svg viewBox="0 0 16 16" width="12" height="12" focusable="false" fill="none" stroke="currentColor" stroke-width="2.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 8.5l3 3 6-6.5" /></svg></span>
            </label>
        </fieldset>

        <?php if ($ins_shop_off) { ?>
            <p class="ins-help ins-choice-note" id="ins-shop-note">
                <?php echo $offer['reason'] === 'disabled'
                    ? osc_esc_html(__('Package installs are turned off on this server. You can switch to shop mode later in the admin.'))
                    : osc_esc_html(__('Needs internet. You can switch to shop mode later in the admin.')); ?>
            </p>
        <?php } elseif ($offer['plugins'] !== array()) { ?>
            <fieldset class="ins-plugin-list ins-addons" id="ins-shop-plugins" <?php echo $ins_shop ? '' : 'hidden'; ?>>
                <legend class="ins-group"><?php _e('Add to your shop'); ?></legend>
                <p class="ins-help"><?php _e('Downloaded with the Aisle shop theme. Change them later under Plugins.'); ?></p>
                <details class="ins-addons-pick">
                    <summary>
                        <span id="ins-addons-count" data-template="<?php echo osc_esc_html($ins_count_tpl); ?>" data-total="<?php echo (int) $ins_total; ?>"><?php echo osc_esc_html(sprintf($ins_count_tpl, $ins_picked, $ins_total)); ?></span>
                        <span class="ins-addons-change" aria-hidden="true"><?php _e('Change'); ?></span>
                    </summary>
                    <?php if ($ins_addons !== array()) { ?>
                        <div class="ins-addons-grid">
                            <?php foreach ($ins_addons as $ins_plugin) {
                                $ins_addon_box($ins_plugin);
                            } ?>
                        </div>
                    <?php } ?>
                    <?php if ($ins_pay !== array()) { ?>
                        <div class="ins-addons-pay" role="group" aria-labelledby="ins-pay-label">
                            <p class="ins-addons-sub" id="ins-pay-label"><?php _e('Take payments'); ?></p>
                            <div class="ins-addons-grid">
                                <?php foreach ($ins_pay as $ins_plugin) {
                                    $ins_addon_box($ins_plugin);
                                } ?>
                            </div>
                        </div>
                    <?php } ?>
                </details>
            </fieldset>
        <?php } ?>

        <div class="ins-example">
            <label class="ins-switch-row" for="sample_data">
                <span>
                    <span class="ins-check-title"><?php _e('Add example content'); ?></span>
                    <span class="ins-help"><?php _e('Sample listings with photos, so you can see how the site looks. Delete them any time.'); ?></span>
                </span>
                <input type="checkbox" class="ins-switch" id="sample_data" role="switch" name="sample_data" value="1" <?php echo $chosen['sample'] ? 'checked' : ''; ?> />
            </label>
        </div>
    </div>

    <div class="ins-actions">
        <span class="ins-hint">
            <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 8.5l3 3 6-6.5" /></svg>
            <?php _e('Database connected'); ?>
        </span>
        <button type="submit" class="ins-btn ins-btn-primary"><?php _e('Continue'); ?><?php install_btn_icon('next'); ?></button>
    </div>
</form>
