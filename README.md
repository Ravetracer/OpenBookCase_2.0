<div align="center">

# 📚 OpenBookCase

**A crowdsourced map of public bookcases and give-boxes.**

Find, share, and care for public bookcases (_Bücherschränke_) and give-boxes near you — on an interactive OpenStreetMap-based map.

[![License: MIT](https://img.shields.io/badge/Code-MIT-blue.svg)](https://opensource.org/license/MIT)
[![Data: ODbL](https://img.shields.io/badge/Data-ODbL-green.svg)](https://opendatacommons.org/licenses/odbl/)
[![Symfony](https://img.shields.io/badge/Symfony-8.1-black.svg)](https://symfony.com/)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-777BB4.svg)](https://www.php.net/)

</div>

---

## About

OpenBookCase is a community-driven web app for discovering and maintaining **public bookcases and give-boxes**. Anyone can browse the map to find a location near them; registered users can add new entries, upload photos, rate locations, and keep a wishlist of books they're hoping to find.

It is the modern rebuild of an older platform, and supports **migrating the legacy data** so nothing is lost.

### Features

- 🗺️ **Interactive map** — Leaflet + OpenStreetMap with marker clustering, accessibility-coloured pins (red/yellow/green traffic light), and deep links to individual entries.
- 🔍 **Search & geocoding** — find places/addresses (via [Photon](https://photon.komoot.io/)), bookcases by name, or jump straight to `lat,lon` coordinates.
- ➕ **Add & reposition** — logged-in users can add an entry (navbar button, map right-click / long-press) and drag markers to correct a position.
- 📋 **List view** — sortable, searchable table of all entries with "nearest to me" distance sorting.
- ⭐ **Ratings** — per-user 1–5 heart ratings with a live average.
- 📷 **Photos** — upload, rotate, and annotate images (with screen-reader alt text), processed via Intervention Image.
- 💌 **Wishlists & messaging** — wish for a book at a bookcase, hand it off to others, and get notified when a followed location changes.
- 🔗 **Short share links** — every entry gets a compact `obc.onl/{code}` share URL.
- 🌍 **Internationalised** — UI available in 6 languages (English, German, Russian, Dutch, Spanish, French).
- 🌓 **Light / dark theme** — DaisyUI-based, persisted per browser.
- ♿ **Accessibility-minded** — Atkinson Hyperlegible font, alt text, and a traffic-light accessibility rating per location.

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Symfony 8.1 |
| Language | PHP ≥ 8.1 (Enums, Attributes, ULID) |
| Database | SQLite (dev) / PostgreSQL · MySQL (configurable) |
| ORM | Doctrine ORM 3.x with ULID primary keys |
| Templates | Twig 3 |
| Frontend JS | Stimulus 3 (server-rendered HTML fragments) |
| Styling | Tailwind CSS v4 + DaisyUI 5 |
| Asset build | Webpack Encore 5 |
| Maps | Leaflet.js 1.9 + MarkerCluster |
| Uploads | VichUploaderBundle 2.x |
| Serialization | JMS Serializer 5.x |
| Auth | Symfony Security + SymfonyCasts VerifyEmail |
| Images | Intervention Image 4.x (GD) |

## Requirements

- **PHP ≥ 8.1** with the `ctype`, `iconv`, and `gd` extensions
- **Composer** 2.x
- **Node.js** 18+ and **npm**
- **Symfony CLI** (optional, but recommended for the dev server)
- A database — **SQLite** works out of the box; PostgreSQL/MySQL are supported via `DATABASE_URL`

## Getting Started

### 1. Clone & install dependencies

```bash
git clone <repository-url> openbookcase
cd openbookcase

composer install
npm install
```

### 2. Configure environment

Copy the distributed env file and adjust it locally — never edit the committed `.env` for secrets, use `.env.local`:

```bash
cp .env .env.local
```

Key variables:

| Variable | Description | Default |
|---|---|---|
| `APP_ENV` | `dev` / `prod` / `test` | `dev` |
| `APP_SECRET` | App secret — **change this** | — |
| `DATABASE_URL` | Database connection | `sqlite:///%kernel.project_dir%/var/data.db` |
| `MAILER_DSN` | Mailer for verification / password-reset e-mails | `null://null` (discards mail) |
| `SHORTENER_BASE_URL` | Base URL for short share links | `https://obc.onl` |

> ⚠️ In development, mail is discarded by default. Set a real `MAILER_DSN` to actually deliver verification and password-reset e-mails.

### 3. Set up the database

```bash
php bin/console doctrine:migrations:migrate
```

(Optional — verify the schema matches the entities:)

```bash
php bin/console doctrine:schema:validate
```

### 4. Build frontend assets

```bash
npm run dev          # one-off development build
# or
npm run watch        # rebuild on change (development)
# or
npm run build        # optimised production build
```

> 🚫 Don't run `npm run build` while `npm run watch` is running — it desyncs the asset manifest.

### 5. Run the app

```bash
symfony server:start
# or, without the Symfony CLI:
php -S localhost:8000 -t public/
```

Then open **http://localhost:8000**.

## Importing Legacy Data

OpenBookCase can ingest data from the older platform and a third-party export:

```bash
# Bulk import from a PHPMyAdmin JSON export (upserts by legacy id; --fresh wipes & reimports)
php bin/console app:import-phpmyadmin-json -f path/to/export.json

# Import only NEW bookcases from a Lesestunden export (coordinate-based dedup)
php bin/console app:import-lesestunden -f var/lesestunden.json
```

Other useful commands:

```bash
php bin/console app:generate-short-codes   # backfill short share codes
php bin/console app:message:send           # send a system inbox message
```

## Testing

```bash
php bin/phpunit
```

## License

OpenBookCase uses **two separate open licenses**, as did its predecessor project:

- 🧑‍💻 **Source code** — [MIT License](https://opensource.org/license/MIT) (see [`LICENSE`](LICENSE))
- 🗃️ **Database / collected data** — [Open Database License (ODbL)](https://opendatacommons.org/licenses/odbl/)

Map data © [OpenStreetMap](https://www.openstreetmap.org/copyright) contributors.

---

<div align="center">
Made with ❤️ for sharing books.
</div>
