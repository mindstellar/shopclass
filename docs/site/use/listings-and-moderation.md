---
title: Listings & moderation
description: Manage published listings in ShopClass — the listing rules, moderation queue, reported listings, comments, and cleaning up expired and spam content.
sidebar:
  order: 4
---

**Listings** in the admin panel is where everything your users publish appears,
and where you post listings yourself.

## The listing rules

**Listings → Settings.** These decide how the site behaves for everyone, so set
them before you have users rather than after:

| Setting | What it decides |
|---|---|
| **Only logged in users can post listings** | Whether publishing needs an account. Off means anyone can post; on cuts spam sharply and cuts volume too. |
| **Moderate listings** | Hold new listings until an admin approves them. Set a threshold and a user stops needing moderation *after n validated listings* — so regulars post freely while newcomers are checked. |
| **Moderate listings posted / edited by admins** | Whether your own team's posts and edits go through the same queue. |
| **Logged-in users' listings need validation** | Whether registration alone is enough to skip validation. |
| **Warn before expiration** | Days of notice a seller gets before a listing expires. |
| **Attach *n* images per listing** | The photo limit. |
| **An user has to wait *n* seconds between each listing added** | Rate limit on posting — the cheapest defence against a bulk poster. |
| **Only allow registered users to contact publisher** | Whether the contact form needs an account. |
| **Notify admin when a new listing is added** | An e-mail to you on every publish. Useful early, unbearable at volume. |
| **Latest listings shown** / **in RSS feed** | How many appear in those lists. |
| **Enable the "send to a friend" form** | A sharing form on the listing page. |

## Moderating

The listings list filters by status, so the moderation queue is a filter rather
than a separate screen. Work through the ones awaiting validation, then the
reported ones.

Actions apply in bulk to selected listings — enable, disable, mark as spam,
delete.

:::danger[Delete is permanent]
The admin says so plainly: *this permanently deletes the listing and its photos.
This cannot be undone.* To take something down reversibly, **disable** it.
:::

## Reported listings

**Listings → Reported listings** collects what visitors have flagged. Each entry
shows the listing and how many reports it has drawn.

Two things worth knowing:

- Reports are keyed by visitor IP, so a site behind a reverse proxy or CDN must
  be passing the real client IP through, or every report looks like it came from
  the same person. See the [caching contract](/docs/developers/caching/).
- A high report count is a signal, not a verdict. Competitors report each other.

## Comments

**Settings → Comments** controls whether listings accept comments at all, and on
what terms:

- **Allow people to post comments on listings** — the master switch.
- **Users must be registered and logged in to comment**.
- **Require a CAPTCHA to post a comment** — see [spam and abuse](/docs/use/spam-and-abuse/).
- **Moderated comments** — hold comments for approval after a threshold.
- **Break comments into pages** with *n* per page.
- **Notifications** — e-mail you when a comment is posted, and when one is held
  for moderation.

Comments are moderated from the **Listings → Comments** screen, with the same
enable/disable/spam/delete actions as listings.

Unmoderated comments on a classifieds site become a spam channel quickly. Either
moderate them, require registration, or turn them off — leaving them open and
unwatched is the one option that always ends badly.

## Users

**Users** lists everyone registered. From there you can activate an account,
enable or disable one, edit its details, or add a user yourself.

**Users → Settings** carries the registration rules:

- **Anyone can register** — the master switch.
- **Users need to validate their account** — e-mail confirmation before the
  account works.
- **When a new user is registered** — notify the admin.

### Ban rules

**Users → Ban rules** blocks registrations and posts matching a pattern —
an e-mail domain, an address, an IP range. This is how you stop a returning
abuser without watching for them.

### Alerts

**Users → Alerts** lists saved searches. As the admin explains: *alerts
notify a user by email when a new listing matches their saved search.*

Alerts are sent by **cron**, on the schedule each user picked for their saved
search — hourly, daily or weekly. If your users say they never receive them,
check cron before anything else — see [setting up cron](/docs/configure/cron/).

## Clearing out old content

**Tools → Cleanup** removes content in bulk by category:

| Group | What it removes |
|---|---|
| **Expired listings** | Listings past their expiration date. |
| **Blocked listings** | Listings that are disabled or blocked. |
| **Spam listings** | Listings visitors have flagged as spam. |
| **Unactivated listings** | Listings never activated from the confirmation e-mail. |
| **Unactivated accounts** | Accounts never activated from the confirmation e-mail. |

You can run a cleanup on demand, or save settings so the **daily cron** does it
for you. On a site of any age this is the difference between a fast database and
a slow one — dead rows still cost you on every search.

Take a [backup](/docs/use/backups-and-maintenance/) before the first run.
