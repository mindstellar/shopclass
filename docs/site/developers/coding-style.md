---
title: Coding style
description: The PHP coding standard for ShopClass core — PSR-12, the pinned php-cs-fixer, the PHP 8.0 floor, and the legacy naming conventions you will meet in older files.
sidebar:
  order: 13
---

ShopClass core follows **PSR-12**, enforced by a pinned `php-cs-fixer` that CI
runs on every pull request. You do not have to memorise the rules — run the
formatter.

## Running the formatter

```bash
composer cs:check    # dry run with a diff — exactly what CI enforces
composer cs:fix      # apply
```

The ruleset is deliberately **non-risky**: only whitespace, structure and import
hygiene are touched, never anything that could change runtime behaviour.
Generated and vendored trees — `oc-includes/vendor`, `oc-includes/assets`,
`oc-content`, `oc-includes/osclass/gui` — are excluded because they are not ours
to reformat.

## Checking the PHP floor

The supported floor is **PHP 8.0**, and CI fails a pull request that uses syntax
or functions newer than that — even if your local PHP is happy with it:

```bash
composer lint:install    # once
composer compat
composer lint            # cs:check + compat together
```

Composer's `config.platform` is pinned to 8.0.0, so a dependency requiring more
is refused at resolution rather than at runtime on somebody's shared host.

## Editor setup

The repository has an `.editorconfig`; most editors pick it up automatically.

| Setting | Value |
|---|---|
| Indentation | 4 spaces (2 for `scss`, `css`, `json`, `yml`) |
| Line endings | LF |
| Encoding | UTF-8 |
| Final newline | required |
| Trailing whitespace | trimmed (except in Markdown) |

## What PSR-12 gives you

The rules you will notice most:

```php
<?php

namespace mindstellar\example;

class Foo
{
    public function bar(int $count): string
    {
        if ($count !== 2) {
            $count = 2;
        } elseif ($count === 3) {
            $count = 4;
        } else {
            $count = 7;
        }

        return (string) $count;
    }
}
```

- Full `<?php` tags always; short tags never. In a file that is only PHP, omit
  the closing tag.
- The class brace goes on its own line; a method brace on its own line; a
  control-structure brace on the same line.
- **Always use braces**, even for a one-line body. `if ($a) $a = 2;` is not
  valid here.
- Never omit `default` from a `switch`.
- Imports sorted alphabetically, unused ones removed.

## Legacy conventions you will meet

Core is two decades old in places, and the fixer does not rename anything. Older
files use **Hungarian notation** for variables:

```php
$iThisIsAnInteger = 42;
$sSomeText        = 'This is some text';
$aVariable        = array(1, 2, 3);
```

New code does not need to adopt it — write plain, descriptive names — but do not
rewrite existing variables just to change their style. A rename that touches a
hundred lines hides the one line that mattered.

**Database column names, however, are a live convention, not legacy.** They
carry a type prefix after an underscore, lowercase, words separated by
underscores:

```
i_integer_column
s_some_text
d_price
b_enabled
dt_registration_date
pk_i_id              -- primary key
fk_i_category_id     -- foreign key
```

Follow it in any table you add — the DAO layer and the schema reconciler both
assume it.

## Documentation blocks

Public functions and classes carry a phpDocumentor-compatible docblock. Say what
is not obvious from the signature; do not restate the parameter types the
signature already gives.

## What you must not rename

ShopClass runs on installs with third-party themes and plugins. The `osc_*`
helper functions, hook names, admin CSS class names and `oc-includes/assets/`
paths are a **public API**. Restyle freely; do not rename or remove them.
