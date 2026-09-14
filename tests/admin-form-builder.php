<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Holds the two front doors of a declared settings page equal: the spec array
 * osc_register_settings_page() has always taken, and the osc_admin_form() builder that
 * is sugar over it.
 *
 * The builder stores nothing and renders nothing, so the only way it can be wrong is by
 * emitting an array that differs from the one the author would have written -- and every
 * way it can differ is silent:
 *
 *  - a modifier writing the wrong key is a field that renders without its help text, or
 *    a required field the save path never rejects as empty;
 *  - a type method emitting the wrong 'type' is a value sanitised by the wrong rule;
 *  - a builder that validated a spec itself would let something through that the array
 *    form still refuses, which is the drift this whole layer exists to prevent.
 *
 * The equivalence held here is order-sensitive: pin() compares with ===, so every hand
 * fixture below is written in the builder's own key order and spells 'type' out. A hand
 * array that omits 'type' has it appended last instead, so a later comparison against a
 * screen's existing hand-written spec has to normalise key order before diffing.
 *
 * DB-free: the builder is a pure transformation from method calls to an array, and
 * registration is a pure function over that array.
 *
 * Usage:  php tests/admin-form-builder.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/settings/SettingsPageRegistry.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/form/store/Store.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/form/store/PreferenceStore.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/form/store/TableStore.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/form/store/StoreFactory.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/ui/FormSpec.php';

use mindstellar\admin\ui\FormSpec;
use mindstellar\settings\SettingsPageRegistry;

// --- the slice of core the helper leans on -----------------------------------------
$GLOBALS['hooks'] = array();

require_once __DIR__ . '/lib/stubs.php';

function osc_add_hook($hook, $fn, $priority = 5)
{
    $GLOBALS['hooks'][$hook][] = $fn;
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';

/** Register a page, returning the exception message when the spec is refused. */
function register_error(string $id, array $spec): ?string
{
    try {
        SettingsPageRegistry::instance()->register($id, $spec);
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return null;
}

/** Build and register through the builder, returning the exception message on refusal. */
function builder_error(FormSpec $form): ?string
{
    try {
        $form->register();
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return null;
}

// Shared callables: a closure is only identical to itself, so the builder and the
// hand-written array have to be handed the same instance for === to mean anything.
$afterSave = static function (array $values, string $id): void {
};
$renderer  = static function (array $field, array $values): void {
};
$sanitizer = static fn ($value) => trim((string)$value);
$validator = static fn ($value, array $field) => null;
$pageValidate = static fn (array $values, string $pageId, $id) => null;

harness_section('the helper');

check('osc_admin_form() exists', function_exists('osc_admin_form'));
check('it returns a FormSpec', osc_admin_form('acme') instanceof FormSpec);
check('and each call is its own builder', osc_admin_form('acme') !== osc_admin_form('acme'));
$idForm = osc_admin_form('acme');
pin('the id is readable from the builder', 'acme', $idForm->id());
// The registry takes the id beside the spec, not inside it: an 'id' key in the array
// would be validated as a page key and refused.
check('and is not a key of the spec it emits', !array_key_exists('id', $idForm->toArray()));
// The builder is declared alongside osc_register_settings_page() and not with the field
// primitives: hAdminUi.php loads after Plugins::init(), so a plugin declaring its page at
// include time -- which is when pages are declared -- would fatal on an undefined function.
check(
    'it is declared in hSettings.php',
    strpos((string)file_get_contents(ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php'), 'function osc_admin_form') !== false
);
$ocLoad     = (string)file_get_contents(ABS_PATH . 'oc-load.php');
$atHelper   = strpos($ocLoad, 'helpers/hSettings.php');
$atPlugins  = strpos($ocLoad, 'Plugins::init()');
check('oc-load.php loads that file', $atHelper !== false);
check('oc-load.php starts the plugins', $atPlugins !== false);
check(
    'and it is loaded before plugins run, which is when a page is declared',
    $atHelper !== false && $atPlugins !== false && $atHelper < $atPlugins,
    'helper at ' . var_export($atHelper, true) . ', plugins at ' . var_export($atPlugins, true)
);

harness_section('every field type has a builder method');

foreach (SettingsPageRegistry::FIELD_TYPES as $type) {
    check('the builder can declare a ' . $type, method_exists(FormSpec::class, $type));
}
pin('and no type is missing from the registry list', 13, count(SettingsPageRegistry::FIELD_TYPES));

harness_section('one field of each type is the array a hand writes');

$simple = array('text', 'email', 'url', 'tel', 'number', 'color', 'secret', 'textarea');
foreach ($simple as $type) {
    pin(
        $type . ' with a label and a hint',
        array(
            'groups' => array(
                array(
                    'fields' => array(
                        array('type' => $type, 'name' => 'f_' . $type, 'label' => 'Label', 'help' => 'Hint'),
                    ),
                ),
            ),
        ),
        osc_admin_form('t')->{$type}('f_' . $type, 'Label', 'Hint')->toArray()
    );
    pin(
        $type . ' with a name alone',
        array('groups' => array(array('fields' => array(array('type' => $type, 'name' => 'f'))))),
        osc_admin_form('t')->{$type}('f')->toArray()
    );
}

pin(
    'select carries its options',
    array(
        'groups' => array(
            array(
                'fields' => array(
                    array(
                        'type'    => 'select',
                        'name'    => 'mode',
                        'label'   => 'Mode',
                        'help'    => 'Hint',
                        'options' => array('live' => 'Live', 'test' => 'Test'),
                    ),
                ),
            ),
        ),
    ),
    osc_admin_form('t')->select('mode', 'Mode', array('live' => 'Live', 'test' => 'Test'), 'Hint')->toArray()
);

pin(
    'radio carries its options',
    array(
        'groups' => array(
            array(
                'fields' => array(
                    array(
                        'type'    => 'radio',
                        'name'    => 'plan',
                        'label'   => 'Plan',
                        'options' => array('a' => 'A', 'b' => 'B'),
                    ),
                ),
            ),
        ),
    ),
    osc_admin_form('t')->radio('plan', 'Plan', array('a' => 'A', 'b' => 'B'))->toArray()
);

pin(
    'checkbox labels the control, not the row',
    array(
        'groups' => array(
            array(
                'fields' => array(
                    array('type' => 'checkbox', 'name' => 'verbose', 'label' => 'Log everything', 'row_label' => 'Logging'),
                ),
            ),
        ),
    ),
    osc_admin_form('t')->checkbox('verbose', 'Log everything')->rowLabel('Logging')->toArray()
);

pin(
    'custom carries its renderer',
    array(
        'groups' => array(
            array(
                'fields' => array(
                    array('type' => 'custom', 'name' => 'widget', 'label' => 'Widget', 'render' => $renderer),
                ),
            ),
        ),
    ),
    osc_admin_form('t')->custom('widget', $renderer, 'Widget')->toArray()
);

harness_section('every modifier writes the key the registry reads');

$mods = array(
    'required'  => array(array(), array('required' => true)),
    'disabled'  => array(array(), array('disabled' => true)),
    'column'    => array(array('s_email'), array('column' => 's_email')),
    'persist'   => array(array(false), array('persist' => false)),
    'writeOnly' => array(array(), array('write_only' => true)),
    'dependsOn' => array(array('b_enabled'), array('depends' => 'b_enabled')),
    'translate' => array(array(), array('translate' => true)),
    'purify'    => array(array(), array('purify' => false)),
    'default'   => array(array('7'), array('default' => '7')),
    'options'   => array(array(array('a' => 'A')), array('options' => array('a' => 'A'))),
    'rowLabel'  => array(array('Row'), array('row_label' => 'Row')),
    'hint'      => array(array('Hint'), array('help' => 'Hint')),
    'prefix'    => array(array('Show'), array('prefix' => 'Show')),
    'suffix'    => array(array('per page'), array('suffix' => 'per page')),
    'width'     => array(array('num'), array('width' => 'num')),
    'attrs'     => array(array(array('min' => '1')), array('attrs' => array('min' => '1'))),
    'render'    => array(array($renderer), array('render' => $renderer)),
    'sanitize'  => array(array($sanitizer), array('sanitize' => $sanitizer)),
    'validate'  => array(array($validator), array('validate' => $validator)),
);
foreach ($mods as $method => $case) {
    [$args, $expected] = $case;
    $form = osc_admin_form('t')->text('f');
    $form->{$method}(...$args);
    pin(
        '->' . $method . '() sets ' . array_key_first($expected),
        array('groups' => array(array('fields' => array(array('type' => 'text', 'name' => 'f') + $expected)))),
        $form->toArray()
    );
}

pin(
    'required(false) is emitted, not dropped',
    array('groups' => array(array('fields' => array(array('type' => 'text', 'name' => 'f', 'required' => false))))),
    osc_admin_form('t')->text('f')->required(false)->toArray()
);
pin(
    'disabled(false) is emitted, not dropped',
    array('groups' => array(array('fields' => array(array('type' => 'text', 'name' => 'f', 'disabled' => false))))),
    osc_admin_form('t')->text('f')->disabled(false)->toArray()
);
// The floor the browser is told and the floor the save applies are one number here. Written
// out as a pair -- ->set('min', N)->sanitize(clamp(N)) -- they can disagree, and a control
// that shows one limit while the save enforces another silently corrects what was typed.
$clamped = osc_admin_form('t')->number('n')->clampMin(3)->toArray()['groups'][0]['fields'][0];
pin('clampMin() tells the browser the floor', 3, $clamped['min'] ?? null);
check('and hands the save a sanitiser', isset($clamped['sanitize']) && is_callable($clamped['sanitize']));
pin('which floors what was typed', 3, call_user_func($clamped['sanitize'], '1'));
pin('and leaves anything above it alone', 9, call_user_func($clamped['sanitize'], '9'));
pin('a blank box is the floor too, not a zero', 3, call_user_func($clamped['sanitize'], ''));
pin('and a whole number is what comes out', 4, call_user_func($clamped['sanitize'], '4.7'));

// The value is dependsOn()'s second key. It has to land where the hand-written array puts
// it whichever order the two were written in, or the equivalence below stops holding.
$byMode = array('type' => 'text', 'name' => 'f', 'depends' => 'mode', 'depends_value' => 'text');
pin(
    'dependsOn() with a value writes depends and depends_value',
    array('groups' => array(array('fields' => array($byMode)))),
    osc_admin_form('t')->text('f')->dependsOn('mode', 'text')->toArray()
);
pin(
    'and a list of values is carried as the list',
    array('groups' => array(array('fields' => array(array_replace($byMode, array('depends_value' => array('text', 'image'))))))),
    osc_admin_form('t')->text('f')->dependsOn('mode', array('text', 'image'))->toArray()
);
pin(
    'which is the hand-written array in the other key order',
    osc_admin_form('t')->field(array('depends_value' => 'text', 'type' => 'text', 'name' => 'f', 'depends' => 'mode'))->toArray(),
    osc_admin_form('t')->text('f')->dependsOn('mode', 'text')->toArray()
);
pin(
    'a later dependsOn() with no value drops the value list an earlier call set',
    array('type' => 'text', 'name' => 'f', 'depends' => 'b_on'),
    osc_admin_form('t')->text('f')->dependsOn('mode', 'text')->dependsOn('b_on')->toArray()['groups'][0]['fields'][0]
);
check(
    'a null value writes no depends_value at all',
    !array_key_exists('depends_value', osc_admin_form('t')->text('f')->dependsOn('mode', null)->toArray()['groups'][0]['fields'][0])
);
$keyOrder = (new ReflectionClass(FormSpec::class))->getConstant('FIELD_KEY_ORDER');
pin(
    'FIELD_KEY_ORDER places depends_value straight after depends',
    'depends_value',
    $keyOrder[array_search('depends', $keyOrder, true) + 1] ?? null
);

pin(
    'set() reaches a key with no method of its own',
    array('groups' => array(array('fields' => array(array('type' => 'text', 'name' => 'f', 'placeholder' => 'x'))))),
    osc_admin_form('t')->text('f')->set('placeholder', 'x')->toArray()
);
pin(
    'field() takes a spec written by hand',
    array('groups' => array(array('fields' => array(array('type' => 'text', 'name' => 'f', 'label' => 'F'))))),
    osc_admin_form('t')->field(array('name' => 'f', 'label' => 'F', 'type' => 'text'))->toArray()
);
// A modifier says "this applies to the field just declared". With no field to apply to it
// would otherwise vanish, and the page would render missing whatever it was setting.
$stray = null;
try {
    osc_admin_form('t')->required();
} catch (LogicException $e) {
    $stray = $e->getMessage();
}
check('a modifier before any field throws rather than vanishing', $stray !== null);
check(
    'and says which key had nowhere to go',
    $stray !== null && strpos($stray, 'FormSpec') !== false,
    (string)$stray
);
pin('modifiers stack on the same field, in one array', 1, count(
    osc_admin_form('t')->text('f')->required()->hint('H')->width('num')->toArray()['groups'][0]['fields']
));
pin(
    'and each lands under its own key',
    array('type' => 'text', 'name' => 'f', 'help' => 'H', 'required' => true, 'width' => 'num'),
    osc_admin_form('t')->text('f')->required()->hint('H')->width('num')->toArray()['groups'][0]['fields'][0]
);

// hint() and help() are one letter apart and write different levels: hint() is the field's
// 'help', help() is the page's. Chaining help() onto a field loses the hint with no error.
pin(
    'hint() writes the field help while help() writes the page help',
    array(
        'help'   => 'What this page does.',
        'groups' => array(array('fields' => array(array('type' => 'text', 'name' => 'f', 'help' => 'What f does.')))),
    ),
    osc_admin_form('t')->help('What this page does.')->text('f')->hint('What f does.')->toArray()
);
pin(
    'so help() after a field still lands on the page, and the field gets none',
    array('help' => 'What f does.', 'groups' => array(array('fields' => array(array('type' => 'text', 'name' => 'f'))))),
    osc_admin_form('t')->text('f')->help('What f does.')->toArray()
);

harness_section('no modifier escapes the key order or the cases above');

// The list above is complete today; the next modifier added is the problem. A key missing
// from FIELD_KEY_ORDER is emitted in call order, so two chains saying the same thing stop
// comparing equal -- which surfaces as an equivalence diff with no obvious cause.
//
// So the set is declared here rather than inferred from calling each method: a probe sees
// only what one call happens to do, and a modifier whose first parameter is optional, or
// that writes a page key as well as a field one, does nothing observable under it. The map
// below is method => the field spec key it writes, and reflection holds it against the
// class.
$modifierKeys = array(
    'required'  => 'required',
    'disabled'  => 'disabled',
    'column'    => 'column',
    'persist'   => 'persist',
    'writeOnly' => 'write_only',
    'dependsOn' => 'depends',
    'translate' => 'translate',
    'purify'    => 'purify',
    'default'   => 'default',
    'options'   => 'options',
    'rowLabel'  => 'row_label',
    'hint'      => 'help',
    'prefix'    => 'prefix',
    'suffix'    => 'suffix',
    'width'     => 'width',
    'attrs'     => 'attrs',
    'render'    => 'render',
    'sanitize'  => 'sanitize',
    'validate'  => 'validate',
);

// Everything public that is not a field modifier, and why. Field type methods come from
// the registry's own list, so a new type is covered by the section above instead.
$notModifiers = array_merge(
    array(
        '__construct',
        'id',         // the page id, which is not a spec key
        'toArray',    // output
        'register',   // output, and it writes to the registry
        'set',        // freeform by design: an unlisted key is emitted after the ordered ones
        'field',      // appends a whole spec rather than writing one key
        'clampMin',   // writes two keys at once, which is the point of it
        'group',      // structure: starts a group rather than writing a key
        'title',      // page-level from here down
        'menu',
        'menuTitle',
        'section',
        'store',
        'capability',
        'help',
        'intro',
        'onValidate',
        'onAfterSave',
    ),
    SettingsPageRegistry::FIELD_TYPES
);
$publicMethods = array_values(array_diff(get_class_methods(FormSpec::class), $notModifiers));

$undeclared = array_values(array_diff($publicMethods, array_keys($modifierKeys)));
check(
    'every public method that is not a type, a page key or output is a declared modifier',
    $undeclared === array(),
    'undeclared: ' . implode(', ', $undeclared)
);
$vanished = array_values(array_diff(array_keys($modifierKeys), $publicMethods));
check(
    'and every declared modifier is still a method on the class',
    $vanished === array(),
    'no longer public: ' . implode(', ', $vanished)
);

$order = (new ReflectionClass(FormSpec::class))->getConstant('FIELD_KEY_ORDER');
check('FIELD_KEY_ORDER is there to be checked against', is_array($order) && $order !== array());
foreach ($modifierKeys as $method => $key) {
    check(
        '->' . $method . '() writes "' . $key . '", and FIELD_KEY_ORDER places it',
        is_array($order) && in_array($key, $order, true),
        '"' . $key . '" is unordered, so chaining order changes the array'
    );
}

// The map is only worth holding the class to if it says what the calls above observed.
$mismatched = array();
foreach ($modifierKeys as $method => $key) {
    $written = isset($mods[$method]) ? array_key_first($mods[$method][1]) : null;
    if ($written !== $key) {
        $mismatched[] = $method . ' declares "' . $key . '" but the case above writes "'
            . var_export($written, true) . '"';
    }
}
check(
    'each declared key is the one the case above actually wrote',
    $mismatched === array(),
    implode('; ', $mismatched)
);

$unexercised = array_values(array_diff(array_keys($modifierKeys), array_keys($mods)));
check(
    'every modifier the class has is exercised above',
    $unexercised === array(),
    'not exercised: ' . implode(', ', $unexercised)
);
$notModifier = array_values(array_diff(array_keys($mods), array_keys($modifierKeys)));
check(
    'while no case above names a method that is not one',
    $notModifier === array(),
    'not a modifier: ' . implode(', ', $notModifier)
);
pin('so the count is the whole set, not a sample', 19, count($modifierKeys));

// The other half of persist(): false says "no column", and a callable says what the column
// takes. Both are one key, so the case above only covers one of them.
$deriver = static fn ($value) => strtoupper((string)$value);
pin(
    'persist() also takes the callable that derives the column value',
    array('groups' => array(array('fields' => array(
        array('type' => 'text', 'name' => 'f', 'persist' => $deriver),
    )))),
    osc_admin_form('t')->text('f')->persist($deriver)->toArray()
);
// The two are separate keys, and in that order: what the column takes is not what the
// control shows, and one chain writing both must not collapse them into one.
pin(
    'persist() and writeOnly() write their own keys, in FIELD_KEY_ORDER',
    array('groups' => array(array('fields' => array(
        array('type' => 'secret', 'name' => 'f', 'persist' => $deriver, 'write_only' => true),
    )))),
    osc_admin_form('t')->secret('f')->persist($deriver)->writeOnly()->toArray()
);
pin(
    'and writeOnly(false) is a field that says it reads back',
    array('groups' => array(array('fields' => array(
        array('type' => 'secret', 'name' => 'f', 'write_only' => false),
    )))),
    osc_admin_form('t')->secret('f')->writeOnly(false)->toArray()
);

harness_section('page-level keys');

pin(
    'every page key, in the order a spec declares them',
    array(
        'title'      => 'Acme',
        'menu'       => 'tools',
        'menu_title' => 'Acme settings',
        'section'    => 'acme_prefs',
        'store'      => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
        'capability' => 'moderator',
        'help'       => 'What this page does.',
        'intro'      => 'Everything Acme does.',
        'validate'   => $pageValidate,
        'after_save' => $afterSave,
        'groups'     => array(array('fields' => array(array('type' => 'text', 'name' => 'f')))),
    ),
    osc_admin_form('acme')
        ->title('Acme')
        ->menu('tools')
        ->menuTitle('Acme settings')
        ->section('acme_prefs')
        ->store('t_ban_rule', 'pk_i_id')
        ->capability('moderator')
        ->help('What this page does.')
        ->intro('Everything Acme does.')
        ->onValidate($pageValidate)
        ->onAfterSave($afterSave)
        ->text('f')
        ->toArray()
);
// Emission order is fixed, not call order: two builders saying the same thing have to
// compare equal, or the equivalence this file rests on is only true by coincidence.
pin(
    'chaining order does not change the array',
    osc_admin_form('acme')->title('Acme')->menu('tools')->intro('I')->text('f')->toArray(),
    osc_admin_form('acme')->intro('I')->text('f')->menu('tools')->title('Acme')->toArray()
);
pin(
    'a key never set is never emitted',
    array('title' => 'Acme', 'groups' => array(array('fields' => array(array('type' => 'text', 'name' => 'f'))))),
    osc_admin_form('acme')->title('Acme')->text('f')->toArray()
);
pin(
    'a page with no fields still emits groups, so the registry is the one that refuses it',
    array('title' => 'Acme', 'groups' => array()),
    osc_admin_form('acme')->title('Acme')->toArray()
);
// store() is the page key that decides where a save lands. Emitted under the wrong key or
// in the wrong shape it falls back to preferences, so the page reports a clean save while
// the table it was bound to stays empty.
pin(
    'store() names a table and its key',
    array(
        'title'  => 'Ban rule',
        'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
        'groups' => array(array('fields' => array(array('type' => 'text', 'name' => 's_name')))),
    ),
    osc_admin_form('banrule')->title('Ban rule')->store('t_ban_rule', 'pk_i_id')->text('s_name')->toArray()
);
pin(
    'and the key defaults to the one every core table uses',
    array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    osc_admin_form('banrule')->store('t_ban_rule')->toArray()['store']
);
check(
    'a page declaring no store emits none, leaving the registry to supply the default',
    !array_key_exists('store', osc_admin_form('acme')->title('Acme')->text('f')->toArray())
);

harness_section('groups');

pin(
    'fields before any group() land in one untitled group',
    array(
        'groups' => array(
            array('fields' => array(array('type' => 'text', 'name' => 'a'), array('type' => 'text', 'name' => 'b'))),
        ),
    ),
    osc_admin_form('t')->text('a')->text('b')->toArray()
);
pin(
    'group() opens a titled one, with its intro',
    array(
        'title'  => 'Acme',
        'groups' => array(
            array('title' => 'Match', 'intro' => 'How it matches.', 'fields' => array(array('type' => 'text', 'name' => 'a'))),
            array('title' => 'Action', 'fields' => array(array('type' => 'text', 'name' => 'b'))),
        ),
    ),
    osc_admin_form('t')
        ->title('Acme')
        ->group('Match', 'How it matches.')
        ->text('a')
        ->group('Action')
        ->text('b')
        ->toArray()
);
pin(
    'a group opened after fields does not swallow them',
    2,
    count(osc_admin_form('t')->text('a')->group('Later')->text('b')->toArray()['groups'])
);
pin(
    'and a modifier after group() applies to the next field, not the last',
    array('type' => 'text', 'name' => 'b', 'required' => true),
    osc_admin_form('t')->text('a')->group('Later')->text('b')->required()->toArray()['groups'][1]['fields'][0]
);

harness_section('builder output registers as the hand-written array does');

$handSpec = array(
    'title'      => 'My Plugin',
    'menu'       => 'plugins',
    'menu_title' => 'My Plugin',
    'section'    => 'myplugin',
    'capability' => 'administrator',
    'help'       => 'Help.',
    'intro'      => 'Intro.',
    'after_save' => $afterSave,
    'groups'     => array(
        array(
            'title'  => 'Connection',
            'intro'  => 'Where to talk to.',
            'fields' => array(
                array('type' => 'secret', 'name' => 'api_key', 'write_only' => false, 'label' => 'API key',
                      'required' => true),
                array('type' => 'url', 'name' => 'endpoint', 'label' => 'Endpoint', 'default' => 'https://example.test'),
                array('type' => 'number', 'name' => 'batch', 'label' => 'Batch', 'suffix' => 'items', 'width' => 'num'),
            ),
        ),
        array(
            'title'  => 'Behaviour',
            'fields' => array(
                array('type' => 'select', 'name' => 'mode', 'label' => 'Mode', 'options' => array('live' => 'Live', 'test' => 'Test')),
                array('type' => 'radio', 'name' => 'plan', 'label' => 'Plan', 'options' => array('a' => 'A', 'b' => 'B')),
                array('type' => 'checkbox', 'name' => 'verbose', 'label' => 'Log everything', 'row_label' => 'Logging'),
                array('type' => 'textarea', 'name' => 'notes', 'label' => 'Notes', 'help' => 'Freeform.'),
                array('type' => 'custom', 'name' => 'widget', 'label' => 'Widget', 'render' => $renderer),
                array('type' => 'email', 'name' => 'notify', 'label' => 'Notify', 'sanitize' => $sanitizer, 'validate' => $validator),
                array('type' => 'text', 'name' => 'tag', 'label' => 'Tag', 'prefix' => 'Tagged', 'attrs' => array('maxlength' => '20')),
                array('type' => 'tel', 'name' => 'phone', 'label' => 'Phone', 'disabled' => true),
                array('type' => 'color', 'name' => 'accent', 'label' => 'Accent'),
                array('type' => 'hidden', 'name' => 'computed', 'label' => 'Computed'),
            ),
        ),
    ),
);

$built = osc_admin_form('myplugin-built')
    ->title('My Plugin')
    ->menu('plugins')
    ->menuTitle('My Plugin')
    ->section('myplugin')
    ->capability('administrator')
    ->help('Help.')
    ->intro('Intro.')
    ->onAfterSave($afterSave)
    ->group('Connection', 'Where to talk to.')
    ->secret('api_key', 'API key')->writeOnly(false)->required()
    ->url('endpoint', 'Endpoint')->default('https://example.test')
    ->number('batch', 'Batch')->suffix('items')->width('num')
    ->group('Behaviour')
    ->select('mode', 'Mode', array('live' => 'Live', 'test' => 'Test'))
    ->radio('plan', 'Plan', array('a' => 'A', 'b' => 'B'))
    ->checkbox('verbose', 'Log everything')->rowLabel('Logging')
    ->textarea('notes', 'Notes', 'Freeform.')
    ->custom('widget', $renderer, 'Widget')
    ->email('notify', 'Notify')->sanitize($sanitizer)->validate($validator)
    ->text('tag', 'Tag')->prefix('Tagged')->attrs(array('maxlength' => '20'))
    ->tel('phone', 'Phone')->disabled()
    ->color('accent', 'Accent')
    ->hidden('computed', 'Computed');

pin('a whole page is the array a hand writes', $handSpec, $built->toArray());

register_error('myplugin-hand', $handSpec);
$built->register();
$handFields  = SettingsPageRegistry::instance()->fields('myplugin-hand');
$builtFields = SettingsPageRegistry::instance()->fields('myplugin-built');

check('the hand-written page registered', SettingsPageRegistry::instance()->get('myplugin-hand') !== null);
check('the built page registered', SettingsPageRegistry::instance()->get('myplugin-built') !== null);
pin('both declare the same field names', array_keys($handFields), array_keys($builtFields));
pin('and the same normalised field specs', $handFields, $builtFields);
pin('nothing is lost on the way through the groups', 13, count($builtFields));
$builtTypes = array_values(array_unique(array_column($builtFields, 'type')));
sort($builtTypes);
$allTypes = SettingsPageRegistry::FIELD_TYPES;
sort($allTypes);
pin('and the page exercises every type the registry allows', $allTypes, $builtTypes);

$handPage  = SettingsPageRegistry::instance()->get('myplugin-hand');
$builtPage = SettingsPageRegistry::instance()->get('myplugin-built');
unset($handPage['id'], $builtPage['id']);
pin('and the same normalised page, groups and all', $handPage, $builtPage);

harness_section('the registry still refuses a bad spec built this way');

pin(
    'an unknown type',
    'SettingsPageRegistry: page "bad-type" field "f" has unknown type "wysiwyg"',
    builder_error(osc_admin_form('bad-type')->title('T')->field(array('name' => 'f', 'type' => 'wysiwyg')))
);
pin(
    'a select with no options',
    'SettingsPageRegistry: page "bad-select" field "mode" needs options',
    builder_error(osc_admin_form('bad-select')->title('T')->select('mode', 'Mode'))
);
pin(
    'a radio with no options',
    'SettingsPageRegistry: page "bad-radio" field "plan" needs options',
    builder_error(osc_admin_form('bad-radio')->title('T')->radio('plan', 'Plan'))
);
pin(
    'a duplicate field name',
    'SettingsPageRegistry: page "bad-dup" declares "f" twice',
    builder_error(osc_admin_form('bad-dup')->title('T')->text('f')->group('Second')->text('f'))
);
pin(
    'a custom field with no renderer',
    'SettingsPageRegistry: page "bad-custom" field "widget" is custom and needs a render callable',
    builder_error(osc_admin_form('bad-custom')->title('T')->custom('widget'))
);
pin(
    'a dependsOn naming a field the page never declares',
    'SettingsPageRegistry: page "bad-depends" field "s_ip" depends on "b_ip", which the page does not declare',
    builder_error(osc_admin_form('bad-depends')->title('T')->text('s_ip')->dependsOn('b_ip'))
);
pin(
    'a translate on a type that cannot expand over locales',
    'SettingsPageRegistry: page "bad-translate" field "verbose" cannot be translated:'
    . ' only text and textarea expand over locales',
    builder_error(osc_admin_form('bad-translate')->title('T')->checkbox('verbose')->translate())
);
pin(
    'a column that is not a key at all',
    'SettingsPageRegistry: page "bad-column" field "f" column must name a key',
    builder_error(osc_admin_form('bad-column')->title('T')->text('f')->column(''))
);
pin(
    'a field mapped onto the key the store addresses the row by',
    'SettingsPageRegistry: page "bad-pk" field "f" maps to "pk_i_id", '
    . 'the primary key the store addresses the row by',
    builder_error(osc_admin_form('bad-pk')->title('T')->store('t_ban_rule')->text('f')->column('pk_i_id'))
);
pin(
    'a non-callable after_save',
    'SettingsPageRegistry: page "bad-after" after_save must be callable',
    builder_error(osc_admin_form('bad-after')->title('T')->text('f')->onAfterSave('not_a_function_anywhere'))
);
pin(
    'a non-callable validate',
    'SettingsPageRegistry: page "bad-validate" field "f" validate must be callable',
    builder_error(osc_admin_form('bad-validate')->title('T')->text('f')->validate('not_a_function_anywhere'))
);
pin(
    'no fields at all',
    'SettingsPageRegistry: page "bad-empty" declares no fields',
    builder_error(osc_admin_form('bad-empty')->title('T'))
);
pin(
    'no title',
    'SettingsPageRegistry: page "bad-title" needs a string title',
    builder_error(osc_admin_form('bad-title')->text('f'))
);
pin(
    'a menu that is not a core section',
    'SettingsPageRegistry: page "bad-menu" menu "nowhere" is not a core menu section',
    builder_error(osc_admin_form('bad-menu')->title('T')->menu('nowhere')->text('f'))
);
pin(
    'an invalid page id',
    'SettingsPageRegistry: invalid page id "Not An Id"',
    builder_error(osc_admin_form('Not An Id')->title('T')->text('f'))
);
check(
    'and none of the refused pages was registered',
    SettingsPageRegistry::instance()->get('bad-select') === null
    && SettingsPageRegistry::instance()->get('bad-dup') === null
    && SettingsPageRegistry::instance()->get('bad-after') === null
);

harness_section('round trip');

$roundSpec = array(
    'title'  => 'Round',
    'menu'   => '',
    'fields' => array(
        array('type' => 'text', 'name' => 'a', 'label' => 'A', 'required' => true),
        array('type' => 'checkbox', 'name' => 'b', 'label' => 'B'),
    ),
);
// Both doors have to have opened, or the two fields() calls below are equal at empty.
$handError = register_error('round-hand', $roundSpec);
check('the hand-written page registers', $handError === null, (string)$handError);
$builtError = builder_error(
    osc_admin_form('round-built')
        ->title('Round')
        ->menu('')
        ->text('a', 'A')->required()
        ->checkbox('b', 'B')
);
check('and so does the built preference page', $builtError === null, (string)$builtError);

pin(
    'the fields sugar and one untitled group normalise the same',
    SettingsPageRegistry::instance()->fields('round-hand'),
    SettingsPageRegistry::instance()->fields('round-built')
);
$hand  = SettingsPageRegistry::instance()->get('round-hand');
$build = SettingsPageRegistry::instance()->get('round-built');
// The id and the section derived from it are the only two keys these can differ on:
// neither page declared a section, so each falls back to its own id.
pin('the section defaults to the page id, not the builder', 'round-built', $build['section']);
pin('for the hand-written one too', 'round-hand', $hand['section']);
unset($hand['id'], $hand['section'], $build['id'], $build['section']);
pin('and everything else about the page is identical', $hand, $build);
pin('a page asking for no menu keeps none', '', $build['menu']);

harness_section('a table-backed page through both doors');

$tableSpec = array(
    'title'  => 'Ban rule',
    'menu'   => '',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(
        array('type' => 'text', 'name' => 's_name', 'label' => 'Name', 'required' => true),
        array('type' => 'text', 'name' => 'email', 'column' => 's_email', 'label' => 'Email'),
    ),
);
$tableHandError = register_error('table-hand', $tableSpec);
check('the hand-written table page registers', $tableHandError === null, (string)$tableHandError);
$tableBuiltError = builder_error(
    osc_admin_form('table-built')
        ->title('Ban rule')
        ->menu('')
        ->store('t_ban_rule', 'pk_i_id')
        ->text('s_name', 'Name')->required()
        ->text('email', 'Email')->column('s_email')
);
check('and so does the built table page', $tableBuiltError === null, (string)$tableBuiltError);
pin(
    'the store normalises to a table and its key',
    array('type' => 'table', 'table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    SettingsPageRegistry::instance()->get('table-built')['store']
);
pin(
    'the column a field maps to survives the trip through the registry',
    's_email',
    SettingsPageRegistry::instance()->fields('table-built')['email']['column'] ?? null
);
pin(
    'and both doors normalise to the same fields',
    SettingsPageRegistry::instance()->fields('table-hand'),
    SettingsPageRegistry::instance()->fields('table-built')
);
// The default is the whole compatibility claim: every page declared before the store
// existed has to keep writing preferences without being edited.
pin(
    'a page that declared no store is still a preference page',
    array('type' => 'preference'),
    SettingsPageRegistry::instance()->get('round-built')['store']
);

exit(harness_result());
