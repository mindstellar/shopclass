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
 * CategoryFilter writes whole-number ids into the SQL, so a category search can run as a plain
 * query, and binds anything else.
 *
 * Usage:  php tests/search-category-filter.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\search\query\CategoryFilter;
use mindstellar\search\query\Statement;

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

if (!defined('DB_TABLE_PREFIX')) {
    define('DB_TABLE_PREFIX', 'oc_');
}

$where = static function (array $ids): array {
    $filter = new CategoryFilter();
    $filter->set($ids);
    $statement = new Statement();
    $filter->apply($statement);
    [$sql, $params] = $statement->compile();

    return array(trim((string) substr($sql, (int) strpos($sql, 'WHERE ') + 6)), $params);
};

harness_section('CategoryFilter::apply');

pin('whole numbers, as ints or digit strings, go into the SQL', array('oc_t_item.fk_i_category_id IN (3, 7, 12)', array()), $where(array(3, '7', '12')));
pin('padded digit strings go in as plain numbers', array('oc_t_item.fk_i_category_id IN (7, 7)', array()), $where(array('007', ' 7')));
pin('text that looks like SQL is bound', array('oc_t_item.fk_i_category_id IN (?)', array('7) OR (1=1')), $where(array('7) OR (1=1')));
pin('anything else is bound', array('oc_t_item.fk_i_category_id IN (?, ?)', array(3, 'x')), $where(array(3, 'x')));
pin('a decimal is bound', array('oc_t_item.fk_i_category_id IN (?)', array(1.5)), $where(array('1.5')));
pin('no ids add no condition', 'SELECT *', (new class () {
    public function __invoke(): string
    {
        $statement = new Statement();
        (new CategoryFilter())->apply($statement);

        return $statement->compile()[0];
    }
})());

harness_section('Ordering::apply');

$orderSql = static function (string $column): string {
    $ordering = new \mindstellar\search\query\Ordering();
    $ordering->order($column, 'DESC', '', true);
    $statement = new Statement();
    $ordering->apply($statement);
    preg_match('/ORDER BY (.*?)\s+LIMIT/s', $statement->compile()[0], $m);

    return $m[1] ?? '';
};
pin('relevance ties are broken by the newest id', 'relevance DESC, oc_t_item.pk_i_id DESC', $orderSql('relevance'));
pin('other orders are left as they are', 'dt_pub_date DESC', $orderSql('dt_pub_date'));

exit(harness_result());
