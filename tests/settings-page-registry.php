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
 * Pins the declared settings page: what a spec must contain, what a submission is
 * sanitised and validated into, and what reaches storage.
 *
 * This is the layer a plugin hands its settings to instead of writing a form, a save
 * handler and a CSRF check of its own, so each of these fails quietly on a live site:
 *
 *  - a spec error accepted at registration is a field silently missing from the page;
 *  - a checkbox read by value rather than presence is a setting that cannot be switched
 *    on, which is exactly how the latest-searches one broke;
 *  - a select that stores an option it never offered is a value the page can never show
 *    again, and one nothing downstream expects;
 *  - a partial write on a rejected save leaves half the page stored and no sign of it.
 *
 * DB-free: registration, sanitisation and validation are pure functions over the spec
 * and the request. Only the final write touches preferences, and that is stubbed here.
 *
 * Usage:  php tests/settings-page-registry.php
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

use mindstellar\settings\SettingsPageRegistry;

// --- the slice of core the helper leans on -----------------------------------------
$GLOBALS['params']      = array();
$GLOBALS['preferences'] = array();
$GLOBALS['hooks']       = array();
$GLOBALS['purified']    = array();

class Params
{
    public static function getParam($key, $a = false, $b = true)
    {
        return $GLOBALS['params'][$key] ?? '';
    }
}

class Preference
{
    public static function newInstance()
    {
        return new self();
    }

    public function get($key, $section = 'osclass')
    {
        return $GLOBALS['preferences'][$section . '/' . $key] ?? null;
    }

    /** Mirrors the real Preference: the whole section, keyed by name. */
    public function getSection($section = 'osclass')
    {
        $out    = array();
        $prefix = $section . '/';
        foreach (($GLOBALS['preferences'] ?? array()) as $k => $v) {
            if (strpos($k, $prefix) === 0) {
                $out[substr($k, strlen($prefix))] = $v;
            }
        }

        return $out;
    }
}

function osc_set_preference($key, $value = '', $section = 'osclass', $type = 'STRING')
{
    $k   = $section . '/' . $key;
    $was = $GLOBALS['preferences'][$k] ?? null;
    $GLOBALS['preferences'][$k] = $value;

    return (int)((string)$was !== (string)$value);
}

function osc_add_hook($hook, $fn, $priority = 5)
{
    $GLOBALS['hooks'][$hook][] = $fn;
}

function osc_admin_base_url($index = false)
{
    return 'https://example.test/oc-admin/index.php';
}

function osc_add_admin_submenu_page($menu, $title, $url, $id, $capability = 'administrator')
{
    $GLOBALS['menu'][] = array($menu, $title, $url, $id, $capability);
}

function osc_validate_email($email, $required = true)
{
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function osc_validate_url($value, $required = false, $headers = false)
{
    return (bool)filter_var($value, FILTER_VALIDATE_URL);
}

function osc_sanitize_url($value)
{
    return filter_var($value, FILTER_SANITIZE_URL);
}

// The real one is hSanitize's, over HTMLPurifier. Here it only records, so which fields
// the save path sends through it can be asserted without a purifier; what it strips is
// pinned against the real one in tests/admin-form-text-purify.php.
function osc_sanitize_text($value)
{
    $GLOBALS['purified'][] = $value;

    return $value;
}

require_once __DIR__ . '/lib/stubs.php';

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

$spec = array(
    'title'   => 'My Plugin',
    'menu'    => 'plugins',
    'intro'   => 'Everything this plugin does.',
    'groups'  => array(
        array(
            'title'  => 'Connection',
            'fields' => array(
                array('type' => 'secret', 'name' => 'api_key', 'label' => 'API key', 'required' => true,
                      'write_only' => false),
                array('type' => 'number', 'name' => 'batch', 'label' => 'Batch', 'min' => 1, 'max' => 500, 'default' => 50),
                array('type' => 'select', 'name' => 'mode', 'label' => 'Mode',
                      'options' => array('live' => 'Live', 'test' => 'Test'), 'default' => 'test'),
                array('type' => 'checkbox', 'name' => 'verbose', 'label' => 'Verbose'),
                array('type' => 'email', 'name' => 'notify', 'label' => 'Notify'),
            ),
        ),
    ),
);

harness_section('what a spec must contain');
check('a well-formed page registers', register_error('myplugin', $spec) === null);
pin('an unknown id is not registered', null, osc_settings_page('nope'));
check('an id with spaces is refused', register_error('my plugin', $spec) !== null);
check('a page with no title is refused', register_error('x1', array('groups' => $spec['groups'])) !== null);
check('a page with no fields is refused', register_error('x2', array('title' => 'X')) !== null);
check(
    'a menu section core does not have is refused',
    register_error('x3', array('title' => 'X', 'menu' => 'nowhere', 'fields' => array(array('name' => 'a')))) !== null
);
check(
    'an unknown field type is refused',
    register_error('x4', array('title' => 'X', 'fields' => array(array('name' => 'a', 'type' => 'wysiwyg')))) !== null
);
// A select with no options renders an empty dropdown: a page that cannot be used, and
// nothing on screen says why.
check(
    'a select with no options is refused',
    register_error('x5', array('title' => 'X', 'fields' => array(array('name' => 'a', 'type' => 'select')))) !== null
);
check(
    'a custom field with no renderer is refused',
    register_error('x6', array('title' => 'X', 'fields' => array(array('name' => 'a', 'type' => 'custom')))) !== null
);
// Two fields of the same name means one silently overwrites the other on save.
check(
    'a name declared twice is refused',
    register_error('x7', array('title' => 'X', 'fields' => array(
        array('name' => 'a'),
        array('name' => 'a'),
    ))) !== null
);

// A master that is not on the page can never be switched on: the dependent field renders,
// never hides, and has its value discarded on every save with nothing to explain why.
$dependsError = register_error('x10', array('title' => 'X', 'fields' => array(
    array('name' => 'a', 'depends' => 'ghost'),
)));
check('a depends naming a field the page does not declare is refused', $dependsError !== null);
pin(
    'and the message names both the field and the master it wanted',
    'SettingsPageRegistry: page "x10" field "a" depends on "ghost", which the page does not declare',
    $dependsError
);
check(
    'a depends that is not a field name is refused',
    register_error('x11', array('title' => 'X', 'fields' => array(array('name' => 'a', 'depends' => true)))) !== null
);
// A master may sit in a later group than the field following it, so the check cannot run
// while the groups are still being walked one at a time.
check('a depends on a field declared in a later group is accepted', register_error('x12', array(
    'title'  => 'X',
    'groups' => array(
        array('fields' => array(array('name' => 'a', 'depends' => 'b'))),
        array('fields' => array(array('name' => 'b', 'type' => 'checkbox'))),
    ),
)) === null);
// A value to match with no master to match it against is a row that never hides and a key
// nothing reads; an empty or non-string value is a row that never shows.
$modeField = array('name' => 'mode', 'type' => 'select', 'options' => array('none' => 'None', 'text' => 'Text'));
pin(
    'a depends_value with no depends is refused',
    'SettingsPageRegistry: page "dv1" field "a" depends_value needs a depends naming the field it is compared with',
    register_error('dv1', array('title' => 'X', 'fields' => array(
        $modeField,
        array('name' => 'a', 'depends_value' => 'text'),
    )))
);
foreach (array(
    'an empty string'          => array('dv2', '', 'must hold only non-empty strings'),
    'an empty list'            => array('dv3', array(), 'must be a string or a list of strings'),
    'a number'                 => array('dv4', 1, 'must be a string or a list of strings'),
    'a list holding an empty string' => array('dv5', array('text', ''), 'must hold only non-empty strings'),
    'a list holding a number'  => array('dv6', array('text', 2), 'must hold only non-empty strings'),
    'a list holding a list'    => array('dv7', array(array('text')), 'must hold only non-empty strings'),
) as $label => [$pageId, $value, $why]) {
    pin(
        'a depends_value of ' . $label . ' is refused',
        'SettingsPageRegistry: page "' . $pageId . '" field "a" depends_value ' . $why,
        register_error($pageId, array('title' => 'X', 'fields' => array(
            $modeField,
            array('name' => 'a', 'depends' => 'mode', 'depends_value' => $value),
        )))
    );
}
check('a depends_value naming one value is accepted', register_error('dv8', array('title' => 'X', 'fields' => array(
    $modeField,
    array('name' => 'a', 'depends' => 'mode', 'depends_value' => 'text'),
))) === null);
check('and so is a list of them', register_error('dv9', array('title' => 'X', 'fields' => array(
    $modeField,
    array('name' => 'a', 'depends' => 'mode', 'depends_value' => array('none', 'text')),
))) === null);
// Only a choice list keeps the value the browser holds. A checkbox is stored as on or off and
// a number or text box is rewritten on save, so the row shown and the value kept could differ.
foreach (array(
    'checkbox' => array('dv10', array('name' => 'm', 'type' => 'checkbox')),
    'number'   => array('dv11', array('name' => 'm', 'type' => 'number')),
    'text'     => array('dv12', array('name' => 'm', 'type' => 'text')),
    'hidden'   => array('dv13', array('name' => 'm', 'type' => 'hidden')),
) as $type => [$pageId, $master]) {
    pin(
        'a depends_value on a ' . $type . ' master is refused',
        'SettingsPageRegistry: page "' . $pageId . '" field "a" depends on ' . $type . ' "m" with a depends_value: '
        . 'only a select or radio master takes one',
        register_error($pageId, array('title' => 'X', 'fields' => array(
            $master,
            array('name' => 'a', 'depends' => 'm', 'depends_value' => '1'),
        )))
    );
}
check('a radio master takes one', register_error('dv14', array('title' => 'X', 'fields' => array(
    array('name' => 'm', 'type' => 'radio', 'options' => array('free' => 'Free', 'paid' => 'Paid')),
    array('name' => 'a', 'depends' => 'm', 'depends_value' => 'paid'),
))) === null);
// A value the master never offers can never be chosen, so the row would never show.
pin(
    'a depends_value that is not one of the master\'s options is refused',
    'SettingsPageRegistry: page "dv15" field "a" depends on "mode" with a depends_value "image", '
    . 'which is not one of its options',
    register_error('dv15', array('title' => 'X', 'fields' => array(
        $modeField,
        array('name' => 'a', 'depends' => 'mode', 'depends_value' => array('text', 'image')),
    )))
);
// Option keys like '0' are ints once in a PHP array; the value is still the string.
check('an integer option key matches its string', register_error('dv16', array('title' => 'X', 'fields' => array(
    array('name' => 'm', 'type' => 'select', 'options' => array('0' => 'Off', '1' => 'On')),
    array('name' => 'a', 'depends' => 'm', 'depends_value' => '0'),
))) === null);

// Only text and textarea expand over locales. Anywhere else the flag would be read on save
// and ignored at render, so the page would store keys nothing on it can edit.
check(
    'translate on a field that cannot expand over locales is refused',
    register_error('x13', array('title' => 'X', 'fields' => array(
        array('name' => 'a', 'type' => 'checkbox', 'translate' => true),
    ))) !== null
);
check('translate on a text field is accepted', register_error('x14', array('title' => 'X', 'fields' => array(
    array('name' => 'a', 'type' => 'text', 'translate' => true),
))) === null);
check('translate on a textarea is accepted', register_error('x15', array('title' => 'X', 'fields' => array(
    array('name' => 'a', 'type' => 'textarea', 'translate' => true),
))) === null);

// Same rule for the flag that turns purification off: only free-typed values are purified,
// so setting it anywhere else reads as a decision that was taken and is not.
check(
    'purify on a type nothing purifies is refused',
    register_error('x30', array('title' => 'X', 'fields' => array(
        array('name' => 'a', 'type' => 'secret', 'purify' => false),
    ))) !== null
);
pin(
    'and the refusal says which types are purified',
    'SettingsPageRegistry: page "x31" field "a" cannot set purify: only text, textarea, tel, color, hidden'
    . ' are purified',
    register_error('x31', array('title' => 'X', 'fields' => array(
        array('name' => 'a', 'type' => 'secret', 'purify' => false),
    )))
);
// 'purify' => 'no' is truthy, so a string here would switch purification *on* for a field
// that was asking for the opposite.
check(
    'a purify that is not a boolean is refused',
    register_error('x32', array('title' => 'X', 'fields' => array(
        array('name' => 'a', 'type' => 'text', 'purify' => 'no'),
    ))) !== null
);
check('purify on a text field is accepted', register_error('x33', array('title' => 'X', 'fields' => array(
    array('name' => 'a', 'type' => 'text', 'purify' => false),
))) === null);
check('purify on a textarea is accepted', register_error('x34', array('title' => 'X', 'fields' => array(
    array('name' => 'a', 'type' => 'textarea', 'purify' => false),
))) === null);
// A phone number and a colour are typed by hand into a control that filters nothing, so
// they are purified like the text field beside them and can say otherwise.
check('purify on a tel is accepted', register_error('x35', array('title' => 'X', 'fields' => array(
    array('name' => 'a', 'type' => 'tel', 'purify' => false),
))) === null);
check('purify on a color is accepted', register_error('x36', array('title' => 'X', 'fields' => array(
    array('name' => 'a', 'type' => 'color', 'purify' => false),
))) === null);

// write_only is what the control shows, and it is deliberately not the same key as
// persist, which is what the column takes. Riding both on one key blanked a column the
// admin had never been shown: the form drew the field empty and saved that.
harness_section('write_only says what the control shows, and a secret must say');
check(
    'a secret that does not declare write_only is refused',
    register_error('w1', array('title' => 'X', 'fields' => array(
        array('name' => 'k', 'type' => 'secret'),
    ))) !== null
);
pin(
    'and the refusal names the key it wants',
    'SettingsPageRegistry: page "w2" field "k" is a secret and must declare write_only',
    register_error('w2', array('title' => 'X', 'fields' => array(
        array('name' => 'k', 'type' => 'secret'),
    )))
);
check('a secret that is write-only registers', register_error('w3', array('title' => 'X', 'fields' => array(
    array('name' => 'k', 'type' => 'secret', 'write_only' => true),
))) === null);
// An API key is the other kind of secret: it is stored to be read back and edited, and
// the type alone cannot tell the two apart, which is why the declaration has to.
check('so does one that says it reads back', register_error('w4', array('title' => 'X', 'fields' => array(
    array('name' => 'k', 'type' => 'secret', 'write_only' => false),
))) === null);
check(
    'write_only that is not a boolean is refused',
    register_error('w5', array('title' => 'X', 'fields' => array(
        array('name' => 'a', 'type' => 'text', 'write_only' => 'yes'),
    ))) !== null
);
check(
    'write_only on a custom field is refused, because core neither reads nor writes one',
    register_error('w6', array('title' => 'X', 'fields' => array(
        array('name' => 'a', 'type' => 'custom', 'render' => static function () {
        }, 'write_only' => true),
    ))) !== null
);
// Every other type may declare it, on either store: a preference holding an API key is as
// good a reason not to redraw it as a column holding a hash.
check('write_only on a text field is accepted', register_error('w7', array('title' => 'X', 'fields' => array(
    array('name' => 'a', 'type' => 'text', 'write_only' => true),
))) === null);
check('and it is not a table-store-only key', register_error('w8', array(
    'title'  => 'X',
    'store'  => 'preference',
    'fields' => array(array('name' => 'a', 'type' => 'text', 'write_only' => true)),
)) === null);
pin(
    'and the list the registry accepts is the list the save path purifies',
    array('text', 'textarea', 'tel', 'color', 'hidden'),
    SettingsPageRegistry::PURIFIED_TYPES
);

// Core never collects a custom field's value, so a custom master is read as off on every
// save while the plugin's own markup shows the dependent row as though it were on.
$customMaster = register_error('x16', array('title' => 'X', 'fields' => array(
    array('name' => 'widget', 'type' => 'custom', 'render' => static fn () => null),
    array('name' => 'a', 'depends' => 'widget'),
)));
check('a depends on a custom field is refused', $customMaster !== null);
pin(
    'and the message says why core cannot answer for it',
    'SettingsPageRegistry: page "x16" field "a" depends on custom field "widget", '
    . 'whose value core never reads',
    $customMaster
);
// A translated master has one value per locale and its controls are named for the locale,
// so the server would call it on while the client script cannot find it at all.
$transMaster = register_error('x17', array('title' => 'X', 'fields' => array(
    array('name' => 'master', 'type' => 'text', 'translate' => true),
    array('name' => 'a', 'depends' => 'master'),
)));
check('a depends on a translated field is refused', $transMaster !== null);
pin(
    'and the message names the per-locale problem',
    'SettingsPageRegistry: page "x17" field "a" depends on translated field "master", '
    . 'which has one value per locale',
    $transMaster
);
// A cycle resolves to off at save time, so every field on it is discarded on every
// submission: no error, nothing written, and "Nothing to update" as the only symptom.
$selfCycle = register_error('x18', array('title' => 'X', 'fields' => array(
    array('name' => 'a', 'depends' => 'a'),
)));
check('a field depending on itself is refused', $selfCycle !== null);
pin(
    'and the message shows the loop',
    'SettingsPageRegistry: page "x18" has a depends cycle: a -> a',
    $selfCycle
);
$pairCycle = register_error('x19', array('title' => 'X', 'fields' => array(
    array('name' => 'a', 'depends' => 'b'),
    array('name' => 'b', 'depends' => 'a'),
)));
check('two fields depending on each other are refused', $pairCycle !== null);
pin(
    'and the message walks the whole chain',
    'SettingsPageRegistry: page "x19" has a depends cycle: a -> b -> a',
    $pairCycle
);
check('a three-field cycle is refused too', register_error('x20', array('title' => 'X', 'fields' => array(
    array('name' => 'a', 'depends' => 'b'),
    array('name' => 'b', 'depends' => 'c'),
    array('name' => 'c', 'depends' => 'a'),
))) !== null);
// The cycle check must not mistake a long chain for a loop.
check('a three-field chain that ends is accepted', register_error('x21', array('title' => 'X', 'fields' => array(
    array('name' => 'a', 'depends' => 'b'),
    array('name' => 'b', 'depends' => 'c'),
    array('name' => 'c', 'type' => 'checkbox'),
))) === null);
// A custom field may still follow a master: core stores nothing for it either way, and the
// plugin's own markup is what the shared script hides.
check('a custom field may itself depend on something', register_error('x22', array('title' => 'X', 'fields' => array(
    array('name' => 'b_on', 'type' => 'checkbox'),
    array('name' => 'widget', 'type' => 'custom', 'render' => static fn () => null, 'depends' => 'b_on'),
))) === null);

harness_section('the store a page writes through');

// The default is the compatibility claim: every page declared before the store existed
// keeps writing preferences with no edit, so a page that names none must normalise to the
// preference store rather than to nothing.
pin(
    'a page that names no store writes preferences',
    array('type' => 'preference'),
    osc_settings_page('myplugin')['store']
);
check('and naming it explicitly is accepted', register_error('s1', array(
    'title'  => 'X',
    'store'  => 'preference',
    'fields' => array(array('name' => 'a')),
)) === null);
// A store nobody implements cannot fall back: silently treated as preferences the page
// reports a clean save while the table it was bound to stays empty.
$unknownStore = register_error('s2', array(
    'title'  => 'X',
    'store'  => 'model',
    'fields' => array(array('name' => 'a')),
));
check('a store core does not have is refused', $unknownStore !== null);
pin(
    'and the message names the store that was asked for',
    'SettingsPageRegistry: page "s2" store "model" is not a store core has',
    $unknownStore
);
$shapelessStore = register_error('s3', array(
    'title'  => 'X',
    'store'  => 42,
    'fields' => array(array('name' => 'a')),
));
check('a store that is neither a name nor a table spec is refused', $shapelessStore !== null);
pin(
    'and the message says what one looks like',
    'SettingsPageRegistry: page "s3" store must be "preference" or an array naming a table and its pk',
    $shapelessStore
);
// Without a key there is nothing to load a row by and nothing to update against, so every
// save would insert a new row and the edit form would never find the one it edited.
$noPk = register_error('s4', array(
    'title'  => 'X',
    'store'  => array('table' => 't_ban_rule'),
    'fields' => array(array('name' => 'a')),
));
check('a table store with no pk is refused', $noPk !== null);
pin('and the message names what is missing', 'SettingsPageRegistry: page "s4" store needs a pk', $noPk);
$noTable = register_error('s5', array(
    'title'  => 'X',
    'store'  => array('pk' => 'pk_i_id'),
    'fields' => array(array('name' => 'a')),
));
check('a table store with no table is refused', $noTable !== null);
pin('and that one too', 'SettingsPageRegistry: page "s5" store needs a table', $noTable);
// The table and the key are interpolated into SQL as identifiers, so they are held to the
// same allowlist QueryBuilder applies -- refused here rather than at the first save.
$badIdent = register_error('s6', array(
    'title'  => 'X',
    'store'  => array('table' => 't_ban rule', 'pk' => 'pk_i_id'),
    'fields' => array(array('name' => 'a')),
));
check('a table name that is not an identifier is refused', $badIdent !== null);
pin(
    'and the message quotes it',
    'SettingsPageRegistry: page "s6" store table "t_ban rule" is not an identifier',
    $badIdent
);
check('a pk that is not an identifier is refused', register_error('s7', array(
    'title'  => 'X',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk i id'),
    'fields' => array(array('name' => 'a')),
)) !== null);

// A well-formed table page, and the shape everything downstream reads.
check('a well-formed table page registers', register_error('s8', array(
    'title'  => 'X',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(
        array('name' => 's_name'),
        array('name' => 'email', 'column' => 's_email'),
    ),
)) === null);
pin(
    'and normalises to the table and key it named',
    array('type' => 'table', 'table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    osc_settings_page('s8')['store']
);
pin(
    'a field that declares a column keeps it',
    's_email',
    SettingsPageRegistry::instance()->fields('s8')['email']['column'] ?? null
);
// A preference has a key the same way a row has a column, so a column on a preference page
// is the preference the value is stored under -- which is what lets a control keep the name
// its own page's script knows while the value goes on living under the key readers use.
check('a column on a page that stores preferences names the preference', register_error('s9', array(
    'title'  => 'X',
    'fields' => array(array('name' => 'max_latest_items_at_home', 'column' => 'maxLatestItems@home')),
)) === null);
pin(
    'and it is kept as declared, punctuation and all',
    'maxLatestItems@home',
    SettingsPageRegistry::instance()->fields('s9')['max_latest_items_at_home']['column'] ?? null
);
// t_preference takes any string for a key, so nothing downstream would refuse this: the
// declaration would simply write whatever it named into the page's section.
$badPrefKey = register_error('s9b', array(
    'title'  => 'X',
    'fields' => array(array('name' => 'a', 'column' => 'not a key')),
));
check('a preference key that is not one is refused', $badPrefKey !== null);
pin(
    'and the message says what it mapped to',
    'SettingsPageRegistry: page "s9b" field "a" maps to "not a key", which is not a preference key',
    $badPrefKey
);
check('two scopes are not a key either', register_error('s9c', array(
    'title'  => 'X',
    'fields' => array(array('name' => 'a', 'column' => 'a@b@c')),
)) !== null);
// The field that is stored nowhere never reaches a key, so what it would have been called
// does not arise -- the same escape the table store already has.
check('a field stored nowhere is not held to it', register_error('s9d', array(
    'title'  => 'X',
    'fields' => array(array('name' => 'a', 'column' => 'not a key', 'persist' => false)),
)) === null);
check('a column that is not a string is refused', register_error('s10', array(
    'title'  => 'X',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(array('name' => 'a', 'column' => true)),
)) !== null);
check('an empty column is refused', register_error('s11', array(
    'title'  => 'X',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(array('name' => 'a', 'column' => '')),
)) !== null);
// A field mapped onto the primary key is the store overwriting the row it is addressing:
// the update sets the key it is matching on, and the insert names a key nobody chose.
$pkField = register_error('s12', array(
    'title'  => 'X',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(array('name' => 'pk_i_id')),
));
check('a field mapped onto the primary key is refused', $pkField !== null);
pin(
    'and the message says what the key is for',
    'SettingsPageRegistry: page "s12" field "pk_i_id" maps to "pk_i_id", '
    . 'the primary key the store addresses the row by',
    $pkField
);
check('and by an explicit column just the same', register_error('s13', array(
    'title'  => 'X',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(array('name' => 'a', 'column' => 'pk_i_id')),
)) !== null);
// A field name is a column name on a table store, so it is held to the identifier
// allowlist even when it declares no column of its own.
check('a field name that is not an identifier is refused on a table store', register_error('s14', array(
    'title'  => 'X',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(array('name' => 'a.b')),
)) !== null);
check('while a preference page may still name a field anything', register_error('s15', array(
    'title'  => 'X',
    'fields' => array(array('name' => 'a.b')),
)) === null);
// One column holds one value. A locale table is a design this store does not have yet, so
// the flag is refused rather than writing 's_name' plus a locale code as a column name.
$transTable = register_error('s16', array(
    'title'  => 'X',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(array('name' => 's_name', 'type' => 'text', 'translate' => true)),
));
check('a translated field on a table store is refused', $transTable !== null);
pin(
    'and the message says why a column cannot hold it',
    'SettingsPageRegistry: page "s16" field "s_name" cannot be translated on a table store: '
    . 'a column holds one value, not one per locale',
    $transTable
);
// A field that says what its key takes is the only way a screen can declare a control that
// is stored nowhere -- a confirmation box, a re-authentication box, the presets another
// field is derived from -- or a value that is derived from the controls beside it. Neither
// is about columns, so both apply on a preference page too.
check('persist on a page that stores preferences is accepted', register_error('s18', array(
    'title'  => 'X',
    'fields' => array(array('name' => 'a', 'persist' => false)),
)) === null);
check('and so is a persist callable there', register_error('s18b', array(
    'title'  => 'X',
    'fields' => array(array('name' => 'a', 'persist' => static fn ($v) => $v)),
)) === null);
$badPersist = register_error('s19', array(
    'title'  => 'X',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(array('name' => 'a', 'persist' => true)),
));
check('persist that is neither false nor a callable is refused', $badPersist !== null);
pin(
    'and the message says what it may be',
    'SettingsPageRegistry: page "s19" field "a" persist must be false or a callable',
    $badPersist
);
// A field that is no column is held to none of the column rules: it may be named anything,
// because nothing is ever written under that name.
check('a field that is no column may be named after the primary key', register_error('s20', array(
    'title'  => 'X',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(
        array('name' => 's_name'),
        array('name' => 'pk_i_id', 'persist' => false),
    ),
)) === null);
check('and a field whose column is derived is still held to them', register_error('s21', array(
    'title'  => 'X',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(array('name' => 'pk_i_id', 'persist' => static fn ($v) => $v)),
)) !== null);
// A custom field has no column either way, so the table rules do not apply to it.
check('a custom field on a table store needs no column', register_error('s17', array(
    'title'  => 'X',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(
        array('name' => 's_name'),
        array('name' => 'widget.1', 'type' => 'custom', 'render' => static fn () => null),
    ),
)) === null);
check(
    'and none of the refused pages was registered',
    osc_settings_page('s2') === null && osc_settings_page('s4') === null && osc_settings_page('s21') === null
);

harness_section('after_save');
check(
    'a non-callable after_save is refused',
    register_error('x8', array('title' => 'X', 'fields' => array(array('name' => 'a')), 'after_save' => 'not a function')) !== null
);
$x9AfterSave = static function ($values, $id) {
};
check('a well-formed page with after_save registers', register_error('x9', array(
    'title'      => 'X',
    'fields'     => array(array('name' => 'a')),
    'after_save' => $x9AfterSave,
)) === null);
// Identity, not is_callable(): the page has to run the callable it was handed, and a
// wrapper or a default substituted for it would satisfy is_callable() just as well.
check('its after_save is the very callable that was declared', osc_settings_page('x9')['after_save'] === $x9AfterSave);
pin('a page that declared none has a null after_save', null, osc_settings_page('myplugin')['after_save']);

harness_section('the page\'s own validate');
// The rule spanning more than one field. Refused here rather than at save time: a page
// whose cross-field rule is a typo registers cleanly and then never rejects anything,
// which is the failure nobody sees until the bad row is already stored.
check(
    'a non-callable validate is refused',
    register_error('x10', array('title' => 'X', 'fields' => array(array('name' => 'a')), 'validate' => 'not a function')) !== null
);
// The row being saved is the third argument, so a cross-field rule can say "that name is
// taken, except on this row". Its position is pinned by tests/admin-ban-rule-form.php.
$x11Validate = static function (array $values, string $pageId, $id) {
    return null;
};
check('a well-formed page with validate registers', register_error('x11', array(
    'title'    => 'X',
    'fields'   => array(array('name' => 'a')),
    'validate' => $x11Validate,
)) === null);
check('its validate is the very callable that was declared', osc_settings_page('x11')['validate'] === $x11Validate);
pin('a page that declared none has a null validate', null, osc_settings_page('myplugin')['validate']);

harness_section('two plugins claiming one id');
// The first keeps it: replacing the page would take the other plugin's settings off the
// menu and leave its saved values under a section nothing reads, both without a sound.
// Throwing is not an option either -- plugins are included unguarded from oc-load.php,
// so an exception here would white-screen the front end over a name collision.
$before = osc_settings_page('myplugin')['title'];
register_error('myplugin', array('title' => 'Impostor', 'fields' => array(array('name' => 'z'))));
pin('the first registration keeps the page', $before, osc_settings_page('myplugin')['title']);
check('the field it declared is not there', !array_key_exists('z', SettingsPageRegistry::instance()->fields('myplugin')));
// Silent would be as bad as replacing; the collision is recorded so it can be found.
pin('and the collision is counted', 1, osc_settings_page_conflicts()['myplugin'] ?? 0);
check('a healthy id is not listed', !array_key_exists('cust', osc_settings_page_conflicts()));

harness_section('defaults');
$page = osc_settings_page('myplugin');
pin('the section defaults to the page id', 'myplugin', $page['section']);
pin('the menu title defaults to the title', 'My Plugin', $page['menu_title']);
pin('the capability defaults to administrator', 'administrator', $page['capability']);
pin('a flat fields list becomes one group', 1, count($page['groups']));

harness_section('values before anything is saved');
pin('a declared default is used', 50, osc_settings_value('myplugin', 'batch'));
pin('a select falls back to its default', 'test', osc_settings_value('myplugin', 'mode'));
// Not '' — a caller writing `if (osc_settings_value(...))` must not have to know that a
// checkbox nobody has touched reads back as an empty string.
pin('an untouched checkbox is false', false, osc_settings_value('myplugin', 'verbose'));
pin('a field the page does not declare is null', null, osc_settings_value('myplugin', 'ghost'));

harness_section('sanitising a submission');
$fields = SettingsPageRegistry::instance()->fields('myplugin');
$GLOBALS['params'] = array('batch' => ' 12 ');
pin('a number arrives as an int', 12, osc_settings_sanitize($fields['batch']));
$GLOBALS['params'] = array('batch' => '2.5');
pin('a decimal stays a float', 2.5, osc_settings_sanitize($fields['batch']));
// The one type that keeps its whitespace. A password with a space at either end is a
// password, and the sign-in form reads what was typed rather than a trimmed copy of it.
$GLOBALS['params'] = array('api_key' => '  sk-live-9f2a  ');
pin('a secret is stored exactly as typed', '  sk-live-9f2a  ', osc_settings_sanitize($fields['api_key']));
$GLOBALS['params'] = array('api_key' => '   ');
pin('spaces alone are a value like any other', '   ', osc_settings_sanitize($fields['api_key']));
$GLOBALS['params'] = array('notify' => '  ops@example.test  ');
pin('while every other type still loses them', 'ops@example.test', osc_settings_sanitize($fields['notify']));
$GLOBALS['params'] = array('verbose' => '1');
pin('a ticked checkbox is true', true, osc_settings_sanitize($fields['verbose']));
$GLOBALS['params'] = array();
pin('an absent checkbox is false', false, osc_settings_sanitize($fields['verbose']));
// A checkbox with no value attribute submits whatever the browser invents; presence is
// the only thing that can be relied on.
$GLOBALS['params'] = array('verbose' => 'on');
pin("a checkbox is read by presence, not by its value", true, osc_settings_sanitize($fields['verbose']));
// An array where a scalar was declared is the shape an attacker reaches for first.
$GLOBALS['params'] = array('api_key' => array('a', 'b'));
pin('an array submitted for a scalar field is dropped', '', osc_settings_sanitize($fields['api_key']));

// An address is an identifier, so a typo has to come back to be corrected rather than be
// edited into a different address that then validates. FILTER_SANITIZE_EMAIL did the
// latter: 'john doe@example.test' was stored as johndoe@example.test and reported saved.
$GLOBALS['params'] = array('notify' => '  someone@example.test  ');
pin('an address is trimmed', 'someone@example.test', osc_settings_sanitize($fields['notify']));
$GLOBALS['params'] = array('notify' => 'john doe@example.test');
pin('and otherwise handed on as typed', 'john doe@example.test', osc_settings_sanitize($fields['notify']));
pin(
    'so validation is what answers it',
    'Notify is not a valid email address',
    osc_settings_validate($fields['notify'], osc_settings_sanitize($fields['notify']))
);
$GLOBALS['params'] = array('notify' => '<b>joe</b>@example.test');
pin('a tag is not quietly deleted out of one either', '<b>joe</b>@example.test', osc_settings_sanitize($fields['notify']));
pin(
    'it is refused too',
    'Notify is not a valid email address',
    osc_settings_validate($fields['notify'], osc_settings_sanitize($fields['notify']))
);

harness_section('validation');
pin('a required field left empty is rejected', 'API key cannot be left empty', osc_settings_validate($fields['api_key'], ''));
pin('a number under its minimum is rejected', 'Batch must be 1 or more', osc_settings_validate($fields['batch'], 0));
pin('a number over its maximum is rejected', 'Batch must be 500 or less', osc_settings_validate($fields['batch'], 900));
pin('a number in range passes', null, osc_settings_validate($fields['batch'], 50));
pin('an option that was never offered is rejected', 'Mode is not one of the available options', osc_settings_validate($fields['mode'], 'debug'));
pin('a declared option passes', null, osc_settings_validate($fields['mode'], 'live'));
pin('a malformed email is rejected', 'Notify is not a valid email address', osc_settings_validate($fields['notify'], 'not-an-email'));
// An optional field left blank has nothing to check; rejecting it would make every
// optional field on the page required by accident.
pin('an optional field left empty passes', null, osc_settings_validate($fields['notify'], ''));

harness_section('a custom validator and a custom sanitiser');
SettingsPageRegistry::instance()->register('cust', array(
    'title'  => 'Custom',
    'fields' => array(
        array('name' => 'slug', 'label' => 'Slug',
              'sanitize' => static fn ($v) => strtolower(str_replace(' ', '-', $v)),
              'validate' => static fn ($v, $f) => strlen($v) > 8 ? 'Slug is too long' : null),
    ),
));
$cust = SettingsPageRegistry::instance()->fields('cust');
$GLOBALS['params'] = array('slug' => 'My Slug');
pin('the field sanitiser runs instead of the type default', 'my-slug', osc_settings_sanitize($cust['slug']));
pin('the field validator runs', 'Slug is too long', osc_settings_validate($cust['slug'], 'aaaaaaaaaa'));

harness_section('which fields the save path purifies');
// Declared text is stripped to plain text before anything else sees it -- the field's own
// sanitiser included, so a callback like the slug one above is handed text and not markup.
$GLOBALS['purified'] = array();
$GLOBALS['params']   = array('slug' => 'My Slug');
osc_settings_sanitize($cust['slug']);
pin('a text field goes through the shared text sanitiser', array('My Slug'), $GLOBALS['purified']);
$GLOBALS['purified'] = array();
$GLOBALS['params']   = array('api_key' => 'sk-live-9f2a', 'batch' => '4', 'notify' => 'a@b.com');
osc_settings_sanitize($fields['api_key']);
osc_settings_sanitize($fields['batch']);
osc_settings_sanitize($fields['notify']);
// A secret may legitimately contain anything, and a number or an address is already
// narrowed by its own type. Purifying those would take characters out for no gain.
pin('a secret, a number and an email do not', array(), $GLOBALS['purified']);

SettingsPageRegistry::instance()->register('typed', array(
    'title'  => 'Typed',
    'fields' => array(
        array('name' => 'phone', 'type' => 'tel'),
        array('name' => 'shade', 'type' => 'color'),
    ),
));
$typedFields         = SettingsPageRegistry::instance()->fields('typed');
$GLOBALS['purified'] = array();
$GLOBALS['params']   = array('phone' => '+1 555 0100', 'shade' => '#ff8800');
osc_settings_sanitize($typedFields['phone']);
osc_settings_sanitize($typedFields['shade']);
// Neither control filters what is typed into it, and neither type has a sanitiser of its
// own, so without this they store less filtered than the text field beside them.
pin('a tel and a color are purified like text', array('+1 555 0100', '#ff8800'), $GLOBALS['purified']);

SettingsPageRegistry::instance()->register('raw', array(
    'title'  => 'Raw',
    'fields' => array(
        array('name' => 'body', 'type' => 'textarea', 'purify' => false),
        array('name' => 'title', 'type' => 'text'),
    ),
));
$rawFields           = SettingsPageRegistry::instance()->fields('raw');
$GLOBALS['purified'] = array();
$GLOBALS['params']   = array('body' => '<p>kept</p>', 'title' => '<p>stripped</p>');
osc_settings_sanitize($rawFields['body']);
pin('a field declaring purify => false is not sent through it', array(), $GLOBALS['purified']);
osc_settings_sanitize($rawFields['title']);
pin('while the field beside it still is', array('<p>stripped</p>'), $GLOBALS['purified']);

harness_section('saving');
$GLOBALS['params'] = array('api_key' => 'k', 'batch' => '25', 'mode' => 'live', 'verbose' => '1', 'notify' => 'a@b.com');
$result = osc_settings_save('myplugin');
pin('a good submission reports no errors', 0, count($result['errors']));
pin('and writes every field', 5, $result['updated']);
pin('into the page section', '25', $GLOBALS['preferences']['myplugin/batch']);
pin('a checkbox stores 1', '1', $GLOBALS['preferences']['myplugin/verbose']);

$GLOBALS['preferences'] = array();
$GLOBALS['params'] = array('api_key' => '', 'batch' => '900', 'mode' => 'live', 'notify' => '');
$result = osc_settings_save('myplugin');
pin('a bad submission reports every error', 2, count($result['errors']));
// Half a saved page is worse than a rejected one: the half that landed is invisible.
pin('and writes nothing at all', 0, count($GLOBALS['preferences']));
pin('while handing back what was submitted', 900, $result['values']['batch']);

harness_section('reading back');
$GLOBALS['params'] = array('api_key' => 'k', 'batch' => '25', 'mode' => 'live', 'verbose' => '1', 'notify' => 'a@b.com');
osc_settings_save('myplugin');
pin('a number reads back as a number', 25, osc_settings_value('myplugin', 'batch'));
pin('a checkbox reads back as a bool', true, osc_settings_value('myplugin', 'verbose'));
$GLOBALS['params']['verbose'] = '';
osc_settings_save('myplugin');
pin('and an unticked one as false', false, osc_settings_value('myplugin', 'verbose'));

harness_section('the menu entry');
$GLOBALS['menu'] = array();
osc_settings_menu_init();
$ids = array_column($GLOBALS['menu'], 3);
check('a page that asked for a menu gets one', in_array('settings-page-myplugin', $ids, true), implode(',', $ids));
// A page reached from a plugin's own link should not also plant an entry nobody asked for.
SettingsPageRegistry::instance()->register('hidden', array(
    'title'  => 'Hidden',
    'menu'   => '',
    'fields' => array(array('name' => 'a')),
));
$GLOBALS['menu'] = array();
osc_settings_menu_init();
$ids = array_column($GLOBALS['menu'], 3);
check('a page that asked for none does not', !in_array('settings-page-hidden', $ids, true), implode(',', $ids));
// The default belongs under Plugins: that is where a plugin's own settings live.
$entry = array_values(array_filter($GLOBALS['menu'], static fn ($m) => $m[3] === 'settings-page-cust'));
pin('a page that named no section lands under Plugins', 'plugins', $entry[0][0] ?? '');
// A theme's functions.php loads after admin_menu_init has run, so a late page gets its entry at once.
$GLOBALS['menu'] = array();
osc_register_settings_page('late', array('title' => 'Late', 'menu' => 'appearance', 'fields' => array(array('name' => 'a'))));
$ids = array_column($GLOBALS['menu'], 3);
check('a page declared after the menu was built still gets its entry', in_array('settings-page-late', $ids, true), implode(',', $ids));

exit(harness_result());
