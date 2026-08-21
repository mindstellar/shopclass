# Sample Forms

A reference plugin for the Forms platform. It does not add a feature you would enable on a
production site — it exists so a plugin author has a working example to copy from, and every hook
it uses is inert unless you deliberately trigger it. Read `index.php`; each section is commented
with what it demonstrates and why.

## What it demonstrates

1. **Registering a placement context** — `osc_register_form_context()` gives a form embedded
   through `osc_render_form($formId, 'sample_forms.embed', $someId)` a readable label (and
   optional link) in **Listings → Form submissions**, instead of a raw `sample_forms.embed #id`.
2. **Filtering a form's fields per context** — the `form_fields` filter runs for both render and
   submit, so an added/removed/reordered field never diverges between the two. This example only
   reverses field order, and only for a form whose slug is literally `sample-reversed`, so it is
   inert on every real form.
3. **Vetoing a submission** — `form_submit_veto` rejects a submission by returning a non-empty
   message. The example rejects any value containing one of a few obviously spammy words.
4. **Amending validation** — `form_validation_errors` lets a plugin add its own error messages on
   top of core validation. The example is a documented no-op; a real plugin would inspect
   `$values` and push messages onto `$errors`.
5. **Reacting to a stored submission** — `form_submitted` fires once a submission is validated and
   saved. This is where a real plugin would send an email, call a webhook, or push to a CRM; the
   example logs the submission id under `OSC_DEBUG` instead.

## Configuration

None. The plugin has no admin screen, no preferences, and no database table — it registers a
context and wires hooks/filters at load time, which is why its install callback is empty.

## Using this as a starting point

Copy `index.php`, rename the `sample_forms_*` functions and the `Short Name` header, and replace
each example body with real logic. The registry calls (`osc_register_form_context`) are guarded
with `function_exists()` so the same file degrades to a no-op rather than a fatal error on a core
version that predates the Forms platform.
