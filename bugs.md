# Core bug log

Bugs found in Osclass core while working on the shopclass theme. Each entry: what
breaks, where, how it was fixed (or routed around), and the version it applies to.

---

## `Resource::findByOwner()` returns a poisoned non-array cache value → fatal

**Where:** `oc-includes/osclass/classes/model/Resource.php` (the cache read in `findByOwner`).

**Symptom:** `Resource::findByOwner(): Return value must be of type array, string
returned`. Reached from `osc_get_resources()` → `osc_has_user_avatar()`; it halted a
production avatar migration partway (~26.5k of 62k rows) and can fatal any live request
that reads an affected owner's resources (e.g. a user avatar).

**Cause:** the method only treated the `=== false` miss sentinel as "read fresh" and
otherwise returned the cached value straight into its `: array` return type. If the object
cache ever yields a non-array for the key (a colliding write, or a backend handing back a
scalar), the return-type declaration fatals. It stayed dormant while `OSC_CACHE` was the
dummy no-op cache and surfaced once a real backend (memcached) was active.

**Fix:** return the cache only when `is_array($cache)`; treat anything else (miss OR a
poisoned non-array) as a miss — read fresh from the DB and re-cache. This also lets the
method self-heal past an already-poisoned entry without a cache flush.

**Applies to:** the 5.3 line (production beta). Fixed on core `develop`; hotfixed on prod
in place pending the next beta release.
