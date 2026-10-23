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

define('IS_AJAX', true);

/**
 * Class CWebAjax
 */
use mindstellar\auth\Actor;
use mindstellar\container\Container;
use mindstellar\exception\BlockedException;
use mindstellar\exception\ConflictException;
use mindstellar\exception\ForbiddenException;
use mindstellar\exception\InvalidException;
use mindstellar\exception\NotFoundException;
use mindstellar\exception\RefusedException;
use mindstellar\listing\ListingPolicy;
use mindstellar\listing\PhotoService;
use mindstellar\listing\UploadTmpStore;
use mindstellar\live\LiveChannel;
use mindstellar\live\LivePublisher;
use mindstellar\messaging\MessageStore;
use mindstellar\messaging\MessagingService;
use mindstellar\utility\AjaxResponse;

class CWebAjax extends BaseModel
{
    use \mindstellar\base\ActionMap;

    /** Each action and the method that answers it; any other action goes to noAction(). */
    private const ACTIONS = array(
        'bulk_actions'                => 'bulkActions',
        'regions'                     => 'regions',
        'cities'                      => 'cities',
        'location'                    => 'location',
        'location_countries'          => 'locationCountries',
        'custom_field_autocomplete'   => 'fieldSuggestions',
        'location_regions'            => 'locationRegions',
        'location_cities'             => 'locationCities',
        'delete_image'                => 'deleteImage',
        'alerts'                      => 'alerts',
        'runhook'                     => 'runHook',
        'custom'                      => 'custom',
        'check_username_availability' => 'checkUsername',
        'ajax_upload'                 => 'ajaxUpload',
        'messages_send'               => 'messagesSend',
        'messages_check'              => 'messagesCheck',
        'messages_since'              => 'messagesSince',
        'messages_live_auth'          => 'messagesLiveAuth',
    );

    /**
     * Boots the base controller, flags the request as AJAX and fires the `init_ajax` hook.
     */
    public function __construct()
    {
        parent::__construct();
        $this->ajax = true;
        osc_run_hook('init_ajax');
    }

    /**
     * Business Layer...
     *
     * Dispatches the AJAX action and echoes its JSON response; an unknown action answers
     * with a JSON error.
     *
     * @return void
     */
    public function doModel()
    {
        $method = $this->actionMethod('noAction');

        $this->$method();
    }

    /**
     * Kept so an old theme's call still gets an empty answer.
     */
    private function bulkActions(): void
    {
    }

    /**
     * The regions of a country, in JSON.
     */
    private function regions(): void
    {
        $regions = Region::getInstance()->findByCountry(Params::getParam('countryId'));
        AjaxResponse::shared($regions);
    }

    /**
     * The cities of a region, in JSON.
     */
    private function cities(): void
    {
        $cities = City::getInstance()->findByRegion(Params::getParamInt('regionId'));
        AjaxResponse::shared($cities);
    }

    /**
     * Places that start with the typed text, for the location box.
     */
    private function location(): void
    {
        $cities = City::getInstance()->ajax(Params::getParamString('term'));
        foreach ($cities as $k => $city) {
            $cities[$k]['label'] = $city['label'] . ' (' . $city['region'] . ')';
        }
        AjaxResponse::shared($cities);
    }

    /**
     * Countries that start with the typed text.
     */
    private function locationCountries(): void
    {
        $countries = Country::getInstance()->ajax(Params::getParamString('term'));
        AjaxResponse::shared($countries);
    }

    /**
     * Suggestions for an autocomplete custom field.
     */
    private function fieldSuggestions(): void
    {
        AjaxResponse::json($this->customFieldAutocomplete(
            (int) Params::getParam('field'),
            Params::getParamString('term')
        ));
    }

    /**
     * Regions that start with the typed text.
     */
    private function locationRegions(): void
    {
        $regions = Region::getInstance()
            ->ajax(Params::getParamString('term'), Params::getParamString('country'));
        AjaxResponse::shared($regions);
    }

    /**
     * Cities that start with the typed text.
     */
    private function locationCities(): void
    {
        $cities =
        // @phpstan-ignore argument.type (region may be an id or a name)
            City::getInstance()->ajax(Params::getParamString('term'), Params::getParamString('region'));
        AjaxResponse::shared($cities);
    }

    /**
     * Delete one of a listing's photos.
     */
    private function deleteImage(): void
    {
        $ajax_photo = Params::getParam('ajax_photo');
        $id         = Params::getParam('id');
        $item       = Params::getParam('item');
        $code       = Params::getParamString('code');
        $secret     = Params::getParamString('secret');
        $json       = array();

        if ($ajax_photo != '') {
            // Only a file this browser's upload token staged is removed.
            $success = UploadTmpStore::discard(UploadTmpStore::formOwner(), (string) $ajax_photo, UploadTmpStore::dir());

            AjaxResponse::json(array(
                'success' => $success,
                'msg'     => _m($success
                    ? 'The selected photo has been successfully deleted'
                    : "The selected photo couldn't be deleted")
            ));

            return;
        }

        // Check for required fields
        if (!(is_numeric($id) && is_numeric($item)
            && preg_match('/^([a-z0-9]+)$/i', $code))
        ) {
            $json['success'] = false;
            $json['msg']     =
                _m("The selected photo couldn't be deleted, the url doesn't exist");
            AjaxResponse::json($json);

            return;
        }

        $aItem = Item::getInstance()->findByPrimaryKey((int) $item);

        // Check if the item exists
        if (count($aItem) == 0) {
            $json['success'] = false;
            $json['msg']     = _m("The listing doesn't exist");
            AjaxResponse::json($json);

            return;
        }

        $actor = Actor::visitorOrAdmin($secret);
        if (!ListingPolicy::canManage($aItem, $actor)) {
            $json['success'] = false;
            $json['msg']     = _m("The listing doesn't belong to you");
            AjaxResponse::json($json);

            return;
        }

        // Does id & code combination exist?
        $result = ItemResource::getInstance()->existResource((int) $id, $code);

        if ($result > 0) {
            $resource = ItemResource::getInstance()->findByPrimaryKey($id);

            if (ListingPolicy::isPhotoOf($resource, $aItem, $code)
                && (new PhotoService())->delete((int) $id, (int) $item, $actor, $code)
            ) {
                $json['msg']     = _m('The selected photo has been successfully deleted');
                $json['success'] = 'true';
            } else {
                $json['msg']     = _m('The selected photo does not belong to you');
                $json['success'] = 'false';
            }
        } else {
            $json['msg']     = _m("The selected photo couldn't be deleted");
            $json['success'] = 'false';
        }

        AjaxResponse::json($json);

        return;
    }

    /**
     * Save a search alert for an e-mail address.
     */
    private function alerts(): void
    {
        echo (string)osc_subscribe_alert(Params::getParamString('alert'), Params::getParamString('email'));
    }

    /**
     * Run a hook the listing form asks for: item_form, item_edit or an ajax_ hook.
     */
    private function runHook(): void
    {
        $hook = Params::getParam('hook');

        if ($hook == '') {
            AjaxResponse::json(array('error' => 'hook parameter not defined'));
            return;
        }

        switch ($hook) {
            case 'item_form':
                osc_run_hook('item_form', Params::getParam('catId'));
                break;
            case 'item_edit':
                $catId  = Params::getParam('catId');
                $itemId = Params::getParamInt('itemId');
                // Stored values go only to someone who may edit the listing.
                if ($itemId > 0 && ListingPolicy::manageable($itemId, Actor::visitorOrAdmin(Params::getParamString('secret'))) === null) {
                    $itemId = 0;
                }
                osc_run_hook('item_edit', $catId, $itemId);
                break;
            default:
                osc_run_hook('ajax_' . $hook);
                break;
        }
    }

    /**
     * Run a plugin's ajax file.
     */
    private function custom(): void
    {
        if (Params::existParam('route')) {
            $routes = Rewrite::getInstance()->getRoutes();
            $rid    = Params::getParamString('route');
            $file   = '../';
            if (isset($routes[$rid]['file'])) {
                $file = $routes[$rid]['file'];
            }
        } else {
            // DEPRECATED: Disclosed path in URL is deprecated, use routes instead
            // This will be REMOVED in 3.4
            $file = Params::getParamString('ajaxfile');
        }

        if ($file == '') {
            AjaxResponse::json(array('error' => 'no action defined'));
            return;
        }

        // valid file?
        if (strpos($file, '../') !== false || strpos($file, '..\\') !== false
            || stripos($file, '/admin/') !== false
        ) { //If the file is inside an "admin" folder, it should NOT be opened in frontend
            AjaxResponse::json(array('error' => 'no valid ajaxFile'));
            return;
        }

        if (!file_exists(osc_plugins_path() . $file)) {
            AjaxResponse::json(array('error' => "ajaxFile doesn't exist"));
            return;
        }

        // Unauthenticated, and it ends in require_once: resolve the path before
        // running it -- .php only, and inside the plugins directory once symlinks
        // are followed.
        $resolved = \mindstellar\security\PluginAjaxFile::resolve($file, osc_plugins_path());
        if ($resolved === null) {
            AjaxResponse::json(array('error' => 'no valid ajaxFile'));
            return;
        }

        require_once $resolved;
    }

    /**
     * Whether a username is free.
     */
    private function checkUsername(): void
    {
        $username = (new \mindstellar\utility\Sanitize())->username(Params::getParam('s_username'));
        if (osc_is_username_blacklisted($username)) {
            AjaxResponse::json(array('exists' => 1, 's_username' => $username));
        } else {
            $user = User::getInstance()->findByUsername($username);
            if (isset($user['s_username'])) {
                AjaxResponse::json(array('exists' => 1, 's_username' => $username));
            } else {
                AjaxResponse::json(array('exists' => 0, 's_username' => $username));
            }
        }
    }

    /**
     * Stage a photo the listing form uploads.
     */
    private function ajaxUpload(): void
    {
        $refused = $this->uploadRefusal();
        if ($refused !== '') {
            AjaxResponse::json(array('success' => false, 'error' => $refused));
            return;
        }
        $uploader = new AjaxUploader();
        $original = pathinfo($uploader->getOriginalName());
        $original['extension'] = $original['extension'] ?? '';
        $filename = uniqid('qqfile_', true) . '.' . $original['extension'];
        try {
            $result =
                $uploader->handleUpload(osc_content_path() . 'uploads/temp/' . $filename);
        } catch (Exception $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);
            AjaxResponse::json(array('success' => false));
            return;
        }

        // auto rotate

        $img = ImageProcessing::fromFile(osc_content_path() . 'uploads/temp/' . $filename);
        $img->autoRotate();
        try {
            $img->saveToFile(
                osc_content_path() . 'uploads/temp/auto_' . $filename,
                $original['extension']
            );
        } catch (Exception $e) {
            trigger_error($e->getMessage(), E_USER_NOTICE);
            AjaxResponse::json(array('success' => false));
            return;
        }
        try {
            $img->saveToFile(
                osc_content_path() . 'uploads/temp/' . $filename,
                $original['extension']
            );
        } catch (Exception $e) {
            trigger_error($e->getMessage(), E_USER_NOTICE);
            AjaxResponse::json(array('success' => false));
            return;
        }

        $result['uploadName'] = 'auto_' . $filename;
        // Stage the name the client attaches and deletes by, under the form's upload token.
        try {
            UploadTmpStore::stage(UploadTmpStore::formOwner(), Params::getParamString('qquuid'), (string) $result['uploadName'], time());
        } catch (\InvalidArgumentException $e) {
            @unlink(UploadTmpStore::dir() . $result['uploadName']);
            AjaxResponse::json(array('success' => false));
            return;
        }
        if (!osc_is_web_user_logged_in() && !osc_is_admin_user_logged_in()) {
            \mindstellar\security\ActionThrottle::record('ajax_upload');
        }
        echo htmlspecialchars(json_encode($result), ENT_NOQUOTES);
    }

    /**
     * The signed-in visitor for a messages call; answers 401 or 403 and returns null otherwise.
     */
    private function messagingActor(): ?Actor
    {
        $actor = Actor::visitor();
        if ($actor->userId() === null) {
            AjaxResponse::json(array('error' => ForbiddenException::SIGN_IN, 'msg' => __('Sign in to use messages')), 401);

            return null;
        }
        if (!osc_messaging_enabled()) {
            AjaxResponse::json(array('error' => ForbiddenException::DISABLED, 'msg' => __('Messages are switched off on this site')), 403);

            return null;
        }

        return $actor;
    }

    /**
     * Send the reply a refused messages call gets: the exception's own status, and its reason as
     * the error code.
     */
    private function messagingRefusal(RefusedException $e): void
    {
        $body = array('error' => 'refused', 'msg' => $e->getMessage());
        if ($e instanceof ForbiddenException) {
            $body['error'] = $e->reason() !== '' ? $e->reason() : 'forbidden';
        } elseif ($e instanceof NotFoundException) {
            $body['error'] = 'not_found';
        } elseif ($e instanceof ConflictException) {
            $body['error'] = $e->reason() !== '' ? $e->reason() : 'conflict';
        } elseif ($e instanceof InvalidException) {
            $body['error']  = ltrim($e->pointer(), '/') !== '' ? ltrim($e->pointer(), '/') : 'invalid';
            $body['errors'] = $e->errors();
        } elseif ($e instanceof BlockedException) {
            $body['error']       = $e->isRateLimit() ? 'rate_limited' : 'login_blocked';
            $body['retry_after'] = $e->retryAfter();
            header('Retry-After: ' . $e->retryAfter());
        }
        AjaxResponse::json($body, $e->httpStatus());
    }

    /**
     * Send a reply in a thread the visitor is in.
     */
    private function messagesSend(): void
    {
        if (Params::getServerParam('REQUEST_METHOD') !== 'POST') {
            header('Allow: POST');
            AjaxResponse::json(array('error' => 'method_not_allowed', 'msg' => __('Use POST')), 405);

            return;
        }
        $actor = $this->messagingActor();
        if ($actor === null) {
            return;
        }
        osc_csrf_check();
        try {
            $sent = Container::root()->get(MessagingService::class)->reply(Params::getParamInt('id'), Params::getParamString('body', false, false, false), $actor);
        } catch (RefusedException $e) {
            $this->messagingRefusal($e);

            return;
        }
        AjaxResponse::json(array('ok' => true, 'message' => $sent->message(), 'held' => $sent->held()));
    }

    /**
     * The visitor's unread total and newest message time; 304 when the poll's tag is still current.
     */
    private function messagesCheck(): void
    {
        $actor = $this->messagingActor();
        if ($actor === null) {
            return;
        }
        $activity = Container::root()->get(MessagingService::class)->activity((int) $actor->userId());
        $etag     = '"' . $activity['unread'] . '.' . md5((string) $activity['latest']) . '"';
        header('Cache-Control: private, no-store');
        header('ETag: ' . $etag);
        if (osc_etag_matches((string) Params::getServerParam('HTTP_IF_NONE_MATCH', false, false), $etag)) {
            http_response_code(304);

            return;
        }
        AjaxResponse::json($activity);
    }

    /**
     * The messages after the one the page already has; reading them marks the thread read.
     */
    private function messagesSince(): void
    {
        $actor = $this->messagingActor();
        if ($actor === null) {
            return;
        }
        try {
            $result = Container::root()->get(MessagingService::class)->since(Params::getParamInt('id'), Params::getParamInt('after'), $actor);
        } catch (RefusedException $e) {
            $this->messagingRefusal($e);

            return;
        }
        $thread = $result['conversation'];
        AjaxResponse::json(array(
            'messages' => array_map(static fn (array $row): array => MessageStore::shape($row), $result['messages']),
            'state'    => (string) $thread['s_state'],
            'blocked'  => (int) $thread['b_buyer_blocked'] === 1 || (int) $thread['b_seller_blocked'] === 1,
        ));
    }

    /**
     * Whether the signed-in visitor may follow a live channel; nchan asks before it opens one.
     * Only their own `user-<id>` channel, named in nchan's X-Channel-Id header, is allowed.
     */
    private function messagesLiveAuth(): void
    {
        header('Cache-Control: no-store');
        if (Container::root()->get(LivePublisher::class)->mode() === 'off') {
            http_response_code(404);

            return;
        }
        $userId = Actor::visitor()->userId();
        if ($userId === null) {
            http_response_code(401);

            return;
        }
        $channel = (string) Params::getServerParam('HTTP_X_CHANNEL_ID', false, false, false);
        if (!osc_messaging_enabled() || LiveChannel::userId($channel) !== $userId) {
            http_response_code(403);

            return;
        }
        http_response_code(200);
    }

    /**
     * The answer to an unknown action.
     */
    private function noAction(): void
    {
        AjaxResponse::json(array('error' => __('no action defined')));
    }
    /**
     * Why this visitor may not stage another photo, or '' when they may. Only guests have an hourly limit.
     *
     * @return string
     */
    private function uploadRefusal(): string
    {
        if (osc_is_admin_user_logged_in() || osc_is_web_user_logged_in()) {
            return '';
        }
        if (ListingPolicy::requiresSignIn(Actor::visitor())) {
            return _m('Only registered users are allowed to post listings');
        }
        if (\mindstellar\security\ActionThrottle::exceededFor('ajax_upload')) {
            return _m('Too many tries from your connection. Please try again later.');
        }

        return '';
    }

    //hopefully generic...

    /**
     * Renders the given theme template between the `before_html` and `after_html` hooks.
     *
     * @param string $file Absolute path to the located template
     *
     * @return void
     */
    public function doView($file)
    {
        osc_run_hook('before_html');
        osc_current_web_theme_path($file);
        osc_run_hook('after_html');
    }

    /**
     * Suggestions for an AUTOCOMPLETE custom field: distinct existing values of the
     * field on live listings, prefix-matched against $term. Only SEARCHABLE fields
     * expose their values (a non-searchable field's data is not meant to be
     * enumerable), and the query binds every value, so it is injection-safe. Plugins
     * can replace/augment the list via the `custom_field_autocomplete_source` filter.
     *
     * @param int    $fieldId t_meta_fields.pk_i_id
     * @param string $term    the typed prefix
     *
     * @return array<int, array{value:string, label:string}>
     */
    private function customFieldAutocomplete($fieldId, $term)
    {
        $term = trim($term);
        if ($fieldId <= 0 || $term === '') {
            return array();
        }

        $field = Field::getInstance()->findByPrimaryKey($fieldId);
        if (!is_array($field) || (int) ($field['b_searchable'] ?? 0) !== 1) {
            return array();
        }

        $rows = \mindstellar\fields\FieldQuery::suggest((int) $fieldId, (string) $term);

        $results = array();
        foreach ($rows as $r) {
            if (isset($r['value']) && $r['value'] !== '') {
                $results[] = array('value' => (string) $r['value'], 'label' => (string) $r['value']);
            }
        }

        return osc_apply_filter('custom_field_autocomplete_source', $results, $fieldId, $term, $field);
    }
}

/* file end: ./CWebAjax.php */
