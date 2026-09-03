---
title: Media & storage
description: Configure images in ShopClass — sizes, upload limits, watermarks — and offload uploads to S3-compatible storage with the migration queue.
sidebar:
  order: 6
---

Photos are most of what a classifieds site serves, and most of what it stores.
Two admin screens cover them: **Media → Settings** for how images are processed,
and **Settings → Storage** for where they live.

## Image sizes

**Media → Settings → Image sizes** defines the three sizes ShopClass generates
from every upload:

| Size | Used for |
|---|---|
| **Thumbnail** | Listing grids and search results |
| **Preview** | The gallery strip on a listing page |
| **Normal** | The full view a visitor opens |

Sizes are entered as dimensions. Bigger is not better here — thumbnails are what
a browse page loads dozens of at once, and they set how fast the page feels.

### Regenerating

Changing a size does not touch images that already exist. **Regenerate images**
rebuilds them from the originals.

On a large site this is slow and heavy. Run it when traffic is low, and take a
[backup](/docs/use/backups-and-maintenance/) first.

## Upload restrictions

**Maximum size** caps what a visitor may upload, in KB. The screen shows the
ceiling PHP itself imposes — *Maximum size PHP configuration allows: n KB* — and
your setting cannot exceed it.

If you need a higher limit than PHP allows, raise `upload_max_filesize` and
`post_max_size` in PHP's configuration first; the ShopClass setting will not
override them. A too-low PHP limit shows up as an upload that silently fails on
large photos — see [debugging PHP errors](/docs/developers/debug-php-errors/).

The photo count per listing is set separately, in **Listings → Settings**.

## Watermarks

ShopClass can watermark uploaded images with **text** or with an **image**,
configured separately.

Watermarking is applied as images are processed, so it affects new uploads.
Existing images take it on when they are regenerated.

A watermark discourages your listings being scraped and re-posted elsewhere.
Keep it small and in a corner: a watermark across the middle of the photo
devalues the listing for the seller who posted it.

## Offloading to S3-compatible storage

**Settings → Storage.** By default images are written to `oc-content/uploads/`
on the web server. That is fine for one server and becomes a problem the moment
you want two, or when uploads outgrow the disk.

Storage offload moves them to an S3-compatible bucket, served directly to
visitors.

### Providers

The provider dropdown prefills the connection fields for:

- Amazon S3
- Cloudflare R2
- DigitalOcean Spaces
- Wasabi
- Backblaze B2
- MinIO / self-hosted
- Custom S3-compatible

Anything speaking the S3 API works through the custom option.

### Connecting

Fill in the credentials and bucket, then press **Test connection** before saving
anything. It confirms the credentials, the bucket and the permissions in one
step, which is much easier to debug than a failed upload later.

Two fields deserve attention:

- **Public URL** — the hostname visitors will load images from. Set this to your
  CDN or custom domain if the bucket is behind one, not the raw endpoint.
- **Keep a local copy** — whether the file also stays on the web server. Costs
  disk, and buys you a working site if the bucket becomes unreachable.

:::note[The secret key is write-only]
Leaving the secret key field blank keeps the saved one. The admin says so:
*leave blank to keep the currently saved secret key.* It is never displayed back
to you.
:::

### Migrating existing images

Turning offload on affects **new** uploads. Everything already on disk stays
there until you move it, and the migration tools do that:

| Action | What it does |
|---|---|
| **Offload all local images to remote storage** | Queues every local image for upload. |
| **Download all remote images back to local** | Pulls everything back — an offline copy, and the way out if you change your mind. |
| **Adopt existing Better S3 images** | Takes over images already in a bucket from the Better S3 plugin, rather than re-uploading them. |

### The queue

Migration does not happen inside your request — it is queued and processed in
the background, which is the only way it can survive a site with tens of
thousands of images.

The screen shows **pending jobs** and **failed jobs**, with **Process queue now**
to run it immediately rather than waiting for [cron](/docs/configure/cron/).

Watch the failed count. A handful of failures is usually a permissions problem
on the bucket; a rising count means the credentials or the bucket policy are
wrong and every job is failing the same way.

### Before you offload

- Take a [backup](/docs/use/backups-and-maintenance/) — this rewrites where
  every image on the site is served from.
- Set a bucket lifecycle policy if your provider charges for storage you forget
  about.
- Make the bucket's objects publicly readable, or serve them through a CDN that
  can read them. A private bucket with no signed-URL path shows visitors broken
  images.
- Test with a single new listing before migrating the whole library.
