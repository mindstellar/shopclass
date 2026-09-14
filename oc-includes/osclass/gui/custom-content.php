<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * A page a plugin owns. Not a page in its own right -- a mount point: whatever
 * the plugin registered is rendered here, and every theme's copy of this file
 * was the same one line wrapping it.
 */
?>
<div class="oe-doc-body">
    <?php osc_show_flash_message(); ?>
    <?php osc_render_file(); ?>
</div>
