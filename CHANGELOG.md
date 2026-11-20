# Changelog

Older releases are archived in [ChangelogHistory.txt](ChangelogHistory.txt).

## Shopclass 7.1.0.rc2

**Shopclass 7.1 brings Shop mode: your site can now be a real online shop.** Add products with sizes and colours, take orders through a cart and checkout, and ship them with tracking, all on your own hosting. Classifieds sites get pay at the end of posting, messaging between buyers and sellers, map area search, place names in each language and faster filters. Shopclass now needs PHP 8.2 or newer.

### New
- **Shop mode**: Settings → General → Site is a sets classifieds or shop. In a shop only admins post, and visitors see buyer pages only. The installer asks for the site type and can add sample data.
- **Products with options**: Colour × Size and so on, each with its own SKU, price, stock, weight and shipping class, an old price shown crossed out, and a low-stock note.
- **Cart and checkout**: pay by card (Stripe, PayPal, Razorpay), bank transfer or cash on delivery. Stock is taken on order and given back on refund or cancel; unpaid card orders are cancelled after the stock hold time.
- **Orders**: Billing → Orders shows a To ship list, ships in parcels with tracking, and marks orders processing, delivered or cancelled, with a history of each change.
- **Invoices, credit notes and partial refunds**: every paid order gets a numbered invoice and every refund a credit note, with tax per line. Refund part of an order by amount or units; card refunds go through the provider.
- **Shop settings** (Settings → Billing → Shop): delivery charge, free delivery, cash on delivery, stock hold time, order number prefix and weight units.
- **Buyer account**: up to 10 saved addresses, a wishlist, back-in-stock and price-drop alerts, and order e-mails for every step (placed, paid, shipped, delivered, cancelled, refunded).
- **Star ratings** on comments (Settings → Comments).
- **Addresses and money for any country**: address forms follow the country, and each currency has its own number of decimals.
- **Pay at the end of posting**: a listing over the free limit is saved and paid for on the last step, with a reminder before an unpaid listing is deleted.
- **Messaging** between buyers and sellers, with an inbox, live delivery, Seen marks, typing and browser notifications. Admins review messages under Listings → Messages.
- **Map area search**, and the free **Map search** plugin to use it.
- **Place names in each language** for countries, regions and cities.
- **Location field**: one box with city suggestions, separate boxes, or dropdowns; Only places from the list refuses a place typed by hand.
- **Faster option and custom-field filters**, and a search count limit that shows "1,000+".
- **Appearance → Menus** builds the main, footer and extra menus from categories, pages and links.
- **Appearance → Customise** edits a theme beside a live preview, saved as a draft until Publish.
- **Page builder** layout blocks (Image and text, Columns, Heading, Button) and an Edit blocks screen with a live preview.
- **Per-language text**: site title and description, theme settings, Customise fields and widget texts can differ per language.
- **Notices bell** in the admin top bar lists site problems and plugin warnings.
- **Plugins**: each plugin's pages are grouped under its name in the Plugins menu, a pending update has an "Update to version X" button, and a theme installs the plugins it needs from one screen.
- **Categories** screen rebuilt: a tree table with search, bulk actions, Add subcategory, Move to… and Undo.
- **Installer** redesigned, with Stripe and PayPal beside Razorpay.
- **Activity log** with plain names, a date filter and one row per action.
- **Right-to-left admin** for right-to-left languages, and a Default admin language setting.

### Breaking
- Shopclass now needs PHP 8.2 or newer; the updater refuses a release the server's PHP cannot run.
- Sites update to 7.1 from 7.0.12; an older 7.0 site is offered 7.0.12 first.
- Releases ship only `shopclass_v*.zip`; `osclass_v*.zip` is no longer built.
- Namespaced core classes moved from `oc-includes/osclass/classes/` to `oc-includes/classes/`. Class names do not change; code that loads a class file by path must use the new path.
- Plugin admin links are shown only under Plugins → the plugin's name, also when the plugin adds them to Billing, Users or Tools.
- In shop mode there is no public user profile, and credit packages are hidden.
- Money uses the currency's decimals, not the language's: JPY prints with none and KWD with three.
- `billing_order_shipped` fires only when the last item ships; a paid order may now be `processing`, so test `Order::isPaid()` rather than `'paid'`.
- `posted_item` and the `listing.created` webhook fire when a held listing is published, not when it is saved.
- Core bundles symfony/http-client 7.4 (was 5.4); a plugin that implements its interfaces needs the 7.x signatures.
- Unused admin actions are removed, and 21 unused helpers, methods and classes are deprecated for removal in 8.0.

### Security
- Code acting as an admin with no admin signed in gets admin rights only in the admin area, cron, jobs and the CLI.
- A plugin download link from the catalogue is shown only when it is an https address.
- An IP ban rule can be an IPv6 address or a CIDR range.

### Changed
- The admin is easier to scan: Settings is grouped by area with a "Find a setting" box, every form ends in one Save bar under its fields, and list screens share one table with Bulk actions first.
- Admin messages say what happened and what to do next. Reworded messages lose their old translations until retranslated.
- With Automatic cron on, background jobs such as order e-mails run within a minute of a visit, not five.
- An update to a new X.Y release replaces `oc-admin` and `oc-includes` whole, so files the release dropped are removed.
- Listing photos are turned upright and saved once.
- Disabling a category disables every subcategory below it, and saving a category no longer changes its listings' end dates.

### Performance
- Option filter counts on 232,000 listings: 446 to 53 ms on MariaDB, 526 to 208 ms on MySQL.
- A theme's API calls on one page share their work, so a home page runs about half the queries.
- Deep admin order pages load in about 2 ms instead of 100 ms.

### Fixed
- A shop install no longer stops at "install theme Aisle" on a slow link: the zip now ships Aisle, and package steps get more time.
- A plugin or theme that needs a newer Shopclass or PHP can no longer be installed, enabled or activated.
- A language that shares its short name with another, such as English (Australia), can be installed again.
- Installing or updating a language keeps e-mail templates the admin edited.
- The Edit language screen has its Save changes button again.
- Custom date fields no longer save the day before when the visitor's time zone differs from the site's.
- Portrait phone photos no longer get white bars, and GIF and upper-case image formats save correctly.
- Static pages, custom fields and e-mail templates get a language tab for every site language.
- A title or description with "&", "<" or ">" is no longer refused as too long.
- Listings with no price sort last by price.
- New installs show currency symbols next to listing prices.
- Sign-up no longer says an activation mail was sent when mail is blocked.
- The memory limit at start-up is only raised, never cut.
- Two first upgrade grants for one listing no longer deadlock.
- Admin forms, list checkboxes, search and photo upload work with screen readers and the keyboard.

### For developers
- `tests/page-budget.php` fails when a public page includes more files, or sends more queries or cache commands, than `tests/page-budget.json` allows.
- Shop, messaging, pages, menus and SEO API endpoints; `GET /listings` filters by option, map area, stock and sale.
- `osc_api_get()` and `osc_api_call()` call the API from PHP with no key.
- Typed cart and order hooks for shipping, tax, promotion and payment plugins. See [Hooks](https://shopclass.org/docs/developers/hooks/).
- `shopclass.json` and the `Modes:` and `Requires Plugins:` headers declare site modes and required plugins. See [Package spec](https://shopclass.org/docs/developers/package-spec/).
- New helpers: `osc_site_mode()`, `osc_menu()`, `osc_place_field()`, `osc_settings_text_value()`, `osc_admin_filter_chips()`, `osc_search_bbox()`, `osc_address_formats()`, `osc_weight_display()` and more. See [Helpers](https://shopclass.org/docs/developers/helpers/).
- `OSC_DEBUG` and `OSC_DEBUG_LOG` can be set as environment variables.
- `ImageProcessing` and `ImageResizer` are deprecated; use `mindstellar\image\Processor`. `Field::findByItem()` is deprecated; use `FieldQuery::forItem()`.
- The full list of hook, API and helper changes is in the developer docs.
