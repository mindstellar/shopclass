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
 * Pins the 'image' field of a declared settings page: what a spec may say, what the page
 * draws, every way a save can change the stored image, and osc_settings_image_url().
 *
 * Every failure here is quiet on a live site: a replaced logo whose old file is never
 * deleted, a rejected save that still uploads, a remove box that deletes a listing photo a
 * hand-edited preference pointed at, or a front-end call to the save pipeline that uploads.
 *
 * DB-free. The uploader, the resource model and the image check are stubbed; the front-end
 * context runs in a child process, because OC_ADMIN is a constant.
 *
 * Usage:  php tests/settings-image-field.php
 */

namespace mindstellar\settings {

    // CLI has no real uploads; SettingsImage calls this unqualified, so the namespaced stub wins.
    function is_uploaded_file($file): bool
    {
        return $file !== '' && strpos($file, 'not-uploaded') === false;
    }
}

namespace mindstellar\model {

    /** The slice of the resource model SettingsImage reads, over $GLOBALS['resources']. */
    final class Resource
    {
        public const OWNER_ITEM    = 'item';
        public const OWNER_USER    = 'user';
        public const OWNER_PAGE    = 'page';
        public const OWNER_SETTING = 'setting';

        public static function newInstance(): self
        {
            return new self();
        }

        public static function isValidOwnerType(string $type): bool
        {
            return preg_match('/^[a-z0-9_-]{1,20}$/', $type) === 1;
        }

        public function findByOwner(string $ownerType, int $ownerId): array
        {
            $out = array();
            foreach ($GLOBALS['resources'] as $row) {
                if ($row['s_owner_type'] === $ownerType && (int)$row['i_owner_id'] === $ownerId) {
                    $out[] = $row;
                }
            }

            return $out;
        }
    }
}

namespace mindstellar\storage {

    /** Records calls into $GLOBALS['events'] instead of touching storage. */
    final class ResourceUploader
    {
        public function upload(string $ownerType, int $ownerId, string $tmpFile, array $options = array()): array|false
        {
            $GLOBALS['events'][]      = array('upload', $ownerType, $ownerId, $tmpFile);
            $GLOBALS['uploadOptions'] = $options;
            if (strpos($tmpFile, 'uploader-fails') !== false) {
                return false;
            }
            $id  = ++$GLOBALS['nextId'];
            $row = array(
                'pk_i_id'      => $id,
                's_owner_type' => $ownerType,
                'i_owner_id'   => $ownerId,
                's_path'       => 'oc-content/uploads/setting/0/',
                's_extension'  => 'png',
                's_storage'    => 'local',
            );
            $GLOBALS['resources'][$id] = $row;

            return $row;
        }

        public function delete(array $resourceRow): void
        {
            $GLOBALS['events'][] = array('delete', (int)$resourceRow['pk_i_id']);
            unset($GLOBALS['resources'][(int)$resourceRow['pk_i_id']]);
        }
    }
}

namespace {

    use mindstellar\admin\ui\FormSpec;
    use mindstellar\admin\ui\SettingsForm;
    use mindstellar\settings\SettingsPageRegistry;

    $front = getenv('SETTINGS_IMAGE_FRONT') === '1';

    if (!defined('ABS_PATH')) {
        define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
    }
    define('OC_ADMIN', !$front);

    $GLOBALS['params']      = array();
    $GLOBALS['files']       = array();
    $GLOBALS['preferences'] = array();
    $GLOBALS['resources']   = array();
    $GLOBALS['events']      = array();
    $GLOBALS['hooks']       = array();
    $GLOBALS['nextId']      = 100;
    $GLOBALS['adminLogged'] = true;
    $GLOBALS['moderator']   = false;
    $GLOBALS['siteMaxKb']   = 100;
    $GLOBALS['prefFails']   = false;
    $GLOBALS['beforeSave']  = null;

    class Params
    {
        public static function getParam($key, $a = false, $b = true)
        {
            return $GLOBALS['params'][$key] ?? '';
        }

        public static function getParamString($key, $a = false, $b = true, $c = true)
        {
            $value = $GLOBALS['params'][$key] ?? '';

            return is_array($value) ? '' : (string)$value;
        }

        public static function purifyText($value)
        {
            return strip_tags((string)$value);
        }

        public static function getFiles($key)
        {
            return $GLOBALS['files'][$key] ?? array();
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
            return $GLOBALS['preferences'][$section . '/' . $key] ?? '';
        }

        public function getSection($section = 'osclass')
        {
            $out = array();
            foreach ($GLOBALS['preferences'] as $k => $v) {
                if (strpos($k, $section . '/') === 0) {
                    $out[substr($k, strlen($section) + 1)] = $v;
                }
            }

            return $out;
        }
    }

    class ImageProcessing
    {
        public static function fromFile($path)
        {
            if (strpos($path, 'not-an-image') !== false) {
                throw new RuntimeException('bad image');
            }

            return new self();
        }
    }

    function osc_set_preference($key, $value = '', $section = 'osclass', $type = 'STRING')
    {
        if ($GLOBALS['prefFails']) {
            return false;
        }
        $GLOBALS['preferences'][$section . '/' . $key] = $value;
        $GLOBALS['events'][]                           = array('pref', $key, $value);

        return true;
    }

    function osc_run_hook($hook, ...$args)
    {
        $GLOBALS['hooks'][] = array($hook, $args);
    }

    function osc_apply_filter($hook, $content = '', ...$args)
    {
        if ($hook === 'admin_form_before_save' && is_callable($GLOBALS['beforeSave'])) {
            return call_user_func($GLOBALS['beforeSave'], $content);
        }

        return $content;
    }

    function osc_add_hook($hook, $fn, $priority = 5)
    {
    }

    function osc_is_admin_user_logged_in()
    {
        return $GLOBALS['adminLogged'];
    }

    function osc_is_moderator()
    {
        return $GLOBALS['moderator'];
    }

    function osc_max_size_kb()
    {
        return $GLOBALS['siteMaxKb'];
    }

    function osc_base_url()
    {
        return 'https://example.test/';
    }

    function osc_admin_base_url($index = false)
    {
        return 'https://example.test/oc-admin/index.php';
    }

    function osc_add_admin_submenu_page($menu, $title, $url, $id, $capability = 'administrator')
    {
    }

    function osc_validate_email($email, $required = true)
    {
        return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    function osc_validate_url($value, $required = false, $headers = false)
    {
        return (bool)filter_var($value, FILTER_VALIDATE_URL);
    }

    require_once __DIR__ . '/lib/harness.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
    require_once __DIR__ . '/lib/stubs.php';
    require_once ABS_PATH . 'oc-includes/osclass/classes/settings/SettingsPageRegistry.php';
    require_once ABS_PATH . 'oc-includes/osclass/classes/settings/SettingsImage.php';
    require_once ABS_PATH . 'oc-includes/osclass/classes/admin/form/store/Store.php';
    require_once ABS_PATH . 'oc-includes/osclass/classes/admin/form/store/StoreException.php';
    require_once ABS_PATH . 'oc-includes/osclass/classes/admin/form/store/PreferenceStore.php';
    require_once ABS_PATH . 'oc-includes/osclass/classes/admin/form/store/TableStore.php';
    require_once ABS_PATH . 'oc-includes/osclass/classes/admin/form/store/StoreFactory.php';
    require_once ABS_PATH . 'oc-includes/osclass/classes/admin/ui/FormSpec.php';
    require_once ABS_PATH . 'oc-includes/osclass/classes/admin/ui/Field.php';
    require_once ABS_PATH . 'oc-includes/osclass/classes/admin/ui/Form.php';
    require_once ABS_PATH . 'oc-includes/osclass/classes/admin/ui/SettingsForm.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hResources.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

    /** Register a page, returning the exception message when the spec is refused. */
    function image_register(string $id, array $spec): ?string
    {
        try {
            SettingsPageRegistry::instance()->register($id, $spec);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** A resource row seeded straight into the stub table. */
    function image_seed(int $id, string $ownerType = 'setting'): void
    {
        $GLOBALS['resources'][$id] = array(
            'pk_i_id'      => $id,
            's_owner_type' => $ownerType,
            'i_owner_id'   => 0,
            's_path'       => 'oc-content/uploads/' . $ownerType . '/0/',
            's_extension'  => 'png',
            's_storage'    => 'local',
        );
    }

    /** One posted file, as PHP puts it in $_FILES. */
    function image_file(string $tmp, int $sizeKb = 10, int $error = UPLOAD_ERR_OK): array
    {
        return array('name' => 'logo.png', 'tmp_name' => $tmp, 'size' => $sizeKb * 1024, 'error' => $error);
    }

    /**
     * Put a submission and a stored preference in place, then save.
     *
     * @param array<string,mixed> $preferences section/key => stored value
     */
    function image_save(string $pageId, array $params, array $files, array $preferences = array()): array
    {
        $GLOBALS['params']      = $params;
        $GLOBALS['files']       = $files;
        $GLOBALS['preferences'] = $preferences;
        $GLOBALS['events']      = array();
        $GLOBALS['hooks']       = array();

        return osc_settings_save($pageId);
    }

    /** The events of one kind, in order. */
    function image_events(string $kind): array
    {
        return array_values(array_filter($GLOBALS['events'], static fn ($e) => $e[0] === $kind));
    }

    /** The values the named hook was last handed. */
    function image_hook_values(string $hook): ?array
    {
        $found = null;
        foreach ($GLOBALS['hooks'] as [$name, $args]) {
            if ($name === $hook) {
                $found = $hook === 'admin_form_save_failed' ? $args[2] : $args[1];
            }
        }

        return $found;
    }

    function image_render(string $pageId, array $values): string
    {
        ob_start();
        SettingsForm::render(osc_settings_page($pageId), $values);

        return (string)ob_get_clean();
    }

    (new FormSpec('brand'))
        ->title('Brand')
        ->checkbox('show_logo', 'Show a logo')
        ->image('logo', 'Logo', 'Shown in the header.')->dependsOn('show_logo')
        ->image('icon', 'Icon')->required()
        ->text('tagline', 'Tagline')
        ->number('width', 'Width')->set('min', 1)
        ->register();

    if ($front) {
        harness_section('front end: the save pipeline cannot upload or delete');
        image_seed(5);
        image_seed(6);
        $stored = array('brand/show_logo' => '1', 'brand/logo' => '5', 'brand/icon' => '6');
        $result = image_save(
            'brand',
            array('show_logo' => '1', 'logo_remove' => '1', 'width' => '3'),
            array('icon' => image_file('/tmp/front.png')),
            $stored
        );
        pin('the save reports no error', array(), $result['errors']);
        pin('nothing is uploaded', array(), image_events('upload'));
        pin('nothing is deleted', array(), image_events('delete'));
        pin('the stored logo is untouched', '5', $GLOBALS['preferences']['brand/logo']);
        pin('the stored icon is untouched', '6', $GLOBALS['preferences']['brand/icon']);
        pin('both resources are still there', array(5, 6), array_keys($GLOBALS['resources']));

        harness_section('front end: reading still works');
        $GLOBALS['preferences']['folio-unregistered/icon'] = '6';
        pin('osc_settings_image_url() answers', 'https://example.test/oc-content/uploads/setting/0/5.png', osc_settings_image_url('brand', 'logo'));
        pin('an unregistered page reads its own section', 'https://example.test/oc-content/uploads/setting/0/6_thumbnail.png', osc_settings_image_url('folio-unregistered', 'icon', 'thumbnail'));

        exit(harness_result());
    }

    harness_section('registry and builder');
    check('image is a field type', in_array('image', SettingsPageRegistry::FIELD_TYPES, true));
    pin(
        'the builder writes the array a hand would',
        array('groups' => array(array('fields' => array(array('type' => 'image', 'name' => 'logo', 'label' => 'Logo', 'help' => 'Hint'))))),
        osc_admin_form('t')->image('logo', 'Logo', 'Hint')->toArray()
    );
    check('the brand page registered', osc_settings_page('brand') !== null);
    check('required, depends, column and max_kb are accepted', image_register('ok1', array('title' => 'X', 'fields' => array(
        array('type' => 'checkbox', 'name' => 'on'),
        array('type' => 'image', 'name' => 'a', 'required' => true, 'depends' => 'on', 'column' => 'a_key', 'max_kb' => 50, 'help' => 'H'),
    ))) === null);
    foreach (array('default' => 'x', 'sanitize' => 'trim', 'validate' => 'trim', 'persist' => false, 'write_only' => true) as $key => $value) {
        pin(
            'an image refuses ' . $key,
            'SettingsPageRegistry: page "no-' . $key . '" field "a" is an image and cannot set ' . $key,
            image_register('no-' . $key, array('title' => 'X', 'fields' => array(array('type' => 'image', 'name' => 'a', $key => $value))))
        );
    }
    foreach (array('zero' => 0, 'a string' => '50', 'negative' => -1) as $label => $value) {
        pin(
            'max_kb of ' . $label . ' is refused',
            'SettingsPageRegistry: page "kb-' . $value . '" field "a" max_kb must be a positive integer',
            image_register('kb-' . $value, array('title' => 'X', 'fields' => array(array('type' => 'image', 'name' => 'a', 'max_kb' => $value))))
        );
    }
    pin(
        'max_kb on a field that takes no file is refused',
        'SettingsPageRegistry: page "kb-text" field "a" cannot set max_kb: only an image field takes a file',
        image_register('kb-text', array('title' => 'X', 'fields' => array(array('type' => 'text', 'name' => 'a', 'max_kb' => 5))))
    );
    pin(
        'a table store is refused',
        'SettingsPageRegistry: page "tbl" field "a" is an image, which only a preference page stores',
        image_register('tbl', array('title' => 'X', 'store' => array('table' => 't_x', 'pk' => 'pk_i_id'), 'fields' => array(array('type' => 'image', 'name' => 'a'))))
    );
    pin(
        'an image cannot be a depends master',
        'SettingsPageRegistry: page "master" field "b" depends on image field "a", which is not a switch',
        image_register('master', array('title' => 'X', 'fields' => array(
            array('type' => 'image', 'name' => 'a'),
            array('type' => 'text', 'name' => 'b', 'depends' => 'a'),
        )))
    );
    pin(
        'a field named like the remove box is refused',
        'SettingsPageRegistry: page "clash" field "a_remove" clashes with the remove box of image field "a"',
        image_register('clash', array('title' => 'X', 'fields' => array(
            array('type' => 'image', 'name' => 'a'),
            array('type' => 'checkbox', 'name' => 'a_remove'),
        )))
    );

    harness_section('render');
    image_seed(7);
    $empty = image_render('brand', array('show_logo' => true, 'logo' => '', 'icon' => '', 'tagline' => '', 'width' => ''));
    check('a page with an image field posts multipart without asking', strpos($empty, 'enctype="multipart/form-data"') !== false, $empty);
    check('the file input takes images', strpos($empty, '<input type="file" id="field-logo" name="logo" class="" accept="image/*" />') !== false, $empty);
    check('an empty required image asks the browser for one', strpos($empty, 'name="icon" class="" accept="image/*" required />') !== false, $empty);
    check('nothing stored draws no preview', strpos($empty, '<img') === false, $empty);
    check('nothing stored draws no remove box', strpos($empty, '_remove') === false, $empty);

    $stored = image_render('brand', array('show_logo' => true, 'logo' => 7, 'icon' => 999, 'tagline' => '', 'width' => ''));
    check(
        'a stored image draws its thumbnail',
        strpos($stored, '<img class="field-image-preview" src="https://example.test/oc-content/uploads/setting/0/7_thumbnail.png" alt="Logo" />') !== false,
        $stored
    );
    check('and a remove box under the field\'s own name', strpos($stored, 'name="logo_remove" value="1"') !== false, $stored);
    check('a stored required image no longer asks the browser for a file', strpos($stored, 'name="icon" class="" accept="image/*" required') === false, $stored);
    check('an id with no resource behind it draws no preview', substr_count($stored, '<img') === 1, $stored);
    check('but can still be cleared', strpos($stored, 'name="icon_remove"') !== false, $stored);

    image_register('plain', array('title' => 'Plain', 'fields' => array(array('type' => 'text', 'name' => 'a'))));
    check('a page with no image field stays urlencoded', strpos(image_render('plain', array('a' => '')), 'enctype') === false);

    harness_section('save: a first upload');
    $base   = array('brand/show_logo' => '1', 'brand/icon' => '7');
    $result = image_save('brand', array('show_logo' => '1', 'width' => '2'), array('logo' => image_file('/tmp/a.png')), $base);
    pin('no error', array(), $result['errors']);
    pin('the file goes through the uploader as a settings image', array(array('upload', 'setting', 0, '/tmp/a.png')), image_events('upload'));
    $newId = $GLOBALS['nextId'];
    pin('the new id is stored', (string)$newId, $GLOBALS['preferences']['brand/logo'] ?? null);
    pin('and counted as a change', true, $result['updated'] >= 1);
    pin('nothing is deleted', array(), image_events('delete'));
    pin('the after_save hook is handed the resource id', $newId, image_hook_values('admin_form_after_save')['logo'] ?? null);
    pin('a logo is fitted, never padded onto the listing photo canvas', true, $GLOBALS['uploadOptions']['fit'] ?? null);
    pin('at its own sizes, not the listing photo sizes', '1600x1600', $GLOBALS['uploadOptions']['variants']['normal'] ?? null);

    harness_section('save: replacing a stored image');
    image_seed(8);
    $result = image_save('brand', array('show_logo' => '1', 'width' => '2'), array('logo' => image_file('/tmp/b.png')), $base + array('brand/logo' => '8'));
    $newId  = $GLOBALS['nextId'];
    pin('no error', array(), $result['errors']);
    pin('the new id replaces the old one', (string)$newId, $GLOBALS['preferences']['brand/logo']);
    pin('the old image is deleted', array(array('delete', 8)), image_events('delete'));
    $order = array_map(static fn ($e) => $e[0] . ($e[1] === 'logo' ? ':logo' : ''), $GLOBALS['events']);
    check(
        'and only after the new id was written',
        array_search('pref:logo', $order, true) !== false && array_search('pref:logo', $order, true) < array_search('delete', $order, true),
        implode(', ', $order)
    );

    harness_section('save: nothing posted');
    image_seed(9);
    $result = image_save('brand', array('show_logo' => '1', 'width' => '2'), array(), $base + array('brand/logo' => '9', 'brand/width' => '2', 'brand/tagline' => ''));
    pin('no error', array(), $result['errors']);
    pin('the stored id is kept', '9', $GLOBALS['preferences']['brand/logo']);
    pin('which is not a change', 0, $result['updated']);
    pin('no uploader call at all', array(), array_merge(image_events('upload'), image_events('delete')));
    $result = image_save('brand', array('show_logo' => '1', 'width' => '2'), array('logo' => image_file('', 0, UPLOAD_ERR_NO_FILE)), $base + array('brand/logo' => '9'));
    pin('an empty file input is the same as none', array('9', array()), array($GLOBALS['preferences']['brand/logo'], image_events('upload')));

    harness_section('save: remove');
    $result = image_save('brand', array('show_logo' => '1', 'width' => '2', 'logo_remove' => '1'), array(), $base + array('brand/logo' => '9'));
    pin('no error', array(), $result['errors']);
    pin('the stored image is deleted', array(array('delete', 9)), image_events('delete'));
    pin('and the field stored empty', '', $GLOBALS['preferences']['brand/logo']);
    image_seed(10);
    image_save('brand', array('show_logo' => '1', 'width' => '2', 'logo_remove' => '1'), array('logo' => image_file('/tmp/c.png')), $base + array('brand/logo' => '10'));
    pin('a new file and the remove box together replace rather than clear', (string)$GLOBALS['nextId'], $GLOBALS['preferences']['brand/logo']);
    image_seed(11, 'user');
    image_save('brand', array('show_logo' => '1', 'width' => '2', 'logo_remove' => '1'), array(), $base + array('brand/logo' => '11'));
    pin('an id naming somebody else\'s resource is never deleted', array(), image_events('delete'));
    check('and the avatar is still there', isset($GLOBALS['resources'][11]));

    harness_section('save: a refused file');
    image_seed(12);
    $withLogo = $base + array('brand/logo' => '12');
    $refusals = array(
        'not an image'           => array(image_file('/tmp/not-an-image.png'), 'Logo is not a valid image'),
        'over the site limit'    => array(image_file('/tmp/big.png', 101), 'Logo must be 100 KB or smaller'),
        'over PHP\'s limit'      => array(image_file('/tmp/big.png', 1, UPLOAD_ERR_INI_SIZE), 'Logo must be 100 KB or smaller'),
        'not an uploaded file'   => array(image_file('/tmp/not-uploaded.png'), 'Logo could not be uploaded'),
        'a partial upload'       => array(image_file('/tmp/p.png', 1, UPLOAD_ERR_PARTIAL), 'Logo could not be uploaded'),
        'refused by the uploader' => array(image_file('/tmp/uploader-fails.png'), 'Logo is not a valid image'),
    );
    foreach ($refusals as $label => [$file, $message]) {
        $result = image_save('brand', array('show_logo' => '1', 'width' => '2'), array('logo' => $file), $withLogo);
        pin($label . ': the save is refused with a field error', array($message), $result['errors']);
        pin($label . ': nothing is written', array(), image_events('pref'));
        pin($label . ': nothing is deleted', array(), image_events('delete'));
        pin($label . ': the page redraws the stored image', 12, $result['values']['logo']);
    }
    pin('only the uploader refusal reached the uploader', array(array('upload', 'setting', 0, '/tmp/uploader-fails.png')), image_events('upload'));

    $result = image_save('brand', array('show_logo' => '1', 'width' => '0'), array('logo' => image_file('/tmp/d.png')), $withLogo);
    pin('another field\'s error means no upload at all', array(array('Width must be 1 or more'), array()), array($result['errors'], image_events('upload')));

    harness_section('save: max_kb');
    image_register('sized', array('title' => 'Sized', 'fields' => array(
        array('type' => 'image', 'name' => 'big', 'label' => 'Big', 'max_kb' => 500),
        array('type' => 'image', 'name' => 'small', 'label' => 'Small', 'max_kb' => 50),
    )));
    $result = image_save('sized', array(), array('big' => image_file('/tmp/e.png', 200)));
    pin('a field\'s max_kb lifts the site limit', array(array(), 1), array($result['errors'], count(image_events('upload'))));
    $result = image_save('sized', array(), array('small' => image_file('/tmp/f.png', 60)));
    pin('and lowers it', array('Small must be 50 KB or smaller'), $result['errors']);
    $result = image_save('sized', array(), array('big' => image_file('/tmp/n.png'), 'small' => image_file('/tmp/uploader-fails.png')));
    pin('one upload failing refuses the save', array('Small is not a valid image'), $result['errors']);
    pin('and deletes the other one it already stored', array(array('delete', $GLOBALS['nextId'])), image_events('delete'));
    pin('so nothing is written', array(), image_events('pref'));

    harness_section('save: required');
    $noIcon = array('brand/show_logo' => '1');
    $result = image_save('brand', array('width' => '2'), array(), $noIcon);
    pin('an empty required image with no upload is an error', array('Icon cannot be left empty'), $result['errors']);
    $result = image_save('brand', array('width' => '2'), array('icon' => image_file('/tmp/g.png')), $noIcon);
    pin('an upload satisfies it', array(), $result['errors']);
    image_seed(13);
    $result = image_save('brand', array('width' => '2'), array(), array('brand/icon' => '13'));
    pin('so does an image already stored', array(), $result['errors']);
    $result = image_save('brand', array('width' => '2', 'icon_remove' => '1'), array(), array('brand/icon' => '13'));
    pin('removing a required image is refused', array('Icon cannot be left empty'), $result['errors']);
    pin('and deletes nothing', array(array(), '13'), array(image_events('delete'), $GLOBALS['preferences']['brand/icon']));
    pin('the failed hook sees the stored id, not the cleared one', 13, image_hook_values('admin_form_save_failed')['icon'] ?? null);
    $result = image_save('brand', array('width' => '2'), array('icon' => image_file('/tmp/not-an-image.png')), $noIcon);
    pin('a refused file on an empty required image reports once', array('Icon is not a valid image'), $result['errors']);

    harness_section('save: depends');
    image_seed(14);
    $result = image_save('brand', array('width' => '2', 'logo_remove' => '1'), array('logo' => image_file('/tmp/h.png')), array('brand/icon' => '7', 'brand/logo' => '14'));
    pin('master off: no error', array(), $result['errors']);
    pin('master off: the posted file is ignored', array(), image_events('upload'));
    pin('master off: the remove box is ignored', array(), image_events('delete'));
    pin('master off: the stored image is untouched', '14', $GLOBALS['preferences']['brand/logo']);
    $result = image_save('brand', array('width' => '2'), array('logo' => image_file('/tmp/not-an-image.png')), array('brand/icon' => '7'));
    pin('master off: a bad file is not even checked', array(), $result['errors']);

    harness_section('save: the write does not land');
    image_seed(15);
    $GLOBALS['prefFails'] = true;
    $result = image_save('brand', array('show_logo' => '1', 'width' => '2'), array('logo' => image_file('/tmp/i.png')), $base + array('brand/logo' => '15'));
    $GLOBALS['prefFails'] = false;
    $newId = $GLOBALS['nextId'];
    pin('the new upload is deleted and the old one kept', array(array('delete', $newId)), image_events('delete'));
    check('the old resource is still there', isset($GLOBALS['resources'][15]));

    image_seed(16);
    $GLOBALS['beforeSave'] = static function (array $values) {
        $GLOBALS['beforeSaw'] = $values['logo'] ?? null;
        unset($values['logo']);

        return $values;
    };
    image_save('brand', array('show_logo' => '1', 'width' => '2'), array('logo' => image_file('/tmp/j.png')), $base + array('brand/logo' => '16'));
    $GLOBALS['beforeSave'] = null;
    $newId = $GLOBALS['nextId'];
    pin('before_save is handed the new resource id', $newId, $GLOBALS['beforeSaw']);
    pin('a listener dropping it leaves the old image stored', '16', $GLOBALS['preferences']['brand/logo']);
    pin('and the orphaned upload is deleted', array(array('delete', $newId)), image_events('delete'));

    harness_section('save: only an admin with the page\'s capability');
    image_seed(17);
    $GLOBALS['adminLogged'] = false;
    $result = image_save('brand', array('show_logo' => '1', 'width' => '2', 'logo_remove' => '1'), array('icon' => image_file('/tmp/k.png')), $base + array('brand/logo' => '17'));
    $GLOBALS['adminLogged'] = true;
    pin('signed out: no upload and no delete', array(), array_merge(image_events('upload'), image_events('delete')));
    pin('signed out: the stored ids are untouched', array('17', '7'), array($GLOBALS['preferences']['brand/logo'], $GLOBALS['preferences']['brand/icon']));

    $GLOBALS['moderator'] = true;
    image_save('brand', array('show_logo' => '1', 'width' => '2', 'logo_remove' => '1'), array('icon' => image_file('/tmp/l.png')), $base + array('brand/logo' => '17'));
    pin('a moderator on an administrator page: no upload and no delete', array(), array_merge(image_events('upload'), image_events('delete')));
    image_register('mod', array('title' => 'Mod', 'capability' => 'moderator', 'fields' => array(array('type' => 'image', 'name' => 'pic'))));
    image_save('mod', array(), array('pic' => image_file('/tmp/m.png')));
    pin('a moderator on a moderator page may upload', 1, count(image_events('upload')));
    $GLOBALS['moderator'] = false;

    harness_section('osc_settings_image_url()');
    image_seed(18);
    $GLOBALS['preferences'] = array('brand/logo' => '18', 'brand/tagline' => '18', 'folio/logo' => '18', 'brand/icon' => '999');
    pin('the main image', 'https://example.test/oc-content/uploads/setting/0/18.png', osc_settings_image_url('brand', 'logo'));
    pin('the thumbnail', 'https://example.test/oc-content/uploads/setting/0/18_thumbnail.png', osc_settings_image_url('brand', 'logo', 'thumbnail'));
    pin('the preview', 'https://example.test/oc-content/uploads/setting/0/18_preview.png', osc_settings_image_url('brand', 'logo', 'preview'));
    pin('a variant core does not write is empty', '', osc_settings_image_url('brand', 'logo', 'original'));
    pin('an id with no resource is empty', '', osc_settings_image_url('brand', 'icon'));
    pin('a field that is not an image is empty', '', osc_settings_image_url('brand', 'tagline'));
    pin('a name the registered page does not declare is empty', '', osc_settings_image_url('brand', 'nope'));
    pin('an unregistered page reads the preference in its own section', 'https://example.test/oc-content/uploads/setting/0/18.png', osc_settings_image_url('folio', 'logo'));
    $GLOBALS['preferences']['brand/logo'] = '';
    pin('nothing stored is empty', '', osc_settings_image_url('brand', 'logo'));
    image_seed(19, 'item');
    $GLOBALS['preferences']['folio/logo'] = '19';
    pin('an id naming a listing photo is empty', '', osc_settings_image_url('folio', 'logo'));

    harness_section('front end (child process)');
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
    $out = array();
    $code = 0;
    exec('SETTINGS_IMAGE_FRONT=1 ' . $cmd . ' 2>&1', $out, $code);
    $childOk = 0;
    foreach ($out as $line) {
        if (preg_match('/^RESULT: (\d+) passed, 0 failed$/', $line, $m)) {
            $childOk = (int)$m[1];
        }
    }
    check('the front-end run passes', $code === 0 && $childOk === 8, implode("\n", $out));

    exit(harness_result());
}
