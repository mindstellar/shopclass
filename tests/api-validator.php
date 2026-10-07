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
 * The API's JSON Schema subset: each keyword, the error pointers, query coercion, and a
 * schema using anything outside the subset reported by schemaProblems().
 *
 * DB-free.  Usage: php tests/api-validator.php
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\routing\Router;
use mindstellar\api\routing\RouteTable;
use mindstellar\api\schema\Definitions;
use mindstellar\api\schema\Schema;
use mindstellar\api\schema\Validator;

$v = new Validator(array('Money' => array(
    'type'                 => 'object',
    'required'             => array('amount'),
    'properties'           => array('amount' => array('type' => 'string', 'pattern' => '^[0-9]+\.[0-9]{2}$')),
    'additionalProperties' => false,
)));

/** "pointer code" per error, so a pin reads at a glance. */
$errs = static function (array $schema, $value) use ($v): array {
    return array_map(static fn ($e) => $e['pointer'] . ' ' . $e['code'], $v->check($schema, $value));
};

harness_section('type');
pin('a string is a string', array(), $errs(array('type' => 'string'), 'x'));
pin('an int is not a string', array(' type'), $errs(array('type' => 'string'), 1));
pin('an integral float is an integer', array(), $errs(array('type' => 'integer'), 3.0));
pin('a fraction is not', array(' type'), $errs(array('type' => 'integer'), 3.5));
pin('an int is a number', array(), $errs(array('type' => 'number'), 3));
pin('a numeric string is not a number', array(' type'), $errs(array('type' => 'number'), '3'));
pin('true is a boolean, 1 is not', array(array(), array(' type')), array($errs(array('type' => 'boolean'), true), $errs(array('type' => 'boolean'), 1)));
pin('a type list allows null', array(), $errs(array('type' => array('string', 'null')), null));
pin('a list is an array, a map is not', array(array(), array(' type')), array($errs(array('type' => 'array'), array(1, 2)), $errs(array('type' => 'array'), array('a' => 1))));
pin('a map is an object, a list is not', array(array(), array(' type')), array($errs(array('type' => 'object'), array('a' => 1)), $errs(array('type' => 'object'), array(1))));
pin('an empty array is both', array(array(), array()), array($errs(array('type' => 'object'), array()), $errs(array('type' => 'array'), array())));
pin('the message says what was wanted', 'must be a string or null', $v->check(array('type' => array('string', 'null')), 1)[0]['message']);

harness_section('numbers and strings');
pin('a value under minimum names minimum', array(' minimum'), $errs(array('type' => 'integer', 'minimum' => 1), 0));
pin('a value over maximum names maximum', array(' maximum'), $errs(array('type' => 'integer', 'maximum' => 100), 101));
pin('within bounds', array(), $errs(array('type' => 'integer', 'minimum' => 1, 'maximum' => 100), 50));
pin('minLength counts characters, not bytes', array(), $errs(array('type' => 'string', 'minLength' => 2), 'éé'));
pin('a string under minLength names minLength', array(' minLength'), $errs(array('type' => 'string', 'minLength' => 2), 'é'));
pin('a string over maxLength names maxLength', array(' maxLength'), $errs(array('type' => 'string', 'maxLength' => 3), 'abcd'));
pin('a string failing the pattern names pattern', array(' pattern'), $errs(array('type' => 'string', 'pattern' => '^[a-z]+$'), 'AB'));
pin('a pattern holding the delimiter still works', array(), $errs(array('type' => 'string', 'pattern' => '^a~b$'), 'a~b'));
pin('an enum accepts a listed value and names enum for another', array(array(), array(' enum')), array($errs(array('enum' => array('asc', 'desc')), 'asc'), $errs(array('enum' => array('asc', 'desc')), 'up')));
pin('enum is strict', array(' enum'), $errs(array('enum' => array(1, 2)), '1'));

harness_section('format');
pin('an e-mail format accepts a full address and names format for a partial one', array(array(), array(' format')), array($errs(array('type' => 'string', 'format' => 'email'), 'a@b.co'), $errs(array('type' => 'string', 'format' => 'email'), 'a@')));
pin('uri wants http or https', array(array(), array(' format')), array($errs(array('type' => 'string', 'format' => 'uri'), 'https://x.test/a'), $errs(array('type' => 'string', 'format' => 'uri'), 'javascript:alert(1)')));
pin('date-time is RFC 3339', array(array(), array(), array(' format')), array(
    $errs(array('type' => 'string', 'format' => 'date-time'), '2026-10-03T12:00:00Z'),
    $errs(array('type' => 'string', 'format' => 'date-time'), '2026-10-03T12:00:00.5+05:30'),
    $errs(array('type' => 'string', 'format' => 'date-time'), '2026-10-03 12:00'),
));

harness_section('objects, arrays and pointers');
$listing = array(
    'type'       => 'object',
    'required'   => array('title', 'price'),
    'properties' => array(
        'title'  => array('type' => 'string', 'minLength' => 1),
        'price'  => array('$ref' => '#/components/schemas/Money'),
        'photos' => array('type' => 'array', 'maxItems' => 2, 'items' => array('type' => 'string', 'format' => 'uri')),
        'a/b'    => array('type' => 'integer'),
    ),
    'additionalProperties' => false,
);
pin('a valid object passes', array(), $errs($listing, array('title' => 'Bike', 'price' => array('amount' => '12.50'))));
pin('required names the missing field', array('/title required', '/price required'), $errs($listing, array()));
pin('errors point into nested values', array('/price/amount pattern', '/photos/1 format'), $errs($listing, array(
    'title' => 'Bike', 'price' => array('amount' => '12.5'), 'photos' => array('https://x.test/1', 'nope'),
)));
pin('additionalProperties false refuses unknown fields', array('/colour additionalProperties'), $errs($listing, array('title' => 'Bike', 'price' => array('amount' => '1.00'), 'colour' => 'red')));
pin('additionalProperties in a $ref applies too', array('/price/currency additionalProperties'), $errs($listing, array('title' => 'Bike', 'price' => array('amount' => '1.00', 'currency' => 'EUR'))));
pin('additionalProperties as a schema checks the extras', array('/x type'), $errs(array('type' => 'object', 'additionalProperties' => array('type' => 'integer')), array('x' => 'no')));
pin('maxItems and minItems', array(array('/photos maxItems'), array(' minItems')), array(
    $errs($listing, array('title' => 'B', 'price' => array('amount' => '1.00'), 'photos' => array('https://a.test', 'https://b.test', 'https://c.test'))),
    $errs(array('type' => 'array', 'minItems' => 1), array()),
));
pin('a slash in a field name is escaped in the pointer', array('/a~1b type'), $errs($listing, array('title' => 'B', 'price' => array('amount' => '1.00'), 'a/b' => 'x')));
pin('annotations are allowed', array(), $errs(array('type' => 'string', 'description' => 'd', 'example' => 'x', 'default' => 'x', 'title' => 't', 'deprecated' => true, 'readOnly' => true), 'x'));

harness_section('query coercion');
$query = array('type' => 'object', 'properties' => array(
    'limit' => array('type' => 'integer', 'minimum' => 1),
    'price' => array('type' => 'number'),
    'pics'  => array('type' => 'boolean'),
    'q'     => array('type' => 'string'),
), 'additionalProperties' => false);
pin('strings become the declared types', array('limit' => 20, 'price' => 9.5, 'pics' => true, 'q' => '12'), $v->coerceQuery($query, array('limit' => '20', 'price' => '9.5', 'pics' => 'true', 'q' => '12')));
pin('what does not parse is left to fail', array('/limit type'), $errs($query, $v->coerceQuery($query, array('limit' => 'ten'))));
pin('an array is left alone', array('/limit type'), $errs($query, $v->coerceQuery($query, array('limit' => array('1')))));

harness_section('schema problems');
pin('a schema inside the subset has none', [], $v->schemaProblems($listing));
pin('a keyword outside the subset', ['unsupported keyword oneOf'], $v->schemaProblems(['type' => 'string', 'oneOf' => []]));
pin('an unknown type', ['unsupported type uuid'], $v->schemaProblems(['type' => 'uuid']));
pin('an unknown format', ['unsupported format ipv4'], $v->schemaProblems(['type' => 'string', 'format' => 'ipv4']));
pin('an array with no items', ['an array needs items'], $v->schemaProblems(['type' => ['string', 'array']]));
pin('an unknown $ref', ['unknown reference #/components/schemas/Nope'], $v->schemaProblems(['$ref' => '#/components/schemas/Nope']));
pin('a $ref with no definitions to point at', ['unknown reference #/components/schemas/Money'], (new Validator())->schemaProblems(['$ref' => '#/components/schemas/Money']));
pin('a broken pattern', ['invalid pattern ('], $v->schemaProblems(['type' => 'string', 'pattern' => '(']));
pin('problems are found at any depth', ['unsupported keyword const', 'unsupported keyword if'], $v->schemaProblems([
    'type' => 'object', 'properties' => ['a' => ['const' => 1], 'b' => ['type' => 'array', 'items' => ['if' => []]]],
]));

harness_section('lazy definitions');
$builds = 0;
$lazy   = Definitions::lazy(['Money'], static function () use (&$builds): array {
    $builds++;

    return ['Money' => ['type' => 'integer']];
});
$lv = new Validator($lazy);
pin('a $ref is checked by name, without building the schemas', [[], 0], [$lv->schemaProblems(['$ref' => '#/components/schemas/Money']), $builds]);
pin('the first check that follows a $ref builds them, once', [[' type'], 1], [
    array_map(static fn (array $e): string => $e['pointer'] . ' ' . $e['code'], array_merge($lv->check(['$ref' => '#/components/schemas/Money'], 'x'), $lv->check(['$ref' => '#/components/schemas/Money'], 5))),
    $builds,
]);
$drift = Definitions::lazy(['Money'], static fn (): array => ['Cash' => []]);
pin('schemas that are not the ones named are refused', 'LogicException', (static function () use ($drift): string {
    try {
        $drift->all();
    } catch (\LogicException $e) {
        return 'LogicException';
    }

    return 'built';
})());
pin('Schema::names() lists every component, in order', array_keys(Schema::components()), Schema::names());
$core   = Schema::definitions();
$routes = (new Router(new Validator($core), RouteTable::core()))->all();
check('building the core routes does not build the component set', !($core->isBuilt()));
$broken = [];
foreach ($routes as $key => $route) {
    try {
        $route->check(new Validator(Schema::definitions()));
    } catch (\InvalidArgumentException $e) {
        $broken[$key] = $e->getMessage();
    }
}
pin('every core route has a handler and schemas the validator can check', [], $broken);

exit(harness_result());
