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
 * The checkout address picker, printed by osc_address_picker(). Reads $pickerAddresses (the
 * address book, default first) and $pickerName. assets/osclass/address-picker.js drives it.
 */

/** @var array<int,array<string,mixed>> $pickerAddresses */
/** @var string $pickerName */
?>
<fieldset class="osc-address-picker" data-address-picker>
    <legend class="osc-address-picker-title"><?php echo osc_esc_html(_m('Deliver to')); ?></legend>
    <?php foreach ($pickerAddresses as $pickerAddress) {
        $pickerData  = array_intersect_key($pickerAddress, array_flip(array('name', 'phone', 'line1', 'city', 'state', 'postcode', 'country')));
        $pickerTitle = trim((string) $pickerAddress['label']) !== '' ? (string) $pickerAddress['label'] . ' · ' . (string) $pickerAddress['name'] : (string) $pickerAddress['name']; ?>
        <label class="osc-address-card oe-panel">
            <input type="radio" name="<?php echo osc_esc_html($pickerName); ?>" value="<?php echo osc_esc_html((string) $pickerAddress['id']); ?>"
                   data-address="<?php echo osc_esc_html((string) json_encode($pickerData, JSON_UNESCAPED_UNICODE)); ?>"<?php
                echo $pickerAddress['default'] ? ' checked' : ''; ?>>
            <span class="osc-address-body">
                <b class="osc-address-name"><?php echo osc_esc_html($pickerTitle); ?></b>
                <span class="osc-address-lines"><?php echo osc_esc_html(osc_address_text($pickerAddress)); ?></span>
            </span>
            <?php if ($pickerAddress['default']) { ?>
                <span class="osc-address-default"><?php echo osc_esc_html(_m('Default')); ?></span>
            <?php } ?>
        </label>
    <?php } ?>
    <label class="osc-address-card oe-panel osc-address-new">
        <input type="radio" name="<?php echo osc_esc_html($pickerName); ?>" value="">
        <span class="osc-address-body"><b class="osc-address-name"><?php echo osc_esc_html(_m('Use a new address')); ?></b></span>
    </label>
    <a class="osc-address-manage" href="<?php echo osc_esc_html(osc_user_addresses_url()); ?>"><?php echo osc_esc_html(_m('Manage addresses')); ?></a>
</fieldset>
<script src="<?php echo osc_esc_html(osc_asset_url_versioned(osc_assets_url('osclass/address-picker.js'))); ?>" defer></script>
