<?php
/**
 * Created by Mindstellar Community.
 * User: navjottomer
 * Date: 18/05/20
 * Time: 4:40 PM
 * License is provided in root directory.
 */

namespace mindstellar\utility;

use Plugins;

/**
 * Class Deprecated
 * Provides common static method to deprecate functions,class,file,hooks,filters
 *
 * @package mindstellar\utility
 */
class Deprecate
{
    /**
     * Deprecate Function
     * Fire a 'd_function_run' hook when deprecated function is called.
     *
     * @param string      $function    Deprecated function name
     * @param string      $version     The version of Shopclass that deprecated the file.
     * @param string|null $replacement The function that should have been used.
     */
    public static function deprecatedFunction(
        $function,
        $version,
        $replacement = null
    ) {
        /**
         * Fires when a deprecated function is called.
         */
        Plugins::runHook('d_function_run', $function, $replacement, $version);

        if (OSC_DEBUG) {
            $debug_backtrace = debug_backtrace();
            $caller          = next($debug_backtrace);
            if ($replacement !== null) {
                self::triggerError(
                    $caller,
                    sprintf(
                        '%1$s is <strong>deprecated</strong> since version %2$s! Use %3$s instead.',
                        $function,
                        $version,
                        $replacement
                    ),
                    E_USER_DEPRECATED
                );
            } else {
                self::triggerError(
                    $caller,
                    sprintf(
                        '%1$s is <strong>deprecated</strong> since version %2$s with no alternative available.',
                        $function,
                        $version
                    ),
                    E_USER_DEPRECATED
                );
            }
        }
    }

    /**
     * Private error_trigger
     *
     * @param array  $caller
     * @param string $message
     * @param int    $level [optional] <p>
     *                      The designated error type for this error. It only works with the E_USER
     *                      family of constants, and will default to <b>E_USER_NOTICE</b>.
     *
     * @return void
     */
    private static function triggerError($caller, $message = null, $level = E_USER_DEPRECATED)
    {
        if ($message === null) {
            throw(new \InvalidArgumentException("Invalid error message."));
        }
        $message .= '</strong> in <strong>' . $caller['file'] . '</strong> on line <strong>' . $caller['line']
            . '</strong>' . "\n<br /> error handled";
        trigger_error($message, $level);
    }

    /**
     * Deprecate Hook
     *
     * @param string      $hook        Hook name to run
     * @param string      $version     The version of Shopclass that deprecated this Hook
     * @param string|null $replacement Replacement Hook name if available
     * @param string|null $message     A message regarding the change.
     * @param mixed       $args,...    hook arguments
     */
    public static function deprecatedRunHook(
        $hook,
        $version,
        $replacement = null,
        $message = null,
        ...$args
    ) {
        if (!Plugins::hasHook($hook)) {
            return;
        }

        self::deprecatedHook($hook, $version, $replacement, $message);

        Plugins::runHook($hook, ...$args);
    }

    /**
     * For internal use only
     * run when a deprecated hook/filter is used.
     *
     * @param      $hook
     * @param      $version
     * @param null $replacement
     * @param null $message
     */
    private static function deprecatedHook(
        $hook,
        $version,
        $replacement = null,
        $message = null
    ) {
        /**
         * Fires when a deprecated hook/filter is called.
         */
        Plugins::runHook('d_hook_run', $hook, $replacement, $version, $message);

        if (OSC_DEBUG) {
            $message         = empty($message) ? '' : ' ' . $message;
            $debug_backtrace = debug_backtrace();

            $caller = next($debug_backtrace);
            if ($replacement !== null) {
                self::triggerError(
                    $caller,
                    sprintf(
                        '%1$s is <strong>deprecated</strong> since version %2$s! Use %3$s instead.',
                        $hook,
                        $version,
                        $replacement
                    ) . $message,
                    E_USER_DEPRECATED
                );
            } else {
                self::triggerError(
                    $caller,
                    sprintf(
                        '%1$s is <strong>deprecated</strong> since version %2$s with no alternative available.',
                        $hook,
                        $version
                    ) . $message,
                    E_USER_DEPRECATED
                );
            }
        }
    }

    /**
     * Deprecate Filter
     *
     * @param string      $filter      Filter name to run
     * @param mixed       $content     Content to filter
     * @param string      $version     The version of Shopclass that deprecated this Filter
     * @param null|string $replacement Replacement Filter name if available.
     * @param null|string $message     A messaged regarding the change.
     * @param mixed       $args,...    filter arguments.
     */
    public static function deprecatedApplyFilter(
        $filter,
        $content,
        $version,
        $replacement = null,
        $message = null,
        ...$args
    ) {
        if (Plugins::hadRun($filter)) {
            return;
        }

        self::deprecatedHook($filter, $version, $replacement, $message);

        Plugins::applyFilter($filter, $content, ...$args);
    }

    /**
     * Deprecate File
     *
     * @param string $file        The file that was called.
     * @param string $replacement The file that should have been included based on ABS_PATH.
     * @param string $version     The version of Shopclass that deprecated the file.
     * @param string $message     A message regarding the change.
     */
    public static function deprecatedFile(
        $file,
        $version,
        $replacement = null,
        $message = ''
    ) {

        /**
         * Fires when a deprecated file is called.
         */
        Plugins::runHook('d_file_included', $file, $replacement, $version, $message);

        if (OSC_DEBUG) {
            $message = empty($message) ? '' : ' ' . $message;

            $debug_backtrace = debug_backtrace();

            $caller = next($debug_backtrace);
            if ($replacement !== null) {
                self::triggerError(
                    $caller,
                    sprintf(
                        '%1$s is <strong>deprecated</strong> since version %2$s! Use %3$s instead.',
                        $file,
                        $version,
                        $replacement
                    ) . $message,
                    E_USER_DEPRECATED
                );
            } else {
                self::triggerError(
                    $caller,
                    sprintf(
                        '%1$s is <strong>deprecated</strong> since version %2$s with no alternative available.',
                        $file,
                        $version
                    ) . $message,
                    E_USER_DEPRECATED
                );
            }
        }
    }
}
