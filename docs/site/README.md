# Published documentation

These pages are the source for **https://mindstellar.com/docs/**. The site pulls
this directory at build time, so a merge here reaches the published docs on the
next deploy — there is no second copy to keep in sync.

They live next to the code on purpose: a pull request that changes behaviour can
change the page describing it in the same commit.

## Writing

- One page per file, Markdown (`.md`) or MDX (`.mdx`).
- Every page needs frontmatter with a `title` and a `description`. The
  description is the search-result snippet, so write it for a stranger.
- `sidebar.order` sets the position within a section; the section itself is
  configured in the site repository.
- A file's path is its URL: `configure/cron.md` → `/docs/configure/cron/`.
  **Renaming a file changes a public URL** — say so in the pull request so a
  redirect can be added.
- Link between pages with absolute site paths: `/docs/configure/cron/`.

Callouts use the `:::note`, `:::tip`, `:::caution` and `:::danger` syntax:

```markdown
:::caution[Optional title]
Text.
:::
```

## Checking your change

The pages render as plain Markdown on GitHub, which is enough for most edits.
To preview the real site, clone the site repository and point it here:

```bash
SHOPCLASS_DOCS_DIR=/path/to/shopclass/docs/site npm run dev
```

## Scope

This directory is the documentation people read. The design specifications one
level up in `docs/` — the caching contract, the market design, the package spec
— stay where they are; site pages link to them rather than duplicating them.
