# Shopclass

A self-hosted classifieds website: listings, categories, member accounts, messages,
search and paid promotions. This image holds everything the site needs to run: nginx,
PHP 8.5 and the Storefront theme. It installs itself the first time it starts.

- Website and docs: [mindstellar.com/docs](https://mindstellar.com/docs/)
- Source: [github.com/mindstellar/shopclass](https://github.com/mindstellar/shopclass)

## One-command install

On a Linux server, this sets up a site with its own database. It can also get a free
HTTPS certificate for your domain:

```bash
curl -fsSL https://github.com/mindstellar/shopclass/releases/latest/download/install.sh | sh
```

## Run it yourself

```yaml
services:
  app:
    image: mindstellar/shopclass:latest
    ports:
      - "80:80"
    environment:
      DB_HOST: db
      DB_NAME: shopclass
      DB_USER: shopclass
      DB_PASSWORD: change-me
      WEB_PATH: http://localhost/
      OSC_ADMIN_EMAIL: you@example.com
    volumes:
      - uploads:/application/oc-content/uploads
      - plugins:/application/oc-content/plugins
      - themes:/application/oc-content/themes
    depends_on:
      - db
  db:
    image: mariadb:11
    environment:
      MARIADB_DATABASE: shopclass
      MARIADB_USER: shopclass
      MARIADB_PASSWORD: change-me
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    volumes:
      - db-data:/var/lib/mysql
volumes:
  db-data:
  uploads:
  plugins:
  themes:
```

With no `OSC_ADMIN_PASSWORD`, a strong admin password is made and printed in the log:
`docker compose logs app`.

## Main settings

| Variable | What it does |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | The database |
| `WEB_PATH` | The site's public address |
| `OSC_ADMIN_USER`, `OSC_ADMIN_EMAIL`, `OSC_ADMIN_PASSWORD` | The first admin account |
| `OSC_TLS_DOMAIN`, `OSC_TLS_REDIRECT_FROM` | Serve HTTPS with a free Let's Encrypt certificate. Publish ports 80 and 443 and keep `/var/lib/shopclass-tls` on a volume |
| `OSC_MICROCACHE` | Set to `1` to cache public pages |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD` | Outgoing mail |

Every setting is in the [Docker guide](https://mindstellar.com/docs/deploy/docker/).

## Tags

- `latest`: the newest stable release
- `beta`, `rc`: the newest beta or release candidate
- `6.4.0` and the like: one exact version

To upgrade, pull a newer tag and start the container again. It updates the database by itself.
