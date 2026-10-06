<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\api;

use mindstellar\admin\ExposedSettings;
use mindstellar\api\auth\AccessTokens;
use mindstellar\api\auth\AdminRows;
use mindstellar\api\auth\Authenticator;
use mindstellar\api\auth\FailureCounter;
use mindstellar\api\auth\PageTokenAuth;
use mindstellar\api\auth\RefreshTokens;
use mindstellar\api\auth\TokenIssuer;
use mindstellar\api\auth\UserRows;
use mindstellar\api\http\SiteOrigin;
use mindstellar\api\idempotency\Idempotency;
use mindstellar\api\idempotency\KvIdempotencyStore;
use mindstellar\api\ratelimit\RateLimiter;
use mindstellar\api\ratelimit\RatePolicy;
use mindstellar\api\read\CategoryCatalog;
use mindstellar\api\read\Cursor;
use mindstellar\api\read\ListingReader;
use mindstellar\api\read\ListingSearch;
use mindstellar\api\read\SiteFacts;
use mindstellar\api\routing\Router;
use mindstellar\api\routing\RouteTable;
use mindstellar\api\schema\Definitions;
use mindstellar\api\schema\OpenApi;
use mindstellar\api\schema\Schema;
use mindstellar\api\schema\Validator;
use mindstellar\api\serializer\CustomFieldSerializer;
use mindstellar\api\serializer\EventData;
use mindstellar\api\serializer\ExtensionMembers;
use mindstellar\api\serializer\Extensions;
use mindstellar\api\serializer\Links;
use mindstellar\api\serializer\ListingSerializer;
use mindstellar\api\serializer\SiteLinks;
use mindstellar\api\serializer\SparseFieldset;
use mindstellar\api\serializer\ViewContext;
use mindstellar\api\write\CustomFieldValues;
use mindstellar\api\write\ImageFetcher;
use mindstellar\api\write\ListingWriter;
use mindstellar\api\write\PhotoIntake;
use mindstellar\api\write\PhotoStage;
use mindstellar\apiaccess\AccessEntries;
use mindstellar\apiaccess\AccountAccess;
use mindstellar\apiaccess\ApiAccess;
use mindstellar\apiaccess\ApiKeys;
use mindstellar\apiaccess\ApiKeyService;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\PageTokens;
use mindstellar\apiaccess\PersonalKeys;
use mindstellar\apiaccess\Scopes;
use mindstellar\apiaccess\SignInStore;
use mindstellar\category\CategoryService;
use mindstellar\currency\CurrencyService;
use mindstellar\fields\FieldService;
use mindstellar\listing\ListingService;
use mindstellar\location\LocationService;
use mindstellar\moderation\CommentModeration;
use mindstellar\moderation\ListingModeration;
use mindstellar\utility\Clock;
use mindstellar\webhook\WebhookServices;

/**
 * The API's composition root: every service is built here on first use and shared after.
 * site() is the one for this request; tests build their own over test doubles.
 */
final class ApiServices
{
    private static ?self $site = null;

    /** @var array<string,object> service name => instance */
    private array $built = [];

    private ?ApiAccess $access = null;

    /**
     * @param SiteFacts|null    $facts         the site's preferences when null
     * @param Links|null        $links         the site's URLs when null
     * @param PhotoStage|null   $photoStage    the site's temp folder when null
     * @param ImageFetcher|null $fetcher       the cURL downloader when null
     * @param int|null          $maxPhotoBytes the site's photo size cap when null
     */
    public function __construct(
        private ApiSettings $settings,
        private Scopes $scopes,
        private SignInStore $store,
        private UserRows $users,
        private Clock $clock,
        private RateLimiter $limiter,
        private ?SiteFacts $facts = null,
        private ?Links $links = null,
        private ?PhotoStage $photoStage = null,
        private ?ImageFetcher $fetcher = null,
        private ?int $maxPhotoBytes = null
    ) {
    }

    /**
     * The services of this site, built once per request.
     */
    public static function site(): self
    {
        if (self::$site === null) {
            $access             = ApiAccess::site();
            self::$site         = new self(
                $access->settings(),
                $access->scopes(),
                $access->store(),
                new UserRows(),
                $access->clock(),
                RateLimiter::fromSite($access->clock())
            );
            self::$site->access = $access;
        }

        return self::$site;
    }

    /**
     * Forget the site's services, so the next site() reads settings and hooks again.
     */
    public static function reset(): void
    {
        self::$site = null;
        ApiAccess::reset();
    }

    /**
     * Keys, sign-ins and page tokens, on this kit's store, scopes and clock.
     */
    public function access(): ApiAccess
    {
        return $this->access ??= new ApiAccess($this->settings, $this->scopes, $this->store, $this->clock);
    }

    public function settings(): ApiSettings
    {
        return $this->settings;
    }

    public function scopes(): Scopes
    {
        return $this->scopes;
    }

    public function store(): SignInStore
    {
        return $this->store;
    }

    public function users(): UserRows
    {
        return $this->users;
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function limiter(): RateLimiter
    {
        return $this->limiter;
    }

    public function kernel(): Kernel
    {
        return $this->once(__FUNCTION__, fn (): Kernel => new Kernel(
            $this->router(),
            $this->authenticator(),
            $this->limiter,
            $this->validator(),
            $this->settings,
            $this->users,
            $this->admins(),
            new Idempotency(new KvIdempotencyStore(), $this->clock)
        ));
    }

    /**
     * Core routes and the plugins' routes.
     */
    public function router(): Router
    {
        return $this->once(__FUNCTION__, fn (): Router => Router::build($this->validator(), RouteTable::core(), handlers: $this->handlers()));
    }

    /**
     * Builds the controller a core route runs on: each takes these services.
     *
     * @return \Closure(class-string): object
     */
    public function handlers(): \Closure
    {
        return function (string $class): object {
            if ($class === OpenApi::class) {
                return $this->openApi();
            }
            if (!str_starts_with($class, __NAMESPACE__ . '\\controller\\') || !class_exists($class)) {
                throw new \LogicException($class . ' is not a core API controller.');
            }

            return $this->once($class, fn (): object => new $class($this));
        };
    }

    /**
     * Plugin fields, read once from the `api_fields` filter.
     */
    public function fields(): ExtensionMembers
    {
        return $this->once(__FUNCTION__, static fn (): ExtensionMembers => ExtensionMembers::fromHooks(new Validator()));
    }

    /**
     * The component schemas, built only when a `$ref` needs them.
     */
    public function definitions(): Definitions
    {
        return $this->once(__FUNCTION__, fn (): Definitions => Schema::definitions($this->fields()));
    }

    public function validator(): Validator
    {
        return $this->once(__FUNCTION__, fn (): Validator => new Validator($this->definitions()));
    }

    public function openApi(): OpenApi
    {
        return $this->once(__FUNCTION__, fn (): OpenApi => OpenApi::forSite($this->router(), $this->definitions(), $this->scopes));
    }

    public function authenticator(): Authenticator
    {
        return $this->once(__FUNCTION__, fn (): Authenticator => new Authenticator(
            $this->keys(),
            new FailureCounter(),
            $this->accessTokens(),
            new PageTokenAuth($this->pageTokens(), $this->users, $this->scopes, SiteOrigin::fromSite())
        ));
    }

    /**
     * Page tokens for the same-site session mode.
     */
    public function pageTokens(): PageTokens
    {
        return $this->access()->pageTokens();
    }

    /**
     * The site preferences every read shares.
     */
    public function facts(): SiteFacts
    {
        return $this->facts ??= SiteFacts::fromSite($this->settings);
    }

    public function links(): Links
    {
        return $this->links ??= new SiteLinks();
    }

    /**
     * Plugin fields as the serializers add them.
     */
    public function extensions(): Extensions
    {
        return $this->once(__FUNCTION__, fn (): Extensions => new Extensions($this->fields()));
    }

    /**
     * Signs and opens the paging cursors.
     */
    public function cursor(): Cursor
    {
        return $this->once(__FUNCTION__, static fn (): Cursor => new Cursor());
    }

    /**
     * The listing serializer every listing answer shares.
     */
    public function listingSerializer(): ListingSerializer
    {
        return $this->once(__FUNCTION__, fn (): ListingSerializer => new ListingSerializer(
            $this->links(),
            $this->extensions(),
            new CustomFieldSerializer(),
            $this->facts()->hidePhone(),
            $this->facts()->keepOriginal()
        ));
    }

    /**
     * The request's locale: the asked one, or the site's default.
     *
     * @throws ProblemException 422 for a locale the site does not have
     */
    public function locale(Request $request): string
    {
        return $this->facts()->locale($request->queryString('locale'));
    }

    /**
     * The view context for a request: its locale, sparse fieldset and includes.
     *
     * @param string[] $members  the resource's top-level members
     * @param string[] $includes the names `include=` may switch on
     *
     * @throws ProblemException 400 for an unknown field or include, 422 for an unknown locale
     */
    public function context(Request $request, Credential $credential, string $object, array $members, array $includes = []): ViewContext
    {
        $include = $request->queryList('include');
        foreach ($include as $name) {
            if (!in_array($name, $includes, true)) {
                throw ProblemException::of('invalid_query', 'include: unknown value ' . $name . '.');
            }
        }

        return new ViewContext(
            $credential,
            $this->locale($request),
            SparseFieldset::parse($request->queryString('fields'), $members, $this->extensions()->declared(), $object),
            $include
        );
    }

    public function keys(): ApiKeys
    {
        return $this->access()->keys();
    }

    public function accessTokens(): AccessTokens
    {
        return $this->once(__FUNCTION__, fn (): AccessTokens => new AccessTokens(
            $this->scopes,
            $this->users,
            familyLive: fn (string $family): bool => $this->store->familyIsLive($family)
        ));
    }

    public function refreshTokens(): RefreshTokens
    {
        return $this->once(
            __FUNCTION__,
            fn (): RefreshTokens => new RefreshTokens($this->store, $this->scopes, $this->users, RefreshTokens::TTL_DAYS, $this->clock)
        );
    }

    public function tokenIssuer(): TokenIssuer
    {
        return $this->once(__FUNCTION__, fn (): TokenIssuer => new TokenIssuer($this->accessTokens(), $this->scopes, $this->clock));
    }

    public function accessEntries(): AccessEntries
    {
        return $this->access()->accessEntries();
    }

    public function personalKeys(): PersonalKeys
    {
        return $this->access()->personalKeys();
    }

    /**
     * The account's "API access" page.
     */
    public function accountAccess(): AccountAccess
    {
        return $this->access()->accountAccess();
    }

    public function admins(): AdminRows
    {
        return $this->once(__FUNCTION__, static fn (): AdminRows => new AdminRows());
    }

    /**
     * The key rules of Settings -> API, for personal keys and `/admin/keys`.
     */
    public function keyService(): ApiKeyService
    {
        return $this->access()->keyService();
    }

    public function listingSearch(): ListingSearch
    {
        return $this->once(__FUNCTION__, fn (): ListingSearch => new ListingSearch($this, $this->listingReader()));
    }

    public function listingReader(): ListingReader
    {
        return $this->once(__FUNCTION__, fn (): ListingReader => new ListingReader(CategoryCatalog::fromSite(), $this->listingSerializer()));
    }

    /**
     * Every rate limit the API counts.
     */
    public function ratePolicy(): RatePolicy
    {
        return $this->once(__FUNCTION__, fn (): RatePolicy => new RatePolicy($this->settings));
    }

    public function photoIntake(): PhotoIntake
    {
        return $this->once(__FUNCTION__, fn (): PhotoIntake => new PhotoIntake(
            $this->photoStage ?? PhotoStage::fromSite($this->clock),
            $this->fetcher ?? ImageFetcher::fromSite(),
            $this->limiter,
            $this->ratePolicy(),
            $this->settings->photoUrls(),
            $this->maxPhotoBytes ?? (int) osc_max_size_kb() * 1024
        ));
    }

    public function listingWriter(): ListingWriter
    {
        return $this->once(__FUNCTION__, fn (): ListingWriter => new ListingWriter(new CustomFieldValues(), $this->facts(), $this->listings()));
    }

    /**
     * Core's listing writes, one for the request.
     */
    public function listings(): ListingService
    {
        return $this->once(__FUNCTION__, static fn (): ListingService => new ListingService());
    }

    public function listingModeration(): ListingModeration
    {
        return $this->once(__FUNCTION__, fn (): ListingModeration => new ListingModeration($this->clock));
    }

    public function commentModeration(): CommentModeration
    {
        return $this->once(__FUNCTION__, static fn (): CommentModeration => CommentModeration::make());
    }

    public function categoryService(): CategoryService
    {
        return $this->once(__FUNCTION__, static fn (): CategoryService => CategoryService::make());
    }

    public function currencyService(): CurrencyService
    {
        return $this->once(__FUNCTION__, static fn (): CurrencyService => CurrencyService::make());
    }

    public function fieldService(): FieldService
    {
        return $this->once(__FUNCTION__, fn (): FieldService => FieldService::make($this->facts()->defaultLocale()));
    }

    public function locationService(): LocationService
    {
        return $this->once(__FUNCTION__, static fn (): LocationService => new LocationService());
    }

    public function exposedSettings(): ExposedSettings
    {
        return $this->once(__FUNCTION__, static fn (): ExposedSettings => new ExposedSettings());
    }

    /**
     * The webhook services, on this kit's clock and settings.
     */
    public function webhooks(): WebhookServices
    {
        return $this->once(__FUNCTION__, fn (): WebhookServices => new WebhookServices($this->clock, $this->settings->webhooksAllowPrivate()));
    }

    /**
     * The `data` of core webhook events.
     */
    public function eventData(): EventData
    {
        return $this->once(__FUNCTION__, fn (): EventData => new EventData($this->listingReader(), $this, $this->clock));
    }

    /**
     * @template T of object
     *
     * @param \Closure(): T $make
     *
     * @return T
     */
    private function once(string $name, \Closure $make): object
    {
        return $this->built[$name] ??= $make();
    }
}
