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
 * AdminText::pluralForms: one distinct form comes back as plain text, several as forms and a
 * digit per n.
 *
 * DB-free.  Usage: php tests/admin-plural-forms.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\admin\AdminText;

pin('one form is plain text', 'and %d more', AdminText::pluralForms(static fn (int $n): string => 'and %d more'));

$two = AdminText::pluralForms(static fn (int $n): string => $n === 1 ? 'one' : 'many');
pin('a digit for each n below 200', 200, strlen($two['idx']));
pin('n = 1 takes its own form', 'one', $two['forms'][(int) $two['idx'][1]]);
pin('n = 5 takes the other', 'many', $two['forms'][(int) $two['idx'][5]]);

exit(harness_result());
