# Schema baselines

Copies of `oc-includes/osclass/installer/struct.sql` as it stood at past releases.
`tests/schema-drift.php` builds a database from one of these, runs the migrations over
it, and requires the result to match a fresh install of the current `struct.sql`. That
is what holds every schema change to having a migration behind it.

They live here as files because the release tags they came from were never pushed:
`5.0.0` through `5.2.2` exist only in local clones, so CI cannot read them out of git.
Without these the sweep silently narrowed to the one baseline it could reach and still
reported that everything passed.

| File | Taken from | Also covers | sha1 of the file |
|---|---|---|---|
| `5.0.0.sql` | 5.0.0 | 5.0.1, 5.0.2 | `1bb73efac2f7…` |
| `5.1.0.sql` | 5.1.0 | 5.1.1, 5.1.2 | `ab149bd33c5a…` |
| `5.2.0.sql` | 5.2.0 | 5.2.1, 5.2.2 | `3030d62e9e38…` |
| `6.0.0.sql` | 6.0.0 | 6.0.1, 6.0.2, 6.1.0 | `346d457751cc…` |

The "also covers" column is not a claim that those releases were checked separately —
their `struct.sql` is byte-identical to the one listed, so testing it tests them.

## Adding one

Nothing needs adding for an ordinary release. The workflow reads every release tag it
can reach and merges those baselines with the files here, discarding duplicates by
content, so a new tag is picked up on its own and only costs a run if it actually
changed the schema.

A file is only needed for a baseline CI cannot reach any other way. Write it out with:

```
git show <tag>:oc-includes/osclass/installer/struct.sql > tests/fixtures/schema-baselines/<tag>.sql
```

## Do not edit these

They record what shipped, not what should have shipped. Editing one to make the drift
check pass removes the only evidence of what an upgrading site is actually starting
from, and hides the migration that is genuinely missing. If a baseline fails, the fix
belongs in `installer/migrations/`.
