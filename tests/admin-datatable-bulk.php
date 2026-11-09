<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The DataTable base draws the select-all header, the row checkbox and the blocked/inactive/active
 * status once, and no subclass keeps its own copy. DB-free.  Usage: php tests/admin-datatable-bulk.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
require_once __DIR__ . '/lib/harness.php';

function __($text, $domain = 'core')
{
    return $text;
}
function osc_esc_html($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

require ABS_PATH . 'oc-includes/classes/admin/ui/Form.php';
require ABS_PATH . 'oc-includes/osclass/classes/datatables/DataTable.php';

class BulkProbe extends DataTable
{
    public function header(): string
    {
        return $this->bulkHeader();
    }

    public function box($value, ?string $label, string $name = 'id[]', array $attrs = array()): string
    {
        return $this->bulkCheckbox($value, $label, $name, $attrs);
    }

    public function status(array $row): array
    {
        return $this->flagStatus($row);
    }

    public function table($params)
    {
    }
}

$t = new BulkProbe();

pin('header', '<input id="check_all" type="checkbox" aria-label="Select all" />', $t->header());
pin('row checkbox defaults to id[]', '<input type="checkbox" name="id[]" value="7" aria-label="Select Bob" />', $t->box(7, 'Bob'));
pin('a null label still draws the row', '<input type="checkbox" name="id[]" value="7" aria-label="Select " />', $t->box(7, null));
pin('plugin name', '<input type="checkbox" name="plugin[]" value="a/index.php" aria-label="Select A" />', $t->box('a/index.php', 'A', 'plugin[]'));
pin('alert name', '<input type="checkbox" name="alert_id[]" value="3" aria-label="Select a@b.c" />', $t->box(3, 'a@b.c', 'alert_id[]'));
pin(
    'listing attributes',
    '<input type="checkbox" name="id[]" value="9" aria-label="Select Car" active="1" blocked="0" />',
    $t->box(9, 'Car', 'id[]', array('active' => 1, 'blocked' => 0))
);
pin('label and value are escaped', '<input type="checkbox" name="id[]" value="&quot;&gt;" aria-label="Select &lt;b&gt;" />', $t->box('">', '<b>'));

pin('blocked', array('class' => 'status-blocked', 'text' => 'Blocked'), $t->status(array('b_enabled' => 0, 'b_active' => 0)));
pin('inactive', array('class' => 'status-inactive', 'text' => 'Inactive'), $t->status(array('b_enabled' => 1, 'b_active' => 0)));
pin('active', array('class' => 'status-active', 'text' => 'Active'), $t->status(array('b_enabled' => 1, 'b_active' => 1)));

$dir = ABS_PATH . 'oc-includes/osclass/classes/datatables/';
foreach (glob($dir . '*DataTable.php') as $file) {
    $src = file_get_contents($file);
    $name = basename($file);
    if ($name === 'DataTable.php') {
        continue;
    }
    check($name . ' has no own check_all', strpos($src, 'id="check_all"') === false);
    check($name . ' has no own row checkbox', strpos($src, '<input type="checkbox"') === false);
}

exit(harness_result());
