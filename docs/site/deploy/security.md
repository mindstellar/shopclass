---
title: Security & hardening
description: "Harden a ShopClass install: file permissions, HTTPS, admin access, login throttling, updates, backups and reporting vulnerabilities."
sidebar:
  order: 2
---

ShopClass ships with CSRF protection (stopping another site from tricking a
visitor's browser into taking an action on yours), hardened sessions, login
throttling and CAPTCHA support. What follows is the deployment side: the parts
that are your server's job rather than the application's.

## Keep it updated

The single most effective thing on this page. Most compromised PHP sites are
running a version with a published, patched vulnerability.

- Watch [releases](https://github.com/mindstellar/shopclass/releases).
- Apply core updates promptly: see [updating](/docs/updating/).
- Update plugins and themes too. An abandoned plugin is a liability regardless
  of how current core is.
- Remove packages you do not use. Disabled is better than installed; uninstalled
  is better than disabled.

## HTTPS everywhere

Serve the whole site over TLS, not just the login page. Session cookies sent
over plain HTTP can be captured and replayed, and an admin session is worth
capturing.

- Redirect HTTP to HTTPS at the web server.
- Set `WEB_PATH` to the `https://` URL so generated links and e-mails match.
- Consider HSTS (a header that tells browsers to always use HTTPS for this
  site) once you are confident TLS will not be turned off.

## File permissions

The web-server user needs to write `oc-content/` and its `uploads/` and
`downloads/` subdirectories. It does not need to write anything else.

```bash
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod 640 config.php
```

:::danger[`777` is never the answer]
It is the most common "fix" in forum posts and it makes every file on the site
writable by every process on the server, including anything an attacker
manages to run. If permissions are genuinely the problem, fix the **owner**, not
the mode.
:::

`config.php` holds your database credentials. It should be readable by the web
server and by nobody else.

## Protect what is not meant to be public

`oc-content/` is served over HTTP, and several things that can appear there
should not be readable:

- `debug.log`, `queries.log`, `explain_queries.log`: see
  [debugging](/docs/developers/debug-php-errors/). Delete them when you are done
  and deny `*.log` in your server config. For nginx:

  ```nginx
  location ~* ^/oc-content/.*\.log$ {
      deny all;
  }
  ```

  The container image already does this.
- Database dumps. Never leave a backup in the web root, except in the backups
  folder below.

### The backups folder

**Tools → Backup and restore** saves backups in `oc-content/downloads/backups/`.
On Apache the `.htaccess` it writes there closes it. nginx ignores `.htaccess`, so
add this to your server block and reload nginx:

```nginx
location ^~ /oc-content/downloads/ {
    deny all;
}
```

The Backup and restore page checks once a day and warns when the folder answers
on the web.

### Backups in a bucket

Backups saved to S3 are uploaded without an ACL, so they are as private as the
bucket. Set a separate private **Backups bucket** in **Settings → Storage**; a
photo bucket is usually public, and a public bucket or a custom domain serves
every key in it. Give it no public access and no custom domain. Downloads use a
signed link that lasts 15 minutes and is never written into the page. After each
upload the site asks for the backup without signing; if the bucket answers, the
Backup page warns that anyone can read it.

Turn `OSC_DEBUG` **off** in production. Displayed errors leak file paths,
database structure and sometimes credentials to anyone who can trigger one.

## The admin panel

- **Use a real admin username.** Not `admin`, and not the site name.
- **Use a unique, long password**, from a password manager.
- **One account per person**, so the [activity log](/docs/use/backups-and-maintenance/#the-activity-log)
  attributes actions to someone.
- **Remove accounts when people leave.**

For a site where the admin panel has no reason to be publicly reachable, put it
behind an IP allowlist, a VPN, or an identity proxy (a separate login wall in
front of the admin panel) at the web-server layer.

Locked out? `php oc-cli.php user:reset-password --user=<name>`: see the
[CLI reference](/docs/cli/).

## Login throttling and the real client IP

Failed logins are throttled per IP and per account by default: 20 attempts per
IP and 10 per account in a 15-minute window.

:::danger[Behind a proxy this needs configuring, or it protects nothing]
If your site sits behind Cloudflare, a tunnel, a load balancer or any reverse
proxy (a server in front of yours that passes requests through) and the real
client IP is not passed through, **every visitor arrives as the proxy**. The
per-IP limit then treats your entire audience as one person, and abuse reports
all key to the same address, and stored IPs and IP bans stop meaning anything.
:::

ShopClass reads the visitor IP only from `REMOTE_ADDR`. It never trusts a
forwarding header from the request itself, because an unauthenticated visitor
could set one to fake any address they like. `Tools → System info` warns when
`REMOTE_ADDR` does not match a forwarding header your proxy is sending, which
usually means this is not set up yet.

The fix is at the web server, not in ShopClass: rewrite `REMOTE_ADDR` to the
real client address, and only for connections that actually come from your
proxy.

**nginx** (the `ngx_http_realip_module`, built in on most distributions):

```nginx
# Only trust the header when the connection itself comes from the proxy.
set_real_ip_from 173.245.48.0/20;   # your proxy's / Cloudflare's IP range
real_ip_header    CF-Connecting-IP; # or X-Real-IP, X-Forwarded-For, ...
```

The container image does this for you: set `OSC_REAL_IP_HEADER` (and
`OSC_REAL_IP_TRUSTED` for the CIDR ranges) and it writes the block above; see
[Docker](/docs/deploy/docker/#putting-it-behind-tls).

**Apache** (`mod_remoteip`):

```apache
RemoteIPHeader CF-Connecting-IP
RemoteIPTrustedProxy 173.245.48.0/20
```

**Cloudflare**: use the `CF-Connecting-IP` header, and trust it only from
[Cloudflare's published IP ranges](https://www.cloudflare.com/ips/): list
every range in `set_real_ip_from` / `RemoteIPTrustedProxy`, not just one.

Whichever proxy you use, trust the narrowest set of peers that can actually
reach your server. A header anyone can send is worse than no header at all,
because it lets an attacker forge a different IP on every login attempt.

Tune the limits in **Settings → Spam and bots**.

## Bots and abuse

Turn on a CAPTCHA (a check that tells a human from a bot) for publishing and
registration before the site is public, add a posting delay, and require
e-mail validation. See [spam and abuse](/docs/use/spam-and-abuse/).

## The database

- A dedicated user with privileges on **one** database. Never the MySQL root
  account.
- A strong, unique password.
- Not reachable from the internet: `localhost` or a private network only.

## Uploads

Uploaded images are user-controlled files. Make sure your web server will not
execute anything in the uploads directory: deny PHP execution under
`oc-content/uploads/` at the server level. A single file can be a valid image
and valid PHP at once (called a polyglot file). This is a real attack
technique, not a theoretical one.

If you have [offloaded uploads to S3](/docs/use/media-and-storage/), the bucket
should be public-read at most, never public-write.

## Backups are a security control

Ransomware and a bad `DELETE` look the same from the restore side. Keep offsite,
tested backups: see [backups](/docs/use/backups-and-maintenance/).

## Reporting a vulnerability

:::caution[Do not open a public issue for a security problem]
A public report is a disclosure: every unpatched site is exposed from the moment
you press submit.
:::

Follow the
[security policy](https://github.com/mindstellar/shopclass/blob/master/SECURITY.md),
which sets out how to report privately and which versions are supported.

## A hardening checklist

- [ ] Running the current release; plugins and themes current
- [ ] Unused plugins and themes removed
- [ ] HTTPS enforced, `WEB_PATH` set to the `https://` URL
- [ ] `config.php` not world-readable; no `777` anywhere
- [ ] `OSC_DEBUG` off; no log files or dumps in the web root
- [ ] PHP execution denied under `oc-content/uploads/`
- [ ] Admin username is not `admin`; one account per person
- [ ] Real client IP configured if behind a proxy
- [ ] CAPTCHA on publishing and registration
- [ ] Database user scoped to one database, not reachable publicly
- [ ] Offsite backups, and one of them restored successfully
- [ ] `OSC_DISABLE_WEB_RESTORE` set if restores should only happen from a shell ([backups](/docs/use/backups-and-maintenance/#turning-web-restore-off))
