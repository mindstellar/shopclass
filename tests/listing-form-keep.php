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
 * ListingInput::keep() and dropKept(): the listing form a web or admin save keeps in the
 * session, so it is filled in again after an error. Custom field values stay kept across a
 * clear until they are dropped.
 *
 * DB-free.  Usage: php tests/listing-form-keep.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\listing\ListingInput;

$session = Session::getInstance();

harness_section('keep');
ListingInput::keep(array('title' => 'Red car', 'price' => '100'), array(7 => 'red', 9 => array('a', 'b')));
pin('the form values are kept', array('Red car', '100'), array($session->_getForm('title'), $session->_getForm('price')));
pin('each custom field is kept under meta_<id>', array('red', array('a', 'b')), array($session->_getForm('meta_7'), $session->_getForm('meta_9')));

harness_section('a clear keeps the custom fields only');
$session->_clearVariables();
pin('the form values are cleared', array('', ''), array($session->_getForm('title'), $session->_getForm('price')));
pin('the custom fields stay', array('red', array('a', 'b')), array($session->_getForm('meta_7'), $session->_getForm('meta_9')));

harness_section('dropKept');
ListingInput::dropKept(array(7 => 'red'));
$session->_clearVariables();
pin('a dropped field goes with the next clear; one not named stays', array('', array('a', 'b')), array($session->_getForm('meta_7'), $session->_getForm('meta_9')));
ListingInput::dropKept(array(9 => 'x'));
$session->_clearVariables();
pin('then it goes too', '', $session->_getForm('meta_9'));

harness_section('no custom fields posted');
ListingInput::keep(array('title' => 'Blue car'), '');
ListingInput::dropKept(null);
pin('a form with no meta array is kept as it is', 'Blue car', $session->_getForm('title'));

exit(harness_result());
