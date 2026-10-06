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
 * A record's action links: $rowActions, in the listing_row_actions shape. Entries
 * with 'group' => 'promote' go on their own line.
 */

/** @var array $rowActions */
$rowGroups = array('' => array(), 'promote' => array());
foreach ((array) $rowActions as $action) {
    if (is_array($action) && isset($action['label'], $action['url'])) {
        $rowGroups[($action['group'] ?? '') === 'promote' ? 'promote' : ''][] = $action;
    }
}
?>
<?php foreach ($rowGroups as $group => $actions) {
    if ($actions === array()) {
        continue;
    } ?>
    <div class="oe-meta oe-row-actions<?php echo $group === 'promote' ? ' oe-row-promote' : ''; ?>">
        <?php if ($group === 'promote') { ?>
            <span><?php echo osc_esc_html(_m('Promote:')); ?></span>
        <?php }
        foreach ($actions as $action) {
            $actionClass   = !empty($action['class']) ? ' ' . osc_esc_html((string) $action['class']) : '';
            $actionConfirm = !empty($action['confirm'])
                ? ' data-osc-confirm="' . osc_esc_html((string) $action['confirm']) . '"' : '';
            if (($action['method'] ?? 'get') === 'post') { ?>
                <form class="oe-inline-form nocsrf" method="post" action="<?php echo osc_esc_html((string) $action['url']); ?>">
                    <?php
                    // The token goes only to this site, never to a URL a plugin points elsewhere.
                    $actionUrl = (string) $action['url'];
                    $siteRoot  = rtrim(osc_base_url(), '/') . '/';
                    if (!preg_match('/[\x00-\x20\\\\]/', $actionUrl)
                        && (strpos($actionUrl, $siteRoot) === 0 || preg_match('#^/(?!/)#', $actionUrl))
                    ) {
                        echo osc_csrf_token_form();
                    }
                    foreach ((array) ($action['fields'] ?? array()) as $fieldName => $fieldValue) { ?>
                        <input type="hidden" name="<?php echo osc_esc_html((string) $fieldName); ?>" value="<?php
                            echo osc_esc_html((string) $fieldValue); ?>">
                    <?php } ?>
                    <button type="submit" class="oe-link-btn<?php echo $actionClass; ?>"<?php echo $actionConfirm; ?>><?php
                        echo osc_esc_html((string) $action['label']); ?></button>
                </form>
            <?php } else { ?>
                <a href="<?php echo osc_esc_html((string) $action['url']); ?>"<?php
                    echo $actionClass !== '' ? ' class="' . trim($actionClass) . '"' : '';
                    echo $actionConfirm; ?>><?php echo osc_esc_html((string) $action['label']); ?></a>
            <?php }
        } ?>
    </div>
<?php } ?>
<?php
foreach ((array) $rowActions as $action) {
    if (!empty($action['confirm'])) {
        osc_gui_print_confirm_script();
        break;
    }
}
