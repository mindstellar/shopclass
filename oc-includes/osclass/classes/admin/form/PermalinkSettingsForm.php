<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\form;

use Rewrite;

/**
 * The permalinks screen: whether friendly URLs are on, and the structure of every one.
 *
 * The one settings screen with real save-time effects. Writing the server's rewrite rules
 * to disk and rebuilding the cached rule table are declared as the page's own after_save,
 * which core runs once after a successful write and never after a refused one -- so a form
 * that was rejected cannot leave a .htaccess describing a structure that was not stored.
 *
 * Every structure field depends on the friendly-URLs switch, exactly as the hand-written
 * screen did with an if: while the switch is off the values are not written and the
 * controls are not required, so the browser does not refuse to submit a form over a box it
 * is not showing.
 *
 * @package mindstellar\admin\form
 */
final class PermalinkSettingsForm
{
    public const PAGE_ID = 'core.settings_permalinks';

    /** The switch every structure field hangs off. */
    private const MASTER = 'rewrite_enabled';

    /** At least one letter or digit, which is what osc_validate_text() asked of these. */
    private const NOT_BLANK = '/[\p{L}\p{N}]/u';

    /**
     * Declare the form, once per request.
     *
     * @return string the page id
     */
    public static function register(): string
    {
        if (osc_settings_page(self::PAGE_ID) !== null) {
            return self::PAGE_ID;
        }

        $form = CoreSettings::page(self::PAGE_ID, __('Permalinks'))
            ->onAfterSave(static function (array $values) {
                self::apply($values);
            })
            ->checkbox(
                self::MASTER,
                __('Enable friendly urls'),
                __('Turns links like index.php?page=item&id=42 into readable ones like '
                   . '/listing/blue-bicycle-42. Your web server needs URL rewriting enabled '
                   . 'for this to work.')
            )
                ->rowLabel(__('Friendly URLs'))
                ->column('rewriteEnabled')
                // The id this screen has always given the switch, kept stable for forked
                // admin themes; the derived one would be field-rewrite_enabled.
                ->set('id', self::MASTER)
            ->custom('rules_open', static function (array $spec) {
                echo '<div id="custom_rules" data-osc-depends="' . self::MASTER . '"'
                     . (empty($spec['values'][self::MASTER]) ? ' hidden' : '') . '>'
                     . '<details class="rules-disclosure"><summary>'
                     . osc_esc_html(__('Advanced: customize URL structure')) . '</summary>';
            })
                ->set('row', false);

        self::section($form, 'listings', __('Listings, pages &amp; categories'), false);
        self::path($form, 'rewrite_item_url', __('Listing URL'), sprintf(
            __('Accepted keywords: %s'),
            '{ITEM_ID},{ITEM_TITLE},{ITEM_CITY},{CATEGORIES}'
        ))
            // The router resolves a listing by the id captured from its URL, so a structure
            // without {ITEM_ID} makes every listing 404 and the rule built from it becomes a
            // greedy catch-all that hijacks the admin too. (#355)
            ->validate(static function ($value) {
                return stripos((string)$value, '{ITEM_ID}') === false
                    ? __('The listing permalink structure must include {ITEM_ID}.')
                    : null;
            });
        self::path($form, 'rewrite_page_url', __('Page URL'), sprintf(
            __('Accepted keywords: %s'),
            '{PAGE_ID}, {PAGE_SLUG}'
        ));
        self::path($form, 'rewrite_cat_url', __('Category URL'), sprintf(
            __('Accepted keywords: %s'),
            '{CATEGORY_ID},{CATEGORY_NAME},{CATEGORIES}'
        ))
            ->sanitize(static function ($value) {
                // DEPRECATED: the older spelling of the same keyword, still accepted.
                return str_replace('{CATEGORY_SLUG}', '{CATEGORY_NAME}', self::normalise($value));
            });

        self::section($form, 'search', __('Search'));
        $form->text('seo_url_search_prefix', __('Search prefix URL'), __('It always appear before the category, region or city url.'))
            ->width('key')
            ->dependsOn(self::MASTER)
            // No structure of its own and no default: an empty prefix is the shipped one,
            // so this is the one field on the page that may be left blank.
            ->sanitize(static function ($value) {
                return rtrim((string)$value, '/');
            });
        self::path($form, 'rewrite_search_url', __('Search URL'));
        self::keyword($form, 'rewrite_search_country', __('Search keyword country'));
        self::keyword($form, 'rewrite_search_region', __('Search keyword region'));
        self::keyword($form, 'rewrite_search_city', __('Search keyword city'));
        self::keyword($form, 'rewrite_search_city_area', __('Search keyword city area'));
        self::keyword($form, 'rewrite_search_category', __('Search keyword category'));
        self::keyword($form, 'rewrite_search_user', __('Search keyword user'));
        self::keyword($form, 'rewrite_search_pattern', __('Search keyword pattern'));

        self::section($form, 'contact', __('Contact, feed &amp; language'));
        self::path($form, 'rewrite_contact', __('Contact'));
        self::path($form, 'rewrite_feed', __('Feed'));
        self::path($form, 'rewrite_language', __('Language'));

        self::section($form, 'item_actions', __('Listing actions'));
        self::path($form, 'rewrite_item_mark', __('Listing mark'));
        self::path($form, 'rewrite_item_send_friend', __('Listing send friend'));
        self::path($form, 'rewrite_item_contact', __('Listing contact'));
        self::path($form, 'rewrite_item_new', __('Listing new'));
        self::path($form, 'rewrite_item_activate', __('Listing activate'));
        self::path($form, 'rewrite_item_edit', __('Listing edit'));
        self::path($form, 'rewrite_item_delete', __('Listing delete'));
        self::path($form, 'rewrite_item_resource_delete', __('Listing resource delete'));

        self::section($form, 'user', __('User account'));
        self::path($form, 'rewrite_user_login', __('User login'));
        self::path($form, 'rewrite_user_dashboard', __('User dashboard'));
        self::path($form, 'rewrite_user_logout', __('User logout'));
        self::path($form, 'rewrite_user_register', __('User register'));
        self::path($form, 'rewrite_user_activate', __('User activate'));
        self::path($form, 'rewrite_user_activate_alert', __('User activate alert'));
        self::path($form, 'rewrite_user_profile', __('User profile'));
        self::path($form, 'rewrite_user_items', __('User listings'));
        self::path($form, 'rewrite_user_alerts', __('User alerts'));
        self::path($form, 'rewrite_user_recover', __('User recover'));
        self::path($form, 'rewrite_user_forgot', __('User forgot'));
        self::path($form, 'rewrite_user_change_password', __('User change password'));
        self::path($form, 'rewrite_user_change_email', __('User change email'));
        self::path($form, 'rewrite_user_change_email_confirm', __('User change email confirm'));
        self::path($form, 'rewrite_user_change_username', __('User change username'));

        $form
            ->custom('rules_close', static function () {
                echo '</details></div>';
            })
                ->set('row', false)
            ->custom('server_rules', static function (array $spec) {
                self::serverRules(!empty($spec['values'][self::MASTER]));
            })
                ->set('row', false)
            ->register();

        return self::PAGE_ID;
    }

    /**
     * What the view needs to draw the form.
     *
     * @param array<string,mixed>|null $values values a rejected save is handing back, or null
     *                                         for the stored ones
     *
     * @return array<string,mixed> view variables for osc_admin_settings_form()
     */
    public static function formVars(?array $values = null): array
    {
        return CoreSettings::vars(
            self::register(),
            'permalinks_post',
            $values,
            array('name' => 'settings_form')
        );
    }

    /**
     * The page's own effect: put the server's rewrite rules where the server will read
     * them, rebuild the cached rule table, and report which of the two happened.
     *
     * Runs only after a successful save, so the rules on disk always describe a structure
     * that is actually stored.
     *
     * @param array<string,mixed> $values the values that were written
     *
     * @return void
     */
    private static function apply(array $values): void
    {
        $file  = osc_base_path() . '.htaccess';
        $rules = osc_server_rewrite_rules();

        if (empty($values[self::MASTER])) {
            // BOOLEAN, as basic_data.sql declares it: the hand-written screen wrote the
            // column's type back to STRING every time friendly URLs were switched off.
            osc_set_preference('mod_rewrite_loaded', '0', 'osclass', 'BOOLEAN');
            self::reportDisabled($file, $rules);

            return;
        }

        osc_reset_preferences();

        // Rebuild the cached rule table from the just-saved permalink preferences.
        // buildRules() fires the before/after_rewrite_rules hooks and stamps the cache
        // version, so live requests pick the new structure up immediately.
        Rewrite::newInstance()->rebuildAndPersistRules();

        self::reportEnabled(self::writeRules($file, $rules), $file, $rules);
    }

    /**
     * Put the rules on disk and say how it went.
     *
     *  1. written
     *  2. written, but mod_rewrite could not be confirmed
     *  3. could not be written
     *  4. could not be written, and mod_rewrite could not be confirmed
     *  5. a file is already there and was left alone
     *  6. the same, with mod_rewrite unconfirmed
     *  7. nginx, which reads no such file at all
     *
     * @param string $file  the .htaccess path
     * @param string $rules the rules the server should be reading
     *
     * @return int one of the statuses above
     */
    private static function writeRules(string $file, string $rules): int
    {
        if (osc_server_is_nginx()) {
            // Writing .htaccess here would create a file nginx never reads, and mod_rewrite
            // is Apache's, so neither test says anything true.
            return 7;
        }

        $status = 3;
        if (file_exists($file)) {
            $status = 5;
        } elseif (is_writable(osc_base_path()) && file_put_contents($file, $rules)) {
            $status = 1;
        }

        if (!@\apache_mod_loaded('mod_rewrite')) {
            $status++;
        }

        return $status;
    }

    /**
     * Flash the message a writeRules() status calls for.
     *
     * @param int    $status a writeRules() status
     * @param string $file   the .htaccess path
     * @param string $rules  the rules the server should be reading
     *
     * @return void
     */
    private static function reportEnabled(int $status, string $file, string $rules): void
    {
        switch ($status) {
            case 1:
                osc_add_flash_ok_message(_m('Permalinks structure updated'), 'admin');
                break;
            case 2:
                $msg = _m('Permalinks structure updated.');
                $msg .= ' ';
                $msg .= _m("However, we can't check if Apache module <b>mod_rewrite</b> is loaded. If you experience some problems with the URLs, you should deactivate <em>Friendly URLs</em>");
                osc_add_flash_warning_message($msg, 'admin');
                break;
            case 3:
                $msg = _m("File <b>.htaccess</b> couldn't be filled out with the right content.");
                $msg .= ' ';
                $msg .= _m("Here's the content you have to add to the <b>.htaccess</b> file. If you can't create the file, please deactivate the <em>Friendly URLs</em> option.");
                $msg .= '</p><pre>' . htmlentities($rules, ENT_COMPAT, 'UTF-8') . '</pre><p>';
                osc_add_flash_error_message($msg, 'admin');
                break;
            case 4:
                $msg = _m("File <b>.htaccess</b> couldn't be filled out with the right content.");
                $msg .= ' ';
                $msg .= _m("Here's the content you have to add to the <b>.htaccess</b> file. If you can't create the file or experience some problems with the URLs, please deactivate the <em>Friendly URLs</em> option.");
                $msg .= '</p><pre>' . htmlentities($rules, ENT_COMPAT, 'UTF-8') . '</pre><p>';
                osc_add_flash_error_message($msg, 'admin');
                break;
            // 6 is 5 with mod_rewrite unverified, which is what a server that does not
            // expose its module list reports.
            case 5:
            case 6:
                if (file_exists($file) && file_get_contents($file) !== $rules) {
                    $msg = _m('File <b>.htaccess</b> already exists and was not modified.');
                    $msg .= ' ';
                    $msg .= _m("Here's the content you have to add to the <b>.htaccess</b> file. If you can't modify the file or experience some problems with the URLs, please deactivate the <em>Friendly URLs</em> option.");
                    $msg .= '</p><pre>' . htmlentities($rules, ENT_COMPAT, 'UTF-8') . '</pre><p>';
                    osc_add_flash_warning_message($msg, 'admin');
                    break;
                }
                osc_add_flash_ok_message(_m('Permalinks structure updated'), 'admin');
                break;
            case 7:
                // nginx. The rules the admin has to paste are on the screen they are being
                // sent back to, so point at them rather than repeating them into a flash.
                $msg = _m('Permalinks structure updated');
                $msg .= ' ';
                $msg .= _m('nginx does not read .htaccess: check the server rules below are in your nginx configuration, then reload nginx.');
                osc_add_flash_ok_message($msg, 'admin');
                break;
            default:
                // Every status writeRules() returns has a branch above; this only fires if
                // one is added without a message, which must not pass silently.
                osc_add_flash_ok_message(_m('Permalinks structure updated'), 'admin');
                break;
        }
    }

    /**
     * Take our own rules back off disk, and say so. A file somebody else edited is left
     * where it is: it may be carrying rules that have nothing to do with this.
     *
     * @param string $file  the .htaccess path
     * @param string $rules the rules a file of ours would be carrying
     *
     * @return void
     */
    private static function reportDisabled(string $file, string $rules): void
    {
        $deleted = true;
        $ours    = false;
        // On nginx the file is not ours and is not what routes requests, so deleting it
        // would only remove something another server might need.
        if (!osc_server_is_nginx() && file_exists($file)) {
            $ours    = file_get_contents($file) === $rules;
            $deleted = $ours ? @unlink($file) : false;
        }

        if ($deleted) {
            osc_add_flash_ok_message(_m('Friendly URLs successfully deactivated'), 'admin');
        } elseif ($ours) {
            osc_add_flash_warning_message(
                _m('Friendly URLs deactivated, but .htaccess file could not be deleted. Please, remove it manually'),
                'admin'
            );
        } else {
            osc_add_flash_warning_message(
                _m('Friendly URLs deactivated, but .htaccess file was modified outside Shopclass and was not deleted'),
                'admin'
            );
        }
    }

    /**
     * The rules block under the form, which is what an administrator copies when core
     * cannot write the file itself. It reads osc_server_rewrite_rules() -- the same string
     * the save writes and compares against -- so what is on screen is what the check wants.
     *
     * @param bool $enabled friendly URLs as the form currently shows them, which on a
     *                      rejected save is the submission rather than the stored value
     *
     * @return void
     */
    private static function serverRules(bool $enabled): void
    {
        if (!$enabled) {
            return;
        }

        $rules = osc_server_rewrite_rules();
        echo '<div class="server-config">';
        if (osc_server_is_nginx()) {
            osc_admin_form_section(__('Server rules (nginx)'), array('spaced' => true));
            echo '<p class="settings-lead">'
                 . osc_esc_html(__('nginx does not read .htaccess files. Add the '
                                   . 'block below to your site\'s nginx server configuration, then reload '
                                   . 'nginx.'))
                 . '</p><div class="server-config-grid"><div class="server-config-block"><pre>'
                 . htmlentities($rules, ENT_COMPAT, 'UTF-8')
                 . '</pre></div></div></div>';

            return;
        }

        $file   = osc_base_path() . '.htaccess';
        $exists = file_exists($file);
        osc_admin_form_section(__('Server rules (.htaccess)'), array('spaced' => true));
        echo '<div class="server-config-grid">';
        if ($exists) {
            echo '<div class="server-config-block"><h4>' . osc_esc_html(__('Your current .htaccess file')) . '</h4>'
                 . '<pre>' . htmlentities((string)file_get_contents($file), ENT_COMPAT, 'UTF-8') . '</pre></div>';
        }
        echo '<div class="server-config-block"><h4>'
             . osc_esc_html($exists ? __('What it should look like') : __('What your .htaccess file should look like'))
             . '</h4><pre>' . htmlentities($rules, ENT_COMPAT, 'UTF-8') . '</pre></div></div></div>';
    }

    /**
     * A heading inside the disclosure. Drawn as the screen has always drawn it, which is a
     * section rather than a second page head.
     *
     * @param \mindstellar\admin\ui\FormSpec $form
     * @param string                         $id     suffix of the custom field it is drawn by
     * @param string                         $title
     * @param bool                           $spaced
     *
     * @return void
     */
    private static function section(
        \mindstellar\admin\ui\FormSpec $form,
        string $id,
        string $title,
        bool $spaced = true
    ): void {
        $form
            ->custom('section_' . $id, static function () use ($title, $spaced) {
                osc_admin_form_section($title, $spaced ? array('spaced' => true) : array());
            })
            ->set('row', false);
    }

    /**
     * A structure fragment: collapsed slashes, no trailing one, and at least one letter or
     * digit left in it.
     *
     * @param \mindstellar\admin\ui\FormSpec $form
     * @param string                         $name
     * @param string                         $label
     * @param string                         $help
     *
     * @return \mindstellar\admin\ui\FormSpec the same builder, for chaining
     */
    private static function path(
        \mindstellar\admin\ui\FormSpec $form,
        string $name,
        string $label,
        string $help = ''
    ): \mindstellar\admin\ui\FormSpec {
        return self::keyword($form, $name, $label, $help)
            ->sanitize(static function ($value) {
                return self::normalise($value);
            });
    }

    /**
     * A structure fragment stored as typed -- the search keywords never took the slash pass.
     *
     * @param \mindstellar\admin\ui\FormSpec $form
     * @param string                         $name
     * @param string                         $label
     * @param string                         $help
     *
     * @return \mindstellar\admin\ui\FormSpec the same builder, for chaining
     */
    private static function keyword(
        \mindstellar\admin\ui\FormSpec $form,
        string $name,
        string $label,
        string $help = ''
    ): \mindstellar\admin\ui\FormSpec {
        return $form->text($name, $label, $help)
            ->width('key')
            ->required()
            ->dependsOn(self::MASTER)
            ->set('pattern', self::NOT_BLANK);
    }

    /**
     * One slash where two were typed, and none on the end.
     *
     * @param mixed $value
     *
     * @return string
     */
    private static function normalise($value): string
    {
        return substr(str_replace('//', '/', (string)$value . '/'), 0, -1);
    }
}
