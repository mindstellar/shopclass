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

// System info > Cache, below the verdict and the facts: clearing it, and how to turn it on.
/** @var array<string, mixed> $env */
$driver     = (string) ($env['cache_driver'] ?? 'default');
$persistent = $driver !== 'default' && !empty($env['cache_supported']);
$shared     = in_array($driver, array('memcached', 'memcache'), true);
?>
    <section class="sysinfo-group" id="cache-clear">
        <?php osc_admin_form_section(__('Clear the cache'), array(
            'spaced' => true,
            'intro'  => $persistent
                ? __('The cache only holds copies. Clearing it is safe: the site rebuilds what it needs on the next request.')
                : __('This cache holds nothing between requests, so there is nothing to clear.'),
        )); ?>
        <?php if ($persistent && $shared) { ?>
            <p class="text-muted"><?php _e('Keys are kept apart per site, so several sites can share one memcached server. Clearing, though, empties the whole server, other sites too.'); ?></p>
        <?php } ?>
        <?php osc_admin_form_open(array('page' => 'tools', 'action' => 'cache_clear', 'horizontal' => false)); ?>
            <?php osc_admin_action_button(array(
                'label' => __('Clear cache'),
                'icon'  => 'bi-arrow-counterclockwise',
                'type'  => 'submit',
                'attrs' => $persistent && !\mindstellar\security\Demo::active() ? array() : array('disabled' => 'disabled'),
            )); ?>
        <?php osc_admin_form_close(null, array('horizontal' => false)); ?>
    </section>

    <?php if ($driver === 'default') { ?>
        <p class="text-muted sysinfo-aside"><?php printf(
            __('To keep data between requests, install APCu, Memcached or Redis/Valkey and set %1$s in config.php. <a href="%2$s">How to change it</a>.'),
            '<code>define(\'OSC_CACHE\', \'apcu\');</code>',
            osc_esc_html(\mindstellar\admin\SystemChecks::url($env, 'server', 'server-help'))
        ); ?></p>
    <?php } ?>
