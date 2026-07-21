<?php
/**
 * Created by Mindstellar Community.
 * User: navjottomer
 * Date: 08/05/20
 * Time: 12:51 AM
 * License is provided in root directory.
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
     * @internal
     */
    private static function handleError($type, $msg)
    {
        self::$lastError = $msg;
    }

    /**
     * Sets access and modification time of file.
     *
     * @param string|iterable $files A filename, an array of files, or a \Traversable instance to create
     *
     * @throws Exception When touch fails
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
     * @param $files
     *
     * @return array|\Traversable
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
     * @throws Exception When the change fails
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
     * @throws Exception When the change fails
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
     * @throws Exception When the change fails
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
     * @param      $originDir
     * @param      $targetDir
     * @param bool $copyOnWindows
     *
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
     * Sync a directory to another.
     *
     * Copies files and directories from the origin directory into the target directory. By default:
     *
     *  - existing files in the target directory will be overwritten, except if they are newer
     * (see the `override` option)
     *  - files in the target directory that do not exist in the source directory will not be deleted
     * (see the `delete` option)
     *
     * @param                   $originDir
     * @param                   $targetDir
     * @param array             $options        An array of boolean options
     *                                          Valid options are:
     *                                          - $options['override'] If true, target files newer than origin files
     *                                          are
     *                                          overwritten (see copy(), defaults to false)
     *                                          - $options['copy_on_windows'] Whether to copy files instead of links on
     *                                          Windows (see symlink(), defaults to false)
     *                                          - $options['delete'] Whether to delete files that are not in the source
     *                                          directory (defaults to false)
     * @param array             $filter         Files/Directory name in array get filtered
     *
     * @throws \Exception
     */
    public function sync($originDir, $targetDir, $options = [], $filter = [])
    {
        $iterator     = null;
        $targetDir    = rtrim($targetDir, '/\\');
        $originDir    = rtrim($originDir, '/\\');
        $originDirLen = strlen($originDir);

        if (!$this->exists($originDir)) {
            throw new RuntimeException(sprintf('The origin directory specified "%s" was not found.', $originDir));
        }

        // Iterate in destination folder to remove obsolete entries
        if (isset($options['delete']) && $options['delete'] && $this->exists($targetDir)) {
            $deleteIterator = $iterator;
            if (null === $deleteIterator) {
                $flags          = FilesystemIterator::SKIP_DOTS;
                $deleteIterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($targetDir, $flags),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
            }
            $targetDirLen = strlen($targetDir);
            foreach ($deleteIterator as $file) {
                $origin = $originDir . substr($file->getPathname(), $targetDirLen);
                if (!$this->exists($origin)) {
                    $this->remove($file);
                }
            }
        }

        $copyOnWindows = $options['copy_on_windows'] ?? false;

        if (null === $iterator) {
            $flags = $copyOnWindows ? FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
                : FilesystemIterator::SKIP_DOTS;
            /**
             * $iterator = new RecursiveIteratorIterator(
             * new RecursiveDirectoryIterator($originDir, $flags),
             * RecursiveIteratorIterator::SELF_FIRST
             * );
             */
            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($originDir, $flags),
                    static function ($filterIterator) use ($filter) {
                        /** @var FilesystemIterator $filterIterator */
                        return !in_array($filterIterator->getBaseName(), $filter, false);
                    }
                ),
                RecursiveIteratorIterator::SELF_FIRST
            );
        }

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
     * @param string|iterable $files A filename, an array of files, or a \Traversable instance to remove
     *
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
            $isFileExists = file_exists($file);
            if (is_link($file)) {
                // See https://bugs.php.net/52176
                if ($isFileExists
                    && !(self::callback('unlink', $file) || '\\' !== DIRECTORY_SEPARATOR
                        || self::callback('rmdir', $file))
                ) {
                    throw new RuntimeException(sprintf('Unable to remove symlink "%s": ' . self::$lastError, $file));
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
     * @param callable $func
     *
     * @return mixed
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
     * @param string|iterable $dirs The directory path
     *
     * @param int             $mode
     *
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
                $target = @fopen($targetFile, 'wb', null, stream_context_create(['ftp' => ['overwrite' => true]]))
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
     * @param string $linkType Name of the link type, typically 'symbolic' or 'hard'
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
     * @param $path
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
     * @param      $origin
     * @param      $target
     * @param bool $overwrite
     *
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
                $this->sync($origin, $target, null, ['override' => $overwrite, 'delete' => $overwrite]);
                $this->remove($origin);

                return;
            }
            throw new RuntimeException(sprintf('Cannot rename "%s" to "%s".', $origin, $target));
        }
    }

    /**
     * Tells whether a file exists and is readable.
     *
     * @throws Exception When windows path is longer than 258 characters
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
     * @param                 $filename
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
     * Get content implementation
     *
     * @param      $url
     * @param null $post_data
     * @param bool $verify_ssl
     * @param int  $timeout Total transfer timeout in seconds. 0 (default) leaves
     *                      no overall limit, matching the historic behaviour, so
     *                      callers such as large-file downloads are unaffected.
     *
     * @return bool|string $data
     */
    public function getContents($url, $post_data = null, bool $verify_ssl = true, int $timeout = 0)
    {
        $data = null;
        if ($this->testCurl()) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            if ($timeout > 0) {
                @curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            }
            curl_setopt(
                $ch,
                CURLOPT_USERAGENT,
                Params::getServerParam('HTTP_USER_AGENT') . ' Shopclass (v.' . OSCLASS_VERSION . ')'
            );
            if (!defined('CURLOPT_RETURNTRANSFER')) {
                define('CURLOPT_RETURNTRANSFER', 1);
            }
            @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($ch, CURLOPT_REFERER, osc_base_url());
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            if (stripos($url, 'https') !== false) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify_ssl);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            }

            if ($post_data !== null) {
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
            }

            $data = curl_exec($ch);
            curl_close($ch);
        } else {
            throw new RuntimeException(sprintf('Unable to get content from "%s". CURL not initializes. 
            Is PHP-curl extension installed?', $url));
        }

        return $data;
    }

    /**
     * Returns true if there is curl on system environment
     *
     * @return bool
     */
    private function testCurl()
    : bool
    {
        return (function_exists('curl_init') || function_exists('curl_exec'));
    }

    /**
     * Return directory structure of given root directory
     *
     * @param string      $root_dir
     * @param string|null $pattern preg_match supported regex pattern
     * @param bool        $follow_symlinks
     *
     * @return array
     */
    public function rSearch(string $root_dir, string $pattern = null, bool $follow_symlinks = false)
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
        /** @var RecursiveDirectoryIterator $iterator */
        foreach ($iterator as $file) {
            $pathname   = $file->getPathname();
            $fileList[] = $file->isDir() ? $pathname . '/' : $pathname;
        }

        return $fileList;
    }

    /**
     * Download Files from given url
     * try to overwrite existing file.
     *
     * @param string $sourceURL
     * @param string $filename
     * @param null   $post_data
     * @param bool   $verify_ssl
     *
     * @return bool|string
     * @throws \Exception
     */
    public function downloadFile(string $sourceURL, string $filename, $post_data = null, bool $verify_ssl = true)
    {
        if (!filter_var($sourceURL, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException(sprintf('Invalid source url "%s". ', $sourceURL));
        }
        if (strpos($filename, '../') !== false || strpos($filename, "..\\") !== false) {
            return false;
        }
        $file_path = $filename;
        if ($this->exists($file_path)) {
            $this->remove($file_path);
        }
        if ($this->testCurl()) {
            set_time_limit(0);
            $fp = fopen($filename, 'wb+');
            if ($fp) {
                $ch = curl_init($sourceURL);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt(
                    $ch,
                    CURLOPT_USERAGENT,
                    Params::getServerParam('HTTP_USER_AGENT') . ' Shopclass (v.' . OSCLASS_VERSION . ')'
                );
                curl_setopt($ch, CURLOPT_FILE, $fp);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_REFERER, osc_base_url());

                if (stripos($sourceURL, 'https') !== false) {
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify_ssl);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                }
                if ($post_data !== null) {
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
                }

                curl_exec($ch);
                curl_close($ch);
                fclose($fp);

                return $file_path;
            }

            return false;
        }

        throw new RuntimeException(sprintf('Unable to download content from "%s". CURL not initializes. 
        Is PHP-curl extension installed?', $sourceURL));
    }
}
