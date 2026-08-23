---
title: Privacy & user data
description: What personal data ShopClass holds, how a user downloads a copy of their own data, and what deleting an account removes.
sidebar:
  order: 15
---

Running a marketplace means holding personal data about the people using it —
profiles, listings, comments, saved searches and, if you sell credits, orders.
ShopClass gives users both halves of a data-subject request: **erasure**, which
has always existed, and **export**, added in **6.2.0**.

## Users can download their own data

A signed-in user follows the link on their account page and receives everything
the site holds about them as **JSON** — profile, listings, comments, saved
searches, orders and credit history.

Two details worth knowing:

- It is **streamed straight to the browser**, never written to disk on the
  server. There is no export file sitting in your web root waiting to be found.
- **Password hashes and account secrets are never included.** They authenticate
  a person rather than describe them, so they are not personal data a subject
  request should return.

You do not have to do anything to enable it, and you are not in the loop when
someone uses it.

## Deleting an account

Erasure removes the account and the personal data attached to it. Some records
are deliberately kept — the accounting records a sale leaves behind, for example
— because a business is required to retain them.

Which tables hold personal data, whether each is included in an export, and what
deleting an account does to each, are recorded together in core
(`mindstellar\privacy\PersonalData::map()`) with a reason attached to every
entry. A test fails if a table with a user column is added to the schema without
one — because the failure mode otherwise is silent: data nobody can see and
nobody knows to look for.

That map is the honest answer when somebody asks what you hold about them.

## What this does and does not give you

It gives you the mechanics: export and erasure, working, without a plugin.

It does not give you a privacy policy, a lawful basis for processing, a data
processing agreement with your host, or a record of processing activities. Those
are yours to write, and no software can write them for you. If you operate in a
jurisdiction with a data protection regime, take advice rather than assuming the
software's features are compliance.

## Related settings worth reviewing

- **[Log retention](/docs/use/spam-and-abuse/).** Failed login attempts are kept
  for 7 days by default. That is a security record containing IP addresses.
- **[The activity log](/docs/use/backups-and-maintenance/#the-activity-log)**
  records admin actions with their originating IP, and can be cleared.
- **[Form submissions](/docs/use/forms-and-custom-fields/#form-submissions)**
  store what people sent you, with their IP. Delete what you no longer need.
- **[Backups](/docs/use/backups-and-maintenance/)** contain personal data too.
  Where you store them, and how long you keep them, is part of the same
  question.
- **Third-party scripts.** Anything you paste into a **Custom Code** widget runs
  on your visitors' browsers and may set its own cookies. Core no longer ships
  Google Analytics; whatever you add in its place is yours to disclose.
