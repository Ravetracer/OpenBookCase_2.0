# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project: OpenBookCase

A crowdsourced web app for searching and finding public bookcases and give-boxes, shown on an OpenStreetMap-based interactive map. Users can view bookcase details, manage wishlists, upload photos, and rate locations. Supports legacy data migration from an older system.

## Code Style

- Symfony 8.1 coding conventions
- PHP 8.1+ features (Enums, Attributes, ULID, named arguments)
- Twig templates + Stimulus 3 controllers (server-rendered HTML fragments fetched via `fetch()`)
- HTML5 / CSS3 / **Tailwind CSS v4 + DaisyUI 5** (Bootstrap and all legacy jQuery were fully removed in the 2024 frontend rebuild)
- Inline SVG icons (Heroicons) via `templates/components/icon.html.twig` — no icon fonts
- JMS Serializer groups for API JSON shaping (not Symfony Serializer)

> The `@symfony/ux-vue` bundle is still installed but **unused** — `assets/vue/controllers/` is empty. The active frontend is Stimulus + Twig fragments injected into DaisyUI `<dialog>` modals.

## Common Commands

```bash
# Install dependencies
composer install
npm install

# Build frontend assets
npm run dev          # development build (watch mode)
npm run build        # production build

# Database
php bin/console doctrine:migrations:migrate
php bin/console doctrine:schema:validate

# Development server
symfony server:start
# or
php -S localhost:8000 -t public/

# Tests
php bin/phpunit
```

## Architecture Overview

### Stack

| Layer | Technology |
|---|---|
| Framework | Symfony 8.0 |
| PHP | >=8.1 |
| Database | SQLite (dev) / configurable via DATABASE_URL |
| ORM | Doctrine ORM 3.x with ULID PKs |
| Templates | Twig 3 |
| Frontend JS | Stimulus 3 (ux-vue installed but unused) |
| Styling | Tailwind CSS v4 + DaisyUI 5 + @tailwindcss/typography (via Webpack Encore PostCSS) |
| Asset Build | Webpack Encore 5 (+ `@tailwindcss/postcss` postcss-loader) |
| File Uploads | VichUploaderBundle 2.x |
| Serialization | JMS Serializer Bundle 5.x |
| Auth | Symfony Security + SymfonyCasts VerifyEmail |
| Maps | Leaflet.js 1.9 + MarkerCluster |
| Image processing | Intervention Image 4.x (GD) — note v4 API: `decodePath()`, not `read()` |

### Directory Structure

```
src/
├── Command/              # CLI commands (ImportPhpMyAdminJsonCommand, ImportOsmCommand, GenerateShortCodesCommand, FixHtmlEntitiesCommand, SendSystemMessageCommand)
├── Controller/           # HTTP controllers (Bookcase, Image, Index, Profile, Registration, Security)
├── Doctrine/Type/        # BinaryUlidType (custom ulid type — see Key Decisions)
├── Entity/               # Doctrine entities (ULID PKs)
│   └── Embeddables/      # Embedded value objects (no own table)
├── Enums/                # PHP 8.1 backed enums
├── Form/                 # Symfony form types
│   └── subForms/         # Nested form components
├── Repository/           # Doctrine repositories
├── Security/             # AppAuthenticator, EmailVerifier
└── Service/              # ImageService (orient/scaleDown/rotate via Intervention)

templates/
├── base.html.twig        # layout + permanent <dialog> modals (login, register, photo, profile)
├── components/icon.html.twig   # Heroicons inline-SVG macro {{ ui.icon('map') }}
├── components/rating.html.twig # DaisyUI mask-heart rating macro (view + editable)
├── form/daisyui_layout.html.twig  # Symfony form theme → DaisyUI markup (registered in twig.yaml)
├── index/                # map, bookcase_detail, edit_bookcase, new_bookcase (quick-add), list + _list_table (partial), photos_modal, snippets/
└── profile/_modal.html.twig    # profile modal body (rendered via render(controller))
assets/
├── app.js                # imports app.css + Leaflet only
├── controllers/          # Stimulus: map, bookcase_modal, photo_modal, profile, wishlist_modal, message_modal, list
├── geocode.js            # shared geocoder helpers (Photon search, placeLabel, parseCoords, parseGeo)
├── styles/app.css        # @import "tailwindcss"; @plugin "daisyui"; Atkinson @font-face
├── styles/               # leaflet.css, MarkerCluster*.css (only these remain)
├── js/                   # leaflet.js, leaflet.markercluster.js (only these remain)
└── webfonts/             # Atkinson Hyperlegible (FontAwesome removed)

postcss.config.mjs        # @tailwindcss/postcss
config/packages/          # Bundle config (doctrine, security, vich, twig form_themes, etc.)
migrations/               # Doctrine migration files
public/build/             # Webpack Encore output (gitignored)
```

## Entities & Relationships

### Core Entity Map

```
Bookcase (ULID)
  ├── embeds Position         (latitude, longitude — indexed, used for bbox queries)
  ├── embeds Address          (street, houseNumber, zipcode, city, additionalData)
  ├── embeds Accessibility    (level, description)
  ├── embeds Active           (ActiveStatus enum, statusDescription)
  ├── M:M  → Caretaker        (join table: bookcase_caretaker)
  ├── 1:N  → OpeningTime      (orphanRemoval)
  ├── 1:N  → Image            (orphanRemoval, VichUploader)
  ├── 1:N  → Rating           (orphanRemoval)
  └── 1:N  → WishlistItem     (orphanRemoval)

User (ULID)
  ├── 1:N  → Image
  ├── 1:N  → Rating
  └── 1:N  → WishlistItem

Caretaker (ULID)
  └── embeds Address
```

### Enums (`src/Enums/`)

| Enum | Values |
|---|---|
| `AccessibilityLevel` | `None`=1, `Partial`=2, `Full`=3 (int-backed; red/yellow/green traffic light) |
| `ActiveStatus` | `Active`, `Inactive` |
| `EntryType` | `Bookcase`, `Givebox` |
| `MapSymbol` | `Standard`, `Givebox`, `Tardis` |
| `WishlistItemStatus` | `Open`, `Dropped`, `NotFound`, `Fulfilled` |

## Routes

| Method | Path | Name | Description |
|---|---|---|---|
| GET | `/` | `app_index` | Homepage (map) |
| GET | `/bookcase/{bookcase}` | `app_bookcase_show` | **Deep link** (by ULID) — renders the map, centers on the entry, auto-opens its detail dialog |
| GET | `/s/{code}` | `app_short_link` | **Short share link** target (`https://obc.onl/{code}` → `/s/{code}`). Resolves `Bookcase.shortCode`; numeric codes fall back to `legacyId` (old shortener links keep working) |
| GET | `/list` | `app_list` | Table list of all bookcases (live search, sortable headers, lat/lon, distance sort) |
| GET | `/list/fragment` | `app_list_fragment` | Table + pagination fragment (HTML) — fetched by the `list` controller for live search/sort/paginate |
| GET | `/help` | `app_help` | Onboarding guide / tutorial (under Info menu). UI-icon-illustrated walkthrough of all features; per-locale partials `static/help/{de,en}.html.twig` (others → English). DaisyUI cards (not `prose`). |
| GET | `/about` `/imprint` `/legal` | `app_about` etc. | Static pages. **Imprint & legal** are legal texts (German authoritative): `templates/static/{imprint,legal}.html.twig` wrap a `prose` container and `include` a per-locale partial `static/{imprint,legal}/{de,en}.html.twig`, choosing `de` for German, else **English** (other locales fall back to English). The English versions carry a "convenience translation; German is binding" note. To update the texts, edit the locale partials — not the wrapper. |
| GET/POST | `/login` | `app_login` | Login form |
| POST | `/logout` | `app_logout` | Logout |
| GET/POST | `/register` | `app_register` | Registration (sends a verification email) |
| GET | `/verify/email` | `app_verify_email` | Email verification |
| GET/POST | `/forgot-password` | `app_forgot_password` | Request a password-reset link (enter e-mail) |
| GET/POST | `/reset-password/{token}` | `app_reset_password` | Set a new password from the e-mailed link |
| POST | `/profile/email` | `app_profile_email` | Update current user's email (AJAX, CSRF) |
| POST | `/profile/home` | `app_profile_home` | Set the user's home map location (`latitude`/`longitude`/`zoom`/`enabled`), AJAX + CSRF (`profile_home`). Used by the profile form **and** the map's "Set as home" popup button |
| POST | `/profile/delete` | `app_profile_delete` | Delete account (AJAX, CSRF) — see Account Deletion |
| GET | `/api/bookcase/` | `api_bookcase_retrieve` | List by bounding box (JSON). NB: no trailing slash → 302 |
| GET | `/api/bookcase/search` | `api_bookcase_search` | Type-ahead title search for the map search bar (JSON: id, title, lat, lon; min 2 chars, ≤8). Declared **before** `/{bookcase}` so the literal wins |
| GET | `/api/bookcase/new` | `api_bookcase_new_html` | Quick-add form fragment (HTML), `ROLE_USER`. Query: `lat`/`lon` prefill, `editable=1` unlocks coords + address search. Declared **before** `/{bookcase}` |
| POST | `/api/bookcase/create` | `api_bookcase_create` | Create from the minimal quick-add form (`BookcaseCreateType`), `ROLE_USER`. Returns new id + marker fields. Declared **before** `/{bookcase}` |
| GET | `/api/bookcase/{bookcase}` | `api_bookcase_retrieve_single` | Single bookcase (JSON) |
| GET | `/api/bookcase/{bookcase}/html` | `api_bookcase_retrieve_single_html` | Detail fragment (HTML) — injected into the modal |
| GET | `/api/bookcase/{bookcase}/edit` | `api_bookcase_retrieve_edit_html` | Edit form fragment (HTML) |
| GET | `/api/bookcase/{bookcase}/photos` | `api_bookcase_retrieve_photos_html` | Photo manager fragment (HTML) |
| POST | `/api/bookcase/{bookcase}/save` | `api_bookcase_save_bookcase` | Save form data (full BookcaseType). Returns `marker` payload so the map can refresh the marker live |
| DELETE | `/api/bookcase/{bookcase}` | `api_bookcase_delete` | Soft-delete: archive snapshot + required JSON `reason` into `deleted_bookcase`, then remove live entry (ROLE_USER) |
| POST | `/api/bookcase/{bookcase}/rating` | `api_bookcase_rate` | Upsert current user's rating (1–5), returns new average |
| POST | `/api/bookcase/{bookcase}/position` | `api_bookcase_move` | Position-only save after a marker drag (`latitude`/`longitude`), `ROLE_USER`. Validates ranges, notifies watchers |
| POST | `/api/bookcase/{bookcase}/image` | `api_image_upload` | Upload image (≤5, author required, optional `altText`, ROLE_USER) |
| POST | `/api/bookcase/{bookcase}/image/{image}/alt` | `api_image_alt` | Update an image's alt text / screen-reader description (ROLE_USER) |
| POST | `/api/bookcase/{bookcase}/image/{image}/rotate` | `api_image_rotate` | Rotate 90° cw/ccw |
| DELETE | `/api/bookcase/{bookcase}/image/{image}` | `api_image_delete` | Delete image |

`ProfileController::modal` has no route — it is rendered inline via `render(controller(...))` in `base.html.twig`. Bookcase deletion is a **soft delete**: `DELETE /api/bookcase/{bookcase}` (ROLE_USER, declared with `methods: ['DELETE']` alongside the GET single route) requires a JSON `reason`, archives a full snapshot into the `deleted_bookcase` table (`DeletedBookcase` entity: originalId, title, JSON payload, reason, deletedBy, deletedAt), then removes the live entry. The detail dialog's Delete button prompts for the reason.

## Frontend Architecture

Server-rendered Twig fragments fetched via `fetch()` and injected into **DaisyUI `<dialog>` modals** (the old Bootstrap off-canvas was removed). Styling is Tailwind v4 + DaisyUI 5.

### Stimulus controllers (`assets/controllers/`)

- **`map_controller.js`** — lives on a wrapper around `#map` (the search overlay is a sibling, kept outside `#map` so Leaflet never eats its events). Leaflet map, bbox queries, marker clusters. Marker popup buttons carry `data-bc-open="{id}"` (plain `data-*`, no Stimulus). Also owns:
    - **Search bar** (`searchInput`/`searchResults`/`searchClear` targets) — debounced query runs the local `/search` endpoint **and** Photon (places) in parallel, plus a `lat,lon` coordinate parse; grouped suggestion list with arrow/Enter nav. Selecting a bookcase flies + `bc:open`; a place/coord flies.
    - **Geolocation** — a custom Leaflet control beside the zoom buttons; centers on the user with a distinct blue dot + accuracy halo, and (when logged in) a subtle "Add bookcase here" link in its popup.
    - **Add a bookcase** (logged-in / `canadd`) — right-click (`contextmenu`) or touch long-press opens an "Add bookcase here" popup that dispatches `bc:create`.
    - **Drag-to-reposition** (logged-in) — markers are `draggable`; on `dragend` it confirms, then `POST /{id}/position` (reverts the marker on cancel/failure).
    - Values include `detailstrans`, `wishesopen`, the `search*`/`geo*` strings, `canadd`, `addhere`, `moveconfirm`/`movefailed`, and `initialId`/`initialLat`/`initialLon` (set by the `/bookcase/{id}` deep link to center + auto-open). Photon/coordinate/`geo:` parsing lives in the shared **`assets/geocode.js`** helper (also used by the modal controller).
- **`bookcase_modal_controller.js`** — lives on the permanent `<dialog id="bookcaseModal">`. **Replaces the old `details_controller` + `edit_controller`** (both deleted). Owns all detail/edit **and quick-add** behavior via **event delegation** on the dialog + `document` listeners for `[data-bc-open]`/`[data-bc-create]` clicks and `bc:open`/`bc:create` CustomEvents (also auto-opens the add dialog on `?add=1`). Routes injected buttons by `data-modal-action`: `detail`, `edit`, `create`, `save`, `delete`, `photos`, `copy-link`, `add-caretaker`, `remove-caretaker`, `close`. The quick-add flow fetches `/new`, posts `/create`, dispatches `bc:created` (map drops the pin), then shows a confirmation panel (add details / add photos / done); it also wires the create form's `geo:` paste and Photon address search. Also handles `change` on `[name="user-rating"]` (quick rate) and `submit` on the edit form.
- **`photo_modal_controller.js`** — on `<dialog id="photoModal">`. Opened by a `bc:photos` CustomEvent (dispatched by bookcase_modal). Loads `/photos` fragment; handles upload/rotate/delete.
- **`profile_controller.js`** — on `<dialog id="profileModal">`. Email update + two-step account deletion (its body is server-rendered, not AJAX-injected, so direct `data-action`/targets are fine).
- **`list_controller.js`** — drives the `/list` page. The search box + "Nearest to me" button live in the permanent shell (Stimulus actions `list#onSearch`/`list#sortByDistance`); the sortable headers (`[data-sort]`), pagination (`[data-page]`) and per-page select live in the **injected** `_list_table` fragment, so they're handled by **delegated** click/change listeners. State (`q`/`sort`/`dir`/`page`/`perPage`/`userLat`/`userLon`) is held in the controller; each change fetches `/list/fragment`, swaps the table, and `replaceState`s the URL. Distance sort gets coordinates from `navigator.geolocation`.

### The delegation rule (important)

Detail/edit/photo/quick-add fragments **and the `_list_table` fragment** are AJAX-injected, so **never put Stimulus controllers/actions on them**. The permanent controllers (dialogs, and the `list` controller on the page shell) handle everything via delegated listeners; injected buttons carry only `data-*` attributes. Cross-controller signalling uses `document` CustomEvents (`bc:open`, `bc:create`, `bc:created`, `bc:photos`, `bc:wishlist`, `watchlist:changed`, `wishlist:changed`). See the `feedback-stimulus-dynamic-content` memory.

### Map behavior

- Initial center: 51.1657°N, 10.4515°E (centre of Germany), zoom 7 — or the user's home position (if enabled), or the deep-link entry at zoom 17.
- `moveend` → `loadEntries()` → `GET /api/bookcase?latMin=&latMax=&lonMin=&lonMax=`; markers built once via `buildAndAddMarker()` (reused by the `bc:created` handler to drop a freshly added pin without a reload).
- Marker icon URLs are **absolute** (`/build/images/...`) so they resolve on nested routes like `/bookcase/{id}`.
- Marker icon precedence (`map_controller.markerIcon`): **accessibility-coloured pin** when the entry has an accessibility level (`marker-icon-accessibility-{red|yellow|green}.png`, retina `marker-icon-2x-accessibility-…`; colour from `AccessibilityLevel::markerColor()`, sent as `accessibility` in the bbox payload) → else Tardis when `mapSymbol === 'tardis'` → else standard. Clustering via `Leaflet.markercluster`.
- **Search** (top-left overlay): address/place via **Photon** (`photon.komoot.io` — chosen over Nominatim for type-ahead), bookcases via `/search`, and direct `lat,lon` entry.
- **Geolocate** control beside the zoom buttons; **right-click / long-press** adds an entry at that point (logged in); **markers are draggable** to correct a position (logged in) with a confirm + `/position` save.

### Theming

Light/dark via DaisyUI (`light --default, dark --prefersdark`); a navbar sun/moon `swap` toggle persists the choice in `localStorage` and sets `data-theme` on `<html>` (inline script in `base.html.twig` applies it before paint). The navbar logo has two variants — `obc2_logo.svg` and `obc2_logo_dark.svg` (the dark one adds a subtle cream outline so the dark-brown wordmark/icon stay readable). They're swapped purely in CSS (`.logo-light`/`.logo-dark`) keyed off `[data-theme="dark"]` **and** `html:not([data-theme])` + `prefers-color-scheme: dark`, mirroring DaisyUI's own dark-mode trigger.

## API Serialization

Uses **JMS Serializer** groups — not Symfony's serializer. Define groups on entity properties via `#[Groups]` annotation.

| Group | Contents |
|---|---|
| `bookcase` | id, title, position (lat/lon), mapSymbol, active status |
| `bookcase_detail` | + address, caretakers, comment, opening times |
| `address` | Address embeddable fields |
| `caretaker` | Caretaker name, contact |
| `images` | Image metadata (filename, thumbnail) |
| `wishlist` | WishlistItem fields |

## Key Architectural Decisions

### ULID Primary Keys + BinaryUlidType (read this before touching ids)
All entities use ULID (not UUID v4), generated via `UlidGenerator`.

**Critical SQLite gotcha:** Symfony's built-in `ulid` Doctrine type declares the column `BLOB` but binds parameters as `STRING`. On SQLite that stores the 16 ULID bytes with **TEXT storage class**, which never compares equal to a BLOB-bound parameter — so `WHERE id = :ulid` lookups silently return nothing (HTTP 404 on detail pages, etc.). Fixed with **`src/Doctrine/Type/BinaryUlidType`** (`getBindingType()` → `BINARY`), registered as the `ulid` type in `config/packages/doctrine.yaml` (`dbal.types.ulid`). This makes ORM persists and lookups consistently use BLOB — matching the raw-DBAL import.
- All ULID columns across the DB were repaired to BLOB storage class.
- Keep this in mind for any new id-based query or import: ids must be BLOB.

### Embedded Value Objects
`Position`, `Address`, `Accessibility`, `Active` are Doctrine embeddables — no separate tables, stored as columns in the parent entity's table. Prefer this for tightly coupled value objects.

### Geospatial Queries
- Indices on `position_latitude` and `position_longitude` columns
- Bounding box DQL: `bc.position.latitude BETWEEN :latMin AND :latMax`
- No PostGIS or spatial extensions required

### Legacy Migration Support
- `User` entity tracks `legacyId`, `legacyPassword` (MD5 hybrid), `legacyUser`, `legacyMigrated`
- `AppAuthenticator` detects legacy users, validates old hash (`md5(substr(sha256(email),5,15).sha512(password))`), and rehashes to bcrypt on first login
- `app:import-phpmyadmin-json` (`ImportPhpMyAdminJsonCommand`) handles bulk import from a PHPMyAdmin JSON export via raw DBAL. By default it **upserts** (matches by `legacy_id`); `--fresh` first **wipes every table** (except the migrations log) and reimports everything from scratch — creating legacy user accounts (`legacy_user=1`, `legacy_migrated=0`, `is_verified=1`, empty password + legacy hash) so they migrate on first login, plus a fallback `importer` user for orphaned image uploads. `--fresh` prompts for confirmation unless run with `-n`. Flags: `-f/--file`, `--clear-images` (delete all `bookcase_*` files from `public/images` first, so a fresh import leaves no orphaned files), `--skip-users`, `--skip-ratings`, `--skip-images`.
- `app:import-osm` (`ImportOsmCommand`) imports public bookcases / give-boxes from **OpenStreetMap, worldwide** — an Overpass JSON export (`amenity=public_bookcase` + `amenity=give_box`), read from `--file` (default `var/osm.json`) or fetched live with `--fetch` (single global Overpass query; `out center tags`, so ways/relations resolve to a centre point). **Insert-only by default** (never overwrites user edits). Dedup is two-layered & **idempotent**: (1) by stable OSM id stored in `Bookcase.osmId` as `"{n|w|r}{id}"` (unique; re-runs match here first); (2) else **coordinate proximity** via an in-memory spatial grid + haversine ≤ `--threshold` m (default 40, longitude search widened by `1/cos(lat)`) against all existing entries + ones added during the run. A coordinate match **backfills** the OSM id onto the unlinked existing row (so future runs match by id) but **never edits** that row's data. `--update` refreshes fields of **genuinely OSM-sourced rows only** (`source='osm'`), and even then keeps a user-given title (only rewrites titles still flagged `titleProvisional`) — coordinate-linked legacy/user rows are left untouched. **Tag mapping:** `addr:*`→`Address`; `wheelchair` yes/limited/no→`AccessibilityLevel` Full/Partial/None; `opening_hours` (`24/7` auto-detect)→`OpeningTime`; `operator`+`contact:*`→`Caretaker`; `website`→webpage; `description`/`note`→comment; give_box→`EntryType::Givebox`+`map_symbol=givebox`. **Titles:** real OSM `name` → built from `addr:*` (`"Public bookcase – Hauptstraße 5, City"`) → generic `"Public bookcase"`/`"Give-box"`; the latter two set `titleProvisional=true` (drives the in-app "help name this bookcase" prompt; cleared on the next full edit-form save). `--reverse-geocode` (opt-in, throttled ~1 req/s) derives a street-based title via Photon for un-addressed entries. **Anti-spam:** a `name` containing a URL is ignored (a title is generated instead), not stored. Flags: `-f/--file`, `--fetch`, `--overpass-url`, `-t/--threshold`, `--update`, `--reverse-geocode`, `--skip-giveboxes`, `--dry-run`. Imported data is **ODbL** — "© OpenStreetMap contributors" is credited on `/licenses` (`static.licenses.data_attribution`). New columns (migration `Version20260614085950`): `bookcase.osm_id` (unique), `source`, `title_provisional`.

### Anti-spam: no URLs in the title
`Bookcase.title` carries `#[Assert\Regex(pattern: '/https?:\/\/|www\./i', match: false, message: 'bookcase.title_no_url')]` (message in the `validators` domain, 6 locales) — so neither the quick-add nor the full edit form can submit a link in the title. Links belong in **webpage / comment / caretaker** fields only. The matching importer guard (above) keeps re-imports from re-adding such spam.

### Ratings (per-user, graphical)
- One `Rating` per user per bookcase; `BookcaseController::rate` (`POST .../rating`) **upserts** (find existing by bookcase+user, else create).
- Detail view shows the **average** as a read-only DaisyUI multicolor `mask-heart` rating (rounded; 0 ratings → all grey). Logged-in users get a **"Rate" dropdown** popover with editable hearts that saves instantly and updates the average live. Rating is NOT in the edit dialog.
- `templates/components/rating.html.twig` macro `hearts(selected, editable)` renders both modes.

### Shareable links (short codes via obc.onl)
- The old `Bookcase.shareLink` column was **removed** (migration `Version20260611...`). Don't re-add it; the import no longer maps `shorturl`.
- Each bookcase has a short, unique **`shortCode`** (6-char base62; `ShortCodeGenerator`; unique index). New entries get one on create; existing rows were backfilled by **`app:generate-short-codes`**.
- The detail dialog's share field shows **`{shortener_base_url}/{shortCode}`** (Twig global from env `SHORTENER_BASE_URL`, default `https://obc.onl`), falling back to the full `app_bookcase_show` deep link if a code is ever missing.
- The external shortener (obc.onl, just an htaccess `RedirectMatch`) 301-redirects `https://obc.onl/{code}` → `https://openbookcase.de/s/{code}`. The `app_short_link` route resolves the code (and old numeric links via `legacyId`) then renders the same map deep link as `app_bookcase_show`.

### Search & geocoding (map search bar + address-to-coordinates)
- **Photon** (`photon.komoot.io`) is the geocoder, not Nominatim — it's free/key-less and built for type-ahead (Nominatim's usage policy discourages per-keystroke querying). Biased to the map centre; `lang` only for `en`/`de`/`fr`.
- Shared helpers live in **`assets/geocode.js`** (`searchPhoton`, `placeLabel`, `parseCoords`, `parseGeo`) — reused by both the map search bar and the quick-add address search, so logic isn't duplicated.
- Bookcase-name search is the local `/api/bookcase/search` endpoint (title `LIKE`, ≤8).

### List view (`/list`) — search, sort, distance
- **Address fallback:** imported entries have empty structured address fields; the real address sits in `Address.additionalData`. Both the list and the detail dialog build the structured line and fall back to `additionalData` when it's empty.
- Live (AJAX) over server-rendered fragments: `/list` renders the shell + initial `_list_table` (works without JS / direct `?q=&sort=` URLs); the `list` controller fetches `/list/fragment` and swaps the table. Repo methods `countFiltered` + `findFilteredPaginated` (in `BookcaseRepository`) do the free-text search (title + every address field) and sort.
- **Distance sort** is portable: ordered in SQL by an **equirectangular planar approximation** (only `+ - *`, with `cos(userLat)` precomputed in PHP and passed as a param — no DB trig, so it works on SQLite/MySQL/Postgres) via a `HIDDEN` DQL select; the per-row km shown is an accurate **Haversine** computed in PHP for the visible page only (`IndexController::haversineKm`). Slight tie-order differences between the two are accepted.

### Adding & repositioning entries (login-gated)
- Creating and moving entries require **`ROLE_USER`** (mirrors photo upload). Entry points (navbar button, map right-click/long-press, geolocation "add here") only render for logged-in users; the navbar button works globally via `/?add=1` (intercepted on the map page, auto-opens elsewhere).
- **Quick-add** is deliberately minimal (`BookcaseCreateType`: title, entry type, installation type, coordinates) so users aren't overwhelmed; everything else uses entity defaults and is editable later via the full `BookcaseType`. After saving, a confirmation panel offers **add details / add photos / done** (photos need the now-existing entry).
- All add dialogs accept a **`geo:lat,lon`** paste field (Wikipedia GeoHack format) that fills the coordinates — even when they're read-only (map-click / geolocation contexts).
- **Drag-to-reposition**: markers are draggable for logged-in users; `dragend` confirms intent, then `POST /{id}/position` (position-only, validated, notifies watchers) — the marker reverts on cancel or failure. New routes: `/new`, `/create`, `/{bookcase}/position`.

### Profile & account deletion
- `ProfileController`: profile modal (username read-only, editable email, no password), `POST /profile/email`, `POST /profile/delete` (both CSRF-protected; tokens `profile_email` / `profile_delete`).

### Home map location
- `User` carries `homeLatitude` / `homeLongitude` / `homeZoom` (nullable) + `useHomeLocation` (bool, opt-in; migration `Version20260613081752`). When enabled, the map page opens centred on the user's home (centre priority in `map_controller.initialize()`: deep-link entry → home → default centre of Germany, zoom 7).
- Set it two ways, both hitting `POST /profile/home` (CSRF `profile_home`): the **profile modal** form (lat/lon/zoom + enable toggle, `profile#updateHome`), or the map's **right-click / long-press popup** — a separated, **confirmed** "Set as home" button below the "Add bookcase here" button (saves the clicked point + current zoom and enables the feature). Home values + token + strings are passed to `map_controller` as Stimulus values from `index.html.twig`.
- **Remove**: a confirmed "Remove home position" button in the profile form posts `clear=1` to the same endpoint, which nulls the coordinates/zoom and disables the flag (`profile#removeHome`).
- When set or removed in the profile modal, a `home:changed` document event keeps the map in sync live (drops/moves/removes the marker without a reload).
- **Home marker**: a distinct, non-clustered marker (`marker-icon-home.png` / `-2x-`, square 30×30) is shown at the home position whenever coordinates are set (independent of the centering toggle), via `map_controller.showHomeMarker()` / `removeHomeMarker()`.

### Navbar bookcase count
- A compact pill in the navbar (book icon + `bookcase_count()|number_format`) shows the live total, hidden on narrow screens. Backed by **`src/Twig/AppExtension.php`** (`bookcase_count()` → `BookcaseRepository::count([])`). The full DaisyUI `stat` component was deliberately not used — it's sized for dashboard cards and dominates the navbar.
- **Account deletion** keeps uploaded images but **nulls `Image.uploadedBy`** (made nullable, migration `Version20260611065636`); deletes the user's ratings + wishlist items + the user; then invalidates the session. Bulk DQL is keyed by id with `EntityManager::clear()` before removing the user (avoids stale-UoW re-sync).

### File Uploads
VichUploaderBundle manages image uploads:
- URI prefix: `/images`, storage: `public/images/`
- Namer: `PropertyNamer` using `Bookcase::uniqueFileName` → `bookcase_{id}_{ulid}`
- Auto-delete on entity update/remove
- **Image paths in Twig:** use bare `/images/{{ image.filename }}` — never `asset()` (see `feedback-image-asset-paths` memory).
- `ImageService` uses Intervention Image v4: `decodePath()` (not `read()`), `orient()`, `scaleDown()`, `rotate()`, `save()`.
- **Alt text (accessibility):** `Image.altText` (nullable) is the screen-reader description. Set on upload (optional `altText` field) and editable per-image in the photo manager (saved on blur → `POST .../image/{image}/alt`). Rendered as the `<img alt>` — detail view falls back to the bookcase title when empty.

### Accessibility (traffic light)
`Accessibility.level` is the int-backed enum **`AccessibilityLevel`** (`None`=1 red / `Partial`=2 yellow / `Full`=3 green) — int-backed so it maps onto the pre-existing INTEGER `accessibility_level` column (**no migration**). Serialized via the JMS exclude-+-virtual-`getLevelValue()` pattern (returns the int, like `Active::status`). Edited as a **traffic-light** of coloured radios (`radio-error/warning/success`) — rendered manually in `edit_bookcase.html.twig` with raw radios bound by the field's `full_name` (then `setRendered`). The detail view shows the DaisyUI **`status status-xl status-{color}`** indicator + the level label + the description. The description field has a hint placeholder (`form.accessibility_description_placeholder`).
- **Gotcha:** the colours are applied via dynamic class names (`status-{{ color }}`), which Tailwind's scanner can't see — the modifiers are safelisted in `app.css` via `@source inline("status-error … radio-success")`. Add any new dynamic DaisyUI modifier there.

### Mobility (fixed vs mobile)
`Bookcase.isMobile` (boolean) replaced the old free-text `mobility` (migration `Version20260612090500`; the column was never populated, so all rows default to `false`/fixed). `true` = mobile installation, `false` = fixed. Edited via a DaisyUI **toggle** in the edit form — opt a checkbox into a switch by passing `attr.class: 'toggle'` (the form theme's `checkbox_widget` adds `toggle-primary` instead of the `checkbox` classes). The detail view shows it as **Mobile**/**Fixed** (translated `mobile`/`fixed` keys).

### Database
Default is SQLite (`var/data.db`) for easy local development. Change `DATABASE_URL` in `.env` for PostgreSQL/MySQL in production.

## Security

- Authentication: form login via `AppAuthenticator` (custom, extends `AbstractLoginFormAuthenticator`)
- CSRF token validated on login form
- Email verification required after registration (`SymfonyCasts VerifyEmail`) — `RegistrationController` emails a signed link via `EmailVerifier`/`MailerInterface`
- Password hashing: auto (bcrypt/argon2 via Symfony)
- **Password reset** (`ResetPasswordController`, self-contained — no external bundle): `POST /forgot-password` looks up the user by e-mail and, if found, stores a one-time **sha256-hashed** token (`User.resetTokenHash`) + 1h expiry (`resetTokenExpiresAt`, migration `Version20260613090602`) and e-mails the raw token as a `/reset-password/{token}` link; the form always shows the same "check your inbox" screen (no account enumeration). `/reset-password/{token}` validates the hash + expiry, lets the user set a new password (≥6, confirmed), then **clears the token** (one-time use), sets `isVerified=true`, and migrates a legacy account (`legacyUser=false`, `legacyMigrated=true`). CSRF on both forms (`forgot_password` / `reset_password`); "Forgot your password?" link in the login modal; success toast on the map page. Copy under the `reset:` translation group (6 locales). **Note:** `MAILER_DSN=null://null` in dev discards all mail — set a real DSN to actually deliver verification/reset e-mails.
- No role-based access control currently enforced in `security.yaml` (access_control is commented out)

### Analytics (Matomo)
Self-hosted Matomo (`https://openbookcase.de/piwik/`, site id 1) is loaded from `base.html.twig`, **gated to `{% if app.environment == 'prod' %}`** so dev/test never pollute the stats. It runs **cookieless** (`_paq.push(['disableCookies'])`) with server-side IP masking, which is why the privacy policy treats it as legitimate-interest (no consent gate) and the cookie modal stays accurate (it sets no cookies). The privacy policy's "Webanalyse … Matomo" section documents it.

### Cookies & privacy (consent notice)
The app uses **only first-party, essential + functional storage — no advertising cookies, no third-party tracking** (analytics is the cookieless self-hosted Matomo above; OSM tiles + Photon geocoding set no `Set-Cookie`, they receive only the IP needed to render the map/search). Inventory:
- **Session cookie** (`PHPSESSID`) — strictly necessary (login, CSRF, flash). `cookie_samesite: lax`, `cookie_secure: auto`.
- **`obc_locale` cookie** (1y) — chosen language (functional).
- **localStorage** `theme` / `locale` — light/dark + language prefs; `cookieConsent` — the acknowledgement flag.

A **first-visit modal** (`#cookieModal` in `base.html.twig`) lists the categories and links to the privacy page; an inline script opens it once (gated by `localStorage.cookieConsent`) and records acknowledgement on accept/close. Re-openable via the navbar **Info → Cookies** link (`[data-open-cookies]`). All copy lives under the `cookies:`/`nav.cookies` translation keys (6 locales). Since there are no consent-requiring trackers, it's an informational acknowledgement rather than a granular opt-in/reject manager.
