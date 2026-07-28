# Veezi for WordPress

Publishes a cinema's [Veezi](https://www.veezi.com/) programme as WordPress
content — films, sessions, posters and booking links — so the website follows
the ticketing system instead of being retyped from it.

The integration is **read-only**. Ticket sales stay in Veezi; the plugin renders
links out to the cinema's own booking pages and never handles a transaction.

> **Status: early.** This release connects to Veezi, syncs the programme —
> posters included, correctly ordered — into WordPress, and gives Elementor the
> fields and the one widget a film listing needs. The single film page and the
> chronological calendar are still to come, and there is no scheduled or
> on-demand trigger yet.

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

### Order, and when a screening stops being one

Both kinds of record carry a rank in WordPress's **menu order** field — films by
when they next screen, sessions chronologically — rewritten on every sync.

That field specifically because it is the only sortable one a page builder
offers that means anything here. Elementor's loop grid sorts by published date,
title, menu order, last modified, comment count or random, and nothing else; for
synced content its default is worse than useless, because published date is when
the sync happened to create the record. **Choose "Menu Order" and the listing is
right**, with nothing to configure and no query identifier to remember. Leave it
on the default and it comes out in an order that means nothing and reports no
error, which is the failure this exists to prevent.

The rank is a position — 1, 2, 3 — not a timestamp. The column is a signed
32-bit integer, and epoch seconds stop fitting in 2038.

Screenings are deleted once they finish, so a listing can be "the next six" with
no date filter — which the loop grid could not express anyway, its own date
filter being backwards-looking and reading the published date. The cutoff is the
end of the film rather than the start of it, so a screening does not disappear
from the website while there is an audience sitting in it.

That is the latest a screening can survive, not a guarantee of the earliest.
Anything Veezi stops listing is deleted on the next sync whatever its time says,
because that is also how a **cancelled** screening stops being sold — and the two
are indistinguishable from here. In practice Veezi's session feed appears to
return only future screenings, which would make its cutoff the operative one;
the plugin does not depend on that either way.

A film whose screenings have all passed leaves the current listing and has its
next-screening and count emptied, but keeps its record and its address, so a link
shared while it was on still works. One that returns to the schedule rejoins,
using the record that was there all along rather than a second copy of it.

### Programming that has not been announced

Veezi distinguishes sessions that are on sale from those merely planned. Both are
synced, but **planned sessions and the films known only from them are stored as
drafts**: visible to an administrator, invisible to a visitor. Nothing publishes
next month's programme before the cinema chooses to.

### Running it twice

A sync is keyed on Veezi's own identifiers and compares before it writes, so
running it against unchanged data creates nothing, updates nothing, and leaves
every modification date alone.

## Building the listing

All of the above is ordinary WordPress content, so a film card is built the way
any other card is: drag out the widgets you want and bind their fields. What the
plugin adds is the fields to bind, and the one thing a card needs that a page
builder cannot express.

### Fields to bind

Seven entries appear in Elementor's dynamic-data picker under **Veezi
Programme**:

| Tag | On a film | On a screening |
|---|---|---|
| **Poster** | the artwork, from the media library | — |
| **Runtime (minutes)** | a bare number — add "min" with the tag's own After control | — |
| **Classification** | the rating | — |
| **Genre** | every genre it is filed under | — |
| **Trailer Link** | the trailer, as a link a video widget understands | — |
| **Session Time** | when it next screens | that screening's time |
| **Booking Link** | the soonest screening still on sale | that screening, unless it is sold out |

They read whichever record is being rendered, so the same card works inside a
loop and on a film's own page, with nothing to name and nothing to configure —
and a duplicated template behaves exactly like the one it was copied from.

Two of them answer differently on a film and on a screening, and **Booking Link**
deliberately skips past a sold-out screening to the next one that can still be
bought. A button pointing at the soonest screening whatever its state goes dead
the moment that screening sells out, and stays dead for the rest of the week
while five others are on sale.

Anything absent resolves to nothing rather than to an error, so a card built for
a film with a trailer keeps working for the one without.

### The session times widget

A card showing every time a film screens this week is a list inside a list. A
loop widget cannot nest and a dynamic tag can offer only one value, so no
arrangement of the two produces it — which is why the plugin ships **one** widget
of its own, and only one.

Drop **Session Times** into the card and it lists that film's remaining
screenings, each linking to the seats for that particular screening. Controls
cover what a row shows and how the times read: the time format, whether to name
the day and in what form, how many to show, and the wording of the sold-out and
nearly-sold-out badges. Times are worked out in the cinema's timezone, not the
site's.

A sold-out screening stays on the card, marked, with no link — somebody scanning
the week needs to see that Saturday is gone, and a button landing on "no seats
available" is a wasted trip.

### A card to start from

`templates/film-card.json` is an importable Elementor template: poster, title,
details, session times and a booking button, with every field already bound.
There is a link to it on **Settings → Veezi**; import it under **Templates →
Saved Templates**, then restyle it. It doubles as a worked example of how the
tags and the widget fit together.

One thing to know about its **Book now** button. When a film has nothing left on
sale the Booking Link resolves to nothing, and Elementor's button widget renders
in that case as a button with no destination rather than not at all — so it
looks clickable and is not. Give it a display condition, or delete it and let the
session times do the booking, which they already do: every time in the list is a
link to the seats for that screening.

### Ordering

Choose **Menu Order** in the loop widget's query — see above for why that field
and no other.

### When nothing appears

Plugin-rendered output explains itself in the builder rather than collapsing to
nothing: a widget with nothing to show says whether the programme has not synced
yet — naming the settings screen — or whether this particular film has simply
finished. A visitor sees nothing at all, which is correct.

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
layer — and against real Elementor, with one seam: `pre_http_request`. Everything
above it is the code that ships. A request no test arranged an answer for fails
the run rather than reaching the network.

```sh
composer install
bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
vendor/bin/phpunit
composer lint
```

The install script downloads WordPress, the core test library and Elementor over
HTTPS and creates the test database. It leaves them alone on subsequent runs;
pass `--force` to reinstall.

Elementor is a prerequisite rather than an optional extra tests skip around: the
dynamic tags and the session-times widget extend its own base classes, so a run
without it would report success while proving nothing about the half of the
plugin a visitor sees. Standing in a fake for it would prove only that the fake
matches what we believed the API to be, which is the one thing already known.

What that does **not** cover is Elementor Pro, which is commercial and cannot be
installed in a public pipeline. Loop grid, template import and the theme builder
are all Pro, so anything that only happens inside them — a card rendering for
each film of a loop, ordering actually honouring menu order — is checked by hand
in a development replica before release. This is the argument for keeping the
presentation layer thin: `src/Presentation` holds the answers and is fully
tested, and `src/Elementor` is the adapter that hands them over.

Fixtures are synthesised rather than captured. A real capture from a cinema's
account would carry its trading details, sales figures and unannounced
programming into a public repository.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
