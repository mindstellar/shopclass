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
 * Write to whoever runs the site. The field names are core's contract --
 * CWebContact reads them from contact_post -- so every theme that shipped this
 * page was laying out a form it did not own.
 *
 * Both contact hooks fire, in the order and the places the bundled themes put
 * them: a plugin adding a field to one contact form expects it on all of them.
 * CSRF is injected on shutdown into any form not marked nocsrf.
 */

ob_start();
osc_run_hook('contact_page_aside');
$contactAside = trim((string) ob_get_clean());
?>
<div class="oe-contact">
    <div class="oe-contact-main">
        <?php osc_show_flash_message(); ?>

        <?php
        $contactForm = array(
            'hidden'  => array('page' => 'contact', 'action' => 'contact_post'),
            'prefix'  => 'oe-contact',
            'name'    => 'contact_form',
            'hint'    => _m('We reply to this address.'),
            'subject'  => true,
            'optional' => true,
            'captcha' => 'contact',
            'top'     => static function () {
                osc_run_hook('contact_form_top');
            },
            'hooks'   => static function () {
                osc_run_hook('contact_form');
                osc_run_hook('admin_contact_form');
            },
        );
        require __DIR__ . '/parts/contact-form.php';
        ?>
        <?php osc_run_hook('contact_form_after'); ?>
    </div>
    <?php if ($contactAside !== '') { ?>
        <aside class="oe-contact-aside"><?php echo $contactAside; ?></aside>
    <?php } ?>
</div>
