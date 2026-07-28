# Veezi for WordPress

Publishes a cinema's [Veezi](https://www.veezi.com/) programme as WordPress
content — films, sessions, posters and booking links — so the website follows
the ticketing system instead of being retyped from it.

The integration is **read-only**. Ticket sales stay in Veezi; the plugin renders
links out to the cinema's own booking pages and never handles a transaction.

> **Status: early.** This release connects to Veezi and syncs the programme,
> posters included, into WordPress. Listing order and the Elementor bindings that
> display it are still to come, and there is no scheduled or on-demand trigger
> yet.

## What it syncs

A sync reads three Veezi endpoints and turns them into ordinary WordPress
content:

- **Films** (`veezi_film`) — one per film something is scheduled for, carrying
  the synopsis, runtime, distributor, release date, trailer link and poster,
  filed under genre and classification taxonomies. Films are never deleted, so a
  link to one keeps working after its season ends.
- **Sessions** (`veezi_session`) — one per screening, with its start and end,
  its booking link, and whether it is sold out or nearly so.

The listing is built from what is scheduled, never from the film catalogue: every
film in a Veezi account reports itself as active, including test records, so a
listing derived from the catalogue would advertise films that will never screen.

### Times

Veezi reports showtimes with no timezone offset — `"2026-08-02T16:30:00"` is a
reading of a clock, not a moment. The plugin interprets them in **the cinema's**
timezone, which it learns from the Veezi account itself, and not in the site's or
the server's. A WordPress install with its timezone unset or wrong still shows
the right time on the page.

Each session stores both the instant, for sorting and filtering, and the time
written out, so nothing has to convert it again to print it.

### Posters

Artwork is copied into the media library and set as the film's featured image,
rather than linked to. Veezi serves one full-resolution poster — around
1340×1920, and the lossless ones reach five megabytes — and the only smaller
variant it offers is 125×182, a thumbnail meant for a box-office screen. Linking
a nine-film listing to the originals would be eight megabytes a page view.

Once it is in the library WordPress makes its own sizes, including
`veezi-poster` at 600px wide for a card, and the cinema can reuse the artwork in
a newsletter without going back to the ticketing system for it. The same nine
cards then come to around 800KB.

Lossless posters are recompressed as WebP, which is worth roughly ten times its
size in that measurement, and WebP specifically because posters carry
transparency and there is no telling a feathered edge from a title treatment
designed to sit on the page itself. The file exactly as Veezi sent it is kept
alongside as the attachment's original.

Artwork is keyed on Veezi's media reference, so a sync running hourly downloads
nothing for a poster that changes twice a year, and best-effort throughout: a
film whose artwork is missing or unreachable syncs without one.

### Programming that has not been announced

Veezi distinguishes sessions that are on sale from those merely planned. Both are
synced, but **planned sessions and the films known only from them are stored as
drafts**: visible to an administrator, invisible to a visitor. Nothing publishes
next month's programme before the cinema chooses to.

### Running it twice

A sync is keyed on Veezi's own identifiers and compares before it writes, so
running it against unchanged data creates nothing, updates nothing, and leaves
every modification date alone.

## Installing

Drop the plugin into `wp-content/plugins/` and activate it, then go to
**Settings → Veezi**, paste the access token Veezi issues for the cinema
(Settings → Web in Veezi Back Office) and press **Test connection**. A working
token answers with the cinema's name.

Requires WordPress 6.5 and PHP 8.2. The plugin has no runtime dependencies —
Composer is used for linting and testing only, and `vendor/` is not shipped.

### Supplying the token without touching the database

```php
define( 'VEEZI_API_TOKEN', 'your-token-here' );
```

A constant in `wp-config.php` overrides whatever is saved, so a staging site can
point at a different Veezi account without a database change, and a production
credential need never be typed into a browser. The settings screen says when a
constant is in force.

### Cinemas outside Australia and New Zealand

Veezi issues an account against one regional endpoint. The default is the
Australia/New Zealand one; point it elsewhere with a filter:

```php
add_filter( 'veezi_api_base_url', fn() => 'https://api.uk.veezi.com' );
```

The cinema's timezone is read from the Veezi account and translated from
Microsoft's naming, which is what Veezi reports, into the names PHP understands.
That translation table covers the places Veezi sells tickets rather than every
name Microsoft has ever issued, so a cinema it does not know falls back to the
site's own timezone. Name the zone exactly with:

```php
add_filter( 'veezi_cinema_timezone', fn() => 'Australia/Melbourne' );
```

## How the token is handled

It is a live credential for a cinema's ticketing account, so:

- it is never rendered back into the settings form once saved — the field shows
  only the last few characters, enough to tell one token from another;
- it is scrubbed out of upstream error messages before they are displayed or
  logged, so a diagnosis stays readable without leaking the credential;
- it is never put in a URL, only in the `VeeziAccessToken` request header;
- it is never sent to the browser, so it cannot reach the REST API, a page
  cache or a proxy log.

Note that a Veezi token also reads seat counts, price card names and sales
figures. That is why it must not reach client-side code: every API call this
plugin makes is server-side. Those commercial fields are **discarded as the
programme is read**, not filtered out at render time — only the sold-out and
few-tickets-left flags are kept — so nothing can leak them through a REST route,
an export or a careless template, because they were never written down.

## Development

Tests run against real WordPress — real post types, real options, real HTTP
layer — with one seam: `pre_http_request`. Everything above it is the code that
ships. A request no test arranged an answer for fails the run rather than
reaching the network.

```sh
composer install
bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
vendor/bin/phpunit
composer lint
```

The install script downloads WordPress and the core test library over HTTPS and
creates the test database. It leaves both alone on subsequent runs; pass
`--force` to reinstall.

Fixtures are synthesised rather than captured. A real capture from a cinema's
account would carry its trading details, sales figures and unannounced
programming into a public repository.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
