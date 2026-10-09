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

use mindstellar\auth\Actor;
use mindstellar\comment\CommentService;
use mindstellar\comment\SavedComment;
use mindstellar\listing\ListingCounters;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingMailService;
use mindstellar\listing\ListingNotices;
use mindstellar\listing\ListingPolicy;
use mindstellar\listing\ListingService;
use mindstellar\listing\PhotoService;
use mindstellar\listing\UploadTmpStore;
use mindstellar\security\Captcha;
use mindstellar\utility\Validate;
use mindstellar\validation\ConflictException;
use mindstellar\validation\InvalidException;
use mindstellar\validation\RefusedException;

/**
 * Class CWebItem
 */
class CWebItem extends BaseModel
{
    private $itemManager;
    private $user;

    /**
     * Boots the base controller, opens the Item model, loads the signed-in user (if any)
     * and fires the `init_item` hook.
     */
    public function __construct()
    {
        parent::__construct();
        $this->itemManager = Item::getInstance();

        $this->user = osc_is_web_user_logged_in() ? User::getInstance()->findByPrimaryKey(osc_logged_user_id()) : null;
        osc_run_hook('init_item');
    }

    //Business Layer...

    /**
     * Dispatches every listing action -- publish, edit, activate, delete, contact, send to a
     * friend, comments, the view beacon -- and renders the listing page by default.
     *
     * @return false|null false only when a captcha check failed and the request was
     *                    redirected back to the form
     */
    public function doModel()
    {
        //calling the view...

        // The view beacon is a lightweight POST endpoint the listing page's client script hits on
        // load; short-circuit before the page-render setup below so it stays cheap.
        if ($this->action === 'view_beacon') {
            $this->countItemViewBeacon();

            return null;
        }

        $locales = OSCLocale::getInstance()->listAllEnabled();
        $this->_exportVariableToView('locales', $locales);

        switch ($this->action) {
            case 'item_add': // post
                if (ListingPolicy::requiresSignIn(Actor::visitor())) {
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

                $countries = Country::getInstance()->listAll();

                // Regions and cities follow a country and a region the seller
                // actually chose. Falling back to the first country listed filled
                // both selects with somewhere else's places while the country
                // select still read "Select a country", so a visitor with no
                // JavaScript could file a listing against a city in another country.
                $countryId = $this->user['fk_c_country_code'] ?? '';
                $regionId  = $this->user['fk_i_region_id'] ?? '';

                $regions = $countryId != ''
                    ? Region::getInstance()->findByCountry($countryId)
                    : array();
                $cities = $regionId != ''
                    ? City::getInstance()->findByRegion($regionId)
                    : array();

                $this->_exportVariableToView('countries', $countries);
                $this->_exportVariableToView('regions', $regions);
                $this->_exportVariableToView('cities', $cities);

                $form     = count(Session::getInstance()->_getForm());
                $keepForm = count(Session::getInstance()->_getKeepForm());
                if ($form == 0 || $form == $keepForm) {
                    Session::getInstance()->_dropKeepForm();
                }
                if ($form == 0) {
                    // Fresh post form (no submitted data to restore): drop any temp
                    // uploads a previous, abandoned posting left in the session so they
                    // can't silently attach to this new listing.
                    UploadTmpStore::removeOwner(UploadTmpStore::formOwner());
                }

                if (Session::getInstance()->_getForm('countryId') != '') {
                    $countryId = Session::getInstance()->_getForm('countryId');
                    $regions   = Region::getInstance()->findByCountry($countryId);
                    $this->_exportVariableToView('regions', $regions);
                    if (Session::getInstance()->_getForm('regionId') != '') {
                        $regionId = Session::getInstance()->_getForm('regionId');
                        $cities   = City::getInstance()->findByRegion($regionId);
                        $this->_exportVariableToView('cities', $cities);
                    }
                }

                $this->_exportVariableToView('user', $this->user);

                osc_run_hook('post_item');

                $this->doView(osc_locate_template(array('item-post.php'), 'item-post'));
                break;
            case 'item_add_post':
                // SAVE form data before CSRF CHECK
                $formData = ListingInput::read(false, true);
                $meta     = Params::getParam('meta');
                ListingInput::keep($formData, $meta);

                osc_csrf_check();

                if (ListingPolicy::requiresSignIn(Actor::visitor())) {
                    osc_add_flash_warning_message(_m('Only registered users are allowed to post listings'));
                    $this->redirectTo(osc_base_url(true));
                }

                if (!Captcha::passes('items')) {
                    osc_add_flash_error_message(Captcha::failMessage());
                    $this->redirectTo(osc_item_post_url());

                    return false; // BREAK THE PROCESS, THE CAPTCHA IS WRONG
                }

                if (ListingPolicy::usesAccountEmail(Actor::visitor(), (string) $formData['contactEmail'])) {
                    foreach ($formData as $key => $value) {
                        Session::getInstance()->_keepForm($key);
                    }
                    osc_add_flash_error_message(ListingService::accountEmailMessage());
                    $this->redirectTo(osc_user_login_url());
                }

                // Bans, the posting wait and the form's own checks are the service's.
                $listings = new ListingService();
                try {
                    $saved = $listings->create($this->listingData($formData), Actor::visitor());
                } catch (RefusedException $e) {
                    ListingNotices::flash($e->notices(), false);
                    osc_add_flash_error_message($e->getMessage());
                    $this->redirectTo(osc_item_post_url());

                    return false;
                }
                ListingNotices::flash($saved->notices(), false);

                ListingInput::dropKept($meta);
                Session::getInstance()->_clearVariables();
                // Uploads were consumed by the successful post; drop the session
                // mapping so it can't bleed into the next listing.
                UploadTmpStore::removeOwner(UploadTmpStore::formOwner());
                if ($saved->needsValidation()) {
                    osc_add_flash_ok_message(_m('Check your inbox to validate your listing'));
                } elseif (osc_moderate_admin_post()) {
                    osc_add_flash_ok_message(_m('Your listing will be published after an admin approves it.'));
                } else {
                    osc_add_flash_ok_message(_m('Your listing has been published'));
                }

                $category =
                    Category::getInstance()->findByPrimaryKey(Params::getParamInt('catId'));
                View::getInstance()->_exportVariableToView('category', $category);
                // Let a theme or plugin send the seller somewhere other than the category
                // search page after publishing — e.g. straight to the new listing.
                $this->redirectTo(
                    osc_apply_filter('item_post_redirect_url', osc_search_category_url(), $saved->id(), $category)
                );
                break;
            case 'item_edit':   // edit item
                $secret = Params::getParamString('secret');
                $id     = Params::getParamInt('id');
                $item   = $this->editable($id, $secret);
                if ($item !== null) {
                    $form     = count(Session::getInstance()->_getForm());
                    $keepForm = count(Session::getInstance()->_getKeepForm());
                    if ($form == 0 || $form == $keepForm) {
                        Session::getInstance()->_dropKeepForm();
                    }
                    if ($form == 0) {
                        // Fresh edit form: drop temp uploads left by an earlier,
                        // abandoned posting so they can't attach to this item.
                        UploadTmpStore::removeOwner(UploadTmpStore::formOwner());
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
                $formData = ListingInput::read(false, false);
                $meta     = Params::getParam('meta');
                ListingInput::keep($formData, $meta);

                osc_csrf_check();

                $secret = Params::getParamString('secret');
                $id     = Params::getParamInt('id');
                $item   = $this->editable($id, $secret);

                if ($item !== null) {
                    $this->_exportVariableToView('item', $item);

                    if (!Captcha::passes('items')) {
                        osc_add_flash_error_message(Captcha::failMessage());
                        $this->redirectTo(osc_item_edit_url($secret, $id));

                        return false; // BREAK THE PROCESS, THE CAPTCHA IS WRONG
                    }

                    $listings = new ListingService();
                    $data     = $this->listingData($formData);
                    // Save to the listing that passed the owner check, never a second reading of the id.
                    $data['idItem'] = (int) $item['pk_i_id'];
                    try {
                        $saved   = $listings->update($data, Actor::visitor());
                        $success = $saved->rows();
                        ListingNotices::flash($saved->notices(), false);
                    } catch (RefusedException $e) {
                        $success = $e->getMessage();
                        ListingNotices::flash($e->notices(), false);
                    }

                    if ($success === 1) {
                        ListingInput::dropKept($meta);
                        Session::getInstance()->_clearVariables();
                        // Uploads were consumed by the successful edit; drop the session
                        // mapping so it can't bleed into a later listing.
                        UploadTmpStore::removeOwner(UploadTmpStore::formOwner());
                        if (osc_moderate_admin_edit()) {
                            osc_add_flash_ok_message(_m('Your listing will be published after an admin approves the changes.'));
                        } else {
                            osc_add_flash_ok_message(_m("Great! We've just updated your listing"));
                        }
                        View::getInstance()->_exportVariableToView(
                            'item',
                            Item::getInstance()->findByPrimaryKey($id)
                        );
                        $this->redirectTo(osc_item_url());
                    } else {
                        osc_add_flash_error_message($success);
                        $this->redirectTo(osc_item_edit_url($secret, $id));
                    }
                }
                break;
            case 'activate':
                $secret = Params::getParamString('secret');
                $id     = Params::getParamInt('id');
                $row    = $id > 0 ? $this->itemManager->findByPrimaryKey($id) : null;
                $actor  = Actor::visitor($secret);
                $item   = array();
                if (is_array($row) && isset($row['pk_i_id'])
                    && (ListingPolicy::isOwner($row, $actor) || ListingPolicy::holdsSecret($row, $actor))
                ) {
                    $item = array($row);
                }

                // item doesn't exist
                if (count($item) == 0) {
                    $this->do404();

                    return null;
                }

                View::getInstance()->_exportVariableToView('item', $item[0]);
                if ($item[0]['b_active'] == 0) {
                    $success = (new ListingService())->activate((int) $item[0]['pk_i_id'], (string) $item[0]['s_secret']);

                    if ($success) {
                        osc_add_flash_ok_message(_m('The listing has been validated'));
                        // The item page hides a listing from a guest, so send them home with
                        // the reason. The owner's item page already explains it.
                        if (!ListingPolicy::canView(array('b_active' => 1) + $item[0], Actor::visitor())) {
                            osc_add_flash_warning_message(
                                _m('The listing will be public once the admin has approved it')
                            );
                            $this->redirectTo(osc_base_url());
                        }
                    } else {
                        osc_add_flash_error_message(_m("The listing can't be validated"));
                    }
                } else {
                    osc_add_flash_warning_message(_m('The listing has already been validated'));
                }

                $this->redirectTo(osc_item_url());
                break;
            case 'item_delete':
                $actor    = Actor::visitor(Params::getParamString('secret'));
                $item     = $this->itemManager->findByPrimaryKey(Params::getParamInt('id'));
                $item     = is_array($item) && isset($item['pk_i_id']) ? $item : null;
                $bySecret = $item !== null && ListingPolicy::holdsSecret($item, $actor);
                $byOwner  = $item !== null && ListingPolicy::isOwner($item, $actor);
                if (!$bySecret && $byOwner) {
                    // The owner's link carries no secret, so it must carry a CSRF token.
                    osc_csrf_check();
                }
                if ($bySecret || $byOwner) {
                    $success = (new ListingService())->delete((int) $item['pk_i_id'], (string) $item['s_secret'], $actor);
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
                $code   = Params::getParamString('code');
                $secret = Params::getParamString('secret');

                if (!(is_numeric($id) && is_numeric($item)
                    && preg_match('/^([a-z0-9]+)$/i', $code))
                ) {
                    osc_add_flash_error_message(_m("The selected photo couldn't be deleted, the url doesn't exist"));
                    $this->redirectTo(osc_item_edit_url($secret, $item));
                }

                $aItem = Item::getInstance()->findByPrimaryKey((int) $item);
                if (count($aItem) == 0) {
                    osc_add_flash_error_message(_m("The listing doesn't exist"));
                    $this->redirectTo(osc_item_edit_url($secret, $item));
                }

                $actor = Actor::visitorOrAdmin($secret);
                if (!ListingPolicy::canManage($aItem, $actor)) {
                    osc_add_flash_error_message(_m("The listing doesn't belong to you"));
                    $this->redirectTo(osc_item_edit_url($secret, $item));
                }

                $result = ItemResource::getInstance()->existResource((int) $id, $code);

                if ($result > 0) {
                    $resource = ItemResource::getInstance()->findByPrimaryKey($id);

                    if (ListingPolicy::isPhotoOf($resource, $aItem, $code)
                        && (new PhotoService())->delete((int) $id, (int) $item, $actor, $code)
                    ) {
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

                $item = Item::getInstance()->findByPrimaryKey((int) $id);
                if (count($item) == 0) {
                    osc_add_flash_error_message(_m("This listing doesn't exist"));
                    $this->redirectTo(osc_base_url(true));
                }
                View::getInstance()->_exportVariableToView('item', $item);

                // Optional CAPTCHA on the report, when enabled and a provider is active —
                // the anonymous-abuse gate for installs that want it.
                if (!Captcha::passes('reports')) {
                    osc_add_flash_error_message(Captcha::failMessage());
                    $this->redirectTo(osc_item_url());

                    return false; // BREAK THE PROCESS, THE CAPTCHA IS WRONG
                }

                // Any further gating (per-reporter dedup, rate-limit) belongs in a listener on
                // the item_mark filter — mark() applies it. The old user-agent allowlist here was
                // broken both ways: it silently dropped reports from browsers not on its stale
                // list (e.g. Firefox) while letting bots that spoof a known UA straight through.
                (new ListingService())->mark((int) $id, (string) $as);

                osc_add_flash_ok_message(_m("Thanks! That's very helpful"));
                $this->redirectTo(osc_item_url());
                break;
            case 'send_friend':
                $item = $this->itemManager->findByPrimaryKey(Params::getParam('id'));
                $this->notFoundIfHidden($item);

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
                $this->notFoundIfHidden($item);
                $this->_exportVariableToView('item', $item);

                Session::getInstance()->_setForm('yourEmail', Params::getParam('yourEmail'));
                Session::getInstance()->_setForm('yourName', Params::getParam('yourName'));
                Session::getInstance()->_setForm('friendName', Params::getParam('friendName'));
                Session::getInstance()->_setForm('friendEmail', Params::getParam('friendEmail'));
                Session::getInstance()->_setForm('message_body', Params::getParam('message'));

                if (!Captcha::passes()) {
                    osc_add_flash_error_message(Captcha::failMessage());
                    $this->redirectTo(osc_item_send_friend_url());

                    return false; // BREAK THE PROCESS, THE CAPTCHA IS WRONG
                }

                $item_url = osc_item_url();
                Params::setParam('item_url', '<a href="' . $item_url . '" >' . $item_url . '</a>');
                try {
                    $sent = (new ListingMailService())->shareWithFriend($item, array(
                        'yourName'    => Params::getParamString('yourName'),
                        'yourEmail'   => Params::getParamString('yourEmail'),
                        'friendName'  => Params::getParamString('friendName'),
                        'friendEmail' => Params::getParamString('friendEmail'),
                        'message'     => Params::getParamString('message'),
                    ));
                } catch (InvalidException $e) {
                    osc_add_flash_error_message(trim(ListingMailService::messages($e)));
                    $this->redirectTo(osc_item_send_friend_url());

                    return false;
                } catch (RefusedException $e) {
                    osc_add_flash_error_message($e->getMessage());
                    $this->redirectTo(osc_item_send_friend_url());

                    return false;
                }
                if ($sent) {
                    osc_add_flash_ok_message(sprintf(_m('We just sent your message to %s'), Params::getParamString('friendName')));
                }
                Session::getInstance()->_clearVariables();
                $this->redirectTo(osc_item_url());
                break;
            case 'contact':
                $item = $this->itemManager->findByPrimaryKey(Params::getParam('id'));
                if (empty($item)) {
                    osc_add_flash_error_message(_m("This listing doesn't exist"));
                    $this->redirectTo(osc_base_url(true));
                } else {
                    $this->notFoundIfHidden($item);
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
                $this->notFoundIfHidden($item);
                $this->_exportVariableToView('item', $item);
                // A failed check goes back to the form it came from, with what was typed.
                $contactValues = array(
                    'yourEmail'    => Params::getParamString('yourEmail'),
                    'yourName'     => Params::getParamString('yourName'),
                    'phoneNumber'  => Params::getParamString('phoneNumber'),
                    'message_body' => Params::getParamString('message'),
                );
                $fail = function (string $error) use ($contactValues) {
                    osc_keep_form($contactValues, $error);
                    $this->redirectTo(osc_local_referer(osc_item_url()));
                };
                if (!Captcha::passes()) {
                    $fail(Captcha::failMessage());

                    return false;
                }

                try {
                    $sent = (new ListingMailService())->contactSeller($item, array(
                        'yourName'    => $contactValues['yourName'],
                        'yourEmail'   => $contactValues['yourEmail'],
                        'phoneNumber' => $contactValues['phoneNumber'],
                        'message'     => $contactValues['message_body'],
                    ), osc_item_attachment() ? static fn () => osc_mail_upload_attachment('attachment') : null);
                } catch (ConflictException $e) {
                    osc_add_flash_error_message($e->getMessage());
                    $this->redirectTo(osc_item_url());

                    return false;
                } catch (InvalidException $e) {
                    $fail(trim(ListingMailService::messages($e)));

                    return false;
                } catch (RefusedException $e) {
                    $fail($e->getMessage());

                    return false;
                }
                if ($sent) {
                    osc_add_flash_ok_message(_m("We've just sent an e-mail to the seller"));
                }

                $this->redirectTo(osc_item_url());
                break;
            case 'add_comment':
                osc_csrf_check();

                $itemId = Params::getParamInt('id');
                $item   = Item::getInstance()->findByPrimaryKey($itemId);
                $this->notFoundIfHidden($item);
                $this->_exportVariableToView('item', $item);

                if (!Captcha::passes('comments')) {
                    osc_add_flash_error_message(Captcha::failMessage());
                    $this->redirectTo(osc_item_url());

                    return false; // BREAK THE PROCESS, THE CAPTCHA IS WRONG
                }

                $input = array(
                    'author_name'  => Params::getParamString('authorName'),
                    'author_email' => Params::getParamString('authorEmail'),
                    'title'        => Params::getParamString('title'),
                    'body'         => Params::getParamString('body'),
                );
                try {
                    $saved = (new CommentService())->post($itemId, $input, Actor::visitor());
                    match ($saved->status()) {
                        SavedComment::LIVE    => osc_add_flash_ok_message(_m('Your comment has been approved')),
                        SavedComment::PENDING => osc_add_flash_info_message(_m('Your comment is awaiting moderation')),
                        default               => osc_add_flash_error_message(_m('Your comment has been marked as spam')),
                    };
                } catch (InvalidException $e) {
                    $this->keepCommentForm($input);
                    osc_add_flash_warning_message($e->getMessage());
                } catch (RefusedException $e) {
                    $this->keepCommentForm($input);
                    osc_add_flash_error_message($e->getMessage());
                } catch (RuntimeException $e) {
                    osc_add_flash_error_message(_m('Sorry, we could not save your comment. Try again later'));
                }

                $this->redirectTo(osc_item_url());
                break;
            case 'delete_comment':
                osc_csrf_check();

                $commentId = Params::getParamInt('comment');
                $itemId    = Params::getParamInt('id');
                $item      = Item::getInstance()->findByPrimaryKey($itemId);
                if (!is_array($item) || $item === array()) {
                    osc_add_flash_error_message(_m("This listing doesn't exist"));
                    $this->redirectTo(osc_base_url(true));
                }
                View::getInstance()->_exportVariableToView('item', $item);

                try {
                    (new CommentService())->delete($commentId, Actor::visitor());
                    osc_add_flash_ok_message(_m('The comment has been deleted'));
                } catch (RefusedException $e) {
                    osc_add_flash_error_message($e->getMessage());
                }
                $this->redirectTo(osc_item_url());
                break;
            default:
                // Reject a non-numeric or array-valued id before it reaches the lookup —
                // a crawler on junk URLs costs no query.
                $id = trim(Params::getParamString('id'));
                if ($id === '' || !ctype_digit($id)) {
                    $this->do404();

                    return null;
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

                    return null;
                }

                // Not validated, disabled or spam: only the owner and admins see it. A 404, not
                // 400 or 410, as the listing may still be published later.
                if (!ListingPolicy::canView($item, Actor::visitorOrAdmin())) {
                    $this->do404();

                    return null;
                }

                if ($item['b_active'] != 1) {
                    osc_add_flash_warning_message(
                        _m("The listing hasn't been validated. Please validate it in order to make it public")
                    );
                } elseif ($item['b_enabled'] == 0 || ($item['b_spam'] ?? 0) == 1) {
                    if (osc_is_admin_user_logged_in()) {
                        osc_add_flash_warning_message(
                            $item['b_enabled'] == 0
                                ? _m("The listing hasn't been enabled. Please enable it in order to make it public")
                                : _m('The listing is marked as spam. Unmark it in order to make it public')
                        );
                    } else {
                        osc_add_flash_warning_message(
                            _m('The listing has been blocked or is awaiting moderation from the admin')
                        );
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
                    ListingCounters::addView((int) $item['pk_i_id']);
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
                    $user = User::getInstance()->findByPrimaryKey($item['fk_i_user_id']);
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

        return null;
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
                ListingCounters::addView($id);
            }
        }

        header('HTTP/1.1 204 No Content');
    }

    //hopefully generic...

    /**
     * Renders the listing template, letting core's page view claim it first.
     *
     * @param string $file Absolute path to the located template
     *
     * @return void
     */
    public function doView($file)
    {
        osc_run_hook('before_html');
        if (!osc_gui_page_view($file)) {
            osc_current_web_theme_path($file);
        }
        Session::getInstance()->_clearVariables();
        osc_run_hook('after_html');
    }

    /**
     * Keep what the comment form sent, so the form shows it again after a refusal.
     *
     * @param array<string,string> $input
     */
    private function keepCommentForm(array $input): void
    {
        Session::getInstance()->_setForm('commentAuthorName', trim(strip_tags($input['author_name'])));
        Session::getInstance()->_setForm('commentAuthorEmail', trim(strip_tags($input['author_email'])));
        Session::getInstance()->_setForm('commentTitle', trim(strip_tags($input['title'])));
        Session::getInstance()->_setForm('commentBody', trim(strip_tags($input['body'])));
    }

    /**
     * The listing this visitor may edit on the public form: their own, or a guest listing
     * whose secret they sent.
     *
     * @return array<string,mixed>|null
     */
    private function editable(int $id, string $secret): ?array
    {
        return ListingPolicy::manageable($id, Actor::visitor($secret));
    }

    /**
     * The listing data the form posted, with its custom field values.
     *
     * @return array<string,mixed>
     */
    private function listingData(array $data): array
    {
        return ListingInput::withMeta($data);
    }

    /**
     * Ends the request with a 404 when the listing is one the public may not see (not
     * validated, disabled or spam), unless the visitor is its owner or an admin.
     *
     * @param array<string,mixed>|mixed $item
     *
     * @return void
     */
    private function notFoundIfHidden($item)
    {
        if (is_array($item) && $item !== array()
            && !ListingPolicy::canView($item, Actor::visitorOrAdmin())
        ) {
            $this->do404();
        }
    }
}

/* file end: ./CWebItem.php */
