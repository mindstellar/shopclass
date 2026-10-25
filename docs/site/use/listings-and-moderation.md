---
title: Listings & moderation
description: "Manage published listings in ShopClass: the listing rules, moderation queue, reported listings, comments, and cleaning up expired and spam content."
sidebar:
  order: 4
---

**Listings** in the admin panel is where everything your users publish
appears, and where you post listings yourself.

## The listing rules

**Listings → Settings.** These decide how the site behaves for everyone.
Set them before you have users, not after.

| Setting | What it decides |
|---|---|
| **Only logged in users can post listings** | Whether publishing needs an account. Off means anyone can post; on cuts spam sharply, and cuts volume too. |
| **Hold new listings for admin moderation** | New listings from users are disabled until an admin approves them. Listings an admin posts are never held. |
| **Hold edited listings for admin moderation** | The same, for edits to an existing listing. An admin's own edits are never held. |
| **Users have to validate their listings** | A new listing stays inactive until the user validates it, with a threshold: **After *n* validated listings the user doesn't need to validate the listings any more**. So regulars post freely while newcomers are checked. |
| **Logged in users don't need to validate their listings** | Skips that validation step for anyone who is logged in. |
| **Warn about expiration** | Days of notice a seller gets before a listing expires. 0 sends no warning. |
| **Search count limit** | The most matches a search page or the API counts. Past it the total shows as "1,000+", which keeps big sites fast. 0 counts every match. New sites start at 1000; updated sites start at 0. |
| **Attach *n* images per listing** | The photo limit. 0 means no limit. |
| **Title length** and **Description length** | The most characters a seller may type. The page refuses a length outside the allowed range and shows why. |
| **Show reCAPTCHA in add/edit listing form** | A captcha on the posting form, once reCAPTCHA is set up. |
| **Allow attached files in contact publisher form** | Whether the contact form takes a file: a picture, PDF, text or office document, up to the size set in **Settings → General**. |
| **An user has to wait *n* seconds between each listing added** | Rate limit on posting: the cheapest defence against a bulk poster. 0 means no wait. |
| **Only allow registered users to contact publisher** | Whether the contact form needs an account. |
| **Notify admin when a new listing is added** | An e-mail to you on every publish. Useful early, unbearable at volume. |
| **Enable the "send to a friend" form** | A sharing form on the listing page. |

:::note
**RSS shows** and **Latest listings shown** (how many listings appear in the
RSS feed and on the home page) live under **Settings → General**, not here.
So do the site contact form's **Attachments** switch and **Largest attached file**
(5 MB unless you change it), which also limits files sent to a publisher.
:::

## Moderating

The listings list filters by status, so the moderation queue is a filter
rather than a separate screen. Work through the ones awaiting validation,
then the reported ones.

Actions apply in bulk to selected listings: enable, disable, mark as spam,
delete.

:::danger[Delete is permanent]
The admin says so plainly: *this permanently deletes the listing and its
photos. This cannot be undone.* To take something down reversibly,
**disable** it instead.
:::

## Selling a listing

On a listing's edit screen, **Sell this item** makes it a product. Tick **Buyers can add this
item to a cart**, then set:

| Field | What it does |
|---|---|
| **SKU** | Your stock code, up to 64 characters. |
| **Old price, shown crossed out** | The price before a sale, shown crossed out next to the price; a higher old price marks the product as on sale. Blank: no sale. |
| **Stock** | How many you have. Each sale takes one; at 0 it is out of stock. Blank does not count stock. |
| **Weight in grams** | For shipping. |
| **Shipping class** | A name your shipping plugin groups items by, such as `bulky`. |

The item sells at the listing price; change that price to change what buyers pay. Turn on
**This item comes in options** for option rows (Colour x Size and so on): each row has its own
SKU, price and stock, and a row with no price sells at the listing price.

Untick it to stop selling. Only admins see this section. When **Settings → General → Site is a**
is set to shop, only admins can add listings at all.

## Reported listings

**Listings → Reported listings** collects what visitors have flagged. Each
entry shows the listing and how many reports it has drawn.

Two things worth knowing:

- Reports are keyed by visitor IP. If your site sits behind a reverse proxy
  or CDN (a service that sits in front of your server), it must pass the
  real client IP through, or every report looks like it came from the same
  person. See the [caching contract](/docs/developers/caching/).
- A high report count is a signal, not a verdict. Competitors report each
  other.

## Comments

**Settings → Comments** controls whether listings accept comments at all,
and on what terms:

- **Allow people to post comments on listings**: the master switch.
- **Users must be registered and logged in to comment**.
- **Require a CAPTCHA to post a comment**: see [spam and abuse](/docs/use/spam-and-abuse/).
- **Allow signed-in users to add a star rating (1 to 5) to a comment**: turns
  comments into reviews. Each user rates a listing once. Until you save this
  screen, it is on for a shop and off for a classifieds site.
- **A comment is being held for moderation**: turns moderation on, with a
  threshold next to it: **Moderated comments** (the number of previously
  approved comments an author needs before theirs appear without review).
- **Comments per page**: break comments into pages, with *n* per page.
- **Notifications**: e-mail the admin when **a new comment is posted**, and
  e-mail the listing's owner when **there's a new comment on his listing**.
  There is no separate notification for a comment held for moderation.

Comments are moderated from the **Listings → Comments** screen, with the
same enable/disable/spam/delete actions as listings.

Unmoderated comments on a classifieds site become a spam channel quickly.
Either moderate them, require registration, or turn them off. Leaving them
open and unwatched is the one option that always ends badly.

## Users

**Users** lists everyone registered. From there you can activate an
account, enable or disable one, edit its details, or add a user yourself.

**Users → Settings** carries the registration rules:

- **Users enabled**: whether the site has user accounts at all.
- **Anyone can register**: the master switch for sign-up.
- **Users need to validate their account**: e-mail confirmation before the
  account works.
- **When a new user is registered**: notify the admin.
- **Username blacklist**: words a username may not contain, separated by commas.
- **Users can message sellers on the site**: off by default. When on, a
  signed-in visitor's contact-seller form starts a conversation instead of an
  e-mail. The seller gets an e-mail that says only "you have a new message",
  with a link. Both read and reply under **Messages** in their account, and
  either can block the other or report the conversation. Guests still get the
  e-mail form, and so do shoppers on a shop. Held and reported messages are
  covered in [spam and abuse](/docs/use/spam-and-abuse/).
- **Send browser notifications for new messages**: each user then turns them
  on per browser from their messages page. The site must use HTTPS.

### Ban rules

**Settings → Security → Ban rules** blocks registrations and posts matching a pattern: an
e-mail domain, an address, an IP range. This is how you stop a returning
abuser without watching for them. Rules marked *Messages only* come from a
member's report and only stop contact mail; see
[Spam and abuse](/docs/use/spam-and-abuse/#messages).

### Alerts

**Users → Alerts** lists saved searches. As the admin explains: *alerts
notify a user by email when a new listing matches their saved search.*

Alerts are sent by **cron**, once a day, or once a week for alerts set to
weekly. If your users say they never receive
them, check cron before anything else. See
[setting up cron](/docs/configure/cron/).

## Clearing out old content

**Tools → Cleanup** removes content in bulk by category:

| Group | What it removes |
|---|---|
| **Reported listings** | Listings visitors have flagged as spam, unchanged for the chosen number of days. |
| **Expired listings** | Listings past their expiration date. |
| **Unactivated listings** | Listings never activated from the confirmation e-mail. |
| **Spam listings** | Listings marked as spam. |
| **Blocked listings** | Listings that are disabled or blocked. |
| **Unactivated users** | Accounts never activated from the confirmation e-mail. |

You can run a cleanup on demand, or save settings so the **daily cron** does
it for you. On a site of any age, this is the difference between a fast
database and a slow one: dead rows still cost you on every search.

Take a [backup](/docs/use/backups-and-maintenance/) before the first run.
