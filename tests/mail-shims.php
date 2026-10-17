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
 * The old e-mail functions in emails.php and utils.php hand every argument, in order, to the
 * mindstellar\mail method their @see names, and return what it returns (nothing for a void one).
 *
 * DB-free. Usage:  php tests/mail-shims.php
 */

define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);

require_once __DIR__ . '/lib/harness.php';

// The real methods, read in a separate process: here the classes are replaced by recorders.
$real = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
    'require ' . var_export(ABS_PATH . 'oc-includes/vendor/autoload.php', true) . ';'
    . '$out = [];'
    . 'foreach (["CoreEmails", "Mailer"] as $c) {'
    . '  foreach ((new ReflectionClass("mindstellar\\\\mail\\\\" . $c))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {'
    . '    $out[$c . "::" . $m->getName()] = ['
    . '      "params" => $m->getNumberOfParameters(),'
    . '      "void" => (bool) preg_match("/@return\\\\s+void\\\\b/", (string) $m->getDocComment()),'
    . '    ];'
    . '  }'
    . '}'
    . 'echo json_encode($out);'
)), true);

$GLOBALS['mail_calls'] = array();
eval('namespace mindstellar\mail;
final class CoreEmails { public static function __callStatic($m, $a) { $GLOBALS["mail_calls"][] = array("CoreEmails::" . $m, $a); return $GLOBALS["mail_return"]; } }
final class Mailer { public static function __callStatic($m, $a) { $GLOBALS["mail_calls"][] = array("Mailer::" . $m, $a); return $GLOBALS["mail_return"]; } }');

function osc_add_hook($hook, $callback)
{
    $GLOBALS['mail_hooks'][$hook] = $callback;
}

$before = get_defined_functions()['user'];
require_once __DIR__ . '/../oc-includes/osclass/emails.php';
require_once __DIR__ . '/../oc-includes/osclass/utils.php';
$added = array_diff(get_defined_functions()['user'], $before);

harness_section('every shim forwards its arguments in order');
$shims = 0;
foreach ($added as $name) {
    $fn = new ReflectionFunction($name);
    if (!preg_match('/@see \\\\mindstellar\\\\mail\\\\(\w+::\w+)\(\)/', (string) $fn->getDocComment(), $see)) {
        continue;
    }
    $shims++;
    $target = $see[1];
    check($name . ' points at a real method', isset($real[$target]));
    if (!isset($real[$target])) {
        continue;
    }
    pin($name . ' has the same number of parameters', $real[$target]['params'], $fn->getNumberOfParameters());

    $args = array();
    foreach ($fn->getParameters() as $i => $param) {
        $args[] = in_array((string) $param->getType(), array('array', '?array'), true) ? array('arg' . $i) : 'arg' . $i;
    }
    $GLOBALS['mail_return'] = match ((string) $fn->getReturnType()) {
        'array' => array('returned'),
        'bool'  => true,
        default => 'returned',
    };
    $GLOBALS['mail_calls'] = array();
    $result = $fn->invokeArgs($args);
    pin($name . ' calls ' . $target . ' once with its arguments', array(array($target, $args)), $GLOBALS['mail_calls']);
    pin($name . ' returns what the method returns', $real[$target]['void'] ? null : $GLOBALS['mail_return'], $result);
}
pin('shims found', 38, $shims);

harness_section('the hooks still name the old functions');
pin('hooks registered', 23, count($GLOBALS['mail_hooks']));
pin('item inquiry hook', 'fn_email_item_inquiry', $GLOBALS['mail_hooks']['hook_email_item_inquiry']);
pin('auto upgrade hook', 'fn_email_auto_upgrade', $GLOBALS['mail_hooks']['after_auto_upgrade']);

exit(harness_result());
