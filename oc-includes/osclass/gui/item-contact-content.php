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
 * Write to the seller about one listing. The field names are core's contract --
 * CWebItem reads them from contact_post -- so a theme that shipped this page was
 * laying out a form it did not own.
 *
 * CSRF is injected on shutdown into any form not marked nocsrf.
 */
?>
<div class="oe-form-page">
    <?php osc_show_flash_message(); ?>

    <p class="oe-muted"><?php printf(
        osc_esc_html(_m('About “%s”')),
        osc_esc_html(osc_item_title())
    ); ?></p>

    <?php
    $contactForm = array(
        'hidden'     => array('page' => 'item', 'action' => 'contact_post', 'id' => (int) osc_item_id()),
        'prefix'     => 'oe-your',
        'hint'       => _m('The seller replies to this address.'),
        'phone'      => true,
        'attachment' => (bool) osc_item_attachment(),
        'captcha'    => 'contact_seller',
        'hooks'      => static function () {
            osc_run_hook('item_contact_form');
        },
    );
    require __DIR__ . '/parts/contact-form.php';
    ?>
</div>
