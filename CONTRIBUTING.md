# Contributing to Shopclass

Thank you for helping. Bug fixes, features, translations and docs are all welcome.

1. Open an issue first and agree the approach.
2. Branch from `develop`. Never target `master`.
3. Make the change, with a test that fails without it.
4. Run the checks in the pull request template, then open the pull request against `develop`.

Read before you start:

- [Contributing guide](docs/site/developers/contributing.md): the workflow, translations and bug reports.
- [Architecture](docs/site/developers/architecture.md): where new code goes.
- [Coding style](docs/site/developers/coding-style.md): the standard CI checks.
- [Tests](tests/README.md): how to run and write them.

Shopclass runs on sites with themes and plugins we cannot see. Treat the `osc_*` helpers,
hook names, admin CSS class names and `oc-includes/assets/` paths as a public API:
change how they work or look, but never rename or remove them.
