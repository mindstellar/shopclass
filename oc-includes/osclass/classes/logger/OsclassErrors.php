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
 * Created by Mindstellar Community.
 * User: navjottomer
 * Date: 07-09-2021
 * Time: 01:04
 * License is provided in root directory.
 */

namespace mindstellar\logger;

use Exception;

/**
 * Class OsclassErrors
 *
 * @package Mindstellar\Logger
 */
class OsclassErrors
{
    private static ?OsclassErrors $instance = null;
    private bool $logEnabled = false;
    private bool $debugEnabled = false;
    private string $logFile = '';

    /**
     * OsclassErrors constructor.
     */
    private function __construct()
    {
        $this->initializeErrorSettings();
    }

    /**
     * Get an instance of OsclassErrors (Singleton pattern).
     *
     * @return OsclassErrors
     */
    public static function newInstance(): OsclassErrors
    {
        return self::$instance ??= new self();
    }

    /**
     * Initialize error settings based on defined constants.
     */
    private function initializeErrorSettings(): void
    {
        if (defined('OSC_DEBUG') && OSC_DEBUG || defined('OSC_INSTALLING')) {
            $this->debugEnabled = true;
            ini_set('display_errors', 1);
            error_reporting(E_ALL | E_STRICT);

            if (defined('OSC_DEBUG_LOG') && OSC_DEBUG_LOG) {
                ini_set('display_errors', 0);
                $this->logEnabled = true;
                $this->logFile = CONTENT_PATH . 'debug.log';
            }
        } else {
            // Production: never leak raw PHP errors to the page — our handlers
            // render a clean page and log the detail instead.
            ini_set('display_errors', '0');
            error_reporting(
                E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_ERROR | E_WARNING | E_PARSE
                | E_USER_ERROR | E_USER_WARNING
            );
        }
    }

    /**
     * Register error handling functions.
     *
     * @return bool
     */
    public function register(): bool
    {
        // Always catch uncaught exceptions and fatal errors, in every mode, so a
        // failure renders a clean page (and is logged) instead of a raw stack
        // trace or a blank screen. Notices/warnings are only surfaced in debug.
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'logFatalErrors']);

        if ($this->debugEnabled) {
            set_error_handler([$this, 'logErrors']);
        }

        return true;
    }

    /**
     * Handle an uncaught exception/error: log it, then render the clean error
     * page (with technical detail only in debug mode).
     *
     * @param \Throwable $e
     */
    public function handleException($e): void
    {
        error_log('Shopclass error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

        if ($this->logEnabled) {
            $this->writeToFile($this->formattedError(
                $e->getMessage(),
                (int)$e->getCode(),
                $e->getFile(),
                (int)$e->getLine(),
                $e->getTraceAsString()
            ));
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $this->formattedError(
                $e->getMessage(),
                (int)$e->getCode(),
                $e->getFile(),
                (int)$e->getLine(),
                $e->getTraceAsString()
            ) . PHP_EOL);

            return;
        }

        $this->renderErrorPage(array(
            'type'    => get_class($e),
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => (int)$e->getLine(),
            'trace'   => $e->getTraceAsString(),
        ));
    }

    /**
     * Handle general errors.
     *
     * @param int    $type
     * @param string $message
     * @param string $file
     * @param int    $line
     *
     * @return bool
     */
    public function logErrors(int $type = E_USER_NOTICE, string $message = '', string $file = __FILE__, int $line = __LINE__): bool
    {
        $this->log($type, $message, $file, $line);

        return true;
    }

    /**
     * Handle fatal errors.
     */
    public function logFatalErrors(): void
    {
        $error = error_get_last();
        if ($error === null) {
            return;
        }

        // Only true fatals warrant the error page at shutdown; a trailing
        // notice or warning must not replace a page that rendered normally.
        $fatalMask = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
        if (($error['type'] & $fatalMask) === 0) {
            return;
        }

        error_log('Shopclass fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);

        if ($this->logEnabled) {
            $this->writeToFile(
                $this->formattedError($error['message'], $error['type'], $error['file'], $error['line'], '')
            );

            return;
        }

        if (PHP_SAPI === 'cli') {
            fwrite(
                STDERR,
                $this->formattedError($error['message'], $error['type'], $error['file'], $error['line'], '') . PHP_EOL
            );

            return;
        }

        $this->renderErrorPage(array(
            'type'    => $this->errorType($error['type']),
            'message' => $error['message'],
            'file'    => $error['file'],
            'line'    => $error['line'],
            'trace'   => '',
        ));
    }

    /**
     * Render the clean, self-contained error page. Technical detail is only
     * passed through when debug mode is on; production sees a plain, useful
     * message and a reference id, and the detail goes to the log.
     *
     * @param array $detail ['type','message','file','line','trace']
     */
    private function renderErrorPage(array $detail): void
    {
        // If output already began we can't produce a clean page; the detail is
        // logged. In debug, append a short note so it isn't lost entirely.
        if (headers_sent()) {
            if ($this->debugEnabled) {
                echo "\n" . htmlspecialchars(
                    (isset($detail['type']) ? $detail['type'] : 'Error') . ': '
                    . (isset($detail['message']) ? $detail['message'] : ''),
                    ENT_QUOTES,
                    'UTF-8'
                );
            }

            return;
        }

        http_response_code(500);
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $seed = (isset($detail['message']) ? $detail['message'] : '')
            . (isset($detail['file']) ? $detail['file'] : '')
            . (isset($detail['line']) ? $detail['line'] : '');
        $ref  = date('ymd-His') . '-' . substr(md5($seed), 0, 6);

        $oscError = array(
            'isDebug' => $this->debugEnabled,
            'heading' => 'Something went wrong',
            'body'    => "This page ran into an unexpected error and couldn't finish loading. The problem has been "
                . 'logged. Please try again in a moment — if it keeps happening, contact the site administrator with '
                . 'the reference below.',
            'ref'     => $ref,
            'detail'  => $detail,
        );

        $this->includeTemplate($oscError, 'Something went wrong', 'This page could not be loaded.');
        exit(1);
    }

    /**
     * Render a clean "database unavailable" page. Called from the DB connection
     * layer when the site is up but cannot reach its database — the most common
     * real-world failure, so it gets a tailored, actionable message.
     *
     * @param string $detailMessage Technical detail (shown only in debug mode)
     */
    public function renderDbError(string $detailMessage = ''): void
    {
        error_log('Shopclass: database unavailable. ' . $detailMessage);

        if (headers_sent()) {
            return;
        }

        http_response_code(503);
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $oscError = array(
            'isDebug' => $this->debugEnabled,
            'heading' => "Can't reach the database",
            'body'    => "Shopclass is running, but it can't connect to its database right now. This is usually a "
                . 'temporary hosting hiccup or a wrong value in config.php. Please try again in a moment; if it '
                . 'persists, check that the database server is up and that the credentials in config.php are correct.',
            'ref'     => null,
            'detail'  => ($this->debugEnabled && $detailMessage !== '')
                ? array('type' => 'Database', 'message' => $detailMessage, 'file' => '', 'line' => '', 'trace' => '')
                : null,
        );

        $this->includeTemplate($oscError, 'Database unavailable', 'Please try again shortly.');
        exit(1);
    }

    /**
     * Include the shared error template, or a bare fallback if it is missing.
     *
     * @param array  $oscError     Data for the template
     * @param string $fbHeading    Fallback heading if the template is absent
     * @param string $fbBody       Fallback body if the template is absent
     */
    private function includeTemplate(array $oscError, string $fbHeading, string $fbBody): void
    {
        $template = ABS_PATH . 'oc-includes/osclass/gui/error.php';
        if (is_file($template)) {
            include $template;

            return;
        }

        echo '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($fbHeading, ENT_QUOTES, 'UTF-8')
            . '</title><h1>' . htmlspecialchars($fbHeading, ENT_QUOTES, 'UTF-8') . '</h1><p>'
            . htmlspecialchars($fbBody, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    /**
     * Format error message.
     *
     * @param string       $message
     * @param int          $errorCode
     * @param string       $file
     * @param int          $lineNo
     * @param string|array $context
     *
     * @return string
     */
    private function formattedError(string $message, int $errorCode, string $file, int $lineNo, $context): string
    {
        $message = $this->errorType($errorCode) . ': ' . $message;
        $message .= ' in ' . $file . ' on line no ' . $lineNo . ' Error Code:' . $errorCode;

        if (!empty($context)) {
            $message .= ' with context: ' . PHP_EOL . var_export($context, true);
        }

        return $message;
    }

    /**
     * Get error type based on error code.
     *
     * @param int $errorCode
     *
     * @return string
     */
    private function errorType(int $errorCode): string
    {
        $errorTypes = [
            E_WARNING => 'WARNING',
            E_PARSE => 'PARSE',
            E_NOTICE => 'NOTICE',
            E_CORE_ERROR => 'CORE_ERROR',
            E_CORE_WARNING => 'CORE_WARNING',
            E_COMPILE_ERROR => 'COMPILE_ERROR',
            E_COMPILE_WARNING => 'COMPILE_WARNING',
            E_USER_ERROR => 'USER_ERROR',
            E_USER_WARNING => 'USER_WARNING',
            E_USER_NOTICE => 'USER_NOTICE',
            E_STRICT => 'STRICT',
            E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
            E_DEPRECATED => 'DEPRECATED',
            E_USER_DEPRECATED => 'USER_DEPRECATED',
        ];

        // Add default error type
        if (!isset($errorTypes[$errorCode])) {
            $errorTypes[$errorCode] = 'ERROR';
        }

        return $errorTypes[$errorCode];
    }

    /**
     * Write error message to log file.
     *
     * @param string $message
     */
    private function writeToFile(string $message): void
    {
        $this->ensureLogFileExists();

        $message = date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL;

        file_put_contents($this->logFile, $message, FILE_APPEND);
    }

    /**
     * Ensure log file exists or create it.
     */
    private function ensureLogFileExists(): void
    {
        if (!file_exists($this->logFile)) {
            try {
                $this->createLogFile();
            } catch (Exception $e) {
                $this->logFile = ini_get('error_log');
                $this->log($e->getCode(), $e->getTraceAsString());
            }
        }
    }

    /**
     * Create log file.
     *
     * @throws Exception
     */
    private function createLogFile(): void
    {
        $logFile = CONTENT_PATH . 'debug.log';

        if (file_exists($logFile)) {
            return;
        }

        if (!is_writable(CONTENT_PATH)) {
            throw new Exception('The content directory is not writable');
        }

        touch($logFile);
    }

    /**
     * Log error.
     *
     * @param int    $type
     * @param string $message
     * @param string $file
     * @param int    $line
     * @param string $context
     *
     * @return bool
     */
    public function log(int $type = E_USER_NOTICE, string $message = '', string $file = __FILE__, int $line = __LINE__, string $context = ''): bool
    {
        if ($this->logEnabled) {
            $message = $this->formattedError($message, $type, $file, $line, $context);
            $this->writeToFile($message);
        } else {
            $message = PHP_SAPI === 'cli'
                ? $this->formattedError($message, $type, $file, $line, $context)
                : $this->htmlFormattedError($message, $type, $file, $line, $context);

            $this->writeToScreen($message);
        }

        return true;
    }

    /**
     * Format error message in HTML.
     *
     * @param string $message
     * @param int    $type
     * @param string $file
     * @param int    $line
     * @param string $context
     *
     * @return string
     */
    private function htmlFormattedError(string $message, int $type, string $file, int $line, string $context): string
    {
        $errorTrace = $context ? '<pre>' . $context . '</pre>' : '';

        ob_start();
        ?>
    <style>
        .error-container {
            width: 100%;
            left: 0;
            right: 0;
            margin: auto;
            z-index: 999;
        }

        .error-container .error {
            border-radius: .25rem;
            font-size: 1.2rem;
            font-weight: normal;
            padding: 1rem;
            margin-top: 10px;
            margin-bottom: 10px;
            clear: both;
            text-align: initial;
            color: #231c1c;
        }

        .error-container .error-info {
            color: #055160;
            background-color: #cff4fc;
        }

        .error-container .error-warning {
            color: #5a5a00;
            background-color: #fff7bd;
        }

        .error-container .error-danger {
            color: #720505;
            background-color: #fcd5d1;
        }

        .error-container pre {
            background: #343a40;
            color: #ffc107;
            padding: 1rem;
            font-size: 1rem;
            border-radius: 0.25rem;
            margin-top: 2rem;
            border: 0;
        }
    </style>

    <div class="error-container">
        <div class="error error-<?php echo $this->errorClass($type); ?>">
            <strong><?php echo $this->errorType($type); ?>:</strong> <?php echo $message; ?>
            <br>
            <strong>Error File:</strong> <?php echo $file; ?>
            <br>
            <strong>Error Line:</strong> <?php echo $line; ?>
            <br>
            <strong>Error Code:</strong> <?php echo $type; ?>
            <br>
            <?php echo $errorTrace; ?>
        </div>
    </div>
        <?php
        return ob_get_clean();
    }


    /**
     * Get error class based on error code.
     *
     * @param int $errorCode
     *
     * @return string
     */
    private function errorClass(int $errorCode): string
    {
        switch ($errorCode) {
            case E_CORE_WARNING:
            case E_COMPILE_WARNING:
            case E_USER_WARNING:
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
            case E_WARNING:
                return 'warning';
            case E_USER_NOTICE:
            case E_STRICT:
            case E_NOTICE:
                return 'info';
            default:
                return 'danger';
        }
    }

    /**
     * Write error message to screen.
     *
     * @param string $message
     */
    private function writeToScreen(string $message): void
    {
        echo $message;
    }

    /**
     * Log exception.
     *
     * @param $exception
     *
     * @return bool
     */
    public function logException($exception): bool
    {
        $this->log(
            $exception->getCode(),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );

        return true;
    }
}
