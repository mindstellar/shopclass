# Tests

Shopclass tests are plain PHP scripts. There is no PHPUnit. Each file runs on its own
and prints `PASS` or `FAIL` per check, then a `RESULT:` line.

## Run them

```bash
npm run test:db                  # once: a throwaway MySQL on 127.0.0.1:33061 (Docker)
composer test                    # every tests/*.php, 4 at a time (php tests/run-unit.php -j N)
composer test:models             # every tests/models/*.php in one shared database
php tests/<name>.php             # one test
php tests/models/<name>.php      # one database test, in a fresh database of its own
php tests/run-models.php <name>  # one database test, the way the suite runs it
```

The database tests need the server from `npm run test:db`, or another MySQL or MariaDB.
Point them at it with `DRIFT_DB_HOST`, `DRIFT_DB_PORT`, `DRIFT_DB_USER` and
`DRIFT_DB_PASS` (default `127.0.0.1:33061`, `root`/`root`). Each test creates its own
database and drops it when it ends.

## Two kinds of test

| Kind | Where | Database | Use it for |
|---|---|---|---|
| Unit | `tests/*.php` | none, or its own scratch one | a class, a helper, a page's output, a source rule |
| Model | `tests/models/*.php` | the shared suite database | anything that reads or writes tables |

CI runs every file `run-unit.php` finds and every file in `tests/models/`. A new test
needs no workflow change.

## Write a test

A unit test:

```php
<?php
/*
 * (licence header, as in every file)
 */

/**
 * What this test checks, in one or two lines.
 *
 * DB-free.  Usage: php tests/<name>.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

harness_section('What the next checks are about');
pin('normalize trims and upper-cases', 'EUR', CurrencyCode::normalize(' eur '));
check('lower case is not valid as stored', !CurrencyCode::valid('usd'));

exit(harness_result());
```

- `pin($label, $expected, $actual)` passes when the two are identical (`===`).
- `check($label, $ok, $detail)` passes when `$ok` is true.
- `harness_section($title)` groups the output.
- `harness_result()` prints the totals and returns the exit code.
- `harness_query_count($fn)` counts the queries `$fn` sends. Use it to pin a query budget.

The docblock at the top is required. `php tests/run-unit.php --check` fails a file
without one, and CI runs that check.

A model test starts like this and seeds its own rows:

```php
require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_<name>');
$user  = seed_user($admin, 'sue', 'sue@example.test');
// ... checks ...

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
```

The `seed_*()` helpers in `tests/lib/scratchdb.php` write rows directly. `tests/lib/`
also holds stubs and test doubles (`stubs.php`, `api-doubles.php`, `test-clock.php`).

## Make sure a test can fail

A test that never fails proves nothing. After you write one, break the code it covers
for a moment and run the test again. It must fail. Then put the code back.

## Tests that read source code

Some tests read a PHP file as text, for example to check that core never imports the
API (`core-no-api-import.php`) or that table SQL stays in models and stores
(`no-raw-table-access.php`). Keep these for rules about the code itself. To test what
code does, run it. When you must read source, check one line or one method, not the
text between two `case` labels: a harmless move then breaks the test.

## Pinned lists

These tests compare the code with a list kept in `tests/fixtures/`. When you change a
hook, an API route or a public method on purpose, run the test with `--write`, read the
diff and commit the new list with your change:

| Test | Pins |
|---|---|
| `hook-contract.php` | hook names and their arguments |
| `api-contract.php` | the REST API surface plugins use |
| `api-openapi-compat.php` | the published OpenAPI document |
| `search-exports-contract.php` | the search helpers themes call |
| `strict-types.php` | files still without `declare(strict_types=1)` |

## What else CI checks

Run these before you push. Each one fails CI when it is out of date.

| Command | When |
|---|---|
| `composer cs:install` once, then `composer lint` | any PHP change (format, PHP 8.0 floor, PHPStan) |
| `composer lint:install` once | before the first `composer lint` |
| `npm run i18n` | a new or changed translatable string |
| `php tools/gen-hooks-doc.php` | a new or changed hook |
| `php tools/gen-openapi.php` and `php tools/gen-api-doc.php` | a REST API route change |
| `npm run build` | a change to SCSS or admin JS (commit the built files) |
| `composer dump-autoload` | a new class without a namespace (commit `oc-includes/vendor/composer/`) |

Other CI jobs compare the committed `oc-includes/vendor/` with `composer.lock`, and the
upgrade migrations with `struct.sql`. They fail with a message that names the fix.
