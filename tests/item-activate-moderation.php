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
 * The e-mail "activate your listing" link. With admin moderation on, the listing starts
 * disabled, and activate() used to do nothing yet answer -1 (truthy), so the visitor was
 * told "validated" and then "hasn't been validated". It has to mark the listing validated
 * whether or not the admin has enabled it yet.
 *
 * DB-free.  Usage: php tests/item-activate-moderation.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');
define('OSC_DEBUG', false);

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['hooks'] = array();

function osc_run_hook($name)
{
    $GLOBALS['hooks'][] = $name;
}

function osc_item_is_counted(array $item): bool
{
    return false;
}

/** An Item stand-in that records what activate() writes. */
class FakeItemModel extends \Item
{
    public $row;
    public $updates = array();

    public function __construct(array $row)
    {
        $this->row = $row;
    }

    public function listWhere(...$args)
    {
        return array($this->row);
    }

    public function findByPrimaryKey($id)
    {
        return $this->row;
    }

    public function update($values, $where)
    {
        $this->updates[] = $values;

        return 1;
    }
}

function run_activate(array $row, string $secret = 'secret')
{
    $GLOBALS['hooks'] = array();
    $fake    = new FakeItemModel($row + array('pk_i_id' => 7, 's_secret' => 'secret', 'b_spam' => 0, 'dt_expiration' => '', 'fk_i_category_id' => 1));
    $ref     = new ReflectionClass(\mindstellar\listing\ListingService::class);
    $service = $ref->newInstanceWithoutConstructor();
    $prop    = $ref->getProperty('items');
    $prop->setAccessible(true);
    $prop->setValue($service, $fake);
    $result = $service->activate(7, $secret);

    return array($result, $fake);
}

harness_section('ListingService::activate()');

list($result, $fake) = run_activate(array('b_active' => 0, 'b_enabled' => 1));
check('an enabled, unvalidated listing is validated', $result === true && $fake->updates === array(array('b_active' => 1)));
check('it fires activate_item', in_array('activate_item', $GLOBALS['hooks'], true));

list($result, $fake) = run_activate(array('b_active' => 0, 'b_enabled' => 0));
check('a not-yet-enabled listing is validated too', $result === true && $fake->updates === array(array('b_active' => 1)));

list($result, $fake) = run_activate(array('b_active' => 1, 'b_enabled' => 1));
check('an already validated listing is left alone', $result === null && $fake->updates === array());

list($result, $fake) = run_activate(array('b_active' => 0, 'b_enabled' => 1), 'wrong');
check('a wrong secret changes nothing', $result === null && $fake->updates === array());

harness_section('CWebItem activate');
$controller = file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebItem.php');
preg_match("/case 'activate':(.*?)\n            case 'item_delete':/s", $controller, $m);
$body = $m[1] ?? '';
check('the case was parsed', $body !== '');
check('a guest is sent home when the page would be hidden', preg_match(
    '/if \(!ListingPolicy::canView\(array\(\'b_active\' => 1\) \+ \$item\[0\], \$this->actor\(false\)\)\) \{[^}]*redirectTo\(osc_base_url\(\)\)/',
    $body
) === 1);

exit(harness_result());
