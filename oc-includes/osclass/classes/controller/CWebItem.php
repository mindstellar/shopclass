<?php

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

use mindstellar\utility\Validate;

/**
 * Class CWebItem
 */
class CWebItem extends BaseModel
{
    private $itemManager;
    private $user;
    private $userId;

    public function __construct()
    {
        parent::__construct();
        $this->itemManager = Item::newInstance();

        // here allways userId == ''
        if (osc_is_web_user_logged_in()) {
            $this->userId = osc_logged_user_id();
            $this->user   = User::newInstance()->findByPrimaryKey($this->userId);
        } else {
            $this->userId = null;
            $this->user   = null;
        }
        osc_run_hook('init_item');
    }

    //Business Layer...

    /**
     * @return bool|void
     */
    public function doModel()
    {
        //calling the view...

        // The view beacon is a lightweight POST endpoint the listing page's client script hits on
        // load; short-circuit before the page-render setup below so it stays cheap.
        if ($this->action === 'view_beacon') {
            $this->countItemViewBeacon();

            return;
        }

        $locales = OSCLocale::newInstance()->listAllEnabled();
        $this->_exportVariableToView('locales', $locales);

        switch ($this->action) {
            case 'item_add': // post
                if (osc_reg_user_post() && $this->user == null) {
                    osc_add_flash_warning_message(_m('Only registered users are allowed to post listings'));
                    // Remember to bring them back to the post form after login — in a signed
                    // cookie, not the session, so this bounce never starts a session.
                    osc_set_login_redirect(osc_item_post_url());
                    $this->redirectTo(osc_user_login_url());
                }

                // Turn them back at the door rather than after they have written the whole
                // listing: the submit path refuses on the same answer, so reaching the form
                // at all would only waste the writing. Admins are never metered, and guests
                // have no quota to be outside of.
                if (!osc_is_admin_user_logged_in()
                    && osc_is_web_user_logged_in()
                    && !osc_user_can_publish()
                ) {
                    osc_add_flash_error_message(osc_listing_limit_message());
                    $this->redirectTo(osc_user_list_items_url());
                }

                $countries = Country::newInstance()->listAll();

                // Regions and cities follow a country and a region the seller
                // actually chose. Falling back to the first country listed filled
                // both selects with somewhere else's places while the country
                // select still read "Select a country", so a visitor with no
                // JavaScript could file a listing against a city in another country.
                $countryId = $this->user['fk_c_country_code'] ?? '';
                $regionId  = $this->user['fk_i_region_id'] ?? '';

                $regions = $countryId != ''
                    ? Region::newInstance()->findByCountry($countryId)
                    : array();
                $cities = $regionId != ''
                    ? City::newInstance()->findByRegion($regionId)
                    : array();

                $this->_exportVariableToView('countries', $countries);
                $this->_exportVariableToView('regions', $regions);
                $this->_exportVariableToView('cities', $cities);

                $form     = count(Session::newInstance()->_getForm());
                $keepForm = count(Session::newInstance()->_getKeepForm());
                if ($form == 0 || $form == $keepForm) {
                    Session::newInstance()->_dropKeepForm();
                }
                if ($form == 0) {
                    // Fresh post form (no submitted data to restore): drop any temp
                    // uploads a previous, abandoned posting left in the session so they
                    // can't silently attach to this new listing.
                    ItemTmpUpload::newInstance()->deleteByToken(osc_upload_token());
                }

                if (Session::newInstance()->_getForm('countryId') != '') {
                    $countryId = Session::newInstance()->_getForm('countryId');
                    $regions   = Region::newInstance()->findByCountry($countryId);
                    $this->_exportVariableToView('regions', $regions);
                    if (Session::newInstance()->_getForm('regionId') != '') {
                        $regionId = Session::newInstance()->_getForm('regionId');
                        $cities   = City::newInstance()->findByRegion($regionId);
                        $this->_exportVariableToView('cities', $cities);
                    }
                }

                $this->_exportVariableToView('user', $this->user);

                osc_run_hook('post_item');

                $this->doView(osc_locate_template(array('item-post.php'), 'item-post'));
                break;
            case 'item_add_post':
                // SAVE form data before CSRF CHECK
                $mItems = new ItemActions(false);
                $mItems->prepareData(true);
                foreach ($mItems->data as $key => $value) {
                    Session::newInstance()->_setForm($key, $value);
                }

                $meta = Params::getParam('meta');
                if (is_array($meta)) {
                    foreach ($meta as $key => $value) {
                        Session::newInstance()->_setForm('meta_' . $key, $value);
                        Session::newInstance()->_keepForm('meta_' . $key);
                    }
                }

                osc_csrf_check();

                if (osc_reg_user_post() && $this->user == null) {
                    osc_add_flash_warning_message(_m('Only registered users are allowed to post listings'));
                    $this->redirectTo(osc_base_url(true));
                }

                if (osc_recaptcha_items_enabled() && osc_captcha_enabled()
                    && !osc_check_captcha()
                ) {
                    osc_add_flash_error_message(_m('Please complete the security check.'));
                    $this->redirectTo(osc_item_post_url());

                    return false; // BREAK THE PROCESS, THE CAPTCHA IS WRONG
                }

                if (!osc_is_web_user_logged_in()) {
                    $user = User::newInstance()->findByEmail($mItems->data['contactEmail']);
                    // The user exists but it's not logged
                    if (isset($user['pk_i_id'])) {
                        foreach ($mItems->data as $key => $value) {
                            Session::newInstance()->_keepForm($key);
                        }
                        osc_add_flash_error_message(_m('A user with that email address already exists, if it is you, please log in'));
                        $this->redirectTo(osc_user_login_url());
                    }
                }

                $banned = osc_is_banned($mItems->data['contactEmail']);
                if ($banned == 1) {
                    osc_add_flash_error_message(_m('Your current email is not allowed'));
                    $this->redirectTo(osc_item_post_url());
                } elseif ($banned == 2) {
                    osc_add_flash_error_message(_m('Your current IP is not allowed'));
                    $this->redirectTo(osc_item_post_url());
                }

                // POST ITEM ( ADD ITEM )
                $success = $mItems->add();

                if ($success != 1 && $success != 2) {
                    osc_add_flash_error_message($success);
                    $this->redirectTo(osc_item_post_url());
                } else {
                    if (is_array($meta)) {
                        foreach ($meta as $key => $value) {
                            Session::newInstance()->_dropKeepForm('meta_' . $key);
                        }
                    }
                    Session::newInstance()->_clearVariables();
                    // Uploads were consumed by the successful post; drop the session
                    // mapping so it can't bleed into the next listing.
                    ItemTmpUpload::newInstance()->deleteByToken(osc_upload_token());
                    if ($success == 1) {
                        osc_add_flash_ok_message(_m('Check your inbox to validate your listing'));
                    } elseif (osc_moderate_admin_post()) {
                        osc_add_flash_ok_message(_m('Your listing will be published after an admin approves it.'));
                    } else {
                        osc_add_flash_ok_message(_m('Your listing has been published'));
                    }

                    $itemId = Params::getParam('itemId');

                    $category =
                        Category::newInstance()->findByPrimaryKey(Params::getParam('catId'));
                    View::newInstance()->_exportVariableToView('category', $category);
                    // Let a theme or plugin send the seller somewhere other than the category
                    // search page after publishing — e.g. straight to the new listing.
                    $this->redirectTo(
                        osc_apply_filter('item_post_redirect_url', osc_search_category_url(), $itemId, $category)
                    );
                }
                break;
            case 'item_edit':   // edit item
                $secret = Params::getParam('secret');
                $id     = Params::getParam('id');
                $item   =
                    $this->itemManager->listWhere(
                        'i.pk_i_id = %d AND ((i.s_secret = %s AND i.fk_i_user_id IS NULL) OR (i.fk_i_user_id = %d))',
                        (int)$id,
                        $secret,
                        (int)$this->userId
                    );
                if (count($item) == 1) {
                    $item = Item::newInstance()->findByPrimaryKey($id);

                    $form     = count(Session::newInstance()->_getForm());
                    $keepForm = count(Session::newInstance()->_getKeepForm());
                    if ($form == 0 || $form == $keepForm) {
                        Session::newInstance()->_dropKeepForm();
                    }
                    if ($form == 0) {
                        // Fresh edit form: drop temp uploads left by an earlier,
                        // abandoned posting so they can't attach to this item.
                        ItemTmpUpload::newInstance()->deleteByToken(osc_upload_token());
                    }

                    $this->_exportVariableToView('item', $item);

                    osc_run_hook('before_item_edit', $item);
                    // Editing is the publishing form with the values filled in, and
                    // ItemForm hands both the same fields. A theme that ships only
                    // item-post.php gets it here too rather than having to keep a
                    // second copy, or a one-line file that includes the first.
                    $this->doView(osc_locate_template(
                        array('item-edit.php', 'item-post.php'),
                        'item-edit'
                    ));
                } else {
                    // add a flash message [ITEM NO EXISTE]
                    osc_add_flash_error_message(_m("Sorry, we don't have any listings with that ID"));
                    if ($this->user != null) {
                        $this->redirectTo(osc_user_list_items_url());
                    } else {
                        $this->redirectTo(osc_base_url());
                    }
                }
                break;
            case 'item_edit_post':
                // SAVE form data before CSRF CHECK
                $mItems = new ItemActions(false);
                // prepare data for ADD ITEM
                $mItems->prepareData(false);
                // set all parameters into session
                foreach ($mItems->data as $key => $value) {
                    Session::newInstance()->_setForm($key, $value);
                }

                $meta = Params::getParam('meta');
                if (is_array($meta)) {
                    foreach ($meta as $key => $value) {
                        Session::newInstance()->_setForm('meta_' . $key, $value);
                        Session::newInstance()->_keepForm('meta_' . $key);
                    }
                }

                osc_csrf_check();

                $secret = Params::getParam('secret');
                $id     = Params::getParam('id');
                $item   =
                    $this->itemManager->listWhere(
                        'i.pk_i_id = %d AND ((i.s_secret = %s AND i.fk_i_user_id IS NULL) OR (i.fk_i_user_id = %d))',
                        (int)$id,
                        $secret,
                        (int)$this->userId
                    );

                if (count($item) == 1) {
                    $this->_exportVariableToView('item', $item[0]);

                    if (osc_recaptcha_items_enabled() && osc_captcha_enabled()
                        && !osc_check_captcha()
                    ) {
                        osc_add_flash_error_message(_m('Please complete the security check.'));
                        $this->redirectTo(osc_item_edit_url($secret, $id));

                        return false; // BREAK THE PROCESS, THE CAPTCHA IS WRONG
                    }

                    $success = $mItems->edit();

                    if ($success == 1) {
                        if (is_array($meta)) {
                            foreach ($meta as $key => $value) {
                                Session::newInstance()->_dropKeepForm('meta_' . $key);
                            }
                        }
                        Session::newInstance()->_clearVariables();
                        // Uploads were consumed by the successful edit; drop the session
                        // mapping so it can't bleed into a later listing.
                        ItemTmpUpload::newInstance()->deleteByToken(osc_upload_token());
                        if (osc_moderate_admin_edit()) {
                            osc_add_flash_ok_message(_m('Your listing will be published after an admin approves the changes.'));
                        } else {
                            osc_add_flash_ok_message(_m("Great! We've just updated your listing"));
                        }
                        View::newInstance()->_exportVariableToView(
                            'item',
                            Item::newInstance()->findByPrimaryKey($id)
                        );
                        $this->redirectTo(osc_item_url());
                    } else {
                        osc_add_flash_error_message($success);
                        $this->redirectTo(osc_item_edit_url($secret, $id));
                    }
                }
                break;
            case 'activate':
                $secret = Params::getParam('secret');
                $id     = Params::getParam('id');
                $item   =
                    $this->itemManager->listWhere(
                        'i.pk_i_id = %d AND ((i.s_secret = %s) OR (i.fk_i_user_id = %d))',
                        (int)$id,
                        $secret,
                        (int)$this->userId
                    );

                // item doesn't exist
                if (count($item) == 0) {
                    $this->do404();

                    return;
                }

                View::newInstance()->_exportVariableToView('item', $item[0]);
                if ($item[0]['b_active'] == 0) {
                    // ACTIVETE ITEM
                    $mItems  = new ItemActions(false);
                    $success = $mItems->activate($item[0]['pk_i_id'], $item[0]['s_secret']);

                    if ($success) {
                        osc_add_flash_ok_message(_m('The listing has been validated'));
                    } else {
                        osc_add_flash_error_message(_m("The listing can't be validated"));
                    }
                } else {
                    osc_add_flash_warning_message(_m('The listing has already been validated'));
                }

                $this->redirectTo(osc_item_url());
                break;
            case 'item_delete':
                $secret = Params::getParam('secret');
                $id     = Params::getParam('id');
                $item   =
                    $this->itemManager->listWhere(
                        'i.pk_i_id = %d AND ((i.s_secret = %s) OR (i.fk_i_user_id = %d))',
                        (int)$id,
                        $secret,
                        (int)$this->userId
                    );
                if (count($item) == 1) {
                    $mItems  = new ItemActions(false);
                    $success = $mItems->delete($item[0]['s_secret'], $item[0]['pk_i_id']);
                    if ($success) {
                        osc_add_flash_ok_message(_m('Your listing has been deleted'));
                    } else {
                        osc_add_flash_error_message(_m("The listing you are trying to delete couldn't be deleted"));
                    }
                    if ($this->user != null) {
                        $this->redirectTo(osc_user_list_items_url());
                    } else {
                        $this->redirectTo(osc_base_url());
                    }
                } else {
                    osc_add_flash_error_message(_m("The listing you are trying to delete couldn't be deleted"));
                    $this->redirectTo(osc_base_url());
                }
                break;
            case 'deleteResources': // Delete images via AJAX
                $id     = Params::getParam('id');
                $item   = Params::getParam('item');
                $code   = Params::getParam('code');
                $secret = Params::getParam('secret');

                if (Session::newInstance()->_get('userId') != '') {
                    $userId = Session::newInstance()->_get('userId');
                    $user   = User::newInstance()->findByPrimaryKey($userId);
                } else {
                    $userId = null;
                    $user   = null;
                }

                if (!(is_numeric($id) && is_numeric($item)
                    && preg_match('/^([a-z0-9]+)$/i', $code))
                ) {
                    osc_add_flash_error_message(_m("The selected photo couldn't be deleted, the url doesn't exist"));
                    $this->redirectTo(osc_item_edit_url($secret, $item));
                }

                $aItem = Item::newInstance()->findByPrimaryKey($item);
                if (count($aItem) == 0) {
                    osc_add_flash_error_message(_m("The listing doesn't exist"));
                    $this->redirectTo(osc_item_edit_url($secret, $item));
                }

                if (!osc_is_admin_user_logged_in()) {
                    if ($userId != null && $userId != $aItem['fk_i_user_id']) {
                        osc_add_flash_error_message(_m("The listing doesn't belong to you"));
                        $this->redirectTo(osc_item_edit_url($secret, $item));
                    }

                    if ($userId == null && $aItem['fk_i_user_id'] == null
                        && $secret != $aItem['s_secret']
                    ) {
                        osc_add_flash_error_message(_m("The listing doesn't belong to you"));
                        $this->redirectTo(osc_item_edit_url($secret, $item));
                    }
                }

                $result = ItemResource::newInstance()->existResource($id, $code);

                if ($result > 0) {
                    $resource = ItemResource::newInstance()->findByPrimaryKey($id);

                    if ($resource['fk_i_item_id'] == $item) {
                        osc_deleteResource($id, false);
                        Log::newInstance()->insertLog(
                            'item',
                            'deleteResource',
                            $id,
                            $id,
                            'user',
                            osc_logged_user_id()
                        );
                        ItemResource::newInstance()->delete(array(
                            'pk_i_id'      => $id,
                            'fk_i_item_id' => $item,
                            's_name'       => $code
                        ));
                        osc_add_flash_ok_message(_m('The selected photo has been successfully deleted'));
                    } else {
                        osc_add_flash_error_message(_m('The selected photo does not belong to you'));
                    }
                } else {
                    osc_add_flash_error_message(_m("The selected photo couldn't be deleted"));
                }

                $this->redirectTo(osc_item_edit_url($secret, $item));
                break;
            case 'mark':
                // Reporting changes state, so it requires a valid CSRF token: a report must
                // come from a real form submission on the listing page. The modern report
                // form (a POST that carries the auto-injected token) works unchanged; the
                // legacy tokenless GET report links (osc_item_link_*) are no longer honoured,
                // which also removes the "a prefetch/<img> silently marks a listing" vector.
                osc_csrf_check();

                $id = Params::getParam('id');
                $as = Params::getParam('as');

                $item = Item::newInstance()->findByPrimaryKey($id);
                if (count($item) == 0) {
                    osc_add_flash_error_message(_m("This listing doesn't exist"));
                    $this->redirectTo(osc_base_url(true));
                }
                View::newInstance()->_exportVariableToView('item', $item);

                // Optional CAPTCHA on the report, when enabled and a provider is active —
                // the anonymous-abuse gate for installs that want it.
                if (osc_recaptcha_reports_enabled() && osc_captcha_enabled()
                    && !osc_check_captcha()
                ) {
                    osc_add_flash_error_message(_m('Please complete the security check.'));
                    $this->redirectTo(osc_item_url());

                    return false; // BREAK THE PROCESS, THE CAPTCHA IS WRONG
                }

                // Any further gating (per-reporter dedup, rate-limit) belongs in a listener on
                // the item_mark filter — mark() applies it. The old user-agent allowlist here was
                // broken both ways: it silently dropped reports from browsers not on its stale
                // list (e.g. Firefox) while letting bots that spoof a known UA straight through.
                (new ItemActions(false))->mark($id, $as);

                osc_add_flash_ok_message(_m("Thanks! That's very helpful"));
                $this->redirectTo(osc_item_url());
                break;
            case 'send_friend':
                $item = $this->itemManager->findByPrimaryKey(Params::getParam('id'));

                $this->_exportVariableToView('item', $item);

                // Master switch (off by default). Don't 404 or fatally break a theme
                // that still links here — bounce back to the listing with a notice.
                if (!osc_enable_send_friend()) {
                    osc_add_flash_warning_message(
                        _m('Sharing listings by email has been disabled by the administrator')
                    );
                    $this->redirectTo(osc_item_url());
                }

                // Sharing is an authenticated action by default: it relays site-branded
                // mail to a request-supplied address, so require a login unless opted out.
                if (osc_reg_user_can_send_friend() && !osc_is_web_user_logged_in()) {
                    osc_add_flash_warning_message(_m('Only registered users can share listings'));
                    $this->redirectTo(osc_user_login_url());
                }

                $this->doView(osc_locate_template(array('item-send-friend.php'), 'item-send-friend'));
                break;
            case 'send_friend_post':
                osc_csrf_check();
                if (!osc_enable_send_friend()) {
                    osc_add_flash_warning_message(
                        _m('Sharing listings by email has been disabled by the administrator')
                    );
                    $this->redirectTo(osc_item_url());
                }
                if (osc_reg_user_can_send_friend() && !osc_is_web_user_logged_in()) {
                    osc_add_flash_warning_message(_m('Only registered users can share listings'));
                    $this->redirectTo(osc_user_login_url());
                }
                $item = $this->itemManager->findByPrimaryKey(Params::getParam('id'));
                $this->_exportVariableToView('item', $item);

                Session::newInstance()->_setForm('yourEmail', Params::getParam('yourEmail'));
                Session::newInstance()->_setForm('yourName', Params::getParam('yourName'));
                Session::newInstance()->_setForm('friendName', Params::getParam('friendName'));
                Session::newInstance()->_setForm('friendEmail', Params::getParam('friendEmail'));
                Session::newInstance()->_setForm('message_body', Params::getParam('message'));

                if (osc_captcha_enabled() && !osc_check_captcha()) {
                    osc_add_flash_error_message(_m('Please complete the security check.'));
                    $this->redirectTo(osc_item_send_friend_url());

                    return false; // BREAK THE PROCESS, THE CAPTCHA IS WRONG
                }

                // Bound how many listings one source may share per window — the form
                // relays site-branded mail, so it needs a ceiling regardless of the login.
                if (\mindstellar\security\ActionThrottle::exceeded(
                    'send_friend',
                    (int)osc_apply_filter('send_friend_throttle_max', 5),
                    (int)osc_apply_filter('send_friend_throttle_window', 3600)
                )) {
                    osc_add_flash_error_message(
                        _m("You've shared too many listings recently. Please try again later.")
                    );
                    $this->redirectTo(osc_item_send_friend_url());
                }

                osc_run_hook('pre_item_send_friend_post', $item);

                $mItem  = new ItemActions(false);
                $result = $mItem->send_friend();

                osc_run_hook('post_item_send_friend_post', $item);

                if (is_string($result)) {
                    // Validation failed — keep the submitted values and show the error.
                    osc_add_flash_error_message($result);
                    $this->redirectTo(osc_item_send_friend_url());
                } else {
                    // Count the accepted send toward the window.
                    \mindstellar\security\ActionThrottle::record('send_friend');
                    Session::newInstance()->_clearVariables();
                    $this->redirectTo(osc_item_url());
                }
                break;
            case 'contact':
                $item = $this->itemManager->findByPrimaryKey(Params::getParam('id'));
                if (empty($item)) {
                    osc_add_flash_error_message(_m("This listing doesn't exist"));
                    $this->redirectTo(osc_base_url(true));
                } else {
                    $this->_exportVariableToView('item', $item);

                    if (osc_item_is_expired()) {
                        osc_add_flash_error_message(
                            _m("We're sorry, but the listing has expired. You can't contact the seller")
                        );
                        $this->redirectTo(osc_item_url());
                    }

                    if ((osc_reg_user_can_contact() && osc_is_web_user_logged_in())
                        || !osc_reg_user_can_contact()
                    ) {
                        $this->doView(osc_locate_template(array('item-contact.php'), 'item-contact'));
                    } else {
                        osc_add_flash_warning_message(_m("You can't contact the seller, only registered users can")
                            . '. <br />' . sprintf(
                                _m('<a href="%s">Click here to sign-in</a>'),
                                osc_user_login_url()
                            ));
                        $this->redirectTo(osc_item_url());
                    }
                }
                break;
            case 'contact_post':
                osc_csrf_check();
                if (osc_reg_user_can_contact() && !osc_is_web_user_logged_in()) {
                    osc_add_flash_warning_message(_m("You can't contact the seller, only registered users can"));
                    $this->redirectTo(osc_base_url(true));
                }

                $item = $this->itemManager->findByPrimaryKey(Params::getParam('id'));
                $this->_exportVariableToView('item', $item);
                if (osc_captcha_enabled() && !osc_check_captcha()) {
                    osc_add_flash_error_message(_m('Please complete the security check.'));
                    Session::newInstance()
                        ->_setForm('yourEmail', Params::getParam('yourEmail'));
                    Session::newInstance()->_setForm('yourName', Params::getParam('yourName'));
                    Session::newInstance()
                        ->_setForm('phoneNumber', Params::getParam('phoneNumber'));
                    Session::newInstance()
                        ->_setForm('message_body', Params::getParam('message'));
                    $this->redirectTo(osc_item_url());

                    return false; // BREAK THE PROCESS, THE CAPTCHA IS WRONG
                }

                $banned = osc_is_banned(Params::getParam('yourEmail'));
                if ($banned == 1) {
                    osc_add_flash_error_message(_m('Your current email is not allowed'));
                    $this->redirectTo(osc_item_url());
                } elseif ($banned == 2) {
                    osc_add_flash_error_message(_m('Your current IP is not allowed'));
                    $this->redirectTo(osc_item_url());
                }

                if (osc_isExpired($item['dt_expiration'])) {
                    osc_add_flash_error_message(
                        _m("We're sorry, but the listing has expired. You can't contact the seller")
                    );
                    $this->redirectTo(osc_item_url());
                }

                // Bound how many enquiries one source may send per window (defence in
                // depth: contact only reaches a listing's own seller, not an arbitrary
                // address, so the default ceiling is looser than share-a-listing).
                if (\mindstellar\security\ActionThrottle::exceeded(
                    'item_contact',
                    (int)osc_apply_filter('item_contact_throttle_max', 15),
                    (int)osc_apply_filter('item_contact_throttle_window', 3600)
                )) {
                    osc_add_flash_error_message(
                        _m("You've sent too many messages recently. Please try again later.")
                    );
                    $this->redirectTo(osc_item_url());
                }

                osc_run_hook('pre_item_contact_post', $item);

                $mItem  = new ItemActions(false);
                $result = $mItem->contact();

                osc_run_hook('post_item_contact_post', $item);
                if (is_string($result)) {
                    osc_add_flash_error_message($result);
                } else {
                    // Count the accepted enquiry toward the window.
                    \mindstellar\security\ActionThrottle::record('item_contact');
                    osc_add_flash_ok_message(_m("We've just sent an e-mail to the seller"));
                }

                $this->redirectTo(osc_item_url());
                break;
            case 'add_comment':
                osc_csrf_check();

                $itemId = Params::getParam('id');
                $item   = Item::newInstance()->findByPrimaryKey($itemId);
                $this->_exportVariableToView('item', $item);

                if (osc_recaptcha_comments_enabled() && osc_captcha_enabled()
                    && !osc_check_captcha()
                ) {
                    osc_add_flash_error_message(_m('Please complete the security check.'));
                    $this->redirectTo(osc_item_url());

                    return false; // BREAK THE PROCESS, THE CAPTCHA IS WRONG
                }

                osc_run_hook('pre_item_add_comment_post', $item);

                $mItem  = new ItemActions(false);
                $status = $mItem->add_comment();

                switch ($status) {
                    case -1:
                        $msg = _m('Sorry, we could not save your comment. Try again later');
                        osc_add_flash_error_message($msg);
                        break;
                    case 1:
                        $msg = _m('Your comment is awaiting moderation');
                        osc_add_flash_info_message($msg);
                        break;
                    case 2:
                        $msg = _m('Your comment has been approved');
                        osc_add_flash_ok_message($msg);
                        break;
                    case 3:
                        $msg = _m('Please fill the required field (email)');
                        osc_add_flash_warning_message($msg);
                        break;
                    case 4:
                        $msg = _m('Please type a comment');
                        osc_add_flash_warning_message($msg);
                        break;
                    case 5:
                        $msg = _m('Your comment has been marked as spam');
                        osc_add_flash_error_message($msg);
                        break;
                    case 6:
                        $msg = _m('You need to be logged to comment');
                        osc_add_flash_error_message($msg);
                        break;
                    case 7:
                        $msg = _m('Sorry, comments are disabled');
                        osc_add_flash_error_message($msg);
                        break;
                }

                // View::newInstance()->_exportVariableToView('item', Item::newInstance()->findByPrimaryKey(Params::getParam('id')));
                $this->redirectTo(osc_item_url());
                break;
            case 'delete_comment':
                osc_csrf_check();

                $commentId = Params::getParam('comment');
                $itemId    = Params::getParam('id');
                $item      = Item::newInstance()->findByPrimaryKey($itemId);

                osc_run_hook('pre_item_delete_comment_post', $item, $commentId);

                $mItem = new ItemActions(false);

                $mItem->add_comment();

                if (count($item) == 0) {
                    osc_add_flash_error_message(_m("This listing doesn't exist"));
                    $this->redirectTo(osc_base_url(true));
                }

                View::newInstance()->_exportVariableToView('item', $item);

                if ($this->userId == null) {
                    osc_add_flash_error_message(_m('You must be logged in to delete a comment'));
                    $this->redirectTo(osc_item_url());
                }

                $commentManager = ItemComment::newInstance();
                $aComment       = $commentManager->findByPrimaryKey($commentId);

                if (count($aComment) == 0) {
                    osc_add_flash_error_message(_m("The comment doesn't exist"));
                    $this->redirectTo(osc_item_url());
                }

                if ($aComment['b_active'] != 1) {
                    osc_add_flash_error_message(_m('The comment is not active, you cannot delete it'));
                    $this->redirectTo(osc_item_url());
                }

                if ($aComment['fk_i_user_id'] != $this->userId) {
                    osc_add_flash_error_message(_m('The comment was not added by you, you cannot delete it'));
                    $this->redirectTo(osc_item_url());
                }

                $commentManager->deleteByPrimaryKey($commentId);
                osc_add_flash_ok_message(_m('The comment has been deleted'));
                $this->redirectTo(osc_item_url());
                break;
            default:
                // Reject a non-numeric or array-valued id before it reaches the lookup —
                // a crawler on junk URLs costs no query.
                $id = trim(Params::getParamString('id'));
                if ($id === '' || !ctype_digit($id)) {
                    $this->do404();

                    return;
                }

                if (Params::getParam('lang') && (new Validate())->localeCode(Params::getParam('lang'))) {
                    osc_set_current_user_locale(Params::getParam('lang'));
                }

                $item = osc_apply_filter(
                    'pre_show_item',
                    $this->itemManager->findByPrimaryKey($id)
                );
                if (count($item) == 0) {
                    $this->do404();

                    return;
                }

                if ($item['b_active'] != 1) {
                    if ((($this->userId == $item['fk_i_user_id']) && ($this->userId != ''))
                        || osc_is_admin_user_logged_in()
                    ) {
                        osc_add_flash_warning_message(
                            _m("The listing hasn't been validated. Please validate it in order to make it public")
                        );
                    } else {
                        // Not public yet: 404, not 400. It is a well-formed URL for a listing
                        // that may be published later, so nothing permanent is signalled.
                        $this->do404();

                        return;
                    }
                } elseif ($item['b_enabled'] == 0) {
                    if (osc_is_admin_user_logged_in()) {
                        osc_add_flash_warning_message(
                            _m("The listing hasn't been enabled. Please enable it in order to make it public")
                        );
                    } elseif (osc_is_web_user_logged_in()
                        && osc_logged_user_id() == $item['fk_i_user_id']
                    ) {
                        osc_add_flash_warning_message(
                            _m('The listing has been blocked or is awaiting moderation from the admin')
                        );
                    } else {
                        $this->do404();

                        return;
                    }
                }

                // Neither the admin nor the listing's own owner counts as a view. The final
                // gate is filterable ('count_view_on_render') so a theme that counts views
                // client-side — e.g. a pixel/beacon, which stays accurate when the item page
                // is served from a full-page cache and PHP never runs — can switch off this
                // render-time increment without also disabling the counter itself.
                if (!osc_is_admin_user_logged_in()
                    && !($item['fk_i_user_id'] != ''
                        && $item['fk_i_user_id'] == osc_logged_user_id())
                    && osc_apply_filter('count_view_on_render', osc_request_counts_as_view(), $item)
                ) {
                    ItemStats::newInstance()->increase('i_num_views', $item['pk_i_id']);
                }

                // When the client beacon owns counting (default), remember this listing id so the
                // page footer can emit the beacon script — the only way a view is counted when the
                // page is served from a full-page cache and PHP never runs on the render.
                if (osc_item_view_beacon_enabled()) {
                    $GLOBALS['osc_view_beacon_item_id'] = (int)$item['pk_i_id'];
                }

                foreach ($item['locale'] as $k => $v) {
                    if (isset($item['locale'][$k]['s_title'])) {
                        $item['locale'][$k]['s_title'] =
                            osc_apply_filter('item_title', $v['s_title']);
                    }
                    if (isset($item['locale'][$k]['s_description'])) {
                        $item['locale'][$k]['s_description'] =
                            nl2br(osc_apply_filter('item_description', $v['s_description']));
                    }
                }

                if ($item['fk_i_user_id'] != '') {
                    $user = User::newInstance()->findByPrimaryKey($item['fk_i_user_id']);
                    $this->_exportVariableToView('user', $user);
                }

                $this->_exportVariableToView('item', $item);

                osc_run_hook('show_item', $item);

                // redirect to the correct url just in case it has changed
                $itemURI = str_replace(osc_base_url(), '', osc_item_url());
                $URI     = Params::getRequestURI(false, false, false);
                // do not clean QUERY_STRING if permalink is not enabled
                if (osc_rewrite_enabled()) {
                    $URI =
                        str_replace(
                            '?' . Params::getServerParam('QUERY_STRING', false, false),
                            '',
                            $URI
                        );
                } else {
                    $params_keep = array('page', 'id');
                    $params      = array();
                    foreach (Params::getParamsAsArray('get') as $k => $v) {
                        if (in_array($k, $params_keep)) {
                            $params[] = "$k=$v";
                        }
                    }
                    $URI = 'index.php?' . implode('&', $params);
                }

                // redirect to the correct url
                if ($itemURI != $URI) {
                    $this->redirectTo(osc_base_url() . $itemURI, 301);
                }

                // Self-referential canonical so parameterised or duplicate variants of the
                // listing consolidate onto the one indexable URL (SEO).
                $this->_exportVariableToView('canonical', osc_item_url());

                // Public listing detail: cacheable for anonymous visitors. A cached hit skips
                // the render-time view increment above; sites that need exact counts drive the
                // counter client-side (the `count_view_on_render` filter), which stays accurate
                // behind a full-page cache.
                osc_mark_response_cacheable();

                // A theme may specialise the listing page per category. The id is
                // already on the row, so offering the candidate costs nothing.
                $viewCandidates = array();
                $viewCategory   = osc_item_category_id();
                if ($viewCategory > 0) {
                    $viewCandidates[] = 'item-' . $viewCategory . '.php';
                }
                $viewCandidates[] = 'item.php';

                $this->doView(osc_locate_template($viewCandidates, 'item'));
                break;
        }
    }

    /**
     * Record one view for a listing from the client beacon (a POST fired by the listing page's
     * footer script). Runs on every request — never cached — so it counts even when the page
     * itself was served from a full-page cache. Applies the same gate as the render-time count:
     * views enabled, not a bot (unless bot views are counted), and never the admin or the
     * listing's own owner. Answers 204 with no body.
     *
     * @return void
     */
    private function countItemViewBeacon()
    {
        if (strtoupper((string)Params::getServerParam('REQUEST_METHOD')) !== 'POST') {
            header('HTTP/1.1 405 Method Not Allowed');

            return;
        }

        $id = Params::getParamInt('id');
        if ($id > 0 && osc_apply_filter('count_view_on_beacon', osc_request_counts_as_view(), $id)) {
            $item = $this->itemManager->findByPrimaryKey($id);
            if (!empty($item)
                && !osc_is_admin_user_logged_in()
                && !($item['fk_i_user_id'] != '' && $item['fk_i_user_id'] == osc_logged_user_id())
            ) {
                ItemStats::newInstance()->increase('i_num_views', $id);
            }
        }

        header('HTTP/1.1 204 No Content');
    }

    //hopefully generic...

    /**
     * @param $file
     *
     * @return void
     */
    public function doView($file)
    {
        osc_run_hook('before_html');
        if (!osc_gui_page_view($file)) {
            osc_current_web_theme_path($file);
        }
        Session::newInstance()->_clearVariables();
        osc_run_hook('after_html');
    }
}

/* file end: ./CWebItem.php */
