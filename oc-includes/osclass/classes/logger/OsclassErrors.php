<?php
/*
 * This file is part of Osclass (Mindstellar).
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
        if ($this->debugEnabled) {
            set_error_handler([$this, 'logErrors']);
            set_exception_handler([$this, 'logException']);
            register_shutdown_function([$this, 'logFatalErrors']);
        }

        return true;
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

        if (!empty($error)) {
            if ($this->logEnabled) {
                $message = $this->formattedError($error['message'], $error['type'], $error['file'], $error['line'], var_export($error, true));
                $this->writeToFile($message);
            } elseif (PHP_SAPI === 'cli') {
                printf($this->formattedError(
                    $error['message'],
                    $error['type'],
                    $error['file'],
                    $error['line'],
                    var_export($error, true)
                ));
                exit(1);
            } else {
                $this->displayErrorPage($error);
            }
        }
    }

    /**
     * Display error page.
     *
     * @param array $error
     */
    private function displayErrorPage(array $error): void
    {
        extract($error);
        $trace = var_export(debug_backtrace(), true);

        include ABS_PATH . 'oc-admin/gui/error.php';
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
