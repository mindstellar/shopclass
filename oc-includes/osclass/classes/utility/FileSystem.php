<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\utility;

use Exception;
use FilesystemIterator;
use InvalidArgumentException;
use LengthException;
use Params;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use RuntimeException;
use Traversable;

/**
 * Class FileSystem
 *
 * @package mindstellar\utility
 */
class FileSystem
{
    private static $lastError;

    /**
     * Error handler installed around native filesystem calls, so their warning text can be
     * quoted in the exception this class throws instead.
     *
     * @param int    $type
     * @param string $msg
     *
     * @return void
     * @internal
     */
    // @phpstan-ignore method.unused (installed as an error handler by name)
    private static function handleError($type, $msg)
    {
        self::$lastError = $msg;
    }

    /**
     * Close a folder to the web: an empty index.php and an .htaccess that denies every
     * request on Apache 2.4 and 2.2. A file already there is left alone.
     *
     * @param string $dir
     *
     * @return void
     */
    public static function protectFolder(string $dir): void
    {
        $dir   = rtrim($dir, '/\\') . '/';
        $files = array(
            'index.php' => "<?php\n",
            '.htaccess' => "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n",
        );
        foreach ($files as $name => $content) {
            if (!is_file($dir . $name)) {
                @file_put_contents($dir . $name, $content);
            }
        }
    }

    /**
     * Sets access and modification time of file.
     *
     * @param string|iterable<string> $files A filename, an array of files, or a \Traversable instance to create
     *
     * @return void
     * @throws RuntimeException When touch fails
     */
    public function touch($files)
    {
        foreach ($this->toIterable($files) as $file) {
            $touch = @touch($file);
            if (true !== $touch) {
                throw new RuntimeException(sprintf('Unable to touch "%s".', $file));
            }
        }
    }

    /**
     * Return an array if isn't.
     *
     * @param string|iterable<string> $files
     *
     * @return iterable<string>
     */
    private function toIterable($files)
    {
        return is_array($files) || $files instanceof Traversable ? $files : [$files];
    }

    /**
     * chmod for an array of files or directories.
     *
     * @param string|iterable $files     A filename, an array of files, or a \Traversable instance to change mode
     * @param int             $mode      The new mode (octal)
     * @param int             $umask     The mode mask (octal)
     * @param bool            $recursive Whether change the mod recursively or not
     *
     * @return void
     * @throws RuntimeException When the change fails
     */
    public function chmod($files, $mode, $umask = 0000, $recursive = false)
    {
        foreach ($this->toIterable($files) as $file) {
            if (true !== @chmod($file, $mode & ~$umask)) {
                throw new RuntimeException(sprintf('Unable to chmod file "%s".', $file));
            }
            if ($recursive && is_dir($file) && !is_link($file)) {
                $this->chmod(new FilesystemIterator($file), $mode, $umask, true);
            }
        }
    }

    /**
     * chown of an array of files or directories.
     *
     * @param string|iterable $files     A filename, an array of files, or a \Traversable instance to change owner
     * @param string|int      $user      A user name or number
     * @param bool            $recursive Whether change the owner recursively or not
     *
     * @return void
     * @throws RuntimeException When the change fails
     */
    public function chown($files, $user, $recursive = false)
    {
        foreach ($this->toIterable($files) as $file) {
            if ($recursive && is_dir($file) && !is_link($file)) {
                $this->chown(new FilesystemIterator($file), $user, true);
            }
            if (function_exists('lchown') && is_link($file)) {
                if (true !== @lchown($file, $user)) {
                    throw new RuntimeException(sprintf('Unable to chown file "%s".', $file));
                }
            } elseif (true !== @chown($file, $user)) {
                throw new RuntimeException(sprintf('Unable to chown file "%s".', $file));
            }
        }
    }

    /**
     * Change the group of an array of files or directories.
     *
     * @param string|iterable $files     A filename, an array of files, or a \Traversable instance to change group
     * @param string|int      $group     A group name or number
     * @param bool            $recursive Whether change the group recursively or not
     *
     * @return void
     * @throws RuntimeException When the change fails
     */
    public function chgrp($files, $group, $recursive = false)
    {
        foreach ($this->toIterable($files) as $file) {
            if ($recursive && is_dir($file) && !is_link($file)) {
                $this->chgrp(new FilesystemIterator($file), $group, true);
            }
            if (function_exists('lchgrp') && is_link($file)) {
                if (true !== @lchgrp($file, $group)) {
                    throw new RuntimeException(sprintf('Unable to chgrp file "%s".', $file));
                }
            } elseif (true !== @chgrp($file, $group)) {
                throw new RuntimeException(sprintf('Unable to chgrp file "%s".', $file));
            }
        }
    }

    /**
     * Creates a symbolic link or copy a directory.
     *
     * @param string $originDir
     * @param string $targetDir
     * @param bool   $copyOnWindows copy the tree instead of linking it, on Windows
     *
     * @return void
     * @throws \Exception
     */
    public function symlink($originDir, $targetDir, $copyOnWindows = false)
    {
        if ('\\' === DIRECTORY_SEPARATOR) {
            $originDir = str_replace('/', '\\', $originDir);
            $targetDir = str_replace('/', '\\', $targetDir);

            if ($copyOnWindows) {
                $this->sync($originDir, $targetDir);

                return;
            }
        }

        $this->mkdir(dirname($targetDir));

        if (is_link($targetDir)) {
            if (readlink($targetDir) === $originDir) {
                return;
            }
            $this->remove($targetDir);
        }

        if (!self::callback('symlink', $originDir, $targetDir)) {
            $this->linkException($originDir, $targetDir, 'symbolic');
        }
    }

    /**
     * The entries under $originDir that sync() would copy: everything but the names in $filter.
     *
     * @param array<int,string> $filter
     * @param int<0,2>          $mode   a RecursiveIteratorIterator mode
     */
    public function filteredIterator(string $originDir, array $filter = [], int $mode = RecursiveIteratorIterator::SELF_FIRST, bool $followLinks = false): RecursiveIteratorIterator
    {
        $flags = $followLinks ? FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS : FilesystemIterator::SKIP_DOTS;

        return new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($originDir, $flags),
                static fn ($file) => !in_array($file->getBasename(), $filter, false)
            ),
            $mode
        );
    }

    /**
     * Sync a directory to another.
     *
     * Copies files and directories from the origin directory into the target directory. By default:
     *
     *  - existing files in the target directory will be overwritten, except if they are newer
     * (see the `override` option)
     *  - files in the target directory that do not exist in the source directory will not be deleted
     * (see the `delete` option)
     *
     * @param string            $originDir
     * @param string            $targetDir
     * @param array<string,bool> $options       An array of boolean options
     *                                          Valid options are:
     *                                          - $options['override'] If true, target files newer than origin files
     *                                          are
     *                                          overwritten (see copy(), defaults to false)
     *                                          - $options['copy_on_windows'] Whether to copy files instead of links on
     *                                          Windows (see symlink(), defaults to false)
     *                                          - $options['delete'] Whether to delete files that are not in the source
     *                                          directory (defaults to false)
     * @param array<int,string> $filter         Files/Directory name in array get filtered
     *
     * @return void
     * @throws \Exception
     */
    public function sync($originDir, $targetDir, $options = [], $filter = [])
    {
        $targetDir    = rtrim($targetDir, '/\\');
        $originDir    = rtrim($originDir, '/\\');
        $originDirLen = strlen($originDir);

        if (!$this->exists($originDir)) {
            throw new RuntimeException(sprintf('The origin directory specified "%s" was not found.', $originDir));
        }

        // Iterate in destination folder to remove obsolete entries
        if (isset($options['delete']) && $options['delete'] && $this->exists($targetDir)) {
            $deleteIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($targetDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            $targetDirLen = strlen($targetDir);
            foreach ($deleteIterator as $file) {
                $origin = $originDir . substr($file->getPathname(), $targetDirLen);
                if (!$this->exists($origin)) {
                    $this->remove($file);
                }
            }
        }

        $copyOnWindows = $options['copy_on_windows'] ?? false;

        $iterator = $this->filteredIterator($originDir, $filter, RecursiveIteratorIterator::SELF_FIRST, $copyOnWindows);

        $this->mkdir($targetDir);
        $filesCreatedWhileMirroring = [];

        /** @var FilesystemIterator $file */
        foreach ($iterator as $file) {
            if ($file->getPathname() === $targetDir || $file->getRealPath() === $targetDir
                || isset($filesCreatedWhileMirroring[$file->getRealPath()])
            ) {
                continue;
            }

            $target                              = $targetDir . substr($file->getPathname(), $originDirLen);
            $filesCreatedWhileMirroring[$target] = true;

            if (!$copyOnWindows && is_link($file)) {
                $this->symlink($file->getLinkTarget(), $target);
            } elseif (is_dir($file)) {
                $this->mkdir($target);
            } elseif (is_file($file)) {
                $this->copy($file, $target, $options['override'] ?? false);
            } else {
                throw new RuntimeException(sprintf('Unable to guess "%s" file type.', $file));
            }
        }
    }

    /**
     * Checks the existence of files or directories.
     *
     * @param string|iterable $files A filename, an array of files, or a \Traversable instance to check
     *
     * @return bool true if the file exists, false otherwise
     */
    public function exists($files)
    {
        $maxPathLength = PHP_MAXPATHLEN - 2;

        foreach ($this->toIterable($files) as $file) {
            if (strlen($file) > $maxPathLength) {
                throw new LengthException(sprintf(
                    'Could not check if file exist because path length exceeds %d characters.',
                    $maxPathLength
                ));
            }

            if (!file_exists($file)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Removes files or directories.
     *
     * @param string|iterable<string> $files A filename, an array of files, or a \Traversable instance to remove
     *
     * @return void
     * @throws \Exception
     */
    public function remove($files)
    {
        if ($files instanceof Traversable) {
            $files = iterator_to_array($files, false);
        } elseif (!is_array($files)) {
            $files = [$files];
        }
        $files = array_reverse($files);
        foreach ($files as $file) {
            // is_link() resolves THROUGH a trailing slash and answers false, while is_dir()
            // still answers true -- so "themes/mytheme/" took the directory branch below and
            // walked into whatever the link pointed at, deleting somebody else's files. The
            // name is trimmed before the test so a link is recognised either way.
            $link         = rtrim((string) $file, '/\\');
            $link         = $link === '' ? (string) $file : $link;
            $isFileExists = file_exists($file);
            if (is_link($link)) {
                // A symlink is removed as itself. What it points at is not ours to delete.
                // See https://bugs.php.net/52176
                if (!(self::callback('unlink', $link) || '\\' !== DIRECTORY_SEPARATOR
                        || self::callback('rmdir', $link))
                ) {
                    throw new RuntimeException(sprintf('Unable to remove symlink "%s": ' . self::$lastError, $link));
                }
            } elseif (is_dir($file)) {
                $this->remove(new FilesystemIterator(
                    $file,
                    FilesystemIterator::CURRENT_AS_PATHNAME | FilesystemIterator::SKIP_DOTS
                ));

                if ($isFileExists && !self::callback('rmdir', $file)) {
                    throw new RuntimeException(sprintf('Unable to remove directory "%s": ' . self::$lastError, $file));
                }
            } elseif ($isFileExists && !self::callback('unlink', $file)) {
                throw new RuntimeException(sprintf('Unable to remove file "%s": ' . self::$lastError, $file));
            }
        }
    }

    /**
     * Run a native filesystem function with our error handler installed, so the warning text
     * it emits is captured in self::$lastError. Extra arguments are forwarded to $func.
     *
     * @param callable $func
     *
     * @return mixed whatever $func returns
     *
     * @throws \Exception
     */
    private static function callback($func)
    {
        self::$lastError = null;
        set_error_handler(__CLASS__ . '::handleError');
        try {
            $result = $func(...array_slice(func_get_args(), 1));
            restore_error_handler();

            return $result;
        } catch (Exception $e) {
            trigger_error($e->getMessage(), E_USER_NOTICE);
        }
        restore_error_handler();

        throw $e;
    }

    /**
     * Creates a directory recursively.
     *
     * @param string|iterable<string> $dirs The directory path
     * @param int                      $mode
     *
     * @return void
     * @throws \Exception
     */
    public function mkdir($dirs, $mode = 0755)
    {
        foreach ($this->toIterable($dirs) as $dir) {
            if (is_dir($dir)) {
                continue;
            }

            if (!self::callback('mkdir', $dir, $mode, true) && !is_dir($dir)) {
                // The directory was not created. Let's throw an exception
                if (self::$lastError) {
                    throw new RuntimeException(sprintf('Unable to create "%s": ' . self::$lastError, $dir));
                }
                throw new RuntimeException(sprintf('Unable to create "%s".', $dir));
            }
        }
    }

    /**
     * Copies a file.
     *
     * If the target file is older than the origin file, it's always overwritten.
     * If the target file is newer, it is overwritten only when the
     * $overwriteNewerFiles option is set to true.
     *
     * @param string $originFile
     * @param string $targetFile
     * @param bool   $overwriteNewerFiles
     *
     * @return void
     * @throws \Exception
     */
    public function copy($originFile, $targetFile, $overwriteNewerFiles = false)
    {
        $originIsLocal = stream_is_local($originFile) || 0 === stripos($originFile, 'file://');
        if ($originIsLocal && !is_file($originFile)) {
            throw new RuntimeException(sprintf('Unable to copy "%s" because file does not exist.', $originFile));
        }

        $this->mkdir(dirname($targetFile));

        $doCopy = true;
        if (!$overwriteNewerFiles && null === parse_url($originFile, PHP_URL_HOST) && is_file($targetFile)) {
            $doCopy = filemtime($originFile) > filemtime($targetFile);
        }

        if ($doCopy) {
            // https://bugs.php.net/64634
            if (false === $source = @fopen($originFile, 'rb')) {
                throw new RuntimeException(sprintf(
                    'Unable to copy "%s" to "%s" because source file could not be opened for reading.',
                    $originFile,
                    $targetFile
                ));
            }

            // Stream context created to allow files overwrite when using FTP stream wrapper - disabled by default
            if (false ===
                $target = @fopen($targetFile, 'wb', false, stream_context_create(['ftp' => ['overwrite' => true]]))
            ) {
                throw new RuntimeException(sprintf(
                    'Unable to copy "%s" to "%s" because target file could not be opened for writing.',
                    $originFile,
                    $targetFile
                ));
            }

            $bytesCopied = stream_copy_to_stream($source, $target);
            fclose($source);
            fclose($target);
            unset($source, $target);

            if (!is_file($targetFile)) {
                throw new RuntimeException(sprintf('Unable to copy "%s" to "%s".', $originFile, $targetFile));
            }

            if ($originIsLocal) {
                // Like `cp`, preserve executable permission bits
                @chmod($targetFile, fileperms($targetFile) | (fileperms($originFile) & 0111));

                if ($bytesCopied !== $bytesOrigin = filesize($originFile)) {
                    throw new RuntimeException(sprintf(
                        'Unable to copy the whole content of "%s" to "%s" (%g of %g bytes copied).',
                        $originFile,
                        $targetFile,
                        $bytesCopied,
                        $bytesOrigin
                    ));
                }
            }
        }
    }

    /**
     * Always throws: reports why a link from $origin to $target could not be created.
     *
     * @param string $origin
     * @param string $target
     * @param string $linkType Name of the link type, typically 'symbolic' or 'hard'
     *
     * @return never
     * @throws RuntimeException always
     */
    private function linkException($origin, $target, $linkType)
    {
        if (self::$lastError && '\\' === DIRECTORY_SEPARATOR
            && false !== strpos(self::$lastError, 'error code(1314)')
        ) {
            throw new RuntimeException(
                sprintf(
                    'Unable to create "%s" link due to error code 1314: \'A required privilege 
                is not held by the client\'. Do you have the required Administrator-rights?',
                    $linkType
                )
            );
        }
        throw new RuntimeException(sprintf(
            'Unable to create "%s" link from "%s" to "%s".',
            $linkType,
            $origin,
            $target
        ));
    }

    /**
     * Remove directory
     *
     * @param string $path
     *
     * @return bool
     */
    public function deleteDir($path)
    {
        if (strpos($path, '../') !== false || strpos($path, "..\\") !== false) {
            return false;
        }

        if (!is_dir($path)) {
            return false;
        }
        try {
            $this->remove($path);

            return true;
        } catch (Exception $e) {
            trigger_error($e->getMessage(), E_USER_NOTICE);

            return false;
        }
    }

    /**
     * A unique id for temporary file and directory names.
     *
     * @param string $prefix
     * @param bool   $more_entropy
     *
     * @return string
     */
    public function generateUniqueId($prefix = 'osc_', $more_entropy = false)
    {
        return uniqid($prefix, $more_entropy);
    }

    /**
     * Renames a file or a directory.
     *
     * @param string $origin
     * @param string $target
     * @param bool   $overwrite
     *
     * @return void
     * @throws \Exception
     */
    public function rename($origin, $target, $overwrite = false)
    {
        // we check that target does not exist
        if (!$overwrite && $this->isReadable($target)) {
            throw new RuntimeException(sprintf('Cannot rename because the target "%s" already exists.', $target));
        }

        if (true !== @rename($origin, $target)) {
            if (is_dir($origin)) {
                // See https://bugs.php.net/54097 & https://php.net/rename#113943
                $this->sync($origin, $target, ['override' => $overwrite, 'delete' => $overwrite]);
                $this->remove($origin);

                return;
            }
            throw new RuntimeException(sprintf('Cannot rename "%s" to "%s".', $origin, $target));
        }
    }

    /**
     * Tells whether a file exists and is readable.
     *
     * @param string $filename
     *
     * @return bool
     * @throws LengthException When the path is longer than PHP_MAXPATHLEN - 2
     */
    private function isReadable($filename)
    {
        $maxPathLength = PHP_MAXPATHLEN - 2;

        if (strlen($filename) > $maxPathLength) {
            throw new LengthException(sprintf(
                'Could not check if file is readable because path length exceeds %d characters.',
                $maxPathLength
            ));
        }

        return is_readable($filename);
    }

    /**
     * Appends/Write content to an existing file.
     *
     * @param string          $filename
     * @param string|resource $content The content to append
     * @param bool            $append
     *
     * @return bool
     * @throws \Exception
     */
    public function writeToFile($filename, $content, $append = false)
    {
        $dir = dirname($filename);

        if (!is_dir($dir)) {
            $this->mkdir($dir);
        }

        if (!is_writable($dir)) {
            throw new RuntimeException(sprintf('Unable to write to the "%s" directory.', $dir));
        }
        if ($append === true) {
            $fp = fopen($filename, 'ab');
        } else {
            $fp = fopen($filename, 'wb');
        }
        if ($fp === false) {
            throw new RuntimeException(sprintf('Unable to write file "%s".', $filename));
        }

        fwrite($fp, $content);
        fclose($fp);

        return true;
    }

    /**
     * Replace $path whole: write a hidden temp file beside it, then rename it into place, so a
     * reader never sees half a file. Nothing is left behind on failure.
     *
     * @param string          $path
     * @param string|callable $data the content, or a call given the open temp handle that returns false to abort
     * @param int|null        $mode the temp file is created with it, so it is never more open than asked
     *
     * @return bool
     */
    public static function writeAtomic(string $path, $data, ?int $mode = null): bool
    {
        try {
            $tmp = dirname($path) . DIRECTORY_SEPARATOR . '.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        } catch (Exception $e) {
            return false;
        }
        $umask = $mode === null ? null : umask(0777 & ~$mode);
        try {
            $out = @fopen($tmp, 'xb');
        } finally {
            if ($umask !== null) {
                umask($umask);
            }
        }
        if ($out === false) {
            return false;
        }
        // A new file cannot be made with execute or special bits, so only those need a chmod.
        $ok = $mode === null || ($mode & ~0666) === 0 || @chmod($tmp, $mode);
        if ($ok) {
            $ok = is_string($data) ? @fwrite($out, $data) === strlen($data) : $data($out) !== false;
        }
        $ok = fclose($out) && $ok;
        if (!$ok || !@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /**
     * The status code a HEAD request for $url answers, without following redirects; 0 when
     * there is no answer.
     *
     * @param string             $url
     * @param int                $timeout seconds for the whole request
     * @param array<int,mixed>   $curlOptions extra cURL options, such as AddressGuard::curlOptions() to pin the checked IP
     *
     * @return int
     */
    public function head(string $url, int $timeout = 3, array $curlOptions = array()): int
    {
        if (!Curl::available()) {
            return 0;
        }
        $ch = $this->curlHandle($url, true, $timeout, array());
        curl_setopt_array($ch, array(
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        ) + $curlOptions);
        $ok     = curl_exec($ch) !== false;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        return $ok ? $status : 0;
    }

    /**
     * Get content implementation
     *
     * @param string       $url
     * @param array<string,mixed>|string|null $post_data
     * @param bool         $verify_ssl
     * @param int          $timeout       Total transfer timeout in seconds. 0 (default) leaves
     *                                    no overall limit, matching the historic behaviour, so
     *                                    callers such as large-file downloads are unaffected.
     * @param array<int,string> $headers  Extra request header lines (e.g. `'If-None-Match: "abc"'`),
     *                                    added on top of the defaults this method already sets.
     * @param array<string,mixed>|null $responseInfo Out parameter. When a variable is passed, it is filled with
     *                                    `['status' => int, 'headers' => array<lowercase-name, value>]`
     *                                    describing the final response (post-redirects) — callers doing
     *                                    conditional GET (ETag / Last-Modified) need the status code to
     *                                    tell a 304 from a 200, and the response headers to read the new
     *                                    validators back.
     *
     * @return bool|string the response body, or false on failure
     */
    public function getContents(
        $url,
        $post_data = null,
        bool $verify_ssl = true,
        int $timeout = 0,
        array $headers = [],
        ?array &$responseInfo = null
    ) {
        $data            = null;
        $responseHeaders = [];
        $responseInfo    = ['status' => 0, 'headers' => []];
        if (Curl::available()) {
            $ch = $this->curlHandle($url, $verify_ssl, $timeout, $headers);
            @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            // Advertise every encoding this curl can decode, and let it decompress
            // transparently. Without it curl sends no Accept-Encoding at all and servers
            // hand back the raw file: the largest country in the location dataset arrives
            // as 76 MB rather than 5.7. The bytes returned are identical either way, so
            // checksums over the result are unaffected.
            @curl_setopt($ch, CURLOPT_ENCODING, '');

            if ($post_data !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
            }

            // A redirect starts a new header block; only the last one (the final
            // response) is what the caller wants, so each "HTTP/" status line resets
            // what has been collected so far.
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curlHandle, $headerLine) use (&$responseHeaders) {
                $length  = strlen($headerLine);
                $trimmed = trim($headerLine);
                if ($trimmed === '') {
                    return $length;
                }
                if (stripos($trimmed, 'HTTP/') === 0) {
                    $responseHeaders = [];

                    return $length;
                }
                $parts = explode(':', $trimmed, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            });

            $data                     = curl_exec($ch);
            $responseInfo['status']  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $responseInfo['headers'] = $responseHeaders;
            unset($ch);
        } else {
            throw new RuntimeException(sprintf('Unable to get content from "%s". CURL not initializes.
            Is PHP-curl extension installed?', $url));
        }

        return $data;
    }

    /**
     * A curl handle with the options every request here shares: timeouts, user agent,
     * HTTP(S) only, a bounded redirect chain and TLS checks.
     *
     * @param string            $url
     * @param bool              $verifySsl
     * @param int               $timeout 0 for no overall limit
     * @param array<int,string> $headers extra request header lines
     *
     * @return \CurlHandle
     */
    private function curlHandle(string $url, bool $verifySsl, int $timeout, array $headers)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        if ($timeout > 0) {
            @curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        }
        // Abort a connection that stalls (under 1 byte/s for 30s) even when no
        // overall timeout is set, so a slow peer cannot hold the request open
        // indefinitely without capping a large-but-progressing download.
        @curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
        @curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 30);
        curl_setopt(
            $ch,
            CURLOPT_USERAGENT,
            Params::getServerParam('HTTP_USER_AGENT') . ' Shopclass (v.' . OSCLASS_VERSION . ')'
        );
        if (!defined('CURLOPT_RETURNTRANSFER')) {
            define('CURLOPT_RETURNTRANSFER', 1);
        }
        // Bound the redirect chain and keep it on HTTP(S): a redirect must not be
        // able to pivot to file://, gopher:// and friends (the classic SSRF jump).
        @curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            @curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            @curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        curl_setopt($ch, CURLOPT_REFERER, osc_base_url());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        if (stripos($url, 'https') !== false) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        return $ch;
    }

    /**
     * Return directory structure of given root directory
     *
     * @param string      $root_dir
     * @param string|null $pattern preg_match supported regex pattern
     * @param bool        $follow_symlinks
     *
     * @return array<int,string> absolute paths; directories carry a trailing slash
     */
    public function rSearch(string $root_dir, ?string $pattern = null, bool $follow_symlinks = false)
    {
        $dirIterator = new RecursiveDirectoryIterator($root_dir, RecursiveDirectoryIterator::SKIP_DOTS);

        if ($follow_symlinks === true) {
            $dirIterator->setFlags(RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS);
        }

        $iterator = new RecursiveIteratorIterator(
            $dirIterator,
            RecursiveIteratorIterator::SELF_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD // Ignore "Permission denied"
        );

        if ($pattern !== null) {
            $iterator = new RegexIterator($iterator, $pattern);
        }

        $fileList = array();
        foreach ($iterator as $file) {
            $pathname   = $file->getPathname();
            $fileList[] = $file->isDir() ? $pathname . '/' : $pathname;
        }

        return $fileList;
    }

    /**
     * Total transfer timeout in seconds for downloadFile(), separate from the connect-only
     * timeout — without it a peer that connects and then stalls mid-transfer can hold the
     * download open indefinitely.
     */
    private const DOWNLOAD_TIMEOUT_SECONDS = 300;

    /**
     * Hosts a package download URL is allowed to resolve to, checked by callers such as
     * mindstellar\upgrade\Upgrade before downloadFile() is invoked (this method stays
     * general-purpose and is used by callers that have nothing to do with packages).
     *
     * @param string $url
     *
     * @return bool true when $url is https and its host matches an allowed entry
     */
    public static function isAllowedPackageHost(string $url): bool
    {
        $defaultHosts = [
            'github.com',
            'objects.githubusercontent.com',
            'raw.githubusercontent.com',
            '*.github.io',
        ];

        // Filterable so a self-hosted install can register its own mirror without patching
        // core; entries may be an exact host or a "*.example.com" wildcard.
        $allowedHosts = (array) osc_apply_filter('market_allowed_package_hosts', $defaultHosts);

        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== 'https') {
            return false;
        }
        $host = strtolower($parts['host']);

        foreach ($allowedHosts as $allowedHost) {
            $allowedHost = strtolower((string) $allowedHost);
            if ($allowedHost === '') {
                continue;
            }

            if (strpos($allowedHost, '*.') === 0) {
                $suffix = substr($allowedHost, 1); // e.g. ".github.io"
                if (substr($host, -strlen($suffix)) === $suffix && $host !== ltrim($suffix, '.')) {
                    return true;
                }
                continue;
            }

            if ($host === $allowedHost) {
                return true;
            }
        }

        return false;
    }

    /**
     * Download Files from given url
     * try to overwrite existing file.
     *
     * @param string      $sourceURL
     * @param string      $filename
     * @param array<string,mixed>|string|null $post_data
     * @param bool        $verify_ssl
     * @param string|null $expectedSha256 When given, the downloaded file is hashed and
     *                                    compared; a mismatch deletes the file and returns false.
     *
     * @return bool|string
     * @throws \Exception
     */
    public function downloadFile(
        string $sourceURL,
        string $filename,
        $post_data = null,
        bool $verify_ssl = true,
        ?string $expectedSha256 = null
    ) {
        if (!Validate::httpUrl($sourceURL)) {
            throw new InvalidArgumentException(sprintf('Invalid source url "%s". ', $sourceURL));
        }
        if ($expectedSha256 !== null && !preg_match('/^[a-f0-9]{64}$/i', $expectedSha256)) {
            throw new InvalidArgumentException(sprintf('Invalid expected sha256 checksum "%s". ', $expectedSha256));
        }
        if (strpos($filename, '../') !== false || strpos($filename, "..\\") !== false) {
            return false;
        }
        $file_path = $filename;
        if ($this->exists($file_path)) {
            $this->remove($file_path);
        }
        if (Curl::available()) {
            set_time_limit(0);
            $fp = fopen($filename, 'wb+');
            if ($fp) {
                $ch = $this->curlHandle($sourceURL, $verify_ssl, self::DOWNLOAD_TIMEOUT_SECONDS, array());
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
                curl_setopt($ch, CURLOPT_FILE, $fp);
                // Decompressed in flight, so the file on disk is the real thing and the
                // checksum below still matches.
                @curl_setopt($ch, CURLOPT_ENCODING, '');
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                if ($post_data !== null) {
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
                }

                $success    = curl_exec($ch);
                $curlErrno  = curl_errno($ch);
                $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                unset($ch);
                fclose($fp);

                if ($success === false || $curlErrno !== 0 || $httpStatus < 200 || $httpStatus >= 300) {
                    $this->remove($file_path);

                    return false;
                }

                if (!$this->exists($file_path) || filesize($file_path) === 0) {
                    $this->remove($file_path);

                    return false;
                }

                if ($expectedSha256 !== null) {
                    $actualSha256 = hash_file('sha256', $file_path);
                    if ($actualSha256 === false || !hash_equals(strtolower($expectedSha256), strtolower($actualSha256))) {
                        $this->remove($file_path);

                        return false;
                    }
                }

                return $file_path;
            }

            return false;
        }

        throw new RuntimeException(sprintf('Unable to download content from "%s". CURL not initializes.
        Is PHP-curl extension installed?', $sourceURL));
    }
}
