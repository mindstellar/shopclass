<?php if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


/*
 * Kept for compatibility only — core no longer reads this list.
 *
 * It was an allowlist of browsers, used to decide whether a request counted as a
 * listing view. That could not work: a crawler identifies itself by appending to
 * an ordinary browser string, so modern crawler user agents match these patterns
 * and were counted as readers. View counting now uses osc_is_bot_request(), a
 * denylist, which a plugin can extend through the bot_user_agents filter.
 */
$user_agents = array(
    'MSIE ([0-9].*)$',
    '^Mozilla/[0-9].([^c][^o][^m].)$',
    '^Mozilla/5.*Gecko',
    '^Mozilla/5.(PLAYSTATION.)',
    'Safari',
    'Omni',
    'iCab',
    'Opera',
    'Konqueror',
    'AOL',
    'MSN',
    'WebTV',
    'BlackBerry.*'
);
