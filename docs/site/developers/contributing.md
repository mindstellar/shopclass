---
title: Contributing
description: How to contribute to ShopClass — reporting bugs, translations, documentation, and the pull request workflow.
sidebar:
  order: 14
---

ShopClass is maintained by its users. Contributions are welcome — bug fixes,
features, translations and documentation — and several of the most useful ones
need no PHP at all.

## Code

1. **Open an issue first**, describing the change. It is much less painful to
   agree an approach before the work than after it.
2. **Branch from `develop`.** Never target `master`.
3. Make the change. If it touches the admin theme, run `npm run build` and
   **commit the compiled output** — releases are cut with `git archive`, so
   whatever is committed is exactly what users receive.
4. Run the linters:
   ```bash
   composer lint     # PSR-12 check + PHP 8.0 compatibility
   ```
5. Open a pull request against `develop`.

See [coding style](/docs/developers/coding-style/) for the standard, and the
[developer overview](/docs/developers/) for a local development stack.

:::caution[Compiled and vendored output is committed on purpose]
Nothing runs `composer install` or `npm run build` at release time. A change to
`composer.json` without a rebuilt `oc-includes/vendor/` ships the old library
under the new version number. Run `composer update <package>` and commit
`vendor/` alongside the manifest — CI fails the build otherwise.
:::

## Documentation

These pages live in the ShopClass repository under
[`docs/site/`](https://github.com/mindstellar/shopclass/tree/master/docs/site)
and are published to this site automatically. Every page has an **Edit this
page** link at the bottom that takes you straight to the file on GitHub.

Documentation is the easiest first contribution, and the most useful one to
someone stuck at 2am: a hosting quirk you worked around, a step that was missing,
an error message nobody had written down.

## Translations

Translations live in
[**mindstellar/shopclass-i18n**](https://github.com/mindstellar/shopclass-i18n).
Fixing an awkward string in a language you speak is a real contribution, and
takes minutes.

## Testing

You do not have to write code to help. Running a prerelease against a real site
— your own copy, not production — and reporting what broke is genuinely
valuable, and it is how compatibility problems get found before a release
instead of after.

## Reporting bugs and suggesting features

Open an [issue](https://github.com/mindstellar/shopclass/issues). A reproducible
report is worth ten vague ones — see
[how to write a bug report](/docs/developers/bug-reports/).

Security vulnerabilities are the exception: **do not** open a public issue. The
[security policy](https://github.com/mindstellar/shopclass/blob/master/SECURITY.md)
explains how to report them privately.

## Helping other people

Questions and discussion happen in
[GitHub Discussions](https://github.com/mindstellar/shopclass/discussions).
Answering someone else's question is a contribution, and often a better use of
an hour than writing code.

## Plugins and themes

Publishing an extension helps the whole ecosystem — and, unlike core, it is
entirely yours. See [the market](/docs/developers/market/).

## Telling people

More users means a stronger community means a better ShopClass. A link, a
mention, a blog post about what you built with it — all of it counts.
