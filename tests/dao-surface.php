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
 * The legacy DAO base class's public methods. Plugins extend DAO for their own tables, so
 * adding, removing or re-typing one of these changes what their models inherit.
 * DB-free.  Usage:  php tests/dao-surface.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

harness_section('DAO public method surface');

pin('the public method map is unchanged', array(
    '__construct'        => 'public __construct()',
    '__wakeup'           => 'public __wakeup()',
    'checkFieldKeys'     => 'public checkFieldKeys($aKey)',
    'count'              => 'public count()',
    'delete'             => 'public delete($where)',
    'deleteByPrimaryKey' => 'public deleteByPrimaryKey($value)',
    'exists'             => 'public exists(array $where): bool',
    'existsByPrimaryKey' => 'public existsByPrimaryKey($value)',
    'findByPrimaryKey'   => 'public findByPrimaryKey($value)',
    'getErrorDesc'       => 'public getErrorDesc()',
    'getErrorLevel'      => 'public getErrorLevel()',
    'getFields'          => 'public getFields()',
    'getPrimaryKey'      => 'public getPrimaryKey()',
    'getTableName'       => 'public getTableName()',
    'getTablePrefix'     => 'public getTablePrefix()',
    'insert'             => 'public insert($values)',
    'insertGetId'        => 'public insertGetId($values)',
    'listAll'            => 'public listAll()',
    'setFields'          => 'public setFields($fields)',
    'setPrimaryKey'      => 'public setPrimaryKey($key)',
    'setTableName'       => 'public setTableName($table)',
    'update'             => 'public update($values, $where)',
    'updateByPrimaryKey' => 'public updateByPrimaryKey($values, $key)',
), harness_public_method_map('DAO'));

exit(harness_result());
