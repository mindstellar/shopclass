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
 * Tabs across the credits pages. Expects $billingTab: 'wallet', 'buy' or 'orders'.
 */

$billingTabs = array('wallet' => array(_m('Credits'), osc_billing_wallet_url()));
if (osc_billing_can_buy()) {
    $billingTabs['buy'] = array(_m('Buy credits'), osc_billing_buy_url());
}
$billingTabs['orders'] = array(_m('Orders'), osc_billing_orders_url());
?>
<nav class="oe-tabs" aria-label="<?php echo osc_esc_html(_m('Credits')); ?>">
    <?php foreach ($billingTabs as $tabKey => [$tabLabel, $tabUrl]) { ?>
        <a href="<?php echo osc_esc_html($tabUrl); ?>"<?php
            echo ($billingTab ?? '') === $tabKey ? ' aria-current="page"' : ''; ?>><?php
            echo osc_esc_html($tabLabel); ?></a>
    <?php } ?>
</nav>
