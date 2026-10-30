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

use mindstellar\currency\CurrencyService;

/**
 * A package and payment-method form, shared by the buy page and the pay step. Expects
 * $pickerPackages (package rows), $pickerGateways (id => PaymentGateway), $pickerFields
 * (hidden name => value, the route included) and $pickerSubmit (the button label).
 *
 * It posts to index.php, not to a page's permalink: a matched rewrite rule writes its own
 * params over the request (see Rewrite::applyParams()), so the route lives in the hidden
 * fields, where nothing overwrites it.
 */

/** @var array $pickerPackages */
/** @var array $pickerGateways */
/** @var array $pickerFields */
/** @var string $pickerSubmit */
?>
            <form method="post" action="<?php echo osc_esc_html(osc_base_url(true)); ?>">
                <?php foreach ((array) $pickerFields as $pickerName => $pickerValue) { ?>
                <input type="hidden" name="<?php echo osc_esc_html((string) $pickerName); ?>" value="<?php echo osc_esc_html((string) $pickerValue); ?>"/>
                <?php } ?>

                <fieldset>
                    <legend><?php echo osc_esc_html(_m('Choose a package')); ?></legend>
                    <?php foreach (array_values((array) $pickerPackages) as $i => $package) { ?>
                        <div class="oe-bill-pkg">
                            <label>
                                <input type="radio" name="packageId" value="<?php echo (int) $package['pk_i_id']; ?>"
                                    <?php echo $i === 0 ? 'checked' : ''; ?> required/>
                                <span class="oe-bill-pkg-name"><?php echo osc_esc_html($package['s_name']); ?></span>
                                <span class="oe-bill-pkg-credits">
                                    <?php echo osc_esc_html(sprintf(_m('%s credits'), number_format((int) $package['i_credits']))); ?>
                                </span>
                                <span class="oe-bill-pkg-price">
                                    <?php echo osc_esc_html(CurrencyService::make()->money((int) $package['i_amount'], (string) $package['s_currency'])); ?>
                                </span>
                            </label>
                        </div>
                    <?php } ?>
                </fieldset>

                <fieldset>
                    <legend><?php echo osc_esc_html(_m('Payment method')); ?></legend>
                    <?php $first = true;
                    foreach ((array) $pickerGateways as $gatewayId => $gateway) { ?>
                        <div class="oe-bill-gw">
                            <label>
                                <input type="radio" name="gateway" value="<?php echo osc_esc_html((string) $gatewayId); ?>"
                                    <?php echo $first ? 'checked' : ''; ?> required/>
                                <?php echo osc_esc_html($gateway->getName()); ?>
                            </label>
                        </div>
                        <?php $first = false;
                    } ?>
                </fieldset>

                <button type="submit" class="oe-btn oe-btn--primary oe-bill-btn"><?php echo osc_esc_html((string) $pickerSubmit); ?></button>
            </form>
