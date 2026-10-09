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
use mindstellar\comment\CommentPolicy;
use mindstellar\comment\CommentService;
use mindstellar\exception\BlockedException;
use mindstellar\exception\ForbiddenException;
use mindstellar\exception\InvalidException;
use mindstellar\exception\RefusedException;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingMailService;
use mindstellar\listing\ListingNotices;
use mindstellar\listing\ListingService;
use mindstellar\listing\ListingStats;
use mindstellar\listing\ListingValidator;
use mindstellar\listing\PhotoService;
use mindstellar\listing\SavedListing;

/**
 * Class ItemActions
 */
class ItemActions
{
    /**
     * Widths of the t_item_location and t_item columns a submitted listing fills.
     */
    public const COLUMN_WIDTHS = ListingValidator::COLUMN_WIDTHS;

    /** Widths of the t_item contact columns a listing form fills. */
    public const CONTACT_WIDTHS = ListingValidator::CONTACT_WIDTHS;

    /** Highest description length the listing settings accept. */
    public const DESCRIPTION_MAX = 20000;

    public $is_admin;
    public $data;
    /** @var bool admin mode that still applies listing limits and moderation */
    private $import = false;
    /** @var bool the data came through prepareDataFrom(), not a form carrying the secret */
    private $fromData = false;
    private $manager;
    /** @var int the listing the last add() made, 0 when it made none */
    private $lastItemId = 0;
    /** @var int the comment the last add_comment() saved, 0 when it saved none */
    private $lastCommentId = 0;
    /** @var int[] the photos the last uploadItemResources() call saved */
    private $uploadedIds = array();

    /**
     * ItemActions constructor.
     *
     * @param bool $is_admin
     */
    public function __construct($is_admin = false)
    {
        $this->is_admin = $is_admin;
        $this->manager  = Item::getInstance();
    }

    /**
     * The id of the listing the last add() made.
     *
     * @return int 0 when it made none
     */
    public function lastItemId(): int
    {
        return $this->lastItemId;
    }

    /**
     * The id of the comment the last add_comment() saved.
     *
     * @return int 0 when it saved none
     */
    public function lastCommentId(): int
    {
        return $this->lastCommentId;
    }

    /**
     * The photo (t_item_resource) ids the last uploadItemResources() call saved, in order.
     *
     * @return int[]
     */
    public function uploadedResourceIds(): array
    {
        return $this->uploadedIds;
    }

    /**
     * Save listings for an importer: no posting wait and no e-mails, as for an admin, but
     * the owner's listing limit and the site's moderation still apply.
     *
     * @return $this
     */
    public function asImport(): self
    {
        $this->is_admin = true;
        $this->import   = true;

        return $this;
    }

    /**
     * Delete resources from the hard drive.
     * Compatibility: use \mindstellar\listing\PhotoService::deleteFilesFromDisk().
     *
     * @param int                                 $itemId
     * @param bool                                $is_admin
     * @param array<int,array<string,mixed>>|null $resources Rows read before the delete; looked up when null
     *
     * @return void
     */
    public static function deleteResourcesFromHD($itemId, $is_admin = false, $resources = null)
    {
        PhotoService::deleteFilesFromDisk($itemId, $is_admin, $resources);
    }

    /**
     * Regenerate the normal, preview and thumbnail variants of one resource.
     * Compatibility: use \mindstellar\listing\PhotoService::regenerateImages().
     *
     * @param array $resource
     *
     * @return void
     */
    public static function regenerateResourceImages(array $resource): void
    {
        PhotoService::regenerateImages($resource);
    }

    /**
     * Insert a listing from $this->data, with its locales, location, images, meta and stats.
     *
     * Compatibility: use \mindstellar\listing\ListingService::create().
     *
     * @return int|string 1 on success, 2 when it still needs validation, else an error message
     */
    public function add()
    {
        $this->lastItemId = 0;
        $result           = (new ListingService())->saveForm(ListingInput::withMeta((array) $this->data), Actor::fromSession((bool) $this->is_admin), true, $this->import);
        if ($result instanceof SavedListing) {
            $this->lastItemId = $result->id();
            // Older plugins read the new id back from the request.
            Params::setParam('itemId', $this->lastItemId);
        }

        return ListingService::legacyResult($result, true);
    }

    /**
     * Write one title/description row per locale for a listing.
     *
     * Compatibility: use \mindstellar\listing\ListingService::writeLocales().
     *
     * @param string               $type        'ADD' or 'EDIT'
     * @param array<string,string> $title       Title per locale
     * @param array<string,string> $description Description per locale
     * @param int                  $itemId
     *
     * @return bool False when a locale could not be written
     */
    public function insertItemLocales($type, $title, $description, $itemId)
    {
        return (new ListingService())->writeLocales($type, $title, $description, $itemId);
    }

    /**
     * Errors for the listing length settings; empty when both are in range.
     *
     * @param mixed $titleLength
     * @param mixed $descriptionLength
     *
     * @return string
     */
    public static function lengthSettingErrors($titleLength, $descriptionLength)
    {
        $inRange = static function ($value, int $max): bool {
            return is_scalar($value) && preg_match('/^[0-9]+$/', (string)$value) === 1
                && (int)$value >= 1 && (int)$value <= $max;
        };

        $errors = '';
        if (!$inRange($titleLength, Item::TITLE_WIDTH)) {
            $errors .= sprintf(_m('Titles can be 1 to %d characters.'), Item::TITLE_WIDTH) . PHP_EOL;
        }
        if (!$inRange($descriptionLength, self::DESCRIPTION_MAX)) {
            $errors .= sprintf(_m('Descriptions can be 1 to %d characters.'), self::DESCRIPTION_MAX) . PHP_EOL;
        }

        return $errors;
    }

    /**
     * Store the uploaded images for a listing, honouring the per-item image cap.
     *
     * Compatibility: use \mindstellar\listing\PhotoService::store().
     *
     * @param array<string,array<int,mixed>> $aResources A $_FILES entry
     * @param int                            $itemId
     *
     * @return int 0 when nothing went wrong
     */
    public function uploadItemResources($aResources, $itemId)
    {
        $photos            = new PhotoService();
        $result            = $photos->store($aResources, $itemId);
        $this->uploadedIds = $photos->storedIds();
        ListingNotices::flash($photos->notices(), (bool) $this->is_admin);

        return $result;
    }

    /**
     * Fire the notification hooks a newly posted listing needs.
     *
     * Compatibility: use \mindstellar\listing\ListingService::notifyNew().
     *
     * @param array<string,mixed> $aItem The prepared listing data, with its 'item' rows
     *
     * @return void
     */
    public function sendEmails($aItem)
    {
        (new ListingService())->notifyNew($aItem['item'], (string) $aItem['active'], Actor::fromSession(false));
    }

    /**
     * Disable an item.
     * Set s_enabled value to 0, for a given item id
     *
     * Compatibility: use \mindstellar\listing\ListingService::disable().
     *
     * @param int $id
     *
     * @return bool
     */
    public function disable($id)
    {
        return (new ListingService())->disable($id);
    }

    /**
     * Take one listing out of the category, location and user totals.
     *
     * @param int $id
     *
     * @return void
     */
    public static function decreaseStatsFor(int $id): void
    {
        $item = Item::getInstance()->findByPrimaryKey($id);
        if ($item) {
            ListingStats::decrease($item);
        }
    }

    /**
     * Update a listing from $this->data, with its locales, location, images, meta and stats.
     *
     * Compatibility: use \mindstellar\listing\ListingService::update().
     *
     * @return int|string|false rows updated on success, an error message, or false
     */
    public function edit()
    {
        $result = (new ListingService())->saveForm(ListingInput::withMeta((array) $this->data), Actor::fromSession((bool) $this->is_admin), false, $this->import, !($this->is_admin && $this->fromData));

        return ListingService::legacyResult($result, false);
    }

    /**
     * Activates (validates) an item: the owner confirmed it.
     *
     * Compatibility: use \mindstellar\listing\ListingService::activate().
     *
     * @param int           $id
     * @param string | null $secret
     *
     * @return bool|int -1 when there was nothing to do
     */
    public function activate($id, $secret = null)
    {
        return (new ListingService())->activate((int) $id, $secret === null ? null : (string) $secret) ?? -1;
    }

    /**
     * Deactivates an item.
     *
     * Compatibility: use \mindstellar\listing\ListingService::deactivate().
     *
     * @param int $id
     *
     * @return bool
     */
    public function deactivate($id)
    {
        return (new ListingService())->deactivate((int) $id);
    }

    /**
     * Enable an item.
     *
     * Compatibility: use \mindstellar\listing\ListingService::enable().
     *
     * @param int $id
     *
     * @return bool
     */
    public function enable($id)
    {
        return (new ListingService())->enable((int) $id);
    }

    /**
     * Set premium on or off for an item.
     *
     * Compatibility: use \mindstellar\listing\ListingService::premium().
     *
     * @param int      $id
     * @param bool     $on
     * @param int|null $days
     * @param bool     $fireHook
     *
     * @return bool
     */
    public function premium($id, $on = true, $days = null, bool $fireHook = true)
    {
        return (new ListingService())->premium((int) $id, (bool) $on, $days === null ? null : (int) $days, $fireHook);
    }

    /**
     * Set spam on or off for an item.
     *
     * Compatibility: use \mindstellar\listing\ListingService::spam().
     *
     * @param int  $id
     * @param bool $on
     *
     * @return bool
     */
    public function spam($id, $on = true)
    {
        return (new ListingService())->spam((int) $id, (bool) $on);
    }

    /**
     * Delete an item, given s_secret and item id.
     *
     * Compatibility: use \mindstellar\listing\ListingService::delete().
     *
     * @param string $secret
     * @param int    $itemId
     *
     * @return int|false
     */
    public function delete($secret, $itemId)
    {
        return (new ListingService())->delete((int) $itemId, (string) $secret, Actor::fromSession((bool) $this->is_admin));
    }

    /**
     * Count a visitor's report on an item.
     *
     * Compatibility: use \mindstellar\listing\ListingService::mark().
     *
     * @param int    $id
     * @param string $as 'spam' | 'badcat' | 'offensive' | 'repeated' | 'expired'
     *
     * @return void
     */
    public function mark($id, $as)
    {
        (new ListingService())->mark((int) $id, (string) $as);
    }

    /**
     * Send the listing in the request to a friend.
     *
     * Compatibility: use \mindstellar\listing\ListingMailService::shareWithFriend().
     *
     * @return string|bool the reasons it was refused, or true
     */
    public function send_friend()
    {
        $item = $this->manager->findByPrimaryKey(Params::getParamInt('id'));
        if (!is_array($item) || $item === array()) {
            return __("This listing doesn't exist");
        }
        View::getInstance()->_exportVariableToView('item', $item);

        try {
            $sent = (new ListingMailService())->shareWithFriend($item, $this->mailForm(array('yourName', 'yourEmail', 'friendName', 'friendEmail', 'message')));
        } catch (InvalidException $e) {
            return ListingMailService::messages($e);
        } catch (RefusedException $e) {
            return $e->getMessage() . PHP_EOL;
        }
        if ($sent) {
            osc_add_flash_ok_message(sprintf(_m('We just sent your message to %s'), Params::getParamString('friendName')));
        }

        return true;
    }

    /**
     * Send the contact form in the request to the seller, or hold it until the sender confirms.
     *
     * Compatibility: use \mindstellar\listing\ListingMailService::contactSeller().
     *
     * @return string|bool the reasons it was refused, true when sent, false when held
     */
    public function contact()
    {
        $item = $this->manager->findByPrimaryKey(Params::getParamInt('id'));
        if (!is_array($item) || $item === array()) {
            return __("This listing doesn't exist");
        }
        View::getInstance()->_exportVariableToView('item', $item);

        try {
            return (new ListingMailService())->contactSeller($item, $this->mailForm(array('yourName', 'yourEmail', 'phoneNumber', 'message')));
        } catch (InvalidException $e) {
            return ListingMailService::messages($e);
        } catch (RefusedException $e) {
            return $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * @param string[] $names
     *
     * @return array<string,string>
     */
    private function mailForm(array $names): array
    {
        $form = array();
        foreach ($names as $name) {
            $form[$name] = Params::getParamString($name);
        }

        return $form;
    }

    /**
     * Post the comment form a request carries, as the signed-in user or a guest.
     * Compatibility: use \mindstellar\comment\CommentService::post().
     *
     * @return int 1 waiting for approval, 2 live, 5 spam or banned, 3 a bad e-mail, 4 an empty
     *             body, 6 only users may comment, 7 comments are off, 8 past the hourly comment
     *             limit (comment_post, see action_throttle_limit), -1 not saved or a listing the
     *             visitor cannot see
     */
    public function add_comment()
    {
        $this->lastCommentId = 0;
        $input = array(
            'author_name'  => Params::getParamString('authorName'),
            'author_email' => Params::getParamString('authorEmail'),
            'title'        => Params::getParamString('title'),
            'body'         => Params::getParamString('body'),
        );
        $actor = Actor::fromSession(false);
        try {
            $saved = (new CommentService())->post(Params::getParamInt('id'), $input, $actor);
        } catch (InvalidException $e) {
            return $e->pointer() === '/body' ? 4 : 3;
        } catch (ForbiddenException $e) {
            return match (CommentPolicy::mayPost($actor, $input['author_email'])) {
                CommentPolicy::DISABLED        => 7,
                CommentPolicy::REGISTERED_ONLY => 6,
                default                        => 5,
            };
        } catch (BlockedException $e) {
            return 8;
        } catch (RefusedException | RuntimeException $e) {
            return -1;
        }
        $this->lastCommentId = $saved->id();

        return $saved->status();
    }

    /**
     * prepareData() from plain values instead of the request.
     *
     * Compatibility: use \mindstellar\listing\ListingInput::fromValues().
     *
     * @param array<string,mixed> $input
     * @param bool                $isAdd
     *
     * @return void
     */
    public function prepareDataFrom(array $input, bool $isAdd): void
    {
        $this->data     = ListingInput::fromValues($input, (bool) $this->is_admin, $isAdd);
        $this->fromData = true;
    }

    /**
     * Read the posted listing form into $this->data.
     *
     * Compatibility: use \mindstellar\listing\ListingInput::read().
     *
     * @param bool $is_add
     *
     * @return void
     */
    public function prepareData($is_add)
    {
        $this->fromData = false;
        $this->data     = ListingInput::read((bool) $this->is_admin, (bool) $is_add);
    }
}
