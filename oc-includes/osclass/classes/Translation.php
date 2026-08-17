<?php

use Gettext\Translator;

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Class Translation
 */
class Translation
{
    private static $instance;
    private $translator;

    /**
     * Translation constructor.
     *
     * @param bool $install
     */
    public function __construct($install = false)
    {
        $this->translator = new Translator();
        if (!$install) {
            // get user/admin locale
            if (defined('OC_ADMIN') && OC_ADMIN) {
                $locale = osc_current_admin_locale();
            } else {
                $locale = osc_current_user_locale();
            }

            // load core
            $core_file = osc_apply_filter('mo_core_path', osc_translations_path() . $locale . '/core.mo', $locale);
            $this->_load($core_file, 'core');

            // load messages
            $domain        = osc_apply_filter('theme', osc_theme());
            $messages_file = osc_apply_filter(
                'mo_theme_messages_path',
                osc_themes_path() . $domain . '/languages/' . $locale . '/messages.mo',
                $locale,
                $domain
            );

            if (!file_exists($messages_file)) {
                $messages_file =
                    osc_apply_filter(
                        'mo_core_messages_path',
                        osc_translations_path() . $locale . '/messages.mo',
                        $locale
                    );
            }
            $this->_load($messages_file, 'messages');

            // load theme
            $theme_file =
                osc_apply_filter(
                    'mo_theme_path',
                    osc_themes_path() . $domain . '/languages/' . $locale . '/theme.mo',
                    $locale,
                    $domain
                );
            if (!file_exists($theme_file)) {
                if (!file_exists(osc_themes_path() . $domain)) {
                    $domain = osc_theme();
                }
                $theme_file = osc_translations_path() . $locale . '/theme.mo';
            }
            $this->_load($theme_file, $domain);

            // load plugins
            $aPlugins = Plugins::listEnabled();
            foreach ($aPlugins as $plugin) {
                $domain      = preg_replace('|/.*|', '', $plugin);
                $plugin_file = osc_apply_filter(
                    'mo_plugin_path',
                    osc_plugins_path() . $domain . '/languages/' . $locale . '/messages.mo',
                    $locale,
                    $domain
                );
                if (file_exists($plugin_file)) {
                    $this->_load($plugin_file, $domain);
                }
            }
        } else {
            $core_file = osc_translations_path() . osc_current_admin_locale() . '/core.mo';
            $this->_load($core_file, 'core');
        }
    }

    /**
     * @param $file
     * @param $domain
     *
     * @return bool|\Translation
     */
    public function _load($file, $domain)
    {
        if (!file_exists($file)) {
            return false;
        }

        // Every catalogue this install uses -- core, theme, and one per enabled plugin --
        // was parsed out of its binary .mo on every single request, building one object
        // per translated string before any page logic ran. Compiling each one to a plain
        // array the first time and reading that back instead costs a fraction of the
        // same work, and leaves none of those objects resident. Anything that stops the
        // cache being read or written -- a read-only deploy, no uploads directory yet, a
        // truncated entry -- falls straight back to parsing, so the only thing ever at
        // stake here is the speed-up.
        $cache = $this->cachePath($file, $domain);

        if ($cache !== null && is_file($cache)) {
            $cached = @file_get_contents($cache);
            if ($cached !== false && $cached !== '') {
                $translations = @unserialize($cached, array('allowed_classes' => false));
                if (is_array($translations)) {
                    $this->translator->loadTranslations($translations);

                    return $this;
                }
            }
        }

        //Create a Translations instance using a po file
        $translations = Gettext\Translations::fromMoFile($file);
        $translations->setDomain($domain);

        if ($cache !== null) {
            $this->writeCache($cache, $translations);
        }

        $this->translator->loadTranslations($translations);

        return $this;
    }

    /**
     * Where the compiled form of one .mo file lives, or null when it cannot be cached.
     *
     * The name carries the catalogue's size and modification time, so a replaced
     * language pack simply misses and recompiles rather than needing anything to
     * invalidate it. Stale entries are inert: nothing reads them again.
     *
     * @param string $file
     * @param string $domain
     *
     * @return string|null
     */
    private function cachePath($file, $domain)
    {
        if (!function_exists('osc_uploads_path')) {
            return null;
        }

        $uploads = osc_uploads_path();
        if ($uploads === '') {
            return null;
        }

        $dir = rtrim($uploads, '/\\') . DIRECTORY_SEPARATOR . 'translations-cache';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        $stamp = md5($file . '|' . $domain . '|' . filesize($file) . '|' . filemtime($file));

        // Deliberately not a .php file. This directory is inside the web root and is
        // writable, and executable content is exactly what turns a stray file-write
        // into something worse. Serialised data is also the quicker of the two to
        // read back, so nothing is traded away for it.
        return $dir . DIRECTORY_SEPARATOR . $stamp . '.cache';
    }

    /**
     * Compile a catalogue to the cache, via a temporary file renamed into place so a
     * concurrent request never reads a half-written one.
     *
     * @param string                $cache
     * @param Gettext\Translations  $translations
     *
     * @return void
     */
    private function writeCache($cache, $translations)
    {
        $payload = serialize(Gettext\Generators\PhpArray::generate($translations));

        $tmp = $cache . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return;
        }

        if (!@rename($tmp, $cache)) {
            @unlink($tmp);
        }
    }

    /**
     * @param bool $install
     *
     * @return \Translation
     */
    public static function newInstance($install = false)
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self($install);
        }

        return self::$instance;
    }

    /**
     * @return \Translation
     */
    public static function init()
    {
        self::$instance = new self();

        return self::$instance;
    }

    /**
     * @return \Gettext\Translator
     */
    public function _get()
    {
        return $this->translator;
    }
}
