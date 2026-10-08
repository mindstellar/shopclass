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

namespace mindstellar\user;

use mindstellar\auth\Actor;
use mindstellar\auth\Reauth;
use mindstellar\auth\SignOut;
use mindstellar\database\Db;
use mindstellar\listing\ListingService;
use mindstellar\location\LocationService;
use mindstellar\moderation\StatusFlags;
use mindstellar\security\ActionToken;
use mindstellar\security\RateLimit;
use mindstellar\utility\DeferredMail;
use mindstellar\utility\Sanitize;
use mindstellar\validation\BlockedException;
use mindstellar\validation\ConflictException;
use mindstellar\validation\ForbiddenException;
use mindstellar\validation\InvalidException;
use mindstellar\validation\NotFoundException;

/**
 * A user's account, for the account pages, the admin's users screen, the API and plugins
 * alike: the profile, the e-mail and password, the account's state, deleting it. The hooks
 * fire from here, so every caller fires the same ones in the same order.
 *
 * An actor with admin rights edits as the users screen does: the e-mail, username, password
 * and state too, logged under the admin.
 */
final class AccountService
{
    /**
     * Widths of the t_user columns an account form fills, from struct.sql.
     * tests/strict-write-guards.php pins them against the live schema.
     */
    public const COLUMN_WIDTHS = [
        's_name'         => 100,
        's_username'     => 100,
        's_email'        => 100,
        's_website'      => 100,
        's_phone_land'   => 45,
        's_phone_mobile' => 45,
        's_country'      => 80,
        's_region'       => 100,
        's_city'         => 100,
        's_city_area'    => 200,
        's_address'      => 100,
        's_zip'          => 15,
    ];

    /** Seconds an account holder waits between activation links. */
    public const RESEND_WAIT = 1200;

    /** E-mail changes a user may ask for in an hour. */
    public const EMAIL_CHANGES = 5;

    /** Each state flag => [its action when true, when false]; StatusFlags orders them. */
    public const FLAG_ACTIONS = [
        'blocked' => ['disable', 'enable'],
        'active'  => ['activate', 'deactivate'],
    ];

    private \User $users;
    private Sanitize $sanitize;
    private ?ListingService $listings;

    /**
     * Each collaborator defaults to the one the site uses; tests pass their own.
     */
    public function __construct(?\User $users = null, ?Sanitize $sanitize = null, ?ListingService $listings = null)
    {
        $this->users    = $users ?? \User::getInstance();
        $this->sanitize = $sanitize ?? new Sanitize();
        $this->listings = $listings;
    }

    /**
     * Make an account from the sign-up form, as AccountInput::signUp() reads it, or from the
     * users screen when the actor is an admin. A visitor's sign-up first needs users and
     * sign-ups switched on, fires `before_user_register`, and is refused for a banned e-mail
     * or address. Fires `register_email_taken`, the `user_add_flash_error` filter and
     * `user_register_failed` on a refusal; otherwise `pre_user_post`, the new-user e-mail
     * hooks and `user_register_completed`.
     *
     * @param array<string,mixed> $form
     * @param bool                $captchaPassed false when the form's captcha was not solved
     * @param bool                $hideTaken     answer a taken e-mail as a new one, with id 0 and
     *                                           nothing made, so the answer does not tell it is taken;
     *                                           a new account's e-mails then go through the job queue.
     *                                           Only while activation is on: without it, signing in
     *                                           with the new password would tell it anyway
     * @param (callable():void)|null $accepted called once the form passed every check, before anything is made;
     *                                           it may throw to refuse, for a taken e-mail too
     *
     * @return array{id:int,active:bool} active is false while the activation link waits
     * @throws InvalidException with the form's messages, one per line
     * @throws ForbiddenException DISABLED when sign-ups are off, BANNED for a ban rule
     */
    public function register(array $form, Actor $actor, bool $captchaPassed = true, bool $hideTaken = false, ?callable $accepted = null): array
    {
        $admin  = $actor->isAdmin();
        $hidden = $hideTaken && !$admin && osc_user_validation_enabled();
        if (!$admin) {
            self::signUpGate((string) ($form['s_email'] ?? ''), $actor->ip());
        }
        $flash  = '';
        $codes  = [];
        $refuse = static function (string $message, int $code) use (&$flash, &$codes): void {
            $flash  .= $message . PHP_EOL;
            $codes[] = $code;
        };
        $password = (string) ($form['s_password'] ?? '');
        if (!$admin && !$captchaPassed) {
            $refuse(_m('Please complete the security check.'), 4);
        }
        if ($password === '') {
            $refuse(_m('The password cannot be empty'), 6);
        }
        if ($password !== (string) ($form['s_password2'] ?? '')) {
            $refuse(_m("Passwords don't match"), 7);
        }

        $now   = date('Y-m-d H:i:s');
        $input = ['dt_reg_date' => $now, 'dt_access_date' => $now, 's_secret' => osc_genRandomPassword(), 's_access_ip' => $actor->ip()]
            + $this->input($form, true);
        if (!osc_validate_url($input['s_website'])) {
            $input['s_website'] = '';
        }
        if ($input['s_name'] == '') {
            $refuse(_m('The name cannot be empty'), 10);
        }
        if (!osc_validate_email($input['s_email'])) {
            $refuse(_m('The email is not valid'), 5);
        }
        $info    = (array) ($form['s_info'] ?? []);
        $tooLong = self::tooLong($input, $info);
        if ($tooLong !== '') {
            $flash  .= $tooLong;
            $codes[] = 11;
        }
        $taken = false;
        if ($this->users->findByEmail($input['s_email']) != false) {
            osc_run_hook('register_email_taken', $input['s_email']);
            if ($hidden) {
                $taken = true;
            } else {
                $refuse(_m('The specified e-mail is already in use'), 3);
            }
        }
        $username = (string) $input['s_username'];
        if ($username !== '') {
            $numeric = Usernames::numericError($username);
            if ($numeric !== '') {
                $refuse($numeric, 13);
            } elseif (osc_is_username_blacklisted($username)) {
                $refuse(_m('The specified username is not valid, it contains some invalid words'), 9);
            } elseif (UserStore::usernameTaken($username, 0, $admin)) {
                // Checked here as well as when claimed, so a taken e-mail refuses it the same way.
                $refuse(_m('Username is already taken'), 8);
            }
        }

        $flash = (string) osc_apply_filter('user_add_flash_error', $flash);
        if ($flash !== '') {
            osc_run_hook('user_register_failed', $codes);

            throw self::refusal($flash);
        }
        if ($accepted !== null) {
            $accepted();
        }
        if ($taken) {
            if ($username !== '') {
                // A new e-mail would have claimed the name, so hold it the same way.
                Usernames::hold($username);
            }
            osc_run_hook('user_register_failed', [3]);

            return ['id' => 0, 'active' => !osc_user_validation_enabled()];
        }

        osc_run_hook('pre_user_post');

        // Store only a fingerprint of the activation code; the plaintext goes in the e-mail.
        $activation = (string) $input['s_secret'];
        $input['s_secret'] = ActionToken::hash($activation);
        // Insert under a unique placeholder, then claim the real name under the username lock.
        $input['s_username'] = '_' . bin2hex(random_bytes(10));

        $failed = 12;
        try {
            // A hidden sign-up sends from the queue, so a new e-mail answers as fast as a taken one.
            $send = $hidden ? [SignUpMail::class, 'queue'] : null;

            return DeferredMail::transaction(function () use ($input, $info, $username, $activation, $admin, $actor, $hidden, &$failed): array {
                $userId = (int) $this->users->insertGetId($input);
                if ($userId <= 0) {
                    trigger_error('User insert produced no row; registration aborted.', E_USER_WARNING);

                    throw self::refusal(_m('Your account could not be created. Please try again.'));
                }
                if ($username === '') {
                    $input['s_username'] = Usernames::assignDefault($userId);
                } else {
                    $claim = Usernames::claim($userId, $username, $admin);
                    if ($claim !== 'ok') {
                        $failed = $claim === 'taken' ? 8 : 12;

                        throw self::refusal($claim === 'taken' ? _m('Username is already taken') : _m('Your account could not be created. Please try again.'));
                    }
                    $input['s_username'] = $username;
                }
                foreach ($info as $locale => $text) {
                    $this->users->updateDescription($userId, $locale, $text);
                }
                \Log::getInstance()->insertLog('user', $admin ? 'add' : 'register', $userId, $input['s_email'], $admin ? 'admin' : 'user', $admin ? $actor->logId() : $userId);

                $user = $this->users->findByPrimaryKey($userId);
                if (!$admin && osc_notify_new_user()) {
                    osc_run_hook('hook_email_admin_new_user', $user);
                }
                $active = $admin || !osc_user_validation_enabled();
                if (!$active && $hidden) {
                    // The queue holds only the id; the job mails a code made when it runs.
                    SignUpMail::queueActivation($userId);
                } elseif (!$active) {
                    $input['s_secret'] = $activation;
                    osc_run_hook('hook_email_user_validation', $user, $input);
                } else {
                    $this->users->update(['b_active' => '1'], ['pk_i_id' => $userId]);
                    // A visitor's address is unconfirmed here, so only an admin-made account
                    // takes the guest listings and alerts under it.
                    if ($admin) {
                        $this->claimGuestListings($userId);
                    }
                }
                osc_run_hook('user_register_completed', $userId);

                return ['id' => $userId, 'active' => $active];
            }, $send);
        } catch (InvalidException $e) {
            osc_run_hook('user_register_failed', [$failed]);

            throw $e;
        }
    }

    /**
     * Why the site takes no sign-ups now, or null when it takes them.
     */
    public static function signUpOpen(): ?string
    {
        if (!osc_users_enabled()) {
            return _m('Users are not enabled');
        }
        if (!osc_user_registration_enabled()) {
            return _m('User registration is not enabled');
        }

        return null;
    }

    /**
     * What a visitor's sign-up must pass before its form is read.
     *
     * @throws ForbiddenException
     */
    private static function signUpGate(string $email, string $ip): void
    {
        $closed = self::signUpOpen();
        if ($closed !== null) {
            throw new ForbiddenException($closed, ForbiddenException::DISABLED);
        }
        osc_run_hook('before_user_register');
        $banned = (int) osc_is_banned(trim($email), $ip !== '' ? $ip : null);
        if ($banned !== 0) {
            throw new ForbiddenException(($banned & 1) ? _m('Your current email is not allowed') : _m('Your current IP is not allowed'), ForbiddenException::BANNED);
        }
    }

    /**
     * Save the profile form, as AccountInput::read() reads it. Fires `pre_user_post`, the
     * `user_edit_flash_error` filter and `user_edit_completed`.
     *
     * @param array<string,mixed> $form
     *
     * @return int 1 saved, 2 an admin edit that also changed the account's state
     * @throws InvalidException with the form's messages, one per line
     */
    public function update(int $userId, array $form, Actor $actor): int
    {
        $admin = $actor->isAdmin();
        $input = $this->input($form, $admin);

        osc_run_hook('pre_user_post');
        $flash = '';
        if ($admin) {
            $holder = $this->users->findByEmail($input['s_email']);
            if (isset($holder['pk_i_id']) && (int) $holder['pk_i_id'] !== $userId) {
                $flash .= sprintf(_m('The specified e-mail is already used by %s'), $holder['s_username']) . PHP_EOL;
            }
        }
        if (!osc_validate_url($input['s_website'])) {
            $input['s_website'] = '';
        }
        if ($input['s_name'] == '') {
            $flash .= _m('The name cannot be empty') . PHP_EOL;
        }
        if ($admin && (string) ($form['s_password'] ?? '') !== (string) ($form['s_password2'] ?? '')) {
            $flash .= _m("Passwords don't match") . PHP_EOL;
        }
        $flash .= self::tooLong($input, (array) ($form['s_info'] ?? []));

        // Only an admin edit carries s_username; a missing or unchanged name is left alone,
        // so an old id-based username still saves.
        $newUsername = null;
        if ($admin && array_key_exists('s_username', $form)) {
            $current = $this->users->findByPrimaryKey($userId);
            if (isset($current['s_username']) && $input['s_username'] !== $current['s_username']) {
                $newUsername = $input['s_username'];
                $numeric     = Usernames::numericError($newUsername);
                if ($newUsername === '') {
                    $flash .= _m('The specified username could not be empty') . PHP_EOL;
                } elseif ($numeric !== '') {
                    $flash .= $numeric . PHP_EOL;
                }
            }
        }
        unset($input['s_username']);
        $newPassword = isset($input['s_password']) ? (string) $form['s_password'] : '';
        unset($input['s_password']);

        $flash = (string) osc_apply_filter('user_edit_flash_error', $flash, $userId);
        if ($flash !== '') {
            throw self::refusal($flash);
        }

        return (int) DeferredMail::transaction(function () use ($userId, $form, $input, $admin, $actor, $newUsername, $newPassword): int {
            $claim = $newUsername !== null ? Usernames::claim($userId, $newUsername, $admin) : 'ok';
            if ($claim === 'taken') {
                throw self::refusal(_m('The specified username is already in use'));
            }
            if ($claim !== 'ok') {
                throw self::refusal(_m('Your profile could not be saved. Please try again.'));
            }
            if ($this->users->update($input, ['pk_i_id' => $userId]) === false) {
                trigger_error('User update wrote no row; profile save aborted.', E_USER_WARNING);

                throw self::refusal(_m('Your profile could not be saved. Please try again.'));
            }
            if ($newPassword !== '') {
                self::setPassword($userId, $newPassword);
            }

            if ($admin) {
                UserStore::carryContact($userId, $input['s_name'], $input['s_email']);
                $email = $input['s_email'];
            } else {
                UserStore::carryName($userId, $input['s_name']);
                $email = (string) ($this->users->findByPrimaryKey($userId)['s_email'] ?? '');
            }
            \Log::getInstance()->insertLog('user', 'edit', $userId, $email, $actor->logRole(), $actor->logId());

            foreach ((array) ($form['s_info'] ?? []) as $locale => $info) {
                $this->users->updateDescription($userId, $locale, $info);
            }

            osc_run_hook('user_edit_completed', $userId);

            if ($admin && $this->applyFlags($userId, ['blocked' => empty($form['b_enabled']), 'active' => !empty($form['b_active'])], $actor) !== []) {
                return 2;
            }

            return 1;
        });
    }

    /**
     * The account's own edit: the profile form and a new e-mail, in one transaction, so an
     * e-mail change over its hourly cap leaves the profile as it was.
     *
     * @param array<string,mixed>|null $form     the profile form; null for no profile edit
     * @param string                   $newEmail '' for no e-mail change
     *
     * @throws InvalidException with the form's messages
     * @throws BlockedException past EMAIL_CHANGES in an hour
     */
    public function editOwn(int $userId, ?array $form, string $newEmail, Actor $actor): void
    {
        DeferredMail::transaction(function () use ($userId, $form, $newEmail, $actor): void {
            if ($form !== null) {
                $this->update($userId, $form, $actor);
            }
            if ($newEmail !== '') {
                $this->requestEmailChange($userId, $newEmail, $actor);
            }
        });
    }

    /**
     * An admin's edit and status flags, all or none: the edit form first, then the flags as
     * applyFlags() sets them.
     *
     * @param array<string,mixed>|null $form  the admin's edit form; null for no edit
     * @param array<string,bool>       $flags `blocked` and `active`
     *
     * @throws InvalidException with the form's messages
     * @throws NotFoundException for no such user
     * @throws \RuntimeException when a change fails
     */
    public function adminEdit(int $userId, ?array $form, array $flags, Actor $actor): void
    {
        DeferredMail::transaction(function () use ($userId, $form, $flags, $actor): void {
            if ($form !== null) {
                $this->update($userId, $form, $actor);
            }
            if ($flags !== []) {
                $this->applyFlags($userId, $flags, $actor);
            }
        });
    }

    /**
     * Ask to change the e-mail: a link goes to the new address, and the address changes once
     * it is opened. An address another account holds gets no link but the same answer, so
     * nobody learns it is taken. Fires `hook_email_new_email`.
     *
     * @return bool whether a link was sent
     * @throws InvalidException for an address that is not one
     * @throws BlockedException past EMAIL_CHANGES in an hour
     * @throws NotFoundException for no such user
     */
    public function requestEmailChange(int $userId, string $newEmail, Actor $actor): bool
    {
        $newEmail = trim($newEmail);
        $user     = $this->users->findByPrimaryKey($userId);
        if (!is_array($user) || empty($user['pk_i_id'])) {
            throw new NotFoundException(_m('No such user.'));
        }
        if (!osc_validate_email($newEmail)) {
            throw new InvalidException('/email', 'format', _m('The specified e-mail is not valid'));
        }
        if (strcasecmp($newEmail, (string) $user['s_email']) === 0) {
            return false;
        }
        if (!RateLimit::hit('email_change', (string) $userId, self::EMAIL_CHANGES, 3600)) {
            throw BlockedException::rateLimit(_m('Too many e-mail changes. Try again later.'), 3600 - (time() % 3600));
        }
        if (isset($this->users->findByEmail($newEmail)['pk_i_id'])) {
            return false;
        }

        return (bool) DeferredMail::transaction(function () use ($userId, $newEmail): bool {
            \UserEmailTmp::getInstance()->insertOrUpdate(['fk_i_user_id' => $userId, 's_new_email' => $newEmail]);
            $code = (string) $this->users->issuePassCode($userId, \User::PASS_CODE_EMAIL);
            osc_run_hook('hook_email_new_email', $newEmail, osc_change_user_email_confirm_url($userId, $code));

            return true;
        });
    }

    /**
     * Apply a pending e-mail change once its confirmation code checks out: the code must
     * match and be younger than User::PASS_CODE_TTL, and is cleared on use. The user row,
     * their listings, comments and alerts switch in one transaction.
     *
     * @return array{status:string,old:string,new:string} status is 'ok', 'invalid', 'taken' or 'failed'
     */
    public function confirmEmailChange(int $userId, string $code): array
    {
        $result = ['status' => 'invalid', 'old' => '', 'new' => ''];
        $user   = $userId > 0 && $code !== '' ? $this->users->findByPrimaryKey($userId) : false;
        if (empty($user['pk_i_id'])) {
            return $result;
        }

        $stored = (string) ($user['s_pass_code'] ?? '');
        $issued = strtotime((string) ($user['s_pass_date'] ?? ''));
        if ($stored === '' || !hash_equals($stored, \User::passCodeHash(\User::PASS_CODE_EMAIL, $code))
            || (int) $user['b_enabled'] !== 1
            || $issued === false || $issued < time() - \User::PASS_CODE_TTL
        ) {
            return $result;
        }

        $pending = \UserEmailTmp::getInstance()->findByPrimaryKey($userId);
        $new     = (string) ($pending['s_new_email'] ?? '');
        if ($new === '') {
            return $result;
        }
        $result['old'] = (string) $user['s_email'];
        $result['new'] = $new;

        $holder = $this->users->findByEmail($new);
        if (!empty($holder['pk_i_id']) && (int) $holder['pk_i_id'] !== $userId) {
            $result['status'] = 'taken';

            return $result;
        }

        $status = 'failed';
        try {
            Db::transaction(static function () use ($userId, $stored, $new, &$status) {
                // Matching on the code as well makes the link single-use under a double click.
                try {
                    $switched = UserStore::switchEmail($userId, $stored, $new);
                } catch (\mindstellar\database\DbException $e) {
                    $status = (int) $e->getCode() === 1062 ? 'taken' : 'failed';
                    throw $e;
                }
                if ($switched !== 1) {
                    $status = 'invalid';
                    throw new \RuntimeException('E-mail change not applied.');
                }
                UserStore::carryEmail($userId, $new);
            });
        } catch (\Throwable $e) {
            $result['status'] = $status;

            return $result;
        }

        $result['status'] = 'ok';

        return $result;
    }

    /**
     * A signed-in user changes their own password: the current one is asked for again, then
     * the new one is stored and every other sign-in ends.
     *
     * @param array<string,mixed> $user    the user's t_user row
     * @param string|null         $confirm the new password typed again, when the form asks for it
     *
     * @throws BlockedException while too many wrong passwords came in a row
     * @throws InvalidException for a wrong current password, or a confirmation that differs
     */
    public function changePassword(array $user, string $current, string $new, ?string $confirm = null): void
    {
        Reauth::check($user, $current);
        if ($confirm !== null && $confirm !== $new) {
            throw new InvalidException('/new_password2', 'mismatch', _m("Passwords don't match"));
        }
        self::setPassword((int) $user['pk_i_id'], $new);
    }

    /**
     * Store a new password and sign the user out everywhere, in one transaction, since an old
     * sign-in must not outlive the password it was made with. Every path that sets a user's
     * password goes through here: a change, a reset, an admin edit.
     *
     * @param string|null $resetCode a password-reset code: the write only happens while the
     *                               code still matches, and uses it up, so a link works once
     *
     * @return bool whether the password was stored
     * @throws \mindstellar\database\DbException
     */
    public static function setPassword(int $userId, string $new, ?string $resetCode = null, string $ip = ''): bool
    {
        $values = ['s_password' => osc_hash_password($new)];
        $where  = ['pk_i_id' => $userId];
        if ($resetCode !== null) {
            $values += ['s_pass_code' => null, 's_pass_date' => null, 's_pass_ip' => $ip];
            $where['s_pass_code'] = \User::passCodeHash(\User::PASS_CODE_RESET, $resetCode);
        }

        return (bool) Db::transaction(static function () use ($userId, $values, $where): bool {
            if (!\User::getInstance()->update($values, $where)) {
                return false;
            }
            SignOut::everywhereUser($userId);

            return true;
        });
    }

    /**
     * Delete an account and everything it owns. The user deletes their own with their
     * password; an admin deletes any. Fires `before_user_delete`, then the model's
     * `delete_user` and `after_delete_user`.
     *
     * @throws NotFoundException for no such user
     * @throws InvalidException  for a blank or wrong password from the user
     * @throws BlockedException  while too many wrong passwords came in a row
     * @throws \RuntimeException when it could not be deleted
     */
    public function delete(int $userId, Actor $actor, string $password = ''): void
    {
        $user = $userId > 0 ? $this->users->findByPrimaryKey($userId) : false;
        if (!is_array($user) || empty($user['pk_i_id'])) {
            throw new NotFoundException(_m('No such user.'));
        }
        if (!$actor->isAdmin()) {
            if ($password === '') {
                throw new InvalidException('/current_password', 'required', _m('Password cannot be blank'));
            }
            Reauth::check($user, $password);
        }

        DeferredMail::transaction(function () use ($userId, $user, $actor): void {
            osc_run_hook('before_user_delete', $user);
            \Log::getInstance()->insertLog('user', 'delete', $userId, (string) $user['s_email'], $actor->logRole(), $actor->logId());
            if (!$this->users->deleteUser($userId)) {
                throw new \RuntimeException('The user could not be deleted.');
            }
        });
    }

    /**
     * Activate an account. Its listings come back when it is enabled, and listings posted
     * under its e-mail as a guest join it. Opened from the activation link (no admin), it
     * tells the admin about the new user. Fires `activate_user`.
     *
     * @return bool false for no such user
     */
    public function activate(int $userId, Actor $actor): bool
    {
        $user = $this->users->findByPrimaryKey($userId);
        if (!$user) {
            return false;
        }

        return (bool) DeferredMail::transaction(function () use ($userId, $user, $actor): bool {
            $this->users->update(['b_active' => 1], ['pk_i_id' => $userId]);
            if (!$actor->isAdmin()) {
                osc_run_hook('hook_email_admin_new_user', $user);
            }
            \Log::getInstance()->insertLog('user', 'activate', $userId, $user['s_email'], $actor->logRole(), $actor->logId());
            if ((int) $user['b_enabled'] === 1) {
                $this->eachListing($userId, 'enable');
            }
            $this->claimGuestListings($userId);
            osc_run_hook('activate_user', $user);

            return true;
        });
    }

    /**
     * Confirm a new account from the validation link: activate it, use up the code and move
     * the guest listings and alerts posted with its e-mail to it.
     *
     * @return array<string,mixed> the user row, as it was before the confirmation
     * @throws NotFoundException for a link that is not valid
     * @throws ConflictException for an account already confirmed, or a code used meanwhile
     */
    public function confirm(int $userId, string $code): array
    {
        $hash = ActionToken::hash($code);
        $user = $this->users->findByIdSecret($userId, $hash);
        if (!$user) {
            throw new NotFoundException(_m('The link is not valid anymore. Sorry for the inconvenience!'));
        }
        if ((int) $user['b_active'] === 1) {
            throw new ConflictException(_m('Your account has already been validated'));
        }

        $done = (bool) Db::transaction(function () use ($userId, $hash): bool {
            // A fresh plaintext secret replaces the used code; the account-delete link reads it.
            $updated = $this->users->update(
                ['b_active' => '1', 's_secret' => osc_genRandomPassword()],
                ['pk_i_id' => $userId, 's_secret' => $hash]
            );
            if (!$updated) {
                return false;
            }
            $this->claimGuestListings($userId);

            return true;
        });
        if (!$done) {
            throw new ConflictException(_m('Account validation failed'));
        }

        return $user;
    }

    /**
     * Move the guest listings and alerts posted with this account's e-mail to the account.
     * Only for a confirmed address, or an account an admin made or activated.
     */
    public function claimGuestListings(int $userId): void
    {
        $user = $this->users->findByPrimaryKey($userId);
        if (!$user || (string) $user['s_email'] === '') {
            return;
        }

        try {
            $claimed = UserStore::claimGuestListings($userId, (string) $user['s_email'], $user['s_name']);
            if ($claimed > 0) {
                $this->users->increaseNumItems($userId, $claimed);
            }
            UserStore::claimGuestAlerts($userId, (string) $user['s_email']);
        } catch (\mindstellar\database\DbException $e) {
            trigger_error('Claiming guest listings failed: ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    /**
     * Deactivate an account; its listings go with it while it is enabled. Fires `deactivate_user`.
     *
     * @return bool false for no such user
     */
    public function deactivate(int $userId, Actor $actor): bool
    {
        return $this->setState($userId, $actor, 'b_active', 0, 'deactivate', 'b_enabled', 'disable', static fn (array $user) => osc_run_hook('deactivate_user', $user));
    }

    /**
     * Unblock an account; its listings come back while it is active. Fires `enable_user`.
     *
     * @return bool false for no such user
     */
    public function enable(int $userId, Actor $actor): bool
    {
        return $this->setState($userId, $actor, 'b_enabled', 1, 'enable', 'b_active', 'enable', static fn (array $user) => osc_run_hook('enable_user', $user));
    }

    /**
     * Block an account; its listings go with it while it is active. Fires `disable_user`.
     *
     * @return bool false for no such user
     */
    public function disable(int $userId, Actor $actor): bool
    {
        return $this->setState($userId, $actor, 'b_enabled', 0, 'disable', 'b_active', 'disable', static fn (array $user) => osc_run_hook('disable_user', $user));
    }

    /**
     * Set several state flags at once, all or none, with each action's hooks and listings: an
     * unblock first, so the listings come back with an activation, a block last, and a flag
     * already as asked left alone.
     *
     * @param array<string,bool> $flags `blocked` and `active`
     *
     * @return string[] the actions that ran
     * @throws NotFoundException for no such user
     * @throws \LogicException for an unknown flag
     * @throws \RuntimeException when a change fails
     */
    public function applyFlags(int $userId, array $flags, Actor $actor): array
    {
        $user = $this->users->findByPrimaryKey($userId);
        if (!is_array($user) || empty($user['pk_i_id'])) {
            throw new NotFoundException(_m('No such user.'));
        }
        $plan = StatusFlags::plan($flags, $user, self::FLAG_ACTIONS);
        if ($plan !== []) {
            DeferredMail::transaction(function () use ($plan, $userId, $actor): void {
                foreach ($plan as $action) {
                    if (!$this->{$action}($userId, $actor)) {
                        throw new \RuntimeException('The user could not be changed.');
                    }
                }
            });
        }

        return $plan;
    }

    /**
     * Send a new activation link to an account not yet active, when the site asks for one.
     * The account's own request waits RESEND_WAIT seconds between links and tells the admin
     * as a sign-up does. Fires `hook_email_user_validation`.
     *
     * @param bool $selfService the account holder asked, not an admin or the API
     * @param (callable(array<string,mixed>): mixed)|null $send sends one e-mail; osc_sendMail() by default
     *
     * @return bool whether a link went out
     */
    public function resendActivation(int $userId, bool $selfService = false, ?callable $send = null): bool
    {
        $user = $this->users->findByPrimaryKey($userId);
        if (!$user || (int) $user['b_active'] === 1 || !osc_user_validation_enabled()) {
            return false;
        }
        if ($selfService && self::resendWait($user) > 0) {
            return false;
        }

        return (bool) DeferredMail::transaction(function () use ($userId, $user, $selfService): bool {
            if ($selfService && osc_notify_new_user()) {
                osc_run_hook('hook_email_admin_new_user', $user);
            }
            // Mail a fresh code and store only its fingerprint.
            $code = osc_genRandomPassword();
            $this->users->update(['s_secret' => ActionToken::hash($code), 'dt_access_date' => date('Y-m-d H:i:s')], ['pk_i_id' => $userId]);
            $user['s_secret'] = $code;
            osc_run_hook('hook_email_user_validation', $user, $user);

            return true;
        }, $send);
    }

    /**
     * Seconds until the account holder may ask for another activation link; 0 when they may.
     *
     * @param array<string,mixed> $user the user row
     */
    public static function resendWait(array $user): int
    {
        return max(0, self::RESEND_WAIT - (time() - (int) strtotime((string) ($user['dt_access_date'] ?? ''))));
    }

    /**
     * @param string $column     the state column to set
     * @param string $other      the column that must be 1 for the listings to follow
     * @param string   $itemAction ListingService's method for each listing
     * @param callable $hook       fires the state's hook with the user row
     */
    private function setState(int $userId, Actor $actor, string $column, int $value, string $logAction, string $other, string $itemAction, callable $hook): bool
    {
        $user = $this->users->findByPrimaryKey($userId);
        if (!$user) {
            return false;
        }

        return (bool) DeferredMail::transaction(function () use ($userId, $user, $actor, $column, $value, $logAction, $other, $itemAction, $hook): bool {
            $this->users->update([$column => $value], ['pk_i_id' => $userId]);
            \Log::getInstance()->insertLog('user', $logAction, $userId, $user['s_email'], $actor->logRole(), $actor->logId());
            if ((int) $user[$other] === 1) {
                $this->eachListing($userId, $itemAction);
            }
            $hook($user);

            return true;
        });
    }

    private function eachListing(int $userId, string $action): void
    {
        $listings = $this->listings ??= new ListingService();
        foreach (\Item::getInstance()->findByUserID($userId) as $item) {
            $listings->$action((int) $item['pk_i_id']);
        }
    }

    /**
     * The t_user row a profile form makes, sanitised. The admin's form also sets the e-mail,
     * username and password.
     *
     * @param array<string,mixed> $form
     *
     * @return array<string,mixed>
     */
    public function input(array $form, bool $admin): array
    {
        $text  = fn (string $key): string => $this->sanitize->string((string) ($form[$key] ?? ''));
        $input = ['dt_mod_date' => date('Y-m-d H:i:s')];
        if ($admin) {
            $input['s_email'] = $this->sanitize->email((string) ($form['s_email'] ?? ''));
            if ((string) ($form['s_password'] ?? '') !== '') {
                $input['s_password'] = osc_hash_password((string) $form['s_password']);
            }
            $input['s_username'] = $this->sanitize->username((string) ($form['s_username'] ?? ''));
        }
        $input['s_name']         = $text('s_name');
        $input['s_website']      = $this->sanitize->websiteUrl((string) ($form['s_website'] ?? ''));
        $input['s_phone_land']   = $this->sanitize->phone((string) ($form['s_phone_land'] ?? ''));
        $input['s_phone_mobile'] = $this->sanitize->phone((string) ($form['s_phone_mobile'] ?? ''));

        $places = LocationService::resolve([
            'countryCode' => (string) ($form['countryId'] ?? ''),
            'country'     => $text('country'),
            'regionId'    => (int) ($form['regionId'] ?? 0) > 0 ? (int) $form['regionId'] : '',
            'region'      => $text('region'),
            'cityId'      => (int) ($form['cityId'] ?? 0) > 0 ? (int) $form['cityId'] : '',
            'city'        => $text('city'),
        ]);
        $input['fk_c_country_code'] = $places['countryId'];
        $input['s_country']         = $places['countryName'];
        foreach (['region' => ['fk_i_region_id', 's_region'], 'city' => ['fk_i_city_id', 's_city']] as $level => [$idColumn, $nameColumn]) {
            if ($places[$level . 'Name'] !== null) {
                $input[$idColumn]   = $places[$level . 'Id'];
                $input[$nameColumn] = $places[$level . 'Name'];
            }
        }
        $input['s_city_area'] = $text('cityArea');
        $input['s_address']   = $text('address');
        $input['s_zip']       = $text('zip');

        // No user form posts coordinates, so a save without them keeps the stored ones.
        foreach (['d_coord_lat' => 90, 'd_coord_long' => 180] as $coord => $limit) {
            if (array_key_exists($coord, $form)) {
                $value         = (string) $form[$coord];
                $input[$coord] = is_numeric($value) && abs((float) $value) <= $limit ? (float) $value : null;
            }
        }
        $input['b_company'] = empty($form['b_company']) ? 0 : 1;

        return $input;
    }

    /**
     * A message per value wider than the column that holds it. A relaxed connection would cut
     * it short and a strict one refuse the statement; neither is something to hand a visitor.
     *
     * @param array<string,mixed> $input a t_user row
     * @param array<mixed>        $info  s_info by locale
     */
    public static function tooLong(array $input, array $info): string
    {
        $labels = [
            's_name'         => _m('Name'),
            's_username'     => _m('Username'),
            's_email'        => _m('E-mail'),
            's_website'      => _m('Website'),
            's_phone_land'   => _m('Landline'),
            's_phone_mobile' => _m('Mobile'),
            's_country'      => _m('Country'),
            's_region'       => _m('Region'),
            's_city'         => _m('City'),
            's_city_area'    => _m('Municipality'),
            's_address'      => _m('Address'),
            's_zip'          => _m('Zip code'),
        ];
        $flash = '';
        foreach (self::COLUMN_WIDTHS as $column => $width) {
            if (!isset($input[$column]) || osc_validate_max((string) $input[$column], $width)) {
                continue;
            }
            $flash .= sprintf(_m('%s is too long, the maximum is %d characters'), $labels[$column], $width) . PHP_EOL;
        }
        // s_info is TEXT, so its limit is 65535 bytes, not characters.
        foreach ($info as $key => $value) {
            if (strlen(is_string($value) ? $value : '') > 65535) {
                $flash .= sprintf(_m('The field %s is too long'), osc_esc_html((string) $key)) . PHP_EOL;
            }
        }

        return $flash;
    }

    /**
     * The form's messages, one per line, as a refusal.
     */
    private static function refusal(string $flash): InvalidException
    {
        $errors = [];
        foreach (preg_split('/\R/', $flash) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $errors[] = ['pointer' => '', 'code' => 'rejected', 'message' => $line];
            }
        }

        return InvalidException::all($errors);
    }
}
