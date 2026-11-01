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
 * Pins the shared avatar upload handler used by the profile page and the admin
 * user editor.
 *
 * remove_avatar deletes even with the feature off; the feature switch gates
 * uploads; an oversize file flashes in the caller's scope and stores nothing; a
 * valid file replaces the old avatar. Both controllers must delegate here.
 *
 * DB-free. The uploader, image check and helpers are stubbed. Usage:  php tests/avatar-upload.php
 */

namespace mindstellar\storage {

    /** Records calls instead of touching storage. */
    final class ResourceUploader
    {
        public static array $calls = array();

        public function deleteByOwner(string $ownerType, int $ownerId): void
        {
            self::$calls[] = array('delete', $ownerType, $ownerId);
        }

        public static bool $fail = false;

        public function upload(string $ownerType, int $ownerId, string $tmpFile, array $options = array()): array|false
        {
            self::$calls[] = array('upload', $ownerType, $ownerId, $tmpFile, $options);

            return array();
        }

        public function replaceOwned(string $ownerType, int $ownerId, string $tmpFile, array $options = array()): array|false
        {
            self::$calls[] = array('replace', $ownerType, $ownerId, $tmpFile, $options);

            return self::$fail ? false : array();
        }
    }

    // CLI has no real uploads; the handler calls this unqualified, so the namespaced stub wins.
    function is_uploaded_file($file): bool
    {
        return $file !== '';
    }
}

namespace mindstellar\image {

    /** The image check: a path naming not-an-image does not open. */
    final class Processor
    {
        public static function site(): self
        {
            return new self();
        }

        public function opens(string $path): bool
        {
            return strpos($path, 'not-an-image') === false;
        }
    }
}

namespace {

    if (!defined('ABS_PATH')) {
        define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
    }

    $GLOBALS['prefs']   = array();
    $GLOBALS['flashes'] = array();

    function osc_get_preference($key, $section = 'osclass')
    {
        return $GLOBALS['prefs'][$key] ?? '';
    }

    function osc_uploads_path()
    {
        $dir = sys_get_temp_dir() . '/avatar-test-' . getmypid() . '/';
        is_dir($dir . 'temp') || mkdir($dir . 'temp', 0777, true);

        return $dir;
    }

    function osc_max_size_kb()
    {
        return 100;
    }

    function osc_add_flash_error_message($msg, $section = 'pubMessages')
    {
        $GLOBALS['flashes'][] = array($section, $msg);
    }

    function _m($s)
    {
        return $s;
    }

    require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
    require_once __DIR__ . '/../oc-includes/osclass/classes/Params.php';
    require_once __DIR__ . '/lib/harness.php';

    use mindstellar\storage\AvatarUpload;
    use mindstellar\storage\ResourceUploader;

    $GLOBALS['okCount']    = 0;
    $GLOBALS['failCount']  = 0;
    $GLOBALS['failLabels'] = array();

    /**
     * Reset request, preferences and recorders for one scenario.
     */
    function avatar_scenario(array $post, array $files, bool $enabled): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET    = array();
        $_POST   = $post;
        $_FILES  = $files;
        Params::init();
        $GLOBALS['prefs']   = array('enabled_user_avatars' => $enabled ? '1' : '', 'avatar_dimensions' => '');
        $GLOBALS['flashes'] = array();
        ResourceUploader::$calls = array();
    }

    function avatar_file(string $tmp, int $size): array
    {
        return array('avatar' => array(
            'name'     => 'me.jpg',
            'tmp_name' => $tmp,
            'size'     => $size,
            'error'    => UPLOAD_ERR_OK,
        ));
    }

    harness_section('remove_avatar');
    avatar_scenario(array('remove_avatar' => '1'), array(), false);
    AvatarUpload::handle(7, 'admin');
    pin('deletes with the feature off', array(array('delete', 'user', 7)), ResourceUploader::$calls);

    avatar_scenario(array('remove_avatar' => '1'), avatar_file('/tmp/ok.jpg', 10), true);
    AvatarUpload::handle(7);
    pin('removal skips the upload', array(array('delete', 'user', 7)), ResourceUploader::$calls);

    avatar_scenario(array('remove_avatar' => '1'), array(), true);
    AvatarUpload::handle(0);
    pin('no user id does nothing', array(), ResourceUploader::$calls);

    harness_section('feature switch');
    avatar_scenario(array(), avatar_file('/tmp/ok.jpg', 10), false);
    AvatarUpload::handle(7);
    pin('disabled feature stores nothing', array(), ResourceUploader::$calls);
    pin('disabled feature flashes nothing', array(), $GLOBALS['flashes']);

    harness_section('rejected files');
    $tooBig = 100 * 1024 + 1;
    avatar_scenario(array(), avatar_file('/tmp/ok.jpg', $tooBig), true);
    AvatarUpload::handle(7, 'admin');
    pin('oversize stores nothing', array(), ResourceUploader::$calls);
    pin(
        'oversize flashes in the admin scope',
        array(array('admin', 'The avatar you tried to upload exceeds the maximum size')),
        $GLOBALS['flashes']
    );

    avatar_scenario(array(), avatar_file('/tmp/ok.jpg', $tooBig), true);
    AvatarUpload::handle(7);
    pin(
        'oversize flashes in the public scope by default',
        array(array('pubMessages', 'The avatar you tried to upload exceeds the maximum size')),
        $GLOBALS['flashes']
    );

    avatar_scenario(array(), avatar_file('/tmp/not-an-image.jpg', 10), true);
    AvatarUpload::handle(7, 'admin');
    pin('invalid image stores nothing', array(), ResourceUploader::$calls);
    pin(
        'invalid image flashes in the passed scope',
        array(array('admin', 'The avatar you tried to upload is not a valid image')),
        $GLOBALS['flashes']
    );

    avatar_scenario(array(), array('avatar' => array('tmp_name' => '/tmp/ok.jpg', 'size' => 10, 'error' => UPLOAD_ERR_NO_FILE)), true);
    AvatarUpload::handle(7);
    pin('upload error stores nothing', array(), ResourceUploader::$calls);

    harness_section('valid upload');
    avatar_scenario(array(), avatar_file('/tmp/ok.jpg', 10), true);
    AvatarUpload::handle(7, 'admin');
    pin(
        'replaces the old avatar and uploads with default variants',
        array(
            array('replace', 'user', 7, '/tmp/ok.jpg', array('variants' => array('normal' => '200x200', 'thumbnail' => '64x64'))),
        ),
        ResourceUploader::$calls
    );
    pin('valid upload flashes nothing', array(), $GLOBALS['flashes']);

    harness_section('store() from a file on disk');
    $ok = tempnam(sys_get_temp_dir(), 'av');
    $bad = sys_get_temp_dir() . '/not-an-image-' . getmypid();
    file_put_contents($bad, 'x');

    avatar_scenario(array(), array(), true);
    pin('stores and returns true', true, AvatarUpload::store(7, $ok));
    pin(
        'replaces the old avatar from a copy in the temp folder, same sizes as an upload',
        array(array('replace', 'user', 7, true, array('variants' => array('normal' => '200x200', 'thumbnail' => '64x64')))),
        array_map(static fn (array $c): array => array($c[0], $c[1], $c[2], str_starts_with($c[3], osc_uploads_path() . 'temp/avatar_'), $c[4]), ResourceUploader::$calls)
    );
    check('the file it was given is left alone', is_file($ok));
    check('and the copy is gone', !is_file(ResourceUploader::$calls[0][3]));

    avatar_scenario(array(), array(), true);
    ResourceUploader::$fail = true;
    pin('a failed store returns false', false, AvatarUpload::store(7, $ok));
    ResourceUploader::$fail = false;

    avatar_scenario(array(), array(), false);
    pin('feature off returns false', false, AvatarUpload::store(7, $ok));
    pin('feature off stores nothing', array(), ResourceUploader::$calls);

    avatar_scenario(array(), array(), true);
    pin('not an image returns false', false, AvatarUpload::store(7, $bad));
    pin('not an image keeps the old avatar', array(), ResourceUploader::$calls);
    pin('missing file returns false', false, AvatarUpload::store(7, $ok . '-gone'));
    pin('no user returns false', false, AvatarUpload::store(0, $ok));
    unlink($ok);
    unlink($bad);

    harness_section('controllers delegate');
    $web   = file_get_contents(__DIR__ . '/../oc-includes/osclass/classes/controller/CWebUser.php');
    $admin = file_get_contents(__DIR__ . '/../oc-includes/osclass/classes/controller/admin/CAdminUsers.php');
    check('CWebUser calls the shared handler', strpos($web, "AvatarUpload::handle((int)\$userId, 'pubMessages')") !== false);
    check('CAdminUsers calls the shared handler', strpos($admin, "AvatarUpload::handle((int)\$userId, 'admin')") !== false);
    check('CWebUser keeps no copy', strpos($web, 'is_uploaded_file') === false && strpos($web, 'deleteByOwner') === false);
    check('CAdminUsers keeps no copy', strpos($admin, 'is_uploaded_file') === false && strpos($admin, 'deleteByOwner') === false);

    exit(harness_result());
}
