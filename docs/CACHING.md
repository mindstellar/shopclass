# Caching contract

Shopclass is designed to sit behind a reverse-proxy or CDN cache. This document is the
contract between the application and that cache: **the application is the authority on whether
a response is public or private, and states it in standard HTTP.** A proxy should respect what
the app says, never infer it from side channels (cookie presence, hashed names, URL lists).

Everything here is domain-independent and stable across installs. If core changes any of it,
this file and the reference proxy config in `.docker/nginx/` change in the same commit.

Implemented in `oc-includes/osclass/helpers/hHttpCache.php` (the emitter + opt-in) and the
front-end `index.php` (one central emit after dispatch). See "Implementation" at the end.

## 1. The only two questions a cache must answer

1. **Is this response private?** -> decided by the *cache-relevant cookie set* (section 2).
2. **What may cache it, and for how long?** -> decided by the *Cache-Control* the app emits (section 3).

Both answers come from the application. A proxy needs exactly one request-side signal (the
cookie set, for the serve-from-cache decision it makes before the app runs); everything else
follows from the response headers.

## 2. Cache-relevant cookies (the allowlist)

These -- and only these -- mean "this response is personalized." A proxy must bypass the cache
(neither serve nor store) when a request carries any of them, and core applies the same set
itself (`osc_cache_relevant_cookies()`, filterable via `cache_relevant_cookies`):

| Cookie | Set when | Why it matters |
|---|---|---|
| `osclass` (session) | a PHP session physically starts (login, a form write) | session-bound output |
| `oc_cache_bypass` | front-end or admin login / remember-me | logged-in user or admin |
| `oc_userLocale` | a visitor switches language (`?lang=`) | changes the rendered language |

Front-end and admin identity (`oc_userId`, `oc_userSecret`, `oc_adminId`) are **keys inside one
cookie named `md5(WEB_PATH)`**, not cookie names — a proxy config cannot hardcode that per-site
hash. `oc_cache_bypass` is the fixed-name flag core writes in lockstep with that cookie so the
edge has one stable name to match. Match those key names directly and the rule never fires.

**Every other cookie is irrelevant to the server and MUST NOT affect caching** -- including
third-party client-set cookies (`_ga`, `_gid`, `_gat_*`, `_gcl_*`, `__gads`, `__gpi`, `_fbp`, ...)
and the theme's `cookies_consent`. The server never reads them, so they never change output.
Keying the cache on cookie *presence* would let a single Analytics cookie defeat the cache for
every real visitor; that is why the decision is a fixed **allowlist of names**, not "any cookie."

**Stability guarantee:** these names are fixed and contain no `md5(WEB_PATH)`/domain hash. Core
will not rename or add a personalization cookie without updating `osc_cache_relevant_cookies()`,
this list, and the reference config together.

## 3. Cache-Control emitted by core

| Response | `Cache-Control` |
|---|---|
| Anonymous, cacheable GET (no cache-relevant cookie) | `public, s-maxage=30, max-age=0, must-revalidate` |
| Personalized, POST, or any cache-relevant cookie present | `private, no-store` |

- `s-maxage` lets a **shared** cache (proxy/CDN) hold the page ~30s; `max-age=0, must-revalidate`
  makes **browsers** revalidate every time, so a page is never served stale from disk cache.
- The `30` is the default and is overridable via `osc_apply_filter('public_cache_max_age', 30)`.
- The whole header is overridable via `osc_apply_filter('response_cache_control', $value)`.
- Core calls `session_cache_limiter('')` on the front end so PHP does not inject its own
  conflicting `no-cache` headers -- core owns `Cache-Control` end to end. Admin keeps PHP's
  default limiter (it is never cached).

## 4. Which responses are cacheable -- the app decides, per response

A response is served as `public` (section 3) only when **all** of these hold; otherwise it is
`private, no-store`:

1. the method is `GET`/`HEAD`,
2. the controller marked the response a **public read page** (opt-in -- see below),
3. no web or admin user is logged in and no session started this request, and
4. no cache-relevant cookie (section 2) is present.

**The default is private.** A page is cacheable only if it opts in, so a new or unclassified
page fails safe (never cached) rather than leaking.

**CSRF tokens do not block caching.** Core's CSRF token is stateless and, for an anonymous
visitor, a shared value bucketed to a 2-hour window (not a per-visitor secret). A briefly-cached
public page carrying one is therefore safe -- every anonymous visitor may use it and it is still
valid when they submit. So anonymous form pages that are genuinely public may still opt in; only
pages that are actually per-user (logged-in, or carrying a cache-relevant cookie) are private,
and those are already excluded by conditions 3-4.

Public read pages that opt in (`osc_mark_response_cacheable()`):

- homepage
- search and results
- category and static-page slugs
- item detail
- **the public user profile -- a user's public listings (`user/items`)**
- error pages (`do404()`, and the `do410()`/`do400()` kept for third-party callers), which exit
  before the stamp in `index.php` and so emit the header themselves -- a crawler walking dead
  URLs is otherwise one full theme render per hit

Private by default (not opted in): the account area (`user/dashboard`, `user/profile` edit,
`user/change_*`, `user/alerts`), item posting and the `item/view` beacon, `/contact`,
`/register`, `/user/login`, `/oc-admin`, and every POST.

**Cacheability is never decided by URL prefix.** Sibling routes under one prefix differ -- the
public `user/items` profile and the private `user/dashboard` both live under `/user`. The
controller knows which is which; a proxy regex does not. This is why the reference proxy config
(section 6) carries no URL allow/deny lists.

## 5. No `Set-Cookie` on the cacheable path

An anonymous cacheable GET emits no `Set-Cookie` -- a stored response would otherwise replay one
cookie to every later visitor. Core guarantees this: the session is lazy (an anonymous read
starts none), and identity, flash, form, redirect and upload cookies are only written on
personalized or redirect responses, which are `private, no-store`. Third-party analytics/ads
cookies are set client-side by JavaScript and never appear in the origin response, so a cached
page carries none of them.

## 6. Reference proxy config (nginx fastcgi micro-cache)

The full reference is `.docker/nginx/microcache.conf`. The essence:

    # http{} scope
    fastcgi_cache_path /var/cache/nginx/microcache levels=1:2 keys_zone=MICROCACHE:10m
                       max_size=200m inactive=60s use_temp_path=off;

    # Bypass ONLY on core's cache-relevant cookies; ignore _ga / __gads / consent / everything else.
    map $http_cookie $mc_private {
        default 0;
        "~(^|;\s*)(oc_cache_bypass|oc_userLocale|osclass|PHPSESSID)=" 1;
    }

    # location ~ \.php$
    fastcgi_cache            MICROCACHE;
    fastcgi_cache_key        "$scheme$request_method$host$request_uri";
    fastcgi_cache_bypass     $mc_private;   # don't SERVE a cached page to a personalized request
    fastcgi_no_cache         $mc_private;   # don't STORE a personalized response
    fastcgi_cache_lock       on;
    fastcgi_cache_use_stale  updating error timeout http_500 http_503;
    fastcgi_cache_background_update on;
    add_header X-Cache $upstream_cache_status always;

    # Deliberately NOT set: no `fastcgi_ignore_headers` (the app's Cache-Control is the authority),
    # no `fastcgi_cache_valid` override, no synthesized Cache-Control map, no URL allow/deny maps.

nginx caches only `GET`/`HEAD` by default, so no method gate is needed. Because the app emits
`private, no-store` on every personalized page and nginx respects it, a session-starting page is
never stored even without the cookie bypass -- the bypass exists for the *serve* decision, which
nginx makes before the app runs and so cannot base on response headers.

## 7. Known limitation: locale

`oc_userLocale` is treated as personalized, so a visitor who switches language bypasses the
cache. The cookie lasts 24 hours, so the bypass is bounded to the visit rather than following
the visitor for a year. Single-language installs (the majority) are unaffected. A future
URL-based locale scheme would let localized pages be cached under distinct keys instead of
bypassing.

## 8. Anti-patterns (why the config stays this small)

- **Don't bypass on "any cookie present."** Analytics/consent cookies would defeat the cache for
  real humans while only bots stay cached.
- **Don't classify by URL prefix.** `user/items` (public seller profile) and `user/dashboard`
  (private) share a prefix but differ. The app decides per response.
- **Don't match a hashed cookie name** (`md5(WEB_PATH)`): it silently breaks on any domain or path
  change and serves cached pages to logged-in users.
- **Don't set `fastcgi_ignore_headers`.** The app's `Cache-Control`/`Set-Cookie` must win.
- **Don't add `Vary: Cookie`.** Unnecessary once the bypass keys on the allowlist, and it would
  fragment the cache per unique cookie jar (i.e. per visitor).

## Implementation

- `osc_mark_response_cacheable()` -- a controller opts the current response in (called in the
  public read actions of CWebMain, CWebSearch, CWebItem, CWebPage, CWebUserNonSecure).
- `osc_response_is_cacheable()` -- evaluates conditions 1-4 of section 4.
- `osc_send_response_cache_headers()` -- emits the header; called once after dispatch in the
  front-end `index.php`, while output is still buffered by `Csrf::init`.
- `osc_cache_relevant_cookies()` -- the section-2 allowlist, shared with the proxy config.
- Filters: `public_cache_max_age`, `response_cache_control`, `response_is_cacheable`,
  `cache_relevant_cookies`.
