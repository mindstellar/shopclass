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
 * BulkAction::options() builds an admin list's bulk menu: an empty "Bulk actions" choice first,
 * then one option per action whose confirm text takes the lower-cased verb.
 *
 * DB-free.  Usage: php tests/bulk-action-options.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\admin\BulkAction;

if (!function_exists('__')) {
    function __(string $s): string
    {
        return $s;
    }
}

$options = BulkAction::options(
    array(
        'delete_all'   => 'Delete',
        'activate_all' => 'Activate',
        'spam_all'     => array('Mark as spam', 'Mark these as SPAM'),
    ),
    'Are you sure you want to %s the selected listings?'
);

pin('one option per action plus the empty one', 4, count($options));

harness_section('Leading choice');
pin('empty value', '', $options[0]['value']);
pin('"Bulk actions" label', 'Bulk actions', $options[0]['label']);
pin('no confirm text', '', $options[0]['data-dialog-content']);

harness_section('Plain label');
pin('value', 'delete_all', $options[1]['value']);
pin('label keeps its case', 'Delete', $options[1]['label']);
pin('confirm uses the lower-cased label', 'Are you sure you want to delete the selected listings?', $options[1]['data-dialog-content']);
pin('second action confirm', 'Are you sure you want to activate the selected listings?', $options[2]['data-dialog-content']);

harness_section('array(label, verb)');
pin('value', 'spam_all', $options[3]['value']);
pin('label is the first element', 'Mark as spam', $options[3]['label']);
pin('confirm uses the lower-cased verb', 'Are you sure you want to mark these as spam the selected listings?', $options[3]['data-dialog-content']);

harness_section('Numeric keys');
$numeric = BulkAction::options(array(5 => 'Move'), '%s?');
pin('value is a string', '5', $numeric[1]['value']);

exit(harness_result());
