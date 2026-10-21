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

declare(strict_types=1);

namespace mindstellar\mail;

use Admin;
use Item;
use mindstellar\billing\ListingCheckout;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\Receipts;
use mindstellar\boot\CoreServices;
use mindstellar\container\Container;
use mindstellar\currency\Money;
use mindstellar\event\DispatcherInterface;
use mindstellar\listing\ListingStatus;
use mindstellar\mail\event\MailComposing;
use mindstellar\mail\event\MailSent;
use OSCLocale;
use Page;
use Params;
use Session;
use User;
use View;

/**
 * The e-mails core sends. The older ones run on their hooks (emails.php keeps the fn_email_* names).
 * The shop mails (orders, product alerts) are sent by an instance that fires MailComposing and MailSent.
 */
final class CoreEmails
{
    private DispatcherInterface $events;

    /** Set by the order mails when a send fails, so the caller can try again later. */
    private static bool $orderMailFailed = false;

    public function __construct(?DispatcherInterface $events = null)
    {
        $this->events = $events ?? CoreServices::dispatcher();
    }

    private static function instance(): self
    {
        return Container::root()->get(self::class);
    }

    /**
     * Forget an earlier failed order mail, before firing a new one.
     *
     * @return void
     */
    public static function resetOrderMailFailure(): void
    {
        self::$orderMailFailed = false;
    }

    /**
     * Whether an order mail sent since resetOrderMailFailure() failed.
     *
     * @return bool
     */
    public static function orderMailFailed(): bool
    {
        return self::$orderMailFailed;
    }

    /**
     * Email the confirmation link for a newly created search alert.
     *
     * @param array<string,mixed> $alert  Alert row; only pk_i_id is read
     * @param string              $email  Subscriber address the alert was created with
     * @param string              $secret Alert secret used to build the activation link
     *
     * @return void
     */
    public static function alertValidation($alert, $email, $secret)
    {
        $user['s_name'] = '';

        // send alert validation email
        $prefLocale       = osc_language();
        $page             = Page::getInstance()->findByInternalName('email_alert_validation');
        $page_description = $page['locale'];

        $_title = osc_apply_filter(
            'email_title',
            osc_apply_filter(
                'email_alert_validation_title',
                $page_description[$prefLocale]['s_title'],
                $alert,
                $email,
                $secret
            )
        );
        $_body  = osc_apply_filter(
            'email_description',
            osc_apply_filter(
                'email_alert_validation_description',
                $page_description[$prefLocale]['s_text'],
                $alert,
                $email,
                $secret
            )
        );

        $validation_link = osc_user_activate_alert_url($alert['pk_i_id'], $secret, $email);
        $validation_link = '<a href ="' . $validation_link . '" target="_blank" >' . $validation_link . '</a>';

        $words   = array();
        $words[] = array(
            '{USER_NAME}',
            '{USER_EMAIL}',
            '{VALIDATION_LINK}'
        );
        $words[] = array(
            $user['s_name'],
            $email,
            $validation_link
        );

        $title = osc_apply_filter(
            'email_alert_validation_title_after',
            osc_mailBeauty($_title, $words),
            $alert,
            $email,
            $secret
        );
        $body  = osc_apply_filter(
            'email_alert_validation_description_after',
            osc_mailBeauty($_body, $words),
            $alert,
            $email,
            $secret
        );

        $emailParams = array(
            'from'     => self::fromAddress(),
            'to'       => $email,
            'to_name'  => $user['s_name'],
            'subject'  => $title,
            'body'     => $body,
            'secret_link' => true,
        );

        osc_sendMail($emailParams);
    }

    /**
     * The editable page a digest is written from, in the site's language.
     *
     * @param string $internalName alert_email_hourly or alert_email_weekly
     *
     * @return array<string,string> The s_title and s_text of that page
     */
    public static function alertTemplate($internalName)
    {
        $page = Page::getInstance()->findByInternalName($internalName);

        return $page['locale'][osc_language()];
    }

    /**
     * Who a digest goes to, and the placeholder values for their copy of it.
     *
     * osc_runAlert() has already looked the account up, so a registered subscriber
     * arrives as their user row. That row has no fk_i_user_id, and testing for one sent
     * every registered subscriber into the no-account branch, greeting them by address.
     * A plugin firing the hook may still hand over an alert row, so that case still
     * finds the account behind it.
     *
     * The subject is plain text and the body is HTML, so the words come twice: as
     * written for the subject, escaped for the body.
     *
     * @param array<string,mixed> $user     A user row, or an alert row
     * @param string              $ads      Rendered listings block
     * @param array<string,mixed> $s_search The alert, for its unsubscribe secret
     *
     * @return array{user:array<string,mixed>,words:array<int,array<int,string>>,htmlWords:array<int,array<int,string>>}
     */
    public static function alertRecipient($user, $ads, $s_search)
    {
        if (empty($user['s_name']) && !empty($user['fk_i_user_id'])) {
            $account = User::getInstance()->findByPrimaryKey($user['fk_i_user_id']);
            if (isset($account['s_email'])) {
                $user = $account;
            }
        }
        if (empty($user['s_name'])) {
            $user['s_name'] = $user['s_email'];
        }

        $unsub_link = osc_user_unsubscribe_alert_url(
            $s_search['pk_i_id'],
            $user['s_email'],
            $s_search['s_secret']
        );
        $unsub_link = '<a href="' . $unsub_link . '">' . __('unsubscribe alert') . '</a>';

        [$words, $htmlWords] = self::words(
            array(
                '{USER_NAME}'  => $user['s_name'],
                '{USER_EMAIL}' => $user['s_email'],
                '{ADS}'        => $ads,
                '{UNSUB_LINK}' => $unsub_link,
            ),
            array('{USER_NAME}', '{USER_EMAIL}')
        );

        return array(
            'user'      => $user,
            'words'     => $words,
            'htmlWords' => $htmlWords,
        );
    }

    /**
     * Send a finished digest. The plain-text part is the HTML one, as it always was.
     *
     * @param array<string,mixed> $user
     * @param string              $title
     * @param string              $body
     *
     * @return void
     */
    public static function alertDeliver($user, $title, $body)
    {
        osc_sendMail(array(
            'from'     => self::fromAddress(),
            'to'       => $user['s_email'],
            'to_name'  => $user['s_name'],
            'subject'  => $title,
            'body'     => $body,
        ));
    }

    /**
     * Email one subscriber the hourly digest of new listings matching their saved search.
     *
     * @deprecated 7.0.0 Core no longer sends hourly alerts; they go out daily.
     *
     * @param array<string,mixed> $user       Recipient; s_name, s_email and fk_i_user_id are read
     * @param string              $ads        Pre-rendered HTML list of the matching listings
     * @param array<string,mixed> $s_search   Saved-search row; pk_i_id and s_secret build the unsubscribe link
     * @param array<int,array<string,mixed>> $items New listings found since the last run
     * @param int                 $totalItems Total number of matches, which may exceed count($items)
     *
     * @return void
     */
    public static function alertHourly($user, $ads, $s_search, $items, $totalItems)
    {
        $template = self::alertTemplate('alert_email_hourly');

        $_title = osc_apply_filter(
            'email_title',
            osc_apply_filter(
                'alert_email_hourly_title',
                $template['s_title'],
                $user,
                $ads,
                $s_search,
                $items,
                $totalItems
            )
        );
        $_body  = osc_apply_filter(
            'email_description',
            osc_apply_filter(
                'alert_email_hourly_description',
                $template['s_text'],
                $user,
                $ads,
                $s_search,
                $items,
                $totalItems
            )
        );

        // The two filters above see the alert row; from here $user is the account it
        // belongs to, which is what the _after filters and the mail itself get.
        $recipient = self::alertRecipient($user, $ads, $s_search);
        $user      = $recipient['user'];
        $words     = $recipient['words'];
        $htmlWords = $recipient['htmlWords'];

        self::alertDeliver(
            $user,
            osc_apply_filter(
                'alert_email_hourly_title_after',
                osc_mailBeauty($_title, $words),
                $user,
                $ads,
                $s_search,
                $items,
                $totalItems
            ),
            osc_apply_filter(
                'alert_email_hourly_description_after',
                osc_mailBeauty($_body, $htmlWords),
                $user,
                $ads,
                $s_search,
                $items,
                $totalItems
            )
        );
    }

    /**
     * Email one subscriber the daily digest of new listings matching their saved search.
     *
     * @param array<string,mixed> $user       Recipient; s_name, s_email and fk_i_user_id are read
     * @param string              $ads        Pre-rendered HTML list of the matching listings
     * @param array<string,mixed> $s_search   Saved-search row; pk_i_id and s_secret build the unsubscribe link
     * @param array<int,array<string,mixed>> $items New listings found since the last run
     * @param int                 $totalItems Total number of matches, which may exceed count($items)
     *
     * @return void
     */
    public static function alertDaily($user, $ads, $s_search, $items, $totalItems)
    {
        $template = self::alertTemplate('alert_email_daily');

        $_title = osc_apply_filter(
            'email_title',
            osc_apply_filter(
                'alert_email_daily_title',
                $template['s_title'],
                $user,
                $ads,
                $s_search,
                $items,
                $totalItems
            )
        );
        $_body  = osc_apply_filter(
            'email_description',
            osc_apply_filter(
                'alert_email_daily_description',
                $template['s_text'],
                $user,
                $ads,
                $s_search,
                $items,
                $totalItems
            )
        );

        // The two filters above see the alert row; from here $user is the account it
        // belongs to, which is what the _after filters and the mail itself get.
        $recipient = self::alertRecipient($user, $ads, $s_search);
        $user      = $recipient['user'];
        $words     = $recipient['words'];
        $htmlWords = $recipient['htmlWords'];

        self::alertDeliver(
            $user,
            osc_apply_filter(
                'alert_email_daily_title_after',
                osc_mailBeauty($_title, $words),
                $user,
                $ads,
                $s_search,
                $items,
                $totalItems
            ),
            osc_apply_filter(
                'alert_email_daily_description_after',
                osc_mailBeauty($_body, $htmlWords),
                $user,
                $ads,
                $s_search,
                $items,
                $totalItems
            )
        );
    }

    /**
     * Email one subscriber the weekly digest of new listings matching their saved search.
     *
     * @param array<string,mixed> $user       Recipient; s_name, s_email and fk_i_user_id are read
     * @param string              $ads        Pre-rendered HTML list of the matching listings
     * @param array<string,mixed> $s_search   Saved-search row; pk_i_id and s_secret build the unsubscribe link
     * @param array<int,array<string,mixed>> $items New listings found since the last run
     * @param int                 $totalItems Total number of matches, which may exceed count($items)
     *
     * @return void
     */
    public static function alertWeekly($user, $ads, $s_search, $items, $totalItems)
    {
        $template = self::alertTemplate('alert_email_weekly');

        $_title = osc_apply_filter(
            'email_title',
            osc_apply_filter(
                'alert_email_weekly_title',
                $template['s_title'],
                $user,
                $ads,
                $s_search,
                $items,
                $totalItems
            )
        );
        $_body  = osc_apply_filter(
            'email_description',
            osc_apply_filter(
                'alert_email_weekly_description',
                $template['s_text'],
                $user,
                $ads,
                $s_search,
                $items,
                $totalItems
            )
        );

        // The two filters above see the alert row; from here $user is the account it
        // belongs to, which is what the _after filters and the mail itself get.
        $recipient = self::alertRecipient($user, $ads, $s_search);
        $user      = $recipient['user'];
        $words     = $recipient['words'];
        $htmlWords = $recipient['htmlWords'];

        self::alertDeliver(
            $user,
            osc_apply_filter(
                'alert_email_weekly_title_after',
                osc_mailBeauty($_title, $words),
                $user,
                $ads,
                $s_search,
                $items,
                $totalItems
            ),
            osc_apply_filter(
                'alert_email_weekly_description_after',
                osc_mailBeauty($_body, $htmlWords),
                $user,
                $ads,
                $s_search,
                $items,
                $totalItems
            )
        );
    }

    /**
     * Tell a comment author their comment has been approved.
     *
     * @param array<string,mixed> $aComment Comment row; s_author_name, s_author_email, s_title and s_body are read
     *
     * @return void
     */
    public static function commentValidated($aComment)
    {
        $mPages = new Page();
        $locale = osc_current_user_locale();
        $aPage  = $mPages->findByInternalName('email_comment_validated');

        if (isset($aPage['locale'][$locale]['s_title'])) {
            $content = $aPage['locale'][$locale];
        } else {
            $content = current($aPage['locale']);
        }

        if (null !== $content) {
            $words   = array();
            $words[] = array(
                '{COMMENT_AUTHOR}',
                '{COMMENT_EMAIL}',
                '{COMMENT_TITLE}',
                '{COMMENT_BODY}',
                '{ITEM_URL}',
                '{ITEM_LINK}',
                '{ITEM_TITLE}'
            );
            $words[] = array(
                $aComment['s_author_name'],
                $aComment['s_author_email'],
                $aComment['s_title'],
                $aComment['s_body'],
                osc_item_url(),
                '<a href="' . osc_item_url() . '">' . osc_item_url() . '</a>',
                osc_item_title()
            );

            $title = osc_apply_filter(
                'email_comment_validated_title_after',
                osc_mailBeauty(osc_apply_filter(
                    'email_title',
                    osc_apply_filter('email_comment_validated_title', $content['s_title'], $aComment)
                ), $words),
                $aComment
            );
            $body  = osc_apply_filter(
                'email_comment_validated_description_after',
                osc_mailBeauty(osc_apply_filter(
                    'email_description',
                    osc_apply_filter('email_comment_validated_description', $content['s_text'], $aComment)
                ), $words),
                $aComment
            );

            $emailParams = array(
                'from'     => self::fromAddress(),
                'to'       => $aComment['s_author_email'],
                'to_name'  => $aComment['s_author_name'],
                'subject'  => $title,
                'body'     => $body,
            );
            osc_sendMail($emailParams);
        }
    }

    /**
     * Confirm to a guest poster that their listing was published, with its edit link.
     *
     * @param array<string,mixed> $item Listing row; pk_i_id, s_secret, s_title and the contact fields are read
     *
     * @return void
     */
    public static function newItemNonRegisterUser($item)
    {
        $mPages = new Page();
        $aPage  = $mPages->findByInternalName('email_new_item_non_register_user');
        $locale = osc_current_user_locale();

        if (isset($aPage['locale'][$locale]['s_title'])) {
            $content = $aPage['locale'][$locale];
        } else {
            $content = current($aPage['locale']);
        }

        $item_url   = osc_item_url();
        $item_url   = '<a href="' . $item_url . '" >' . $item_url . '</a>';
        $edit_url   = osc_item_edit_url($item['s_secret'], $item['pk_i_id']);
        $delete_url = osc_item_delete_url($item['s_secret'], $item['pk_i_id']);

        $words   = array();
        $words[] = array(
            '{ITEM_ID}',
            '{USER_NAME}',
            '{USER_EMAIL}',
            '{ITEM_TITLE}',
            '{ITEM_URL}',
            '{ITEM_LINK}',
            '{EDIT_LINK}',
            '{EDIT_URL}',
            '{DELETE_LINK}',
            '{DELETE_URL}'
        );
        $words[] = array(
            $item['pk_i_id'],
            $item['s_contact_name'],
            $item['s_contact_email'],
            $item['s_title'],
            osc_item_url(),
            $item_url,
            '<a href="' . $edit_url . '">' . $edit_url . '</a>',
            $edit_url,
            '<a href="' . $delete_url . '">' . $delete_url . '</a>',
            $delete_url
        );

        $title = osc_apply_filter(
            'email_new_item_non_register_user_title_after',
            osc_mailBeauty(osc_apply_filter(
                'email_title',
                osc_apply_filter('email_new_item_non_register_user_title', $content['s_title'], $item)
            ), $words),
            $item
        );
        $body  = osc_apply_filter(
            'email_new_item_non_register_user_description_after',
            osc_mailBeauty(osc_apply_filter(
                'email_description',
                osc_apply_filter('email_new_item_non_register_user_description', $content['s_text'], $item)
            ), $words),
            $item
        );

        $emailParams = array(
            'from'     => self::fromAddress(),
            'to'       => $item['s_contact_email'],
            'to_name'  => $item['s_contact_name'],
            'subject'  => $title,
            'body'     => $body,
            'secret_link' => true,
        );

        osc_sendMail($emailParams);
    }

    /**
     * Email a user the link that lets them set a new password.
     *
     * @param array<string,mixed> $user         User row; s_name and s_email are read
     * @param string              $password_url Password-recovery URL
     *
     * @return void
     */
    public static function userForgotPassword($user, $password_url)
    {
        $aPage  = Page::getInstance()->findByInternalName('email_user_forgot_password');
        $locale = osc_current_user_locale();

        if (isset($aPage['locale'][$locale]['s_title'])) {
            $content = $aPage['locale'][$locale];
        } else {
            $content = current($aPage['locale']);
        }

        if (null !== $content) {
            $words   = array();
            $words[] = array(
                '{USER_NAME}',
                '{USER_EMAIL}',
                '{PASSWORD_LINK}',
                '{PASSWORD_URL}',
                '{DATE_TIME}'
            );
            $words[] = array(
                $user['s_name'],
                $user['s_email'],
                '<a href="' . $password_url . '">' . $password_url . '</a>',
                $password_url,
                date(osc_date_format() ?: 'Y-m-d') . ' ' . date(osc_time_format() ?: 'H:i:00')
            );

            $title = osc_apply_filter(
                'email_user_forgot_pass_word_title_after',
                osc_mailBeauty(
                    osc_apply_filter(
                        'email_title',
                        osc_apply_filter('email_user_forgot_pass_word_title', $content['s_title'], $user, $password_url)
                    ),
                    $words
                ),
                $user,
                $password_url
            );
            $body  = osc_apply_filter(
                'email_user_forgot_password_description_after',
                osc_mailBeauty(
                    osc_apply_filter(
                        'email_description',
                        osc_apply_filter(
                            'email_user_forgot_password_description',
                            $content['s_text'],
                            $user,
                            $password_url
                        )
                    ),
                    $words
                ),
                $user,
                $password_url
            );

            $emailParams = array(
                'from'     => self::fromAddress(),
                'to'       => $user['s_email'],
                'to_name'  => $user['s_name'],
                'subject'  => $title,
                'body'     => $body,
                'secret_link' => true,
            );

            osc_sendMail($emailParams);
        }
    }

    /**
     * Send the welcome email that follows a successful registration.
     *
     * @param array<string,mixed> $user User row; s_name and s_email are read
     *
     * @return void
     */
    public static function userRegistration($user)
    {
        $pageManager = new Page();
        $locale      = osc_current_user_locale();
        $aPage       = $pageManager->findByInternalName('email_user_registration');

        if (isset($aPage['locale'][$locale]['s_title'])) {
            $content = $aPage['locale'][$locale];
        } else {
            $content = current($aPage['locale']);
        }

        if (null !== $content) {
            $words   = array();
            $words[] = array(
                '{USER_NAME}',
                '{USER_EMAIL}'
            );
            $words[] = array(
                $user['s_name'],
                $user['s_email']
            );

            $title = osc_apply_filter(
                'email_user_registration_title_after',
                osc_mailBeauty(
                    osc_apply_filter(
                        'email_title',
                        osc_apply_filter('email_user_registration_title', $content['s_title'], $user)
                    ),
                    $words
                ),
                $user
            );
            $body  = osc_apply_filter(
                'email_user_registration_description_after',
                osc_mailBeauty(osc_apply_filter(
                    'email_description',
                    osc_apply_filter('email_user_registration_description', $content['s_text'], $user)
                ), $words),
                $user
            );

            $emailParams = array(
                'from'     => self::fromAddress(),
                'to'       => $user['s_email'],
                'to_name'  => $user['s_name'],
                'subject'  => $title,
                'body'     => $body,
            );

            osc_sendMail($emailParams);
        }
    }

    /**
     * Email the confirmation link to an address a user is changing to.
     *
     * @param string $new_email      Address awaiting confirmation
     * @param string $validation_url Link that confirms the change
     *
     * @return void
     */
    public static function newEmail($new_email, $validation_url)
    {
        $locale = osc_current_user_locale();
        $aPage  = Page::getInstance()->findByInternalName('email_new_email');

        if (isset($aPage['locale'][$locale]['s_title'])) {
            $content = $aPage['locale'][$locale];
        } else {
            $content = current($aPage['locale']);
        }

        if (null !== $content) {
            $words   = array();
            $words[] = array(
                '{USER_NAME}',
                '{USER_EMAIL}',
                '{VALIDATION_LINK}',
                '{VALIDATION_URL}'
            );
            $words[] = array(
                Session::getInstance()->_get('userName'),
                Params::getParam('new_email'),
                '<a href="' . $validation_url . '" >' . $validation_url . '</a>',
                $validation_url
            );

            $title = osc_apply_filter(
                'email_new_email_title_after',
                osc_mailBeauty(osc_apply_filter(
                    'email_title',
                    osc_apply_filter('email_new_email_title', $content['s_title'], $new_email, $validation_url)
                ), $words),
                $new_email,
                $validation_url
            );
            $body  = osc_apply_filter(
                'email_new_email_description_after',
                osc_mailBeauty(
                    osc_apply_filter(
                        'email_description',
                        osc_apply_filter('email_new_email_description', $content['s_text'], $new_email, $validation_url)
                    ),
                    $words
                ),
                $new_email,
                $validation_url
            );

            $emailParams = array(
                'from'     => self::fromAddress(),
                'to'       => $new_email,
                'to_name'  => Session::getInstance()->_get('userName'),
                'subject'  => $title,
                'body'     => $body,
                'secret_link' => true,
            );
            osc_sendMail($emailParams);
            osc_add_flash_ok_message(_m("We've sent you an e-mail. Follow its instructions to validate the changes"));
        } else {
            osc_add_flash_error_message(
                _m('We tried to sent you an e-mail, but it failed. Please, contact an administrator')
            );
        }
    }

    /**
     * Email a new account its activation link.
     *
     * @param array<string,mixed> $user  User row; pk_i_id, s_name and s_email are read
     * @param array<string,mixed> $input Insert payload the account was created from; s_secret is read
     *
     * @return void
     */
    public static function userValidation($user, $input)
    {
        $mPages = new Page();
        $locale = osc_current_user_locale();
        $aPage  = $mPages->findByInternalName('email_user_validation');

        if (isset($aPage['locale'][$locale]['s_title'])) {
            $content = $aPage['locale'][$locale];
        } else {
            $content = current($aPage['locale']);
        }

        if (null !== $content) {
            $validation_url = osc_user_activate_url($user['pk_i_id'], $input['s_secret']);
            $words          = array();
            $words[]        = array(
                '{USER_NAME}',
                '{USER_EMAIL}',
                '{VALIDATION_LINK}',
                '{VALIDATION_URL}'
            );
            $words[]        = array(
                $user['s_name'],
                $user['s_email'],
                '<a href="' . $validation_url . '" >' . $validation_url . '</a>',
                $validation_url
            );

            $title = osc_apply_filter(
                'email_user_validation_title_after',
                osc_mailBeauty(osc_apply_filter(
                    'email_title',
                    osc_apply_filter('email_user_validation_title', $content['s_title'], $user, $input)
                ), $words),
                $user,
                $input
            );
            $body  = osc_apply_filter(
                'email_user_validation_description_after',
                osc_mailBeauty(
                    osc_apply_filter(
                        'email_description',
                        osc_apply_filter('email_user_validation_description', $content['s_text'], $user, $input)
                    ),
                    $words
                ),
                $user,
                $input
            );

            $emailParams = array(
                'from'     => self::fromAddress(),
                'to'       => $user['s_email'],
                'to_name'  => $user['s_name'],
                'subject'  => $title,
                'body'     => $body,
                'secret_link' => true,
            );
            osc_sendMail($emailParams);
        }
    }

    /**
     * Forward a listing to the address given in the "send to a friend" form.
     *
     * @param array<string,mixed> $aItem Form payload: yourName, yourEmail, friendName, friendEmail, message, s_title
     *
     * @return void
     */
    public static function sendFriend($aItem)
    {
        $mPages = new Page();
        $aPage  = $mPages->findByInternalName('email_send_friend');
        $locale = osc_current_user_locale();

        if (isset($aPage['locale'][$locale]['s_title'])) {
            $content = $aPage['locale'][$locale];
        } else {
            $content = current($aPage['locale']);
        }

        $item_url = osc_item_url();
        $item_url = '<a href="' . $item_url . '" >' . $item_url . '</a>';

        [$titleWords, $words] = self::words(array(
            '{FRIEND_NAME}'  => $aItem['friendName'],
            '{USER_NAME}'    => $aItem['yourName'],
            '{USER_EMAIL}'   => $aItem['yourEmail'],
            '{FRIEND_EMAIL}' => $aItem['friendEmail'],
            '{ITEM_TITLE}'   => $aItem['s_title'],
            '{COMMENT}'      => $aItem['message'],
            '{ITEM_URL}'     => osc_item_url(),
            '{ITEM_LINK}'    => $item_url,
        ), array('{FRIEND_NAME}', '{USER_NAME}', '{USER_EMAIL}', '{FRIEND_EMAIL}', '{COMMENT}'));

        $title = osc_apply_filter('email_send_friend_title_after', osc_mailBeauty(osc_apply_filter(
            'email_title',
            osc_apply_filter('email_send_friend_title', $content['s_title'], $aItem)
        ), $titleWords), $aItem);
        $body  = osc_apply_filter(
            'email_send_friend_description_after',
            osc_mailBeauty(
                osc_apply_filter(
                    'email_description',
                    osc_apply_filter('email_send_friend_description', $content['s_text'], $aItem)
                ),
                $words
            ),
            $aItem
        );

        $emailParams = array(
            'from'      => self::fromAddress(),
            'from_name' => osc_page_title(),
            'to'        => $aItem['friendEmail'],
            'to_name'   => $aItem['friendName'],
            'reply_to'  => $aItem['yourEmail'],
            'subject'   => $title,
            'body'      => $body . \mindstellar\security\MessageGuard::reportFooter(
                (string) $aItem['yourEmail'],
                (string) $aItem['friendEmail']
            ),
        );

        if (osc_notify_contact_friends()) {
            $admins      = Admin::getInstance()->listAll();
            $adminEmails = array();
            foreach ($admins as $admin) {
                if (!empty($admin['s_email'])) {
                    $adminEmails[] = $admin['s_email'];
                }
            }
            $emailParams['add_bcc'] = $adminEmails;
        }

        osc_sendMail($emailParams);
    }

    /**
     * Placeholder lists for osc_mailBeauty(): one for the subject and one for the body. Text a
     * visitor typed is escaped in the body, with its line breaks kept, and has its tags removed in
     * the subject.
     *
     * @param array<string,mixed> $values  placeholder => value
     * @param string[]            $visitor the placeholders that hold what a visitor typed
     *
     * @return array{0:array<int,array<int,mixed>>,1:array<int,array<int,mixed>>} subject words, body words
     */
    public static function words(array $values, array $visitor): array
    {
        $title = $values;
        $body  = $values;
        foreach ($visitor as $key) {
            $title[$key] = strip_tags((string) $values[$key]);
            $body[$key]  = nl2br(osc_esc_html((string) $values[$key]));
        }

        return array(
            array(array_keys($title), array_values($title)),
            array(array_keys($body), array_values($body)),
        );
    }

    /**
     * Deliver a listing contact-form enquiry to the seller.
     *
     * @param array<string,mixed> $aItem Form payload: id, yourName, yourEmail, phoneNumber, message
     *
     * @return void
     */
    public static function itemInquiry($aItem)
    {
        $id          = $aItem['id'];
        $yourEmail   = $aItem['yourEmail'];
        $yourName    = $aItem['yourName'];
        $phoneNumber = $aItem['phoneNumber'];
        $message     = $aItem['message'];

        $path = null;
        $item = Item::getInstance()->findByPrimaryKey($id);
        View::getInstance()->_exportVariableToView('item', $item);

        $mPages = new Page();
        $aPage  = $mPages->findByInternalName('email_item_inquiry');
        $locale = osc_current_user_locale();

        if (isset($aPage['locale'][$locale]['s_title'])) {
            $content = $aPage['locale'][$locale];
        } else {
            $content = current($aPage['locale']);
        }

        $item_url  = osc_item_url();
        $item_link = '<a href="' . $item_url . '" >' . $item_url . '</a>';

        [$titleWords, $words] = self::words(array(
            '{CONTACT_NAME}' => $item['s_contact_name'],
            '{USER_NAME}'    => $yourName,
            '{USER_EMAIL}'   => $yourEmail,
            '{USER_PHONE}'   => $phoneNumber,
            '{ITEM_TITLE}'   => $item['s_title'],
            '{ITEM_URL}'     => $item_url,
            '{ITEM_LINK}'    => $item_link,
            '{COMMENT}'      => $message,
        ), array('{CONTACT_NAME}', '{USER_NAME}', '{USER_EMAIL}', '{USER_PHONE}', '{COMMENT}'));

        $title = osc_apply_filter('email_item_inquiry_title_after', osc_mailBeauty(osc_apply_filter(
            'email_title',
            osc_apply_filter('email_item_inquiry_title', $content['s_title'], $aItem)
        ), $titleWords), $aItem);
        $body  = osc_apply_filter(
            'email_item_inquiry_description_after',
            osc_mailBeauty(
                osc_apply_filter(
                    'email_description',
                    osc_apply_filter('email_item_inquiry_description', $content['s_text'], $aItem)
                ),
                $words
            ),
            $aItem
        );

        $emailParams = array(
            'from'      => self::fromAddress(),
            'from_name' => osc_page_title(),
            'to'        => $item['s_contact_email'],
            'to_name'   => $item['s_contact_name'],
            'reply_to'  => $yourEmail,
            'subject'   => $title,
            'body'      => $body . \mindstellar\security\MessageGuard::reportFooter(
                (string) $yourEmail,
                (string) $item['s_contact_email']
            ),
        );

        if (osc_notify_contact_item()) {
            $admins      = Admin::getInstance()->listAll();
            $adminEmails = array();
            foreach ($admins as $admin) {
                if (!empty($admin['s_email'])) {
                    $adminEmails[] = $admin['s_email'];
                }
            }
            $emailParams['add_bcc'] = $adminEmails;
        }

        if (osc_item_attachment()) {
            // CWebItem refuses a bad file before the mail is built.
            $attachment = osc_mail_upload_attachment('attachment');
            if (is_array($attachment)) {
                $emailParams['attachment'] = $attachment;
            }
        }

        osc_sendMail($emailParams);
    }

    /**
     * Notify every administrator that a comment was posted on a listing.
     *
     * @param array<string,mixed> $aItem Comment payload: id, title, body, authorName, authorEmail
     *
     * @return void
     */
    public static function newCommentAdmin($aItem)
    {
        $authorName  = trim(strip_tags((string) $aItem['authorName']));
        $authorEmail = trim(strip_tags((string) $aItem['authorEmail']));
        $body        = trim((string) $aItem['body']);
        // only \n -> <br/>
        $body   = nl2br(strip_tags($body));
        $title  = $aItem['title'];
        $itemId = $aItem['id'];

        $item = Item::getInstance()->findByPrimaryKey($itemId);
        View::getInstance()->_exportVariableToView('item', $item);
        $itemURL = osc_item_url();
        $itemURL = '<a href="' . $itemURL . '" >' . $itemURL . '</a>';

        $mPages = new Page();
        $aPage  = $mPages->findByInternalName('email_new_comment_admin');
        $locale = osc_current_user_locale();

        if (isset($aPage['locale'][$locale]['s_title'])) {
            $content = $aPage['locale'][$locale];
        } else {
            $content = current($aPage['locale']);
        }

        $words   = array();
        $words[] = array(
            '{COMMENT_AUTHOR}',
            '{COMMENT_EMAIL}',
            '{COMMENT_TITLE}',
            '{COMMENT_TEXT}',
            '{ITEM_TITLE}',
            '{ITEM_ID}',
            '{ITEM_URL}',
            '{ITEM_LINK}'
        );
        $words[] = array(
            $authorName,
            $authorEmail,
            $title,
            $body,
            $item['s_title'],
            $itemId,
            osc_item_url(),
            $itemURL
        );

        $title_email = osc_apply_filter(
            'email_new_comment_admin_title_after',
            osc_mailBeauty(
                osc_apply_filter(
                    'email_title',
                    osc_apply_filter('email_new_comment_admin_title', $content['s_title'], $aItem)
                ),
                $words
            ),
            $aItem
        );
        $body_email  = osc_apply_filter(
            'email_new_comment_admin_description_after',
            osc_mailBeauty(osc_apply_filter(
                'email_description',
                osc_apply_filter('email_new_comment_admin_description', $content['s_text'], $aItem)
            ), $words),
            $aItem
        );

        $admins = Admin::getInstance()->listAll();
        foreach ($admins as $admin) {
            if (!empty($admin['s_email'])) {
                $emailParams = array(
                    'from'     => self::fromAddress(),
                    'to'       => $admin['s_email'],
                    'to_name'  => __('Admin'),
                    'subject'  => $title_email,
                    'body'     => $body_email,
                );
                osc_sendMail($emailParams);
            }
        }
    }

    /**
     * Email a registered poster the activation link for their new listing.
     *
     * @param array<string,mixed> $item Listing row; pk_i_id, s_secret, locale and the contact fields are read
     *
     * @return void
     */
    public static function itemValidation($item)
    {
        View::getInstance()->_exportVariableToView('item', $item);
        $contactEmail = $item['s_contact_email'];
        $contactName  = $item['s_contact_name'];
        $mPages       = new Page();
        $locale       = osc_current_user_locale();
        $aPage        = $mPages->findByInternalName('email_item_validation');

        if (isset($aPage['locale'][$locale]['s_title'])) {
            $content = $aPage['locale'][$locale];
        } else {
            $content = current($aPage['locale']);
        }

        $item_url  = osc_item_url();
        $item_link = '<a href="' . $item_url . '" >' . $item_url . '</a>';

        $all = '';

        if (isset($item['locale'])) {
            foreach ($item['locale'] as $locale => $data) {
                $locale_name = OSCLocale::getInstance()->findByCode($locale);
                $all         .= '<br/>';
                if (isset($locale_name[0]) && isset($locale_name[0]['s_name'])) {
                    $all .= __('Language') . ': ' . $locale_name[0]['s_name'] . '<br/>';
                } else {
                    $all .= __('Language') . ': ' . $locale . '<br/>';
                }
                $all .= __('Title') . ': ' . $data['s_title'] . '<br/>';
                $all .= __('Description') . ': ' . $data['s_description'] . '<br/>';
                $all .= '<br/>';
            }
        } else {
            $all .= __('Title') . ': ' . $item['s_title'] . '<br/>';
            $all .= __('Description') . ': ' . $item['s_description'] . '<br/>';
        }

        // Format activation URL
        $validation_url = osc_item_activate_url($item['s_secret'], $item['pk_i_id']);

        $words   = array();
        $words[] = array(
            '{ITEM_DESCRIPTION_ALL_LANGUAGES}',
            '{ITEM_DESCRIPTION}',
            '{ITEM_COUNTRY}',
            '{ITEM_PRICE}',
            '{ITEM_REGION}',
            '{ITEM_CITY}',
            '{ITEM_ID}',
            '{USER_NAME}',
            '{USER_EMAIL}',
            '{ITEM_TITLE}',
            '{ITEM_URL}',
            '{ITEM_LINK}',
            '{VALIDATION_LINK}',
            '{VALIDATION_URL}'
        );
        $words[] = array(
            $all,
            $item['s_description'],
            $item['s_country'],
            osc_format_price($item['i_price']),
            $item['s_region'],
            $item['s_city'],
            $item['pk_i_id'],
            $item['s_contact_name'],
            $item['s_contact_email'],
            $item['s_title'],
            $item_url,
            $item_link,
            '<a href="' . $validation_url . '" >' . $validation_url . '</a>',
            $validation_url
        );

        $title = osc_apply_filter('email_item_validation_title_after', osc_mailBeauty(osc_apply_filter(
            'email_title',
            osc_apply_filter('email_item_validation_title', $content['s_title'], $item)
        ), $words), $item);
        $body  = osc_apply_filter(
            'email_item_validation_description_after',
            osc_mailBeauty(
                osc_apply_filter(
                    'email_description',
                    osc_apply_filter('email_item_validation_description', $content['s_text'], $item)
                ),
                $words
            ),
            $item
        );

        $emailParams = array(
            'from'     => self::fromAddress(),
            'to'       => $contactEmail,
            'to_name'  => $contactName,
            'subject'  => $title,
            'body'     => $body,
            'secret_link' => true,
        );
        osc_sendMail($emailParams);
    }

    /**
     * Notify every administrator that a listing was posted.
     *
     * @param array<string,mixed> $item Listing row; pk_i_id, s_secret, locale and the contact fields are read
     *
     * @return void
     */
    public static function adminNewItem($item)
    {
        View::getInstance()->_exportVariableToView('item', $item);
        $title  = osc_item_title();
        $mPages = new Page();
        $locale = osc_current_user_locale();
        $aPage  = $mPages->findByInternalName('email_admin_new_item');

        if (isset($aPage['locale'][$locale]['s_title'])) {
            $content = $aPage['locale'][$locale];
        } else {
            $content = current($aPage['locale']);
        }

        $item_url  = osc_item_url();
        $item_link = '<a href="' . $item_url . '" >' . $item_url . '</a>';

        $all = '';

        if (isset($item['locale'])) {
            foreach ($item['locale'] as $locale => $data) {
                $locale_name = OSCLocale::getInstance()->findByCode($locale);
                $all         .= '<br/>';
                if (isset($locale_name[0]) && isset($locale_name[0]['s_name'])) {
                    $all .= __('Language') . ': ' . $locale_name[0]['s_name'] . '<br/>';
                } else {
                    $all .= __('Language') . ': ' . $locale . '<br/>';
                }
                $all .= __('Title') . ': ' . $data['s_title'] . '<br/>';
                $all .= __('Description') . ': ' . $data['s_description'] . '<br/>';
                $all .= '<br/>';
            }
        } else {
            $all .= __('Title') . ': ' . $item['s_title'] . '<br/>';
            $all .= __('Description') . ': ' . $item['s_description'] . '<br/>';
        }

        // Format activation URL
        $validation_url = osc_item_activate_url($item['s_secret'], $item['pk_i_id']);

        // Format admin edit URL
        $admin_edit_url = osc_item_admin_edit_url($item['pk_i_id']);

        $words   = array();
        $words[] = array(
            '{EDIT_LINK}',
            '{EDIT_URL}',
            '{ITEM_DESCRIPTION_ALL_LANGUAGES}',
            '{ITEM_DESCRIPTION}',
            '{ITEM_COUNTRY}',
            '{ITEM_PRICE}',
            '{ITEM_REGION}',
            '{ITEM_CITY}',
            '{ITEM_ID}',
            '{USER_NAME}',
            '{USER_EMAIL}',
            '{ITEM_TITLE}',
            '{ITEM_URL}',
            '{ITEM_LINK}',
            '{VALIDATION_LINK}',
            '{VALIDATION_URL}'
        );
        $words[] = array(
            '<a href="' . $admin_edit_url . '" >' . $admin_edit_url . '</a>',
            $admin_edit_url,
            $all,
            $item['s_description'],
            $item['s_country'],
            osc_format_price($item['i_price']),
            $item['s_region'],
            $item['s_city'],
            $item['pk_i_id'],
            $item['s_contact_name'],
            $item['s_contact_email'],
            $item['s_title'],
            $item_url,
            $item_link,
            '<a href="' . $validation_url . '" >' . $validation_url . '</a>',
            $validation_url
        );

        $title = osc_apply_filter('email_admin_new_item_title_after', osc_mailBeauty(osc_apply_filter(
            'email_title',
            osc_apply_filter('email_admin_new_item_title', $content['s_title'], $item)
        ), $words), $item);
        $body  = osc_apply_filter(
            'email_admin_new_item_description_after',
            osc_mailBeauty(
                osc_apply_filter(
                    'email_description',
                    osc_apply_filter('email_admin_new_item_description', $content['s_text'], $item)
                ),
                $words
            ),
            $item
        );

        $admins = Admin::getInstance()->listAll();
        foreach ($admins as $admin) {
            if (!empty($admin['s_email'])) {
                $emailParams = array(
                    'from'     => self::fromAddress(),
                    'to'       => $admin['s_email'],
                    'to_name'  => __('Admin'),
                    'subject'  => $title,
                    'body'     => $body,
                    'secret_link' => true,
                );
                osc_sendMail($emailParams);
            }
        }
    }

    /**
     * Email a guest poster the activation link for their new listing.
     *
     * @param array<string,mixed> $item Listing row; pk_i_id, s_secret, locale and the contact fields are read
     *
     * @return void
     */
    public static function itemValidationNonRegisterUser($item)
    {
        View::getInstance()->_exportVariableToView('item', $item);

        $mPages = new Page();
        $aPage  = $mPages->findByInternalName('email_item_validation_non_register_user');
        $locale = osc_current_user_locale();

        if (isset($aPage['locale'][$locale]['s_title'])) {
            $content = $aPage['locale'][$locale];
        } else {
            $content = current($aPage['locale']);
        }

        $item_url   = osc_item_url();
        $item_link  = '<a href="' . $item_url . '" >' . $item_url . '</a>';
        $edit_url   = osc_item_edit_url($item['s_secret'], $item['pk_i_id']);
        $delete_url = osc_item_delete_url($item['s_secret'], $item['pk_i_id']);

        $all = '';

        if (isset($item['locale'])) {
            foreach ($item['locale'] as $locale => $data) {
                $locale_name = OSCLocale::getInstance()->findByCode($locale);
                $all         .= '<br/>';
                if (isset($locale_name[0]) && isset($locale_name[0]['s_name'])) {
                    $all .= __('Language') . ': ' . $locale_name[0]['s_name'] . '<br/>';
                } else {
                    $all .= __('Language') . ': ' . $locale . '<br/>';
                }
                $all .= __('Title') . ': ' . $data['s_title'] . '<br/>';
                $all .= __('Description') . ': ' . $data['s_description'] . '<br/>';
                $all .= '<br/>';
            }
        } else {
            $all .= __('Title') . ': ' . $item['s_title'] . '<br/>';
            $all .= __('Description') . ': ' . $item['s_description'] . '<br/>';
        }

        // Format activation URL
        $validation_url = osc_item_activate_url($item['s_secret'], $item['pk_i_id']);

        $words   = array();
        $words[] = array(
            '{ITEM_DESCRIPTION_ALL_LANGUAGES}',
            '{ITEM_DESCRIPTION}',
            '{ITEM_COUNTRY}',
            '{ITEM_PRICE}',
            '{ITEM_REGION}',
            '{ITEM_CITY}',
            '{ITEM_ID}',
            '{USER_NAME}',
            '{USER_EMAIL}',
            '{ITEM_TITLE}',
            '{ITEM_URL}',
            '{ITEM_LINK}',
            '{VALIDATION_LINK}',
            '{VALIDATION_URL}',
            '{EDIT_LINK}',
            '{EDIT_URL}',
            '{DELETE_LINK}',
            '{DELETE_URL}'
        );
        $words[] = array(
            $all,
            $item['s_description'],
            $item['s_country'],
            osc_format_price($item['i_price']),
            $item['s_region'],
            $item['s_city'],
            $item['pk_i_id'],
            $item['s_contact_name'],
            $item['s_contact_email'],
            $item['s_title'],
            $item_url,
            $item_link,
            '<a href="' . $validation_url . '" >' . $validation_url . '</a>',
            $validation_url,
            '<a href="' . $edit_url . '">' . $edit_url . '</a>',
            $edit_url,
            '<a href="' . $delete_url . '">' . $delete_url . '</a>',
            $delete_url
        );

        $title = osc_apply_filter(
            'email_item_validation_non_register_user_title_after',
            osc_mailBeauty(
                osc_apply_filter(
                    'email_title',
                    osc_apply_filter('email_item_validation_non_register_user_title', $content['s_title'], $item)
                ),
                $words
            ),
            $item
        );
        $body  = osc_apply_filter(
            'email_item_validation_non_register_user_description_after',
            osc_mailBeauty(
                osc_apply_filter(
                    'email_description',
                    osc_apply_filter('email_item_validation_non_register_user_description', $content['s_text'], $item)
                ),
                $words
            ),
            $item
        );

        $emailParams = array(
            'from'     => self::fromAddress(),
            'to'       => $item['s_contact_email'],
            'to_name'  => $item['s_contact_name'],
            'subject'  => $title,
            'body'     => $body,
            'secret_link' => true,
        );

        osc_sendMail($emailParams);
    }

    /**
     * Notify every administrator that a user account was created.
     *
     * @param array<string,mixed> $user User row; s_name and s_email are read
     *
     * @return void
     */
    public static function adminNewUser($user)
    {
        $pageManager = new Page();
        $locale      = osc_current_user_locale();
        $aPage       = $pageManager->findByInternalName('email_admin_new_user');

        if (isset($aPage['locale'][$locale]['s_title'])) {
            $content = $aPage['locale'][$locale];
        } else {
            $content = current($aPage['locale']);
        }

        if (null !== $content) {
            $words   = array();
            $words[] = array(
                '{USER_NAME}',
                '{USER_EMAIL}'
            );
            $words[] = array(
                $user['s_name'],
                $user['s_email']
            );

            $title = osc_apply_filter(
                'email_admin_user_registration_title_after',
                osc_mailBeauty(osc_apply_filter(
                    'email_title',
                    osc_apply_filter('email_admin_user_registration_title', $content['s_title'], $user)
                ), $words),
                $user
            );
            $body  = osc_apply_filter(
                'email_admin_user_regsitration_description_after',
                osc_mailBeauty(
                    osc_apply_filter(
                        'email_description',
                        osc_apply_filter('email_admin_user_regsitration_description', $content['s_text'], $user)
                    ),
                    $words
                ),
                $user
            );

            $admins = Admin::getInstance()->listAll();
            foreach ($admins as $admin) {
                if (!empty($admin['s_email'])) {
                    $emailParams = array(
                        'from'     => self::fromAddress(),
                        'to'       => $admin['s_email'],
                        'to_name'  => osc_page_title(),
                        'subject'  => $title,
                        'body'     => $body,
                    );
                    osc_sendMail($emailParams);
                }
            }
        }
    }

    /**
     * Deliver a message sent from a user's public profile contact form.
     *
     * @param int|string $id          Id of the user being contacted, as supplied by the request
     * @param string     $yourEmail   Sender address
     * @param string     $yourName    Sender name
     * @param string     $phoneNumber Sender phone number
     * @param string     $message     Message body
     *
     * @return void
     */
    public static function contactUser($id, $yourEmail, $yourName, $phoneNumber, $message)
    {
        $mPages = new Page();
        $aPage  = $mPages->findByInternalName('email_contact_user');
        $locale = osc_current_user_locale();

        if (isset($aPage['locale'][$locale]['s_title'])) {
            $content = $aPage['locale'][$locale];
        } else {
            $content = current($aPage['locale']);
        }

        [$titleWords, $words] = self::words(array(
            '{CONTACT_NAME}' => osc_user_name(),
            '{USER_NAME}'    => $yourName,
            '{USER_EMAIL}'   => $yourEmail,
            '{USER_PHONE}'   => $phoneNumber,
            '{COMMENT}'      => $message,
        ), array('{CONTACT_NAME}', '{USER_NAME}', '{USER_EMAIL}', '{USER_PHONE}', '{COMMENT}'));

        $title = osc_apply_filter('email_item_inquiry_title_after', osc_mailBeauty(osc_apply_filter(
            'email_title',
            osc_apply_filter(
                'email_item_inquiry_title',
                $content['s_title'],
                $id,
                $yourEmail,
                $yourName,
                $phoneNumber,
                $message
            )
        ), $titleWords), $id, $yourEmail, $yourName, $phoneNumber, $message);
        $body  = osc_apply_filter(
            'email_item_inquiry_description_after',
            osc_mailBeauty(
                osc_apply_filter(
                    'email_description',
                    osc_apply_filter(
                        'email_item_inquiry_description',
                        $content['s_text'],
                        $id,
                        $yourEmail,
                        $yourName,
                        $phoneNumber,
                        $message
                    )
                ),
                $words
            ),
            $id,
            $yourEmail,
            $yourName,
            $phoneNumber,
            $message
        );

        $emailParams = array(
            'from'     => self::fromAddress(),
            'to'       => osc_user_email(),
            'to_name'  => osc_user_name(),
            'reply_to' => $yourEmail,
            'subject'  => $title,
            'body'     => $body . \mindstellar\security\MessageGuard::reportFooter((string) $yourEmail, (string) osc_user_email()),
        );

        if (osc_notify_contact_item()) {
            $admins      = Admin::getInstance()->listAll();
            $adminEmails = array();
            foreach ($admins as $admin) {
                if (!empty($admin['s_email'])) {
                    $adminEmails[] = $admin['s_email'];
                }
            }
            $emailParams['add_bcc'] = $adminEmails;
        }

        osc_sendMail($emailParams);
    }

    /**
     * Notify a listing's owner that a comment was posted on it.
     *
     * @param array<string,mixed> $aItem Comment payload: id, title, body, authorName, authorEmail
     *
     * @return void
     */
    public static function newCommentUser($aItem)
    {
        $authorName  = trim(strip_tags((string) $aItem['authorName']));
        $authorEmail = trim(strip_tags((string) $aItem['authorEmail']));
        $body        = trim(strip_tags((string) $aItem['body']));
        $body        = nl2br($body);
        $title       = $aItem['title'];
        $itemId      = $aItem['id'];

        $item = Item::getInstance()->findByPrimaryKey($itemId);
        View::getInstance()->_exportVariableToView('item', $item);
        $itemURL = osc_item_url();
        $itemURL = '<a href="' . $itemURL . '" >' . $itemURL . '</a>';

        $mPages = new Page();
        $aPage  = $mPages->findByInternalName('email_new_comment_user');
        $locale = osc_current_user_locale();

        if (isset($aPage['locale'][$locale]['s_title'])) {
            $content = $aPage['locale'][$locale];
        } else {
            $content = current($aPage['locale']);
        }

        $words   = array();
        $words[] = array(
            '{COMMENT_AUTHOR}',
            '{COMMENT_EMAIL}',
            '{COMMENT_TITLE}',
            '{COMMENT_TEXT}',
            '{ITEM_TITLE}',
            '{ITEM_ID}',
            '{ITEM_URL}',
            '{ITEM_LINK}',
            '{SELLER_NAME}',
            '{SELLER_EMAIL}'
        );
        $words[] = array(
            $authorName,
            $authorEmail,
            $title,
            $body,
            $item['s_title'],
            $itemId,
            osc_item_url(),
            $itemURL,
            $item['s_contact_name'],
            $item['s_contact_email']
        );

        $title_email = osc_apply_filter(
            'email_new_comment_user_title_after',
            osc_mailBeauty(
                osc_apply_filter(
                    'email_title',
                    osc_apply_filter('email_new_comment_user_title', $content['s_title'], $aItem)
                ),
                $words
            ),
            $aItem
        );
        $body_email  = osc_apply_filter(
            'email_new_comment_user_description_after',
            osc_mailBeauty(
                osc_apply_filter(
                    'email_description',
                    osc_apply_filter('email_new_comment_user_description', $content['s_text'], $aItem)
                ),
                $words
            ),
            $aItem
        );

        $emailParams = array(
            'from'     => self::fromAddress(),
            'to'       => $item['s_contact_email'],
            'to_name'  => $item['s_contact_name'],
            'subject'  => $title_email,
            'body'     => $body_email,
        );
        osc_sendMail($emailParams);
    }

    /**
     * Email a newly created administrator their account credentials.
     *
     * @param array<string,mixed> $data New admin row: s_name, s_username, s_password, s_email
     *
     * @return void
     */
    public static function newAdmin($data)
    {

        $name     = trim(strip_tags((string) $data['s_name']));
        $username = trim(strip_tags((string) $data['s_username']));

        $mPages = new Page();
        $aPage  = $mPages->findByInternalName('email_new_admin');
        $locale = osc_current_user_locale();

        if (isset($aPage['locale'][$locale]['s_title'])) {
            $content = $aPage['locale'][$locale];
        } else {
            $content = current($aPage['locale']);
        }

        $words   = array();
        $words[] = array(
            '{ADMIN_NAME}',
            '{USERNAME}',
            '{PASSWORD}',
            '{WEB_ADMIN_LINK}'
        );
        $words[] = array(
            $name,
            $username,
            $data['s_password'],
            '<a href="' . osc_admin_base_url() . '">' . osc_page_title() . '</a>',
        );

        $title_email = osc_apply_filter('email_new_admin_title_after', osc_mailBeauty(osc_apply_filter(
            'email_title',
            osc_apply_filter('email_new_admin_title', $content['s_title'], $data)
        ), $words), $data);
        $body_email  = osc_apply_filter(
            'email_new_admin_description_after',
            osc_mailBeauty(
                osc_apply_filter(
                    'email_description',
                    osc_apply_filter('email_new_admin_description', $content['s_text'], $data)
                ),
                $words
            ),
            $data
        );

        $emailParams = array(
            'from'     => self::fromAddress(),
            'to'       => $data['s_email'],
            'to_name'  => $data['s_name'],
            'subject'  => $title_email,
            'body'     => $body_email,
        );
        osc_sendMail($emailParams);
    }

    /**
     * Warn a listing's contact that the listing is about to expire.
     *
     * @param array<string,mixed> $aItem Listing row; pk_i_id, s_title, dt_expiration and the contact fields are read
     *
     * @return void
     */
    public static function warnExpiration($aItem)
    {

        $itemId = $aItem['pk_i_id'];

        View::getInstance()->_exportVariableToView('item', $aItem);
        $itemURL = osc_item_url();
        $itemURL = '<a href="' . $itemURL . '" >' . $itemURL . '</a>';

        $mPages = new Page();
        $aPage  = $mPages->findByInternalName('email_warn_expiration');
        $locale = osc_current_user_locale();

        if (isset($aPage['locale'][$locale]['s_title'])) {
            $content = $aPage['locale'][$locale];
        } else {
            $content = current($aPage['locale']);
        }

        $words       = array();
        $words[]     = array(
            '{USER_NAME}',
            '{ITEM_TITLE}',
            '{ITEM_ID}',
            '{ITEM_EXPIRATION_DATE}',
            '{ITEM_URL}',
            '{ITEM_LINK}',
            '{SELLER_NAME}',
            '{SELLER_EMAIL}',
            '{CONTACT_NAME}',
            '{CONTACT_EMAIL}'
        );
        $words[]     = array(
            $aItem['s_contact_name'],
            $aItem['s_title'],
            $itemId,
            $aItem['dt_expiration'],
            osc_item_url(),
            $itemURL,
            $aItem['s_contact_name'],
            $aItem['s_contact_email'],
            $aItem['s_contact_name'],
            $aItem['s_contact_email']
        );
        $title_email = osc_apply_filter(
            'email_warn_expiration_title_after',
            osc_mailBeauty(
                osc_apply_filter(
                    'email_title',
                    osc_apply_filter('email_warn_expiration_title', $content['s_title'], $aItem)
                ),
                $words
            ),
            $aItem
        );
        $body_email  = osc_apply_filter(
            'email_warn_expiration_description_after',
            osc_mailBeauty(
                osc_apply_filter(
                    'email_description',
                    osc_apply_filter('email_warn_expiration_description', $content['s_text'], $aItem)
                ),
                $words
            ),
            $aItem
        );

        $emailParams = array(
            'from'     => self::fromAddress(),
            'to'       => $aItem['s_contact_email'],
            'to_name'  => $aItem['s_contact_name'],
            'subject'  => $title_email,
            'body'     => $body_email,
        );
        osc_sendMail($emailParams);
    }

    /**
     * Remind the owner of a listing waiting for payment to pay before it is deleted.
     *
     * @param array<string,mixed> $item the held listing row; only its id is read
     *
     * @return void
     */
    public static function listingUnpaidReminder($item)
    {
        self::instance()->mailListingUnpaidReminder($item);
    }

    /**
     * Tell a product alert's owner the listing is back in stock, or its price went down.
     *
     * @param array<string,mixed> $alert t_alerts row; fk_i_user_id and s_email are read
     * @param array<string,mixed> $item  the listing row, with its title
     * @param string              $kind  stock or price
     * @param int|null            $price for a price alert, the new price in micros
     *
     * @return void
     */
    public static function productAlert($alert, $item, $kind, $price = null)
    {
        self::instance()->mailProductAlert($alert, $item, (string) $kind, $price);
    }

    /**
     * Tell the buyer a goods order that waits for payment (bank transfer, cash on delivery) was received.
     *
     * @param Order $order
     *
     * @return void
     */
    public static function orderPlaced($order)
    {
        self::instance()->mailOrder('order_placed', $order);
    }

    /**
     * Tell the buyer their order is on its way, with the tracking link when there is a safe one.
     *
     * @param Order $order
     *
     * @return void
     */
    public static function orderShipped($order)
    {
        self::instance()->mailOrder('order_shipped', $order);
    }

    /**
     * Tell the buyer their order arrived, with a link to rate each product.
     *
     * @param Order $order
     *
     * @return void
     */
    public static function orderDelivered($order)
    {
        self::instance()->mailOrder('order_delivered', $order);
    }

    /**
     * Tell the buyer their order was cancelled and what happens to a payment.
     *
     * @param Order $order
     *
     * @return void
     */
    public static function orderCancelled($order)
    {
        self::instance()->mailOrder('order_cancelled', $order);
    }

    /**
     * Tell the buyer their order was refunded: how much, and how.
     *
     * @param Order $order
     *
     * @return void
     */
    public static function orderRefunded($order)
    {
        self::instance()->mailOrder('order_refunded', $order);
    }

    /**
     * Tell the shop owner a goods order came in that is paid or will be paid on delivery.
     *
     * @param Order $order
     *
     * @return void
     */
    public static function adminNewOrder($order)
    {
        self::instance()->mailOrder('admin_new_order', $order);
    }

    /**
     * @param array<string,mixed> $alert
     * @param array<string,mixed> $item
     */
    public function mailProductAlert(array $alert, array $item, string $kind, ?int $price = null): void
    {
        $user = !empty($alert['fk_i_user_id']) ? User::getInstance()->findByPrimaryKey((int) $alert['fk_i_user_id']) : false;
        $to   = is_array($user) && !empty($user['s_email']) ? (string) $user['s_email'] : (string) ($alert['s_email'] ?? '');
        $name = is_array($user) && !empty($user['s_name']) ? (string) $user['s_name'] : $to;
        if ($to === '') {
            return;
        }

        View::getInstance()->_exportVariableToView('item', $item);
        $url = osc_item_url();
        if ($kind === 'price') {
            $title = __('Price drop: {ITEM_TITLE}');
            $body  = __('<p>Hi {USER_NAME},</p><p>{ITEM_TITLE} now sells for {ITEM_PRICE}.</p><p>{ITEM_LINK}</p>');
        } else {
            $title = __('Back in stock: {ITEM_TITLE}');
            $body  = __('<p>Hi {USER_NAME},</p><p>{ITEM_TITLE} is back in stock.</p><p>{ITEM_LINK}</p>');
        }
        $body .= __('<p>You asked {WEB_TITLE} to tell you once; this alert is now off.</p>');

        [$words, $htmlWords] = self::words(
            array(
                '{USER_NAME}'  => $name,
                '{ITEM_TITLE}' => (string) ($item['s_title'] ?? ''),
                '{ITEM_PRICE}' => $price === null ? '' : osc_format_price($price),
                '{ITEM_URL}'   => $url,
                '{ITEM_LINK}'  => '<a href="' . osc_esc_html($url) . '">' . osc_esc_html($url) . '</a>',
            ),
            array('{USER_NAME}', '{ITEM_TITLE}', '{ITEM_PRICE}')
        );
        $this->deliver(new MailComposing('product_alert', $to, $name, $title, $body, $words, $htmlWords, null, $alert, $item, array($alert, $item, $kind)));
    }

    /**
     * @param array<string,mixed> $item
     */
    public function mailListingUnpaidReminder(array $item): void
    {
        $itemId = (int) ($item['pk_i_id'] ?? 0);
        // Read fresh: a listing paid or deleted since the caller read it gets no reminder.
        $item = $itemId > 0 ? Item::getInstance()->findByPrimaryKey($itemId) : null;
        if (!is_array($item) || !ListingStatus::isHeld($item)) {
            return;
        }
        $ownerId = (int) ($item['fk_i_user_id'] ?? 0);
        $user    = $ownerId > 0 ? User::getInstance()->findByPrimaryKey($ownerId) : false;
        if (!is_array($user) || empty($user['s_email'])) {
            return;
        }
        $to   = (string) $user['s_email'];
        $name = (string) ($user['s_name'] ?? '');

        $page    = (new Page())->findByInternalName('email_listing_unpaid_reminder');
        $locale  = osc_current_user_locale();
        $content = null;
        if (is_array($page) && !empty($page['locale'])) {
            $content = isset($page['locale'][$locale]['s_title']) ? $page['locale'][$locale] : current($page['locale']);
        }
        $title = is_array($content) ? (string) $content['s_title'] : __('{WEB_TITLE} - Pay to publish your listing');
        $body  = is_array($content)
            ? (string) $content['s_text']
            : __('<p>Hi {USER_NAME},</p><p>Your listing <a href="{PAY_URL}">{ITEM_TITLE}</a> is saved but not published. It costs {CREDITS} credits to publish.</p><p>Pay for it by {DEADLINE} or it is deleted: {PAY_LINK}</p><p>Regards,</p><p>{WEB_LINK}</p>');

        $payUrl = osc_billing_publish_url($itemId);
        $quote  = ListingCheckout::quote($itemId, $ownerId);

        [$words, $htmlWords] = self::words(
            array(
                '{USER_NAME}'  => $name !== '' ? $name : $to,
                '{ITEM_TITLE}' => (string) ($item['s_title'] ?? ''),
                '{ITEM_ID}'    => (string) $itemId,
                '{CREDITS}'    => (string) $quote['credits'],
                '{DEADLINE}'   => osc_esc_html((string) osc_format_date((string) ($item['dt_expiration'] ?? ''))),
                '{PAY_URL}'    => osc_esc_html($payUrl),
                '{PAY_LINK}'   => '<a href="' . osc_esc_html($payUrl) . '">' . osc_esc_html($payUrl) . '</a>',
            ),
            array('{USER_NAME}', '{ITEM_TITLE}')
        );
        $this->deliver(new MailComposing('listing_unpaid_reminder', $to, $name, $title, $body, $words, $htmlWords, null, null, $item, array($item)));
    }

    public function mailOrder(string $kind, Order $order): void
    {
        $extra = array();
        if ($kind === 'order_delivered') {
            $rate = '';
            $seen = array();
            foreach (OrderLineStore::ofOrder($order->getId()) as $line) {
                $itemId = (int) ($line['item_id'] ?? 0);
                if ($line['kind'] !== OrderLineStore::KIND_ITEM || $itemId < 1 || isset($seen[$itemId])) {
                    continue;
                }
                $seen[$itemId] = true;
                $rate .= '<li>' . osc_esc_html((string) $line['title']) . ' - <a href="' . osc_esc_html(osc_item_url_ns($itemId)) . '">'
                    . osc_esc_html(__('Rate it')) . '</a></li>';
            }
            $extra = array('{ORDER_PRODUCTS}' => $rate === '' ? '' : '<ul>' . $rate . '</ul>');
        } elseif ($kind === 'order_refunded') {
            $extra = array('{REFUND_AMOUNT}' => osc_esc_html(Money::format($order->getAmount(), $order->getCurrency())));
        }
        $mail = self::orderMail($order, $kind === 'admin_new_order', $extra);
        if ($mail === null) {
            return;
        }
        [$title, $body] = self::orderTemplates($kind);
        $this->deliver(new MailComposing($kind, $mail['to'], $mail['toName'], $title, $body, $mail['words'], $mail['htmlWords'], $order, null, null, array($order)));
    }

    /**
     * The subject and body templates of an order mail.
     *
     * @return array{0:string,1:string}
     */
    private static function orderTemplates(string $kind): array
    {
        switch ($kind) {
            case 'order_placed':
                return array(
                    __('We received your order {ORDER_NUMBER}'),
                    __('<p>Hi {USER_NAME},</p><p>Thank you for your order from {WEB_TITLE}. We have it and will get it ready once your payment ({PAYMENT_METHOD}) is in.</p>{ORDER_LINES}{ORDER_ADDRESS}<p>{ORDER_LINK}</p>'),
                );
            case 'order_shipped':
                return array(
                    __('Your order {ORDER_NUMBER} has shipped'),
                    __('<p>Hi {USER_NAME},</p><p>Your order {ORDER_NUMBER} from {WEB_TITLE} is on its way.</p>{ORDER_TRACKING}{ORDER_ADDRESS}<p>{ORDER_LINK}</p>'),
                );
            case 'order_delivered':
                return array(
                    __('Your order {ORDER_NUMBER} was delivered'),
                    __('<p>Hi {USER_NAME},</p><p>Your order {ORDER_NUMBER} from {WEB_TITLE} was delivered. We hope you enjoy it.</p>{ORDER_PRODUCTS}<p>{ORDER_LINK}</p>'),
                );
            case 'order_cancelled':
                return array(
                    __('Your order {ORDER_NUMBER} was cancelled'),
                    __('<p>Hi {USER_NAME},</p><p>Your order {ORDER_NUMBER} from {WEB_TITLE} was cancelled. If you paid for it, you are not charged: any amount taken is returned to you.</p>{ORDER_LINES}<p>{ORDER_LINK}</p>'),
                );
            case 'order_refunded':
                return array(
                    __('Your order {ORDER_NUMBER} was refunded'),
                    __('<p>Hi {USER_NAME},</p><p>We refunded {REFUND_AMOUNT} for order {ORDER_NUMBER} to your original payment method ({PAYMENT_METHOD}). It can take 3-5 working days to show up.</p>{ORDER_LINES}<p>{ORDER_LINK}</p>'),
                );
            default:
                return array(
                    __('New order {ORDER_NUMBER} on {WEB_TITLE}'),
                    __('<p>Order {ORDER_NUMBER} came in from {USER_NAME} ({USER_EMAIL}). Payment: {PAYMENT_METHOD}.</p>{ORDER_LINES}{ORDER_ADDRESS}<p>{ORDER_LINK}</p>'),
                );
        }
    }

    /**
     * Let listeners shape the mail, send it unless one suppressed it, and announce the result. A
     * failed order mail is flagged for the caller.
     */
    private function deliver(MailComposing $composing): void
    {
        $composing = $this->events->dispatch($composing);
        if ($composing->isSuppressed()) {
            return;
        }
        $mail = $composing->render();
        $sent = (bool) osc_sendMail(array(
            'from'    => self::fromAddress(),
            'to'      => $composing->recipient(),
            'to_name' => $composing->recipientName(),
            'subject' => $mail['subject'],
            'body'    => $mail['body'],
        ));
        if (!$sent && $composing->order() !== null) {
            self::$orderMailFailed = true;
        }
        $this->events->dispatch(new MailSent($composing->kind(), $composing->order(), $composing->recipient(), $mail['subject'], $mail['body'], $sent));
    }

    /**
     * The recipient and the placeholder words of an order mail. Every value that came from a
     * visitor or a seller is escaped.
     *
     * @param Order $order
     * @param bool  $toAdmin Send to the shop's contact address instead of the buyer
     * @param array<string,string> $extra More placeholders, already escaped HTML
     *
     * @return array<string,mixed>|null null when there is nobody to send to
     */
    private static function orderMail(Order $order, bool $toAdmin, array $extra = array()): ?array
    {
        $user  = User::getInstance()->findByPrimaryKey($order->getUserId());
        $buyer = $order->meta('_buyer');
        $buyer = is_array($buyer) ? $buyer : array();
        $email = (string) ($buyer['email'] ?? (is_array($user) ? ($user['s_email'] ?? '') : ''));
        $uname = (string) ($buyer['name'] ?? (is_array($user) ? ($user['s_name'] ?? '') : ''));
        $to    = $toAdmin ? (string) osc_contact_email() : $email;
        if ($to === '') {
            return null;
        }

        $details  = Receipts::details($order);
        $url      = $toAdmin
            ? osc_admin_base_url(true) . '?page=billing&action=order&id=' . $order->getId()
            : osc_billing_orders_url();
        $tracking = '';
        if ($details['carrier'] !== '' || $details['tracking'] !== '' || $details['tracking_url'] !== '') {
            $tracking = '<p>'
                . ($details['carrier'] !== '' ? osc_esc_html(sprintf(__('Carrier: %s'), $details['carrier'])) . '<br>' : '')
                . ($details['tracking'] !== '' ? osc_esc_html(sprintf(__('Tracking number: %s'), $details['tracking'])) . '<br>' : '')
                . ($details['tracking_url'] !== ''
                    ? '<a href="' . osc_esc_html($details['tracking_url']) . '">' . osc_esc_html(__('Track your parcel')) . '</a>'
                    : '')
                . '</p>';
        }

        [$words, $htmlWords] = self::words(
            $extra + array(
                '{ORDER_NUMBER}'   => Receipts::number($order),
                '{USER_NAME}'      => $uname !== '' ? $uname : $email,
                '{USER_EMAIL}'     => $email,
                '{PAYMENT_METHOD}' => osc_billing_gateway_name($order->getGateway()),
                '{ORDER_TOTAL}'    => $details['total'],
                '{ORDER_LINES}'    => Receipts::linesHtml($details),
                '{ORDER_ADDRESS}'  => Receipts::addressHtml($details),
                '{ORDER_TRACKING}' => $tracking,
                '{ORDER_URL}'      => $url,
                '{ORDER_LINK}'     => '<a href="' . osc_esc_html($url) . '">' . osc_esc_html($url) . '</a>',
            ),
            array('{USER_NAME}', '{USER_EMAIL}', '{PAYMENT_METHOD}')
        );

        return array('to' => $to, 'toName' => $toAdmin ? '' : $uname, 'words' => $words, 'htmlWords' => $htmlWords);
    }

    /**
     * Report the outcome of an automatic core upgrade to every administrator.
     *
     * @param array<string,mixed> $result Upgrade outcome: error code, message and version
     *
     * @return void
     */
    public static function autoUpgrade($result)
    {

        $body = __('<p>Dear {WEB_TITLE} admin,</p>');
        if ($result['error'] == 0 || $result['error'] == 6) {
            $title = __('{WEB_TITLE} - Your site has upgraded to Shopclass {VERSION}');
            $body  .= __('<p>Your site at {WEB_LINK} has been updated automatically to Shopclass {VERSION}</p>');
            if ($result['error'] == 6) {
                $body .= __('<p>There were some minor errors removing temporary files. '
                    .'Please manually remove the "oc-content/downloads/oc-temp" folder</p>');
            }
        } else {
            $title = __('{WEB_TITLE} - We failed trying to upgrade your site to Shopclass {VERSION}');
            $body  .= '<p>We failed trying to upgrade your site to Shopclass {VERSION}. Heres is the error message: {MESSAGE}</p>';
        }
        $body .= '<p>If you experience any issues or need support, we will be happy to help you at the Shopclass support forums</p>';
        $body .= '<p><a href="https://github.com/mindstellar/shopclass/discussions">https://github.com/mindstellar/shopclass/discussions</a></p>';
        $body .= '<p>The Shopclass team</p>';

        $words   = array();
        $words[] = array(
            '{MESSAGE}',
            '{VERSION}'
        );
        $words[] = array(
            $result['message'],
            $result['version']
        );

        $title = osc_apply_filter('email_after_auto_upgrade_title_after', osc_mailBeauty(osc_apply_filter(
            'email_title',
            osc_apply_filter('email_after_auto_upgrade_title', $title, $result)
        ), $words), $result);
        $body  = osc_apply_filter(
            'email_after_auto_upgrade_description_after',
            osc_mailBeauty(
                osc_apply_filter(
                    'email_description',
                    osc_apply_filter('email_after_auto_upgrade_description', $body, $result)
                ),
                $words
            ),
            $result
        );

        $admins = Admin::getInstance()->listAll();
        foreach ($admins as $admin) {
            if (!empty($admin['s_email']) && ($admin['b_moderator'] == 0)) {
                $emailParams = array(
                    'from'     => self::fromAddress(),
                    'to'       => $admin['s_email'],
                    'to_name'  => osc_page_title(),
                    'subject'  => $title,
                    'body'     => $body,
                );
                osc_sendMail($emailParams);
            }
        }
    }

    /**
     * The address outgoing mail is sent from, falling back to the site contact address.
     *
     * @return string
     */
    public static function fromAddress()
    {
        $tmp = osc_mailserver_mail_from();

        return !empty($tmp) ? $tmp : osc_contact_email();
    }
}
