# docs/

Two different things live here, with two different audiences.

## `site/` — the published documentation

Everything under [`site/`](site/) is the source for
**[mindstellar.com/docs](https://mindstellar.com/docs/)**. It is written for people
running or extending a Shopclass site: installing, configuring, the admin panel,
deployment, and the plugin and theme API.

That is where user-facing documentation belongs. See [`site/README.md`](site/README.md).

## The specifications in this directory

The files beside `site/` are **design and contract documents**, written for people
working on core or building against it. They record what a subsystem's contract is and
why it was drawn that way — the reasoning behind a decision, not instructions for using
the feature.

| Document | What it covers | Status |
|---|---|---|
| [`PACKAGE-SPEC.md`](PACKAGE-SPEC.md) | The contract a plugin or theme must satisfy: header fields, compatibility, versioning, artwork, security. Normative — core, the registry CI and the catalog builder all follow it. | Shipped, in force |
| [`MARKET.md`](MARKET.md) | The GitHub-native plugin and theme ecosystem: the registries, the static catalog, and how core browses and installs from it. | Live since 6.1.0 |
| [`CACHING.md`](CACHING.md) | What the application guarantees to a reverse proxy or CDN: the cookie allowlist and the `Cache-Control` it emits. Normative. | Implemented |
| [`BILLING.md`](BILLING.md) | The seam between core's entitlements and a payment plugin's money. | Shipped in 6.2.0 |
| [`CUSTOM-FIELDS.md`](CUSTOM-FIELDS.md) | Field inheritance down the category tree, reusable forms, conditional logic, the field-type registry. | Implemented |
| [`PAGE-BUILDER.md`](PAGE-BUILDER.md) | Folding page templates and the widget registry into one composition model. | Phases 1–3 shipped |

Where a specification and a page under `site/` cover the same ground, the specification is
authoritative on the contract and the site page is authoritative on how to use it.

Designs for work that has not started are kept out of this directory: a specification
sitting beside shipped ones reads as a description of the software, and someone will
believe the feature exists.

A document here is expected to age into a record. When the thing it describes ships, its
status line says so and its planning sections become the account of what was decided —
they are not deleted, because the reasoning is the point.
