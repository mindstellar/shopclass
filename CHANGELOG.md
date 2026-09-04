# Changelog

Older releases are archived in [ChangelogHistory.txt](ChangelogHistory.txt).

## Shopclass 6.3.0

Themes can now tell core about themselves: where their header and footer live, which views
and widget zones they own, and whether core may write the document head. The pages core owns —
account deletion, credits, buy and orders — render inside the theme rather than on a page of
their own, and a theme can add a view without a patch to core. A theme that declares nothing
behaves exactly as it did. Core now also renders every account and sign-in page itself when the
theme ships none, using a documented class vocabulary a theme restyles in CSS alone.

### Security

- **Deleting an account was a GET with the account id and secret in the URL.**
  `?page=user&action=delete&id=…&secret=…` removed the signed-in account as soon as
  the page was requested — a mail scanner, a prefetch, or a leaked referrer was
  enough. GET now shows a confirm form; the deletion is POST `delete_post` with a
  CSRF token and the current password. Existing theme links that still carry id and
  secret land on the confirm page and no longer delete. Themes may ship
  `user-delete_account.php`; core falls back to its own view when they do not.
- A member's "About you" text rendered unescaped on the public profile page core falls
  back to, so anyone who could register could store a script that ran for every visitor to
  that profile. It is escaped now, as the bundled themes already did.

### New

- Themes declare their page chrome with `osc_add_theme_support('chrome', …)`. Core falls back to
  probing `header.php`/`footer.php` and `common/header.php`/`common/footer.php`, so existing
  themes need no change.
- Themes declare extra view names with `osc_add_theme_support('views', …)`, so core's list of
  names a static page may not take is no longer hardcoded.
- Every front-end page resolves through an ordered candidate list: `osc_locate_template()`, the
  `template_candidates` filter, and per-category `item-{id}.php` and `search-{category}.php`
  views. A theme adds a view without a core patch.
- Themes name and describe their widget zones with
  `osc_add_theme_support('widget_locations', …)`; the admin screen shows the label and
  description instead of the raw slug. Themes that only carry a `Widgets:` line are unchanged.
- `osc_head()`, `osc_body_class()` and `osc_language_attributes()` let a theme hand the
  document head and the body classes to core, so a page core renders is described like any
  other. Each part of the head is opt-out.
- Core renders all 13 account and sign-in views — dashboard, listings, alerts, profile, the
  three settings pages, sign in, register, the two password-reset steps, the public profile and
  the plugin account slot — when the theme ships none. A theme that ships one still wins.
- A published `.oe-*` class vocabulary for those pages, documented at
  `docs/site/developers/account-pages.md`, so a theme restyles them in CSS with no PHP.
- `osc_gui_account_view()` resolves one account view: the theme's file, then a parent theme's,
  then core's page inside the theme's chrome, then core's own shell.

### Fixed

- A theme that shipped no view for an account page rendered a blank document.
- Flash messages carry `role="status"`, or `role="alert"` on an error, so a screen reader is
  told they appeared. The dismiss control is keyboard-operable without a theme script, and two
  queued messages no longer share one `id`.
- The profile form's "About you" field is labelled with the id it actually renders, and its
  region and city lists follow the country without a page reload.
- Core-rendered pages dropped to a bare standalone page on the default theme, which keeps its
  chrome in `common/` rather than the theme root.
- The credits, buy and orders pages each carried their own copy of the same stylesheet, which had
  drifted: an unstyled `History` heading, links in the browser's default blue, unbranded radios.

## Shopclass 6.2.0

Back up your database before upgrading: this release rebuilds foreign keys across twenty-four
tables. It also lets sites sell credits and charge for listings, lets people download a copy of
their own data, closes three paths that could execute arbitrary files under the plugins
directory, and removes Google Analytics from the core. Search engines get more to work
with too: every listing index now has its own description and a single canonical.

### Security

- **A listing description was stored exactly as submitted on sites using the rich editor.**
  Params' XSS check strips every tag, so it is switched off wherever a rich editor is in
  use, and nothing replaced it — a script in a description ran for every visitor who opened
  the listing, the listing's author included. Descriptions are now sanitised against an
  allow-list of the markup the toolbars actually produce; scripts, iframes, event handlers
  and any URL scheme other than http/https/mailto do not survive it. Only sites that turned
  on "use TinyMCE on the frontend" were affected — it is off unless an admin enables it, and
  everywhere else every tag was already stripped. The public listing editor also loses its
  source-view button, which is not what made this exploitable but is no longer worth
  offering.
- **The plugin admin-page route would execute any file inside the plugins directory.**
  `?page=plugins&action=renderplugin&file=…` ends in `require_once`, and decided what to
  run by looking for the literal `../` — so anything else under the plugins tree was
  executed as PHP. One of its two checks also compared `strpos()` with `==`, so a path
  beginning `..\` (offset 0, which `== false`) passed the test meant to stop it. The path
  is now resolved before it is used, by the same guard the AJAX routes use.
- **The `custom` AJAX action would execute any file inside the plugins directory.** It
  guarded only against `../`, so any path that stayed inside the directory was included and
  run as PHP regardless of what it was — a README, a lockfile, a language catalogue, or
  anything a plugin had written there from a request. The front-end copy of the action needs
  no login at all. The target must now be a `.php` file, and the path is resolved before it is
  used so that symlinks and encodings cannot land outside the plugins directory. Plugins
  reached through `osc_ajax_plugin_url()` are unaffected: that helper has always named a
  `.php` file inside the plugins directory.
- **Search-alert tokens are now authenticated.** They were AES-256-CTR with no MAC, a
  malleable combination: anyone holding one valid token had a known plaintext, and could
  edit the ciphertext into a token for a search of their choosing. Tokens are now AES-256-GCM
  and a tampered one fails to decrypt rather than decrypting to something chosen. Tokens
  issued by an earlier release are still accepted, so an alert link in an already-rendered
  page keeps working.
- The plugin list read out of preferences is no longer unserialized with object support
  enabled, matching how the rewrite-rule cache already read its own.
- Removed a dead fallback in the alert cipher that used Rijndael with the initialisation
  vector set to the key. It could never run — the openssl extension it tested for is a hard
  requirement — but it was the only remaining use of `phpseclib` in the core.
- Dropped the `pensiero/php-openssl-cryptor` dependency. Nothing calls it now that alert
  tokens are encrypted directly, and it was an unmaintained wrapper around what the openssl
  extension already provides. `phpseclib` itself stays: the `mcrypt_*` functions it backs are
  still there for plugins written before PHP 7.2 removed them.

### Performance

- **Translations are no longer parsed out of their binary catalogue on every request.** Core,
  theme, and one catalogue per enabled plugin were each read and rebuilt into one object per
  translated string before any page logic ran. Each catalogue is now compiled once and read
  back in a single step, which measures around fourteen times quicker per catalogue on a
  1,400-string catalogue — and saves the megabyte or so of objects each one left resident,
  the scarcer of the two on shared hosting. A replaced language pack is picked up on its own;
  an install that cannot write to its uploads directory simply parses as before, as does one
  whose cached copy is unreadable.

### Fixed

- A search carrying a category id rather than a slug returned 404, though the category
  existed. Both spellings now resolve.
- A static page built from blocks rendered with no `<head>` — no title, description or
  canonical. It now renders through the theme's page view.
- Price, photo, premium and custom-field filters each claimed their own canonical, so
  every permutation was a separate indexable URL. They now point at the page they
  filter.
- Static pages had no canonical, and the sign-in and registration forms were
  indexable. Both fixed; the contact page stays indexable and gains a canonical.
- Listing indexes in the same category all served the same meta description. They now
  lead with category, location and count, and city and region pages get one at all.
- Stripping HTML for a description glued words together across tags. Also affects
  static-page and listing excerpts.
- The category-city and category-region sitemaps listed a second, duplicate URL for every
  page. Clear the sitemap cache after upgrading (Settings -> Sitemap -> Regenerate).
- Activating, deactivating, installing, or uninstalling a plugin, or switching a theme,
  did not take effect until php-fpm restarted when `opcache.validate_timestamps` is off
  (the usual production setting) — the change appeared not to apply, or a stale class ran
  against new state and fataled, auto-deactivating the plugin. These operations now reset
  opcache so the next request recompiles.
- Logged-in visitors could be served a cached anonymous page by a reverse proxy or the
  nginx micro-cache. Identity lives as keys inside one cookie named `md5(WEB_PATH)`, which
  the cookie-name bypass could not match; the app now also sets a fixed-name `oc_cache_bypass`
  cookie in lockstep with login, and the caching contract matches that alongside the `osclass`
  session cookie and `oc_userLocale`. Nothing leaked (the response was already `private,
  no-store`), but logged-in users saw stale/anonymous pages.
- The chosen front-end language cookie now expires after 24 hours instead of a year, so a
  visitor who switches language is not kept out of the shared cache long after that visit.
- The web installer returned a 500 on a fresh install. The billing helper reads a
  preference as it loads, so requiring it during bootstrap asked for a database the
  installer had not configured yet. Unreleased; it never reached a published build.

- Sites are told when a newer translation is available again. The check returned false
  before doing anything and asked a market that no longer exists.

- Sample content is created again on a fresh install. Both installers were missing the
  billing helper the listing form calls, so seeding stopped with an undefined function
  and the install finished without it.

- Cloudflare Turnstile (and reCAPTCHA) tokens were passed through HTMLPurifier
  before siteverify. The token is opaque, not HTML; purifying it can empty or
  alter the value so every captcha check fails. The posted field is now read
  unpurified via Params::getParamString($name, false, false) on POST only.
  The previous empty-token guard used ORed inequalities and was always true.
- Two wallet writes in the same second no longer log a failed insert. The balance was always
  correct; the error was noise.

- **The package smoke test blamed every submission for preferences it had not created.**
  Rendering the search page mints a search-alert token, which writes
  `alert_private_key` and `alert_public_key`; those landed between the harness's before
  and after snapshots, so the diff attributed them to whatever package was being tested.
  Core's lazy writes now happen before the baseline is taken.
- **Plugin fields stopped appearing on the listing edit form.** `plugin_edit_item()` passes
  `edit&itemId=123`, from when the request was built by pasting that into a query string;
  the rewritten script sends it through `URLSearchParams`, which encodes the whole thing as
  one value. The hook therefore arrived as `item_edit&itemId=123`, matched nothing, and
  every plugin that renders on the edit form silently rendered nothing. A theme passing the
  same shape keeps working.

- **Deleting a custom field that had been submitted through a form failed.** The delete
  removed the field's values, its category assignments and its form memberships, then hit a
  foreign key on the submitted values it had not cleared and stopped — leaving the field in
  place but stripped of everything attached to it. The submitted values are now removed with
  it. This is the same fault that stopped categories being deleted when they had custom
  fields assigned.
- Every delete cascade now runs in a transaction, so a delete that cannot finish leaves the
  record exactly as it was instead of removing its children and failing on the parent.
- Deleting a form now removes the submissions made through it, deleting a listing removes its
  report and moderation history, and deleting a region or city removes its recorded slug
  history. None of these tables has a foreign key, so nothing was clearing them: the rows
  stayed behind, and an id later reused by a new record inherited them.
- A listing's counts are now decremented once it has actually been removed. A delete that
  failed still took the listing out of every category, location and user total.
- Deleting a form reported success when it had removed nothing.
- Running a database upgrade repeatedly no longer adds a duplicate copy of a foreign key each
  time. The schema reconciler compared keys including their `ON DELETE` clause, so a key whose
  rule had changed read as missing and was appended rather than replaced.
- A database upgrade no longer rewrites columns that had not changed. Where a table's
  definition padded between a column's name and its type to keep them aligned, the reconciler
  read the type as empty, decided it differed from the live one and issued a `CHANGE COLUMN`
  for it — rebuilding the table on every upgrade to arrive back where it started.
- Several schema changes made between 5.0 and 6.0 had never been written as migrations and
  reached an upgrading site only because the reconciler noticed they were missing: the
  storage-offload table and its column on listing images, right-to-left locale support, the
  numeric custom-field type, per-field settings, and the widening of the two user IP columns
  that truncated every IPv6 address. They are migrations now, so the upgrade no longer depends
  on the repair pass to arrive at a complete schema. Sites already on 5.2 or later are
  unaffected — every step checks first and does nothing where the change is already present.
- A listing URL that matches nothing returned 410 Gone, claiming a listing had existed there
  and was permanently deleted — for any id, including ones never issued. It now returns 404.
- Listings awaiting moderation or blocked, and unknown category/location/user subdomains,
  returned 400 Bad Request; they now return 404.
- Error pages now send a `Cache-Control` header, so a crawler walking dead URLs can be
  absorbed by a reverse proxy instead of costing a page render per hit.
- The account menu offered "Credits" and "Buy credits" whenever billing was on, so a site
  that enabled it only to cap listings sent every seller to an empty state. They now appear
  only where credits can be bought, or are already held.
- A seller at their listing limit only found out after writing the whole listing. Opening
  the post form now turns them back with the same message.
- Account-menu entries added by a plugin rendered below the log-out row, and pushed log out
  into the middle of the list. Log out is kept last.
- The installer downloaded storefront 1.0.1 when a fresh install had no bundled copy of the
  theme, two releases behind. It now fetches 1.2.0, from a single pinned version.
- **"My listings" and public seller profiles listed every premium listing on the site.** The
  not-expired filter contributed an ungrouped `OR`, so the query read `(mine AND live) OR (any
  premium listing)` — other sellers' rows appeared with the owner's own controls beside them,
  the counts and pager were inflated to match, and a premium listing that was admin-disabled or
  awaiting moderation could reach a public profile. The same clause also dropped the expiry and
  spam tests entirely on a site with no premium listings.
- The photo uploader told a seller the site-wide photo limit even when they held a raised
  one, capping them in the browser below what the upload actually accepts.
- Posting a comment refused any address whose top-level domain was longer than three
  characters — `.info`, `.online`, `.store`, `.agency` — reporting it as a missing email.
  The same form accepted a local part containing spaces. It now validates the way the
  contact form and registration always have.
- The `nospam` listing filter did not exclude spam. It asked for an option name the filter
  builder had no case for, so the one test it exists for was silently never applied.
- **Four of the five forms on Settings → Billing saved nothing.** Pricing (which owns the
  seller listing limit), offline payments, upgrades and seller limits posted actions the
  settings router had no case for, so each one landed on the General settings page and
  discarded the values with no error. Only the enable/disable toggle worked.

### Changed

- Micro-cache entries are kept for a day rather than dropped after a minute idle, and
  `docker-compose.prod.yml` turns the micro-cache on.
- CSRF tokens stamp their issue time on a half-hour bucket rather than the exact second.
  Two renders of a page are otherwise identical, so the second-level stamp was the only
  thing making them differ — which is what stopped a cache or a validator recognising them
  as the same page. How long a token is accepted is unchanged.
- The description editor on the post and edit listing pages refused to load, reporting that
  no TinyMCE license key had been provided. The front-end editor was the only one of the five
  that did not declare the bundled GPL build.
- Buying credits did nothing: the Continue button re-rendered the package picker and placed
  no order. A matched rewrite rule wrote its own params over the request, so once the buy
  page had a permalink the `action=checkout` the form posts arrived as the route's
  `action=buy`. A rule no longer overwrites a value the POST body supplies — a form's hidden
  fields are its intent, the URL only says where it was rendered — which fixes the same trap
  for any page that gains a permalink later. Unreleased; it never reached a published build.
- `osc_tinymce_config()` is the one place every rich-text editor is configured from, with a
  `basic` and a `full` preset. The settings each editor shared were copied into five call
  sites, which is how the front-end one was left without a licence key; a `tinymce_config`
  filter now also gives plugins their first way into these editors.
- The new billing permalinks could 404 for good after upgrading. The permalink table
  rebuilds when its stamped version stops matching the code's, which is true from the first
  request after new files land — before that release's migration has seeded the preferences
  the new routes are built from. A request that won that race compiled without them and
  stamped the new version anyway, so it never rebuilt again. The upgrade now recompiles the
  table once its migrations have run. Unreleased; it never reached a published build.
- Auto-cron runs its work in the same process on PHP-FPM, after the page has been sent,
  instead of asking the site for `?page=cron` over HTTP. That request only ever existed to
  get the work off the visitor's page load, and an origin behind a proxy cannot make it —
  it resolves its own public address to the proxy and never reaches itself, so nothing ran
  and nothing said so. Setups without FPM keep the old request. Nobody waits either way.
- The wallet, buy-credits and orders pages have permalinks (`user/credits`,
  `user/credits/buy`, `user/orders`) instead of query strings — the only account links that
  still had them. The old `?page=billing` form keeps resolving, so existing links and the
  gateway callback are unaffected.
- A release that ships no migration no longer sends the admin to the upgrade screen. The
  version is carried across on the next admin page load, with a notice saying so. Releases
  with migrations waiting still go to the screen, where the schema reconcile runs under
  supervision — an install carried across automatically has not been reconciled.
- `osc_get_locations_sql_url()` is deprecated in favour of the published location
  catalogue, and the unreachable installer function that was its only caller is gone.

- TinyMCE updated to 8.8.2. The editor now declares the GPL licence it is bundled
  under; version 8 refuses to start without one.

- The published Docker image runs PHP 8.5.

- PHPMailer updated to 7.1.1 and HTMLPurifier to 4.19.0.

- Translations now come from the Shopclass translations repository rather than the
  Osclass one. 32 languages carried over, re-merged against the current strings.

- A language catalogue that translates nothing is no longer compiled or shipped. The
  English ones were header-only files every lookup missed before falling back to the
  text it would have used anyway.

- Email templates ship as `mail.json` only; the parallel `mail.sql` copy is gone. Installing
  a language with no templates of its own now imports the bundled English set under that
  language instead of under `en_US`.
- Security policy now states supported versions, private reporting, response times and scope.
- Review routing added for security, database, controller, helper, schema, release and
  build-output paths.

- Foreign keys on dependent tables — descriptions, stats, slug history, custom-field values
  and link tables — now declare `ON DELETE CASCADE`, so the database removes them with their
  parent. Tables whose removal has side effects (listings, comments, uploaded files, the
  location hierarchy) deliberately keep the previous behaviour and are still removed by the
  code that performs those side effects. Existing installs are converted on upgrade.

### New

- The Docker image can now remove a cached page before it expires: it carries
  `ngx_cache_purge`, and `OSC_MICROCACHE` writes a purge endpoint reachable only from
  inside the container. That is what the nginx Cache plugin needs to hold public pages for
  an hour instead of core's thirty seconds.
- Public pages carry an `ETag`, so a returning visitor or a crawler gets a small "nothing
  changed" reply instead of the page again. They were already told to revalidate on every
  use but had nothing to revalidate against, so every check re-sent the whole page. nginx
  answers these from its own copy once it holds one.
- `response_body` filters the finished page, after CSRF tokens are injected and before
  anything reaches the client — one place for anything needing the whole body, instead of
  a second output buffer racing the first. Returning an empty string sends no body.
- `invalidate_item_cache` fires whenever a listing's rendered output goes stale — an edit,
  an image added or removed, the listing deleted, and a storage offload once it has
  rewritten the image URLs. That last case fired nothing at all before, so a proxy or CDN
  went on serving a page pointing at local files the offload had moved.
- `oc-cli.php storage:work` drains the storage-offload queue and nothing else, so it can be
  scheduled every minute. The queue was reachable only through the hourly cron tier, which
  runs a whole schedule block besides — on a busy site the backlog never cleared.
- `billing_listing_limit_message` filters the message a seller sees at their listing limit,
  for a plugin that sells extra slots and needs to say so.

- `osc_user_listing_limit()`, `osc_user_listings_used()`, `osc_user_listings_remaining()`,
  `osc_user_can_publish()` and `osc_listing_limit_message()` let a theme show a seller their
  listing quota before they hit it. -1 means unlimited.

- CI fails when the committed vendor and asset trees no longer match what
  composer.lock and package-lock.json declare. Releases ship those trees verbatim, so
  a bump that edits only a manifest would hand users the old library.

- Translation templates are published to the translations repository automatically when
  the strings they hold change, so translators are never working from an older set.

- CI fails when the translation templates no longer match the source they are extracted
  from, so a template cannot quietly stop offering newer strings to translators.

- `_x()`, `_ex()` and `_mx()` translate a string with a context, so two identical English
  words that are different words in another language can each be translated correctly.
  The context is for translators and is never shown.

- Delete hooks for the records that had none: `before_delete_field` / `after_delete_field`,
  `before_delete_field_group` / `after_delete_field_group`, `before_delete_page` /
  `after_delete_page`, `before_delete_country` / `after_delete_country`,
  `before_delete_region` / `after_delete_region`, `before_delete_city` / `after_delete_city`,
  `before_delete_city_area` / `after_delete_city_area`, `before_delete_form_submission` /
  `after_delete_form_submission`, `before_delete_widget` / `after_delete_widget`, and
  `after_delete_category` to pair with the existing `delete_category`. Each `before_` hook
  runs before the delete's transaction opens and each `after_` hook only once it has
  committed, so a plugin's own database work is never rolled back with a failed delete.
- **Sites can now sell credits and charge for listings.** Off by default, so nothing changes
  until you turn it on. Once enabled from **Settings → Billing**, you choose how many
  listings a seller may have live at once for free, and price extra listing slots and
  featured listings in credits.
  A built-in **bank transfer** option lets buyers pay by wire or cash — write your own
  payment instructions and settle each order by hand once the money arrives, with no card
  processor or API keys involved. **Billing → Packages** is where you define the credit
  bundles buyers choose from at checkout.
- Buyers get a wallet page showing their credit balance and history, a page to buy credit
  bundles, and a page listing their own past orders — plus a **Feature this listing** action
  that spends credits to run a listing as featured for a set number of days.


- **People can download a copy of their own data.** Signing in and following the link on the
  account page returns everything the site holds about them as JSON — profile, listings,
  comments, saved searches, orders and credit history — streamed straight to the browser
  rather than written anywhere. Erasure already existed; this is the other half of a
  data-subject request, and it is the only piece that was missing.

  Which tables hold personal data, whether each is included, and what deleting an account
  does to each, are recorded in one place (`mindstellar\privacy\PersonalData::map()`) with a
  reason attached — including for the sections deliberately kept, like the accounting
  records a sale leaves behind. A test fails if a table with a user column is added to the
  schema without an entry, because the failure mode otherwise is silent: data nobody can
  see and nobody knows to look for.

  Password hashes and account secrets are never included; they authenticate rather than
  describe.

### Breaking

- **`oc-includes/assets/chart-js/` has been removed.** Chart.js was added in 2021 and
  never used: no core file, admin page, bundled plugin or theme has ever loaded it, and
  the admin's charts are Google Charts. It shipped 122 KB to every install. A third-party
  plugin loading that path directly should bundle its own copy.

- **Back up your database before upgrading.** This release rebuilds foreign keys on
  twenty-four tables so that the database removes dependent rows along with their parent.
  Each key is checked against the whole table as it is rebuilt, so the time it takes
  follows the number of rows: measured over a quarter of a million listings and three
  quarters of a million custom-field values, the whole rebuild took about six seconds.
  A much larger site, or one on slow shared hosting, should expect longer, though not the
  kind of wait that needs planning around. If a timeout page appears, the upgrade is
  still running and will finish; sites with shell access can sidestep that entirely with
  `php oc-cli.php db:upgrade`.
  Before each key is rebuilt, any row still pointing at a parent that no longer exists is
  removed. A healthy database has none. If yours does, they are rows nothing could reach
  and the new key could not be added while they remained — the backup is what lets you
  look at them afterwards if you want to.
  An upgrade that is interrupted is safe to resume: each step is recorded as it completes
  and every step can be re-run, so starting the upgrade again finishes it.

- **Google Analytics has been removed from core.** The **Tracking ID** field is gone from
  Settings → General and no measurement snippet is rendered on public pages any more. Sites
  that were using it should paste their own snippet into a **Custom Code** widget under
  Appearance → Widgets, or install a plugin that provides it.
- `osc_google_analytics_id()` is deprecated. It still returns whatever measurement ID was
  saved before the upgrade — the stored value is left untouched, so a theme that prints its
  own snippet keeps working — but core no longer reads it and nothing can set it.

## Shopclass 6.1.0

Plugins and themes can now be found, installed and updated from inside the admin. Packages
declare which Shopclass and PHP versions they support, and the site is only ever offered a
version it can actually run — so an install on an older release is routed to the last version
that still works there rather than one that would fail on boot. Update checks, which have been
silently reporting nothing since 3.x, work again. The download and extraction path every
plugin, theme and core update passes through has been hardened, and packages that ship no
artwork render a built-in placeholder instead of a broken image. Container deployments should
read the upgrade note below before redeploying.

### Breaking

- **Container deployments: back up `oc-content/plugins` and `oc-content/themes` before upgrading.**
  Earlier production images kept those directories inside the container's writable layer, where
  anything installed was already discarded on every redeploy. This release moves them onto named
  volumes so installs finally persist — but the first redeploy onto the new arrangement seeds those
  volumes from the image, so packages installed into a still-running old container are not carried
  across and cannot be recovered afterwards. Copy them out first, and reinstall once the upgrade is
  done. Sites installed from the zip are unaffected. A stale entry may remain in the active-plugins
  preference for a package whose files are gone; it is ignored and harmless.

### New

- Plugins and themes can be browsed, installed and updated from the admin. Plugins and
  Appearance each gain **Browse** and **Updates** tabs backed by a published catalog of packages;
  search, category filter and sort run in the browser, and a package with no artwork falls back to
  a built-in placeholder. Installs verify the publisher host and a SHA-256 checksum, stage and
  validate the package before touching the live directory, and roll back to a backup if the swap
  fails.
- Plugin and theme update checks work again. The market helpers they relied on have returned
  `false` unconditionally since 3.x, so the admin update badges could never report anything; they
  now resolve against the catalog and offer the highest version the site can actually run, rather
  than the newest that exists.
- `oc-cli.php` gains `market:refresh`, `market:search`, `market:info`, `market:install` and
  `market:update` for headless and container installs.
- Container deployments keep what they install. `oc-content/plugins` and `oc-content/themes`
  are persisted alongside uploads and downloads, and the entrypoint reconciles bundled packages
  from a pristine copy in the image on every start — installing what is missing, refreshing only
  what the image has newer, and never touching a package the site owner installed. Package
  installs are now gated separately from the core self-updater (`OSC_DISABLE_PACKAGE_INSTALLS`,
  off by default), so a container can update plugins and themes in place while core continues to
  update by deploying a new image.
- Catalog listings carry a download count, and Browse can sort by it. The figure is GitHub's
  cumulative count of release-asset downloads, so it includes CI, mirrors and bots and is not an
  install count; it is shown only where there is one, and the default ordering stays most
  recently updated.
- Plugins and themes can declare `Requires Shopclass`, `Tested up to`, and `Requires PHP` in
  their header block. All three are optional — a package that declares nothing is treated as
  before, never as incompatible — and they are parsed for both plugins and themes.
- `mindstellar\market\Compatibility` evaluates those fields into one of four verdicts and
  picks the highest release a site can actually run. A site on 6.1 offered a package whose
  newest version requires 7.0 resolves to that package's last 6.x-compatible release rather
  than being offered an update that would fatal on boot.
- `osc_theme_screenshot_url()` and `osc_plugin_icon_url()` resolve a package's artwork, or a
  bundled placeholder when it has none, with `osc_theme_has_screenshot()` /
  `osc_plugin_has_icon()` to tell the two apart. Both are filterable.
- `tools/package-lint.php` validates a package directory against the published package
  specification, and `deprecated-api.json` lists every deprecated core symbol with its
  replacement. Both ship as release assets so external tooling reads one authoritative copy
  instead of maintaining its own.
- The package contract and the market design are documented in `docs/PACKAGE-SPEC.md` and
  `docs/MARKET.md`.

### Changed

- A package's compatibility is no longer decided by an exact string match against a
  comma-separated version list, which judged a package declaring `6.0.2` incompatible with
  6.0.3. The legacy list is still honoured when a package declares nothing newer.
- A download that returns a non-2xx status, an empty body, or a body failing its expected
  checksum is now a failure rather than a file written to disk and reported as success.

### Security

- Zip extraction now resolves every entry against the destination and rejects the whole
  archive if any entry escapes it, rather than skipping that entry and continuing. Absolute
  paths, Windows drive prefixes, backslash traversal, and symlink entries are all rejected,
  and entry-count, per-entry size, total size and compression-ratio caps stop a zip bomb
  before it is decompressed.
- Package downloads can carry an expected SHA-256, verified before extraction, and a
  checksum-carrying package is restricted to an allowlist of release hosts so a tampered
  source cannot redirect an install elsewhere. Packages resolved from a site's own update
  URI are unaffected.
- Redirect and total-transfer limits were added to the download path, which previously
  followed redirects without a cap and had no overall timeout.

### Fixed

- The catalog no longer bakes a compatibility verdict per version at build time — one static
  file is served to sites on many core versions, so a verdict computed against whatever core
  the build happened to run against was wrong for every other one, including the reference
  plugin showing as incompatible on its own catalog. It now publishes the raw `requires` /
  `requires_php` / `tested` fields plus a package-level supported range, and every verdict is
  computed locally, as it already was for the install/update gate itself.
- A prerelease core was refused any package requiring the release it belongs to — `6.1.0.beta2`
  could not install a package declaring `Requires Shopclass: 6.1.0`, because the beta sorts below
  the release. Compatibility now compares against the release a prerelease belongs to, so testers
  are not locked out of the series they are testing.
- The Appearance screen no longer renders a broken image for a theme that ships no
  `screenshot.png`; it also gained lazy loading, real alternative text, and intrinsic
  dimensions so the grid no longer reflows.
- `Zip::isPathValid()` never rejected anything — its condition evaluated false for every
  ordinary path, so the destination check had been dead since it was written.
- The plugin and theme update-package builders assembled their result and then returned
  nothing, and their GitHub branch tested `stripos(...) === true`, which that function never
  returns. Neither could ever have produced a package.
- `osc_downloadFile()` discarded the result of the download it performed and always reported
  success.

## Shopclass 6.0.3

An SEO pass on the public pages: self-referential canonicals, correct handling of empty and
query-string search URLs, and a valid breadcrumb graph.

### Changed

- Public pages emit a self-referential `<link rel="canonical">` — item detail, the homepage and
  search/category pages. The search canonical is the unsorted, page-1 URL, so paginated and
  sort/facet permutations of a result set consolidate onto one indexable URL.
- A valid but empty category or location page now returns `200` with
  `<meta name="robots" content="noindex, follow">` instead of a soft `404`, so a real landing page is
  not de-indexed while it holds no listings. Empty free-text or faceted searches still return `404`.
- The core breadcrumb is now a valid schema.org `BreadcrumbList` — the list is wrapped in the
  `BreadcrumbList` scope and each crumb carries a `position`, so the breadcrumb rich result can be
  parsed (themes rendering their own breadcrumb are unaffected).

### Fixed

- A query-string search on the rewritten `/search` route (e.g. `/search?sPattern=x`) now
  301-redirects to the friendly URL instead of returning `404`.
- Deleting a category assigned to a meta field group no longer fails silently — the group ↔
  category mapping is now cleared as part of the delete cascade, so the foreign key no longer
  blocks removal and the category no longer reappears in the tree.

### Security

- The `generator` meta tag no longer publishes the exact version, so a visitor cannot read it to
  target a known-vulnerable release.

## Shopclass 6.0.2

A maintenance release: the production container can send mail through an external SMTP relay, and the
core feature preferences are consolidated back into one section.

### New

- Container mail relay — the production Docker image installs msmtp and renders its config from the
  environment (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD`, `SMTP_FROM`, `SMTP_TLS`,
  `SMTP_STARTTLS`), so a deployed container sends registration, password-reset and contact email
  through a real provider with no in-app SMTP setup. With no relay configured, mail is logged and
  dropped by a shim so the failure is loud rather than silent.
- Opt-in `:edge` container image channel — a push to `develop` whose head commit message contains
  `[publish-edge]` refreshes `ghcr.io/mindstellar/shopclass:edge` (gated on the same tests as a
  release) without cutting a versioned tag, so deployers can preview changes before the next release.

### Changed

- Core feature preferences — sitemap, spam moderation, cleanup, item stats and the admin activity log
  — now live in the shared `osclass` preference section instead of their own, so every core setting is
  found in one place. An automatic migration relocates existing values on upgrade; nothing is lost.

## Shopclass 6.0.1

A security and maintenance release on top of 6.0.0: it hardens the public "send to a friend" form and
lets the Docker image recover the real client IP when it runs behind a trusted proxy.

### New

- Real client IP behind a proxy — set `OSC_REAL_IP_HEADER` (e.g. `CF-Connecting-IP` for a Cloudflare
  tunnel, `X-Forwarded-For` for a load balancer) so the image restores the visitor's IP into
  `REMOTE_ADDR`, which login throttling and abuse keying rely on. `OSC_REAL_IP_TRUSTED` sets the
  trusted-proxy CIDRs (defaults to any peer — correct only when the sole ingress is that proxy). Off
  by default.

### Security

- The "send to a friend" form emailed a listing to a recipient taken straight from the request, from
  the site's own address — an anonymous mail-relay surface. It now ships off by default
  (`enable_send_friend`) and, when enabled, requires a logged-in user (`reg_user_can_send_friend`, on
  by default), both under Settings → Listings. A theme that still links to it is bounced back with a
  notice rather than breaking. Both it and the contact-seller form are now rate limited per source
  address (filterable via `send_friend_throttle_max` / `item_contact_throttle_max`), so neither can be
  driven as a spam relay.

## Shopclass 6.0.0

The first stable release under the Shopclass name — the culmination of the Osclass modernization.
Since the last stable (Osclass 5.2.0) the admin has been rebuilt on Bootstrap 5 and stripped of
jQuery, the front end made sessionless so public pages are reverse-proxy/CDN cacheable, sitemaps and
S3 storage brought into core, search made pluggable and sharper, and a long list of security holes
closed. The bundled public theme is now Storefront — a modern, responsive, vanilla-JS front end —
replacing Bender. PHP 8.0 is now the floor.

### New

- Cache-safe listing view counts via a JS beacon (an uncached POST), so counts stay accurate behind
  a full-page cache and non-JS crawlers stop inflating them. Toggle via the `item_view_beacon`
  preference or `item_view_beacon_enabled` filter.
- Core-owned HTTP caching: public read pages emit `public, s-maxage=30, must-revalidate` (all else
  `private, no-store`), keyed only on Shopclass's own login/session/locale cookies, so a reverse
  proxy or CDN can cache them with no plugin and third-party analytics/ad cookies don't defeat it.
  Filterable via `public_cache_max_age` and `response_cache_control`; reference nginx micro-cache
  config in `.docker/nginx/microcache.conf`.
- Built-in XML sitemap at `/sitemapindex.xml` — paginated item, category, static-page and optional
  location sitemaps, configurable under Settings → Sitemap and hookable for a search backend. Core
  only serves it when no sitemap plugin claims the path.
- Built-in S3-compatible storage — offload images to S3, R2, Spaces, Wasabi, B2 or MinIO with public
  or presigned URLs and an optional CDN base. Uploads/deletes/migrations run through a cron-drained
  queue and each image records its location, so local and remote coexist. Supersedes the `better-s3`
  plugin.
- Pluggable search backend: a `search_results` filter lets a plugin answer searches from an external
  engine (Manticore, Elasticsearch), and returning a `model` key hands it the whole page (premiums,
  `osc_search()`). Return `null` and core's MySQL search runs unchanged.
- Sharper built-in search — requires every word, matches prefixes, honours `"quoted phrases"` and
  `-excluded` terms, ranks title above description, and falls back to substring for short queries.
  Adds a title `FULLTEXT` index (one-time `ALTER TABLE`). `Search::fromPrimaryKeys(array $ids)`
  hydrates an externally-produced match set, paging to the id count and preserving caller ranking.
- Command-line interface (`oc-cli.php`) for maintenance: `cron`, `db:upgrade`, `cache:flush`,
  `sitemap:warm`, `user:create-admin`, `user:reset-password`, plugin management
  (`plugin:list`/`activate`/`deactivate`), theme management (`theme:list`/`activate`), and a
  `doctor` health check.
- Headless install — `oc-cli.php install --unattended` provisions a fresh site (schema, seed data,
  baseline migrations, admin account) from environment variables or flags, with no interactive step,
  so a container or one-click platform can self-provision on first boot. Idempotent: a no-op once
  installed. DB settings and `WEB_PATH` from the environment or `config.php` are authoritative; when
  they come from the environment no `config.php` is written, keeping the container filesystem
  read-only.
- Official production Docker image (`Dockerfile`) — a single self-contained container (nginx +
  php-fpm + supervisor) that self-provisions on first boot via the headless installer and applies
  pending migrations on every start, so a container platform or `docker compose -f
  docker-compose.prod.yml up` brings up an installed, running site with no manual step. The default
  storefront theme is bundled from its release; configure via `DB_*`, `WEB_PATH` and `OSC_ADMIN_*`,
  and offload uploads to S3 for multi-instance scaling. Published to GHCR on every release, tagged
  with the exact version plus a moving channel alias (`:6.0.0.rc2` and `:rc`; `:latest` for stable).
  The image sets `OSC_DISABLE_SELF_UPDATE=1` so the admin's file-writing self-updater is turned off
  (it would be discarded on the next redeploy) — update by deploying a newer image tag; the
  entrypoint's `db:upgrade` migrates the schema. The same flag disables self-update on any immutable
  install.
- Demo mode from the environment — set `OSC_DEMO=1` to enable the read-only public-demo lockdown in
  a container, where `OSC_IGNORE_CONFIG_FILE` skips the `config.php` `define('DEMO', true)`. A value
  in `config.php` still wins.
- Translation templates are generated and shipped — a build step (`npm run i18n`) extracts every
  translatable string from the source into `oc-content/languages/core.pot` and `messages.pot`, so a
  translator can start a new locale, and compiles the bundled locale's `.po` to `.mo` so the binary
  catalogues never go stale. The release zip also drops build-only files (`Dockerfile`, `.docker/`,
  compose files, `phpcs.xml`).
- Core spam moderation — a keyword blocklist and visitor reporting that record why a listing was
  flagged, quarantine matches for review, and auto-hide past a threshold. Gate-able via the
  `item_mark` filter / `item_marked` action. Supersedes the Butler plugin.
- Provider-agnostic captcha — Cloudflare Turnstile alongside reCAPTCHA, verified server-side, failing
  closed, now also on admin login.
- Rebuilt first-run installer — four steps with a live "Test connection" check, plain error messages,
  transactional writes, and no jQuery/Bootstrap.
- Configuration can come entirely from the environment; `config.php` is optional
  (`OSC_IGNORE_CONFIG_FILE`, `OSC_CONFIG_FILE`). DB connections accept `host:port` / `DB_PORT` and a
  10-second connect timeout.
- Versioned database migrations — an ordered runner with a `t_migration` ledger, plus a CI check that
  a fresh and an upgraded install reach the same schema.
- Native Cleanup tool (expired/unactivated/spam/orphaned content) and Activity log management
  (filterable viewer, on/off switch, cron-enforced retention) under Tools.
- Rebuilt admin Categories manager — a real tree with drag-to-reorder/nest, inline counts, and a
  drawer editor.
- Per-admin dark/light theme toggle (persisted server-side) and correct RTL mirroring via logical
  CSS, with no separate stylesheet.
- `memcached` object-cache driver, selectable from the environment; the old `memcache` driver is
  deprecated.
- Friendly-named image downloads — a resource endpoint serves `<owner-slug>-<id>.<ext>`, linked via
  `osc_resource_download_url()`; a private bucket redirects to a short-lived signed URL.
- Themes can register routes to their own controllers: `osc_add_route()` now resolves a file in the
  theme root, and `osc_add_route_hook($id, $regexp, $url)` registers a controller-dispatched route
  that can act and redirect.
- New model events `item_content_updated` and `item_expiration_updated` fire on direct-model writes;
  `item_post_redirect_url` filters the post-publish redirect; `ItemForm::category_select()` accepts
  an `$attributes` array; `osc_csrf_token_form()` complements `osc_csrf_token_url()`.
- Autocomplete custom-field type: a text field whose suggestions come from a core AJAX endpoint —
  the distinct existing values of that field, gated to searchable fields so nothing else is
  enumerable — rendered through the shared vanilla `oscAutocomplete` combobox with no per-field JS
  (FieldForm emits `data-osc-*` attributes; a static init wires the widget). Plugins can supply
  their own source via the `custom_field_autocomplete_source` filter; themes style `.osc-ac-list`.
- Public form JavaScript can defer to the footer: the form validation and location-picker
  methods (`CommentForm`/`ContactForm`/`SendFriendForm`/`UserForm::js_validation()`,
  `ItemForm::location_javascript_new()`/`location_javascript()`) and `osc_render_form()` take an
  opt-in flag that enqueues their inline `<script>` after the file scripts instead of echoing it in
  place, wiring dependencies (e.g. the autocomplete lib) automatically. Off by default, so themes
  that call these in-place are unchanged. New helper `osc_enqueue_script_code($code, $deps, $id)`
  exposes the underlying footer inline-script queue, now id-deduplicated.
- Install smoke test in CI — a release zip is unpacked, installed against a real database, and signed
  into before it can become a release.
- Storefront is the new default public theme — a modern, responsive, vanilla-JS front end that
  replaces Bender as the bundled default. Fresh installs ship and activate it, and the release build
  bundles it from its own repository.
- Category slug changes now redirect permanently — renaming a category records its former slug and
  301-redirects old inbound links (and indexed search results) to the current canonical URL instead
  of 404ing. Old-slug-to-category mappings are stored so renames never chain, and the category tree
  and row object caches are invalidated on every category add/edit/reorder/delete so the new URL
  resolves immediately.

### Breaking

- Minimum PHP is now 8.0.
- The admin is vanilla JavaScript — jQuery and its plugins are no longer loaded on any admin page
  (tabs, modals, autocomplete, datepicker, category tree, validation all rewritten natively), and
  core ships no jQuery at all. `jquery`/`jquery-ui`/`jquery-validate` stay registered for themes that
  enqueue them; a plugin needing one must enqueue it itself.
- The item-form photo uploader moved from jQuery Fine Uploader to a vanilla `osc-uploader`, and
  enqueued scripts are now deferred by default (filter-controllable). A theme/plugin that hooked Fine
  Uploader must migrate.
- Removed the admin's legacy float-grid classes (`.grid-system`, `.grid-row`, `.grid-10`…`.grid-100`)
  in favour of Bootstrap 5's `.row`/`.col-*`.
- `RSSFeed::addItem()` now escapes values itself — stop pre-escaping link/image URLs in plugins or
  they double-encode.
- `ItemForm::category_select()` gained a trailing `$attributes = []` parameter. A theme that
  overrides this method (or any `Form`/`ItemForm` extension point) with the old signature becomes a
  compile-time fatal on upgrade, since PHP requires the override to stay signature-compatible — add
  the parameter to the override, or accept future options through the array.
- Removed the unused `INSTANT` alert frequency — core never dispatched it. Its mail builder,
  `hook_alert_email_instant` hook and `alert_email_instant` template are gone (an upgrade deletes the
  dead template); `osc_runAlert('INSTANT')` is now a no-op.

### Security

- Sign-in attempts are rate limited — failed sign-ins and reset requests counted per address and per
  account over a rolling window (20/10 per 15 min), refused before any password is hashed, and
  recorded against the name as typed so the limiter can't enumerate accounts. Adds `t_login_attempt`.
- Sign-in and reset forms no longer reveal which usernames/emails are registered — one answer for
  both cases, doing equal work so timing can't tell them apart.
- The database layer moved onto a parameterised query API; the audit behind it found and fixed
  several SQL injection vectors, including one reachable from anonymous public search.
- CSRF and remember-me rebuilt as stateless HMAC-signed tokens backed by a per-install key, not the
  session, so anonymous pages stay cacheable. Reset/activation codes are single-use and stored
  hashed; the remember-me cookie is HttpOnly/Secure/SameSite and a password change revokes it
  everywhere.
- Core HTTP fetches now verify the peer's TLS certificate by default. `osc_file_get_contents()` had
  forced `verify_ssl=false`, so every core fetch — including `install_locations()`, which runs the
  SQL it downloads — ran unauthenticated. It now defaults to `true`, caps redirects, pins to HTTP(S),
  and aborts stalled transfers.
- The saved-search alert endpoint no longer trusts a caller-supplied `userid` — an anonymous request
  could activate a recurring alert on any account, skipping confirmation; the owner now comes from
  the session.
- The installer carries a CSRF nonce on every state-changing step (closing a hole that could finalise
  an install with no admin), re-validates admin email/username server-side, and never reflects
  passwords into the page.
- Fixed a stored XSS in the admin search-alerts list and escaped remaining user-controlled output
  across admin datatables, statistics widgets and the comment editor. Watermark uploads are validated
  by content, not filename; added the missing CSRF check on `upgrade_db`; search-alert subscription
  can require a logged-in user.
- Public comment and abuse-report forms now require the configured captcha and carry a CSRF token,
  closing an unauthenticated spam/forgery vector on the two state-changing public forms.
- `redirectTo()` strips CR/LF from the `Location` URL, closing a header-injection / response-splitting
  vector on redirects built from request-derived values.

### Changed

- Rebranded from Osclass to Shopclass (new teal identity, rewritten README) and relicensed to
  GPL-3.0-or-later, retaining the Apache-2.0 notice for Osclass-derived code.
- The admin was rebuilt on a Bootstrap 5.3 design system — collapsible sidebar shell, unified content
  header, restyled settings/tables/callouts/messages, and on-brand dark-mode charts and login.
- Crawlers no longer count as listing views (a denylist, extensible via the `bot_user_agents`
  filter), so **view counts drop after upgrading** — that's them becoming accurate; revertible under
  Tools → Cleanup. Render-time counting is wrapped in a `count_view_on_render` filter for themes that
  count client-side.
- The front end is now sessionless: login identity, flash messages, form-repopulation values, the
  interface language, the post-flood wait and pre-save photo staging all moved off `$_SESSION` (into
  signed cookies or the database), so browsing, forms and posting hold no session and stay
  reverse-proxy cacheable across app servers. Existing sessions/remember-me survive the upgrade; only
  the admin and installer still use a session, and the `_setForm`/`_getForm` and
  `Session::_get('userId')` APIs keep working through shims.
- The RSS feed was modernised on `DOMDocument` — image `<enclosure>`, stable `<guid>`, single-escaped
  URLs, `rss_feed_item` filter — fixing a CDATA-breakout injection.
- Object cache gained an atomic `osc_cache_increment()`; the retired APC driver was removed (an
  unknown `OSC_CACHE` now falls back to the default); captcha verification uses an 8s timeout.
- Retired the unused `t_keywords` table (a migration drops it) and moved the build toolchain from
  Grunt to `sass-embedded` + `esbuild`.

### Performance

- Login went from ~1.4s to ~175ms — the bcrypt cost had been 15 since 2014 and is now 12, still above
  the recommended floor; existing passwords re-hash on next login, overridable with `BCRYPT_COST`.
- Listing statistics no longer grow without bound — `t_item_stats` kept a row per listing per day
  with seven indexes and was never pruned; it now keeps one row per listing plus a small site-wide
  daily rollup, six indexes gone, data migrated across.
- Anonymous browse/search pages are cacheable (lazy sessions/CSRF, stateless alerts); listing search
  uses an exact cheap `COUNT(*)`, the per-item resource N+1 is batched, `User::findByPrimaryKey` is
  cached, and query logging is gated behind `OSC_DEBUG_DB`.
- Auto-cron self-requests are throttled to at most once per 5 minutes instead of firing on every page
  view, so a busy site no longer spawns an FPM worker per hit.

### Fixed

- Five admin strings that passed a non-existent text domain (`'admin'`/`'modern'`/`'osclass'`) to
  `__()`/`_e()` always rendered untranslated; they now use the default `core` domain and are
  translatable.
- Category dropdowns list sub-categories again — the nested `select()` option builder recursed with
  the child array as the selected value and an integer as its options, collapsing each parent's
  children into a single empty option. Affects the admin category parent picker and any nested select.
- Checkbox custom fields render as translatable Yes/No text instead of a broken tick/cross image that
  only the old Bender theme shipped, so every other theme showed a missing image. Overridable via the
  `item_meta_checkbox_value` filter.
- The admin/one-click upgrade now records the new version in the `version` preference. The upgrade
  swaps the code files and upgrades the database in a single request, so the `OSCLASS_VERSION`
  constant loaded at the start still held the pre-upgrade value when the version was written — the
  preference lagged a version behind after every upgrade. It's now read from the freshly-synced code
  on disk.
- Pagination: the `list-last` class now lands on the final item (it was overwritten and never
  applied, so the last page's styling was off), a Pagination object can be rendered more than once
  without duplicating classes, and an out-of-range `iPage` no longer renders a bogus page number.
  The list is now a labelled navigation landmark with `aria-current` on the active page and
  `aria-label`s on the first/prev/next/last arrows. `osc_pagination_items()` no longer emits an
  undefined-variable notice outside profile/list contexts.
- Send-to-friend no longer 500s on an empty or malformed form. It dispatched the email without
  validating the recipient, so an empty/bad address reached PHPMailer and threw. It now validates
  the sender/recipient names and emails up front (like the contact-seller form) and returns a
  field-level error instead.
- `osc_sendMail()` no longer lets a bad address or dead mailserver 500 the page: a malformed
  recipient/BCC/reply-to threw from `addAddress()` before the existing `send()` guard was reached.
  The whole dispatch is now guarded, so any mail failure degrades to a logged warning and a
  `false` return for every caller.
- The database debug panel (`OSC_DEBUG_DB`) now counts queries issued through the new
  `mindstellar\database\Connection` API, which previously bypassed the log and left the panel
  reading zero — including `OSC_DEBUG_DB_EXPLAIN` plans for its SELECTs. Parameterised queries are
  shown with their real values inlined (debug display only; execution still binds). The panel is
  redesigned — a docked, collapsible summary (totals, slowest query, duplicate/slow/error counts)
  over a query list with color-coded timing, SQL highlighting, per-query EXPLAIN tables that flag
  full scans/missing keys/filesort, and duplicate-query flags for spotting N+1s.
- `osc_format_price()` drops the fractional part when a price is whole at the locale's precision, so
  `1,234` no longer renders as `1,234.00` while `1,234.50` keeps its decimals; the locale thousands
  separator and decimal point are unchanged.
- No more deprecation notices on PHP 8.4 or 8.5 (implicitly-nullable params and old cast spellings).
- `Plugins::hasHook()` reports whether a hook still has a listener, not merely whether one was ever
  registered — an emptied priority bucket used to read as true forever.
- Saved-search alerts: no longer stop firing mid-cron (the reused `Search` never cleared its keyword
  pattern, poisoning later alerts; restore resets it), each search is isolated in a try/catch so one
  error doesn't drop the rest of the run, and they match the same listings on replay as when saved
  (`toJson()` double-escaping fixed; old alerts repaired on replay).
- `osc_route_url()` emits `page=route` (not `page=custom`) for controller routes, so a fileless route
  no longer 404s when rewrite is disabled.
- `Item::updateExpirationDate()` — fixed a malformed UPDATE that silently never changed the date, and
  a null-deref that warned and mis-moved the counters when a listing had no location row.
- Storage settings persist the selected S3 provider and a locked region (R2's `auto`), reflect
  Better-S3's real activation state, and reliably queue new uploads for offload.
- Publishing a listing no longer cascades into foreign-key errors when the parent insert returns no
  row — `ItemActions::add()` checks the new id first, and ids are captured from the insert itself
  (`DAO::insertGetId()`) rather than a shared `insert_id` another statement could reset. A
  transaction left open at end of request is rolled back and logged.
- The "new version available" notice no longer sticks on an older release (the releases feed isn't
  newest-first; it now picks the highest version), and a failed update check no longer counts as a
  check or resets the daily timer.
- Assorted: unsaveable "Search alerts" setting; "most viewed" ignoring visibility/ordering; General
  Settings fatal when the update check had never run; sitemap XSL served as `text/xsl`;
  reported-listing double counting; real image compression (`image_png_compression` /
  `image_jpeg_quality` filters); env-only install 503 / WEB_PATH / utf8 bugs; friendly-URL
  fall-through to the home page; listing count not incremented on an email-change reassign; invalid
  `composer.json` version; non-numeric price TypeError on post; canonical-host redirect emitting a
  bare `:` port; login/admin-auth pages starting a session just to remember a return URL; "remove
  photo" leaving temp files; `Resource::findByOwner()` fataling on a poisoned cache entry.

Source: https://github.com/mindstellar/shopclass
