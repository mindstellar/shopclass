<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The English mail templates ship twice: installer/mail.json (core's fallback) and the en_US
 * language pack. Both copies must stay the same.
 *
 * DB-free.  Usage: php tests/mail-templates-copy.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

pin(
    'oc-content/languages/en_US/mail.json matches oc-includes/osclass/installer/mail.json',
    md5_file(ABS_PATH . 'oc-includes/osclass/installer/mail.json'),
    md5_file(ABS_PATH . 'oc-content/languages/en_US/mail.json')
);

exit(harness_result());
