## What and why

<!-- One or two lines. Link the issue. -->

## Checks

The commands are in `tests/README.md`, under "What else CI checks".

- [ ] `composer test` and `composer test:models` pass
- [ ] `composer lint` passes
- [ ] A new test fails without the change
- [ ] Changed strings: `npm run i18n`, templates committed
- [ ] Changed hooks: `php tools/gen-hooks-doc.php`, and `php tests/hook-contract.php --write` if on purpose
- [ ] Changed API routes: `php tools/gen-openapi.php` and `php tools/gen-api-doc.php`
- [ ] Changed SCSS or admin JS: `npm run build`, built files committed
- [ ] New class without a namespace: `composer dump-autoload`, `oc-includes/vendor/composer/` committed
- [ ] No `osc_*` helper, hook name, admin CSS class or asset path renamed or removed
- [ ] `CHANGELOG.md` has one line for a change users or plugin authors notice
