# Veezi for WordPress

Publishes a cinema's [Veezi](https://www.veezi.com/) programme as WordPress
content — films, sessions, posters and booking links — so the website follows
the ticketing system instead of being retyped from it.

The integration is **read-only**. Ticket sales stay in Veezi; the plugin renders
links out to the cinema's own booking pages and never handles a transaction.

> **Status: early.** This release connects to Veezi, syncs the programme —
> posters included, correctly ordered — into WordPress on a schedule, gives
> Elementor the fields and the one widget a film listing needs, and gives every
> film a page of its own. The chronological calendar and a coming-soon listing
> are still to come.

## What it syncs

A sync reads three Veezi endpoints and turns them into ordinary WordPress
content:

- **Films** (`veezi_film`) — one per film something is scheduled for, carrying
  the synopsis, runtime, distributor, release date, cast and crew, trailer link
  and poster, filed under genre and classification taxonomies. Films are never
  deleted, so a link to one keeps working after its season ends.
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

## Keeping it up to date

A sync runs **hourly**, on WordPress's own cron, so nobody has to remember
anything. Hourly is more often than a cinema's programme changes and costs
almost nothing: three small JSON reads, and artwork only for a poster that is
new.

That it is WordPress's cron matters. A host can drive that queue from outside,
which is what this cinema's server does — request-triggered cron is switched off
and the platform runs it on a real schedule. So the programme refreshes on a
site nobody has visited since yesterday, and the plugin never tries to spawn a
run of its own.

The event heals. It goes on the schedule at activation and is checked again on
every request, because the ways it can vanish are ordinary ones — a database
restored from before the plugin existed, or an update applied in place, which
reactivates silently and so skips the activation hook. A site whose event has
gone shows no error at all; the programme simply stops changing, which is the
hardest kind of failure to notice.

To sync more or less often:

```php
add_filter( 'veezi_sync_recurrence', fn() => 'twicedaily' );
```

Any interval registered with WordPress works. One it does not know is ignored
rather than obeyed, because `wp_schedule_event()` refuses an unknown name and a
typo would otherwise leave a site that never syncs again.

### Syncing on demand

**Settings → Veezi** has a **Sync now** button for the last-minute change: a
session added at the box office ten minutes ago is on the website ten minutes
later rather than at the top of the hour. It ignores anything cached, because
that is exactly the question being re-asked.

The same screen shows when the programme last synced and what that run did, and
when the next one is due.

### Only one at a time

A first sync has a poster to fetch per film and can outlast the gap between two
cron firings; and pressing **Sync now** while one is going is one click. So a
run takes a lock for its duration, and anything that finds the lock held stands
aside — which is not a failure, and is not reported as one. The lock expires
after fifteen minutes, so a run killed by a PHP timeout or a restarted container
does not stop the site syncing forever.

### When Veezi is unreachable

An outage at the ticketing provider must not blank the cinema's website, so the
sync fetches **every** feed before it writes **anything**. If any part of the
fetch fails, the run stops and whatever synced last is still on the site,
published, ordered and complete. A partial answer is never applied — a feed that
arrives reporting fewer screenings than the site has, followed by a feed that
fails, would otherwise delete screenings on the strength of half a reply.

A failed run:

- leaves the programme exactly as it was, and shows a visitor nothing at all;
- writes a line to the server's PHP error log, ungated by `WP_DEBUG` — which is
  off on a production site, the one place the record matters;
- raises an admin notice, so the cinema finds out before a customer does.

The notice puts itself away: nothing dismisses it except the next run that
works. The record of the last **successful** sync is kept separately and is not
disturbed by a failure, because through an outage the programme on the page is
still exactly what that run put there.

### Calling Veezi no more than necessary

Veezi publishes no rate limits and marks every response uncacheable, so how
often this plugin calls is entirely its own restraint. Most of that is
structural: the site renders from WordPress content and never calls the API to
draw a page, so a listing seen by ten thousand visitors costs Veezi nothing.
What is left is bursts — two cron firings catching up after downtime, a run
retried straight after a partial failure — and responses are cached for five
minutes to absorb them.

Deliberately short: a cache long enough to matter is a cache long enough to hide
a programme change from a cinema that has just made one. A failure is never
cached, since remembering an outage would extend it past the moment Veezi came
back; the cache is keyed on the token as well as the endpoint, so a site
repointed at another cinema's account is never answered with the last one's; and
**Test connection** never uses it, because it exists to ask Veezi *now*.

### If the site keeps a different time from the cinema

Showtimes are converted in the cinema's own timezone wherever they are printed,
so they stay right whatever WordPress is set to. Everything *else* dated on a
WordPress site is the site timezone's doing, though, from a post's publication
time to whatever a page builder prints from a date field.

So when the two disagree, an administrator gets a notice naming both and
pointing at Settings → General. Compared as clocks rather than as names —
Melbourne and Sydney are two names for one clock, and warning about that would
teach an administrator to ignore the warning.

## Building the listing

All of the above is ordinary WordPress content, so a film card is built the way
any other card is: drag out the widgets you want and bind their fields. What the
plugin adds is the fields to bind, and the one thing a card needs that a page
builder cannot express.

### Fields to bind

Eight entries appear in Elementor's dynamic-data picker under **Veezi
Programme**:

| Tag | On a film | On a screening |
|---|---|---|
| **Poster** | the artwork, from the media library | — |
| **Runtime (minutes)** | a bare number — add "min" with the tag's own After control | — |
| **Classification** | the rating | — |
| **Genre** | every genre it is filed under | — |
| **Cast and Crew** | everybody credited, or one role at a time | — |
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

**Cast and Crew** carries a **Role** control, because a film page wants the same
field twice under two headings. Left alone it is the whole credit list with each
person's role beside their name; set to a role — Director, Screenwriter,
Producer or Actor — it is the names alone, in the order Veezi lists them, which
is billing order. The heading above them is yours, the same way the runtime tag
gives a bare number and leaves "min" to you. Those four are every role the
catalogue currently uses; if Veezi starts sending a fifth, whoever holds it still
appears in the full list under Veezi's own word for it.

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

### A film page to start from

Every film also has a page of its own, at `/film/<its name>/`. The address is
settled the first time the film is published and never recomputed afterwards, so
a distributor renaming a film upstream — a subtitle appears, a colon moves —
does not break the links already out there. And the page goes on resolving after
the last screening has passed: the film leaves the listing and loses its next
screening, but the record and its address stay where they were, because a link
somebody shared in August should still open in December.

`templates/film-page.json` is the single film view: poster, title, the same
details a card shows, *directed by* / *written by* / *starring*, every remaining
screening from the same **Session Times** widget the card uses, the synopsis and
the trailer. Import it the same way, then place it in a theme-builder **Single**
template for Films. The theme builder is an Elementor Pro feature; without it
WordPress falls back to the theme's own single template, which shows the title
and the synopsis and nothing else this plugin knows about.

It ships with **no Book now button**, deliberately, and that is the one
difference from the card worth understanding. Every film page eventually becomes
an archived film page — that is the promise this whole view is built on — and on
one of those the booking link resolves to nothing, which Elementor's button
widget renders as a button with no destination rather than as no button. On a
card that state is rare, because a Now Showing listing only holds films that are
on sale; here it is certain and permanent. The session times carry the booking
anyway: every time in the list is a link to the seats for that screening. Add a
button if you want one, and give it a display condition.

The trailer is Elementor's own video widget with its link bound to **Trailer
Link**. Veezi sends a YouTube *watch* URL, which is not an address a player can
be pointed at, and the video widget takes that form and works out the embed
itself — along with every privacy, playback and lightbox setting it offers,
which is a better place for that knowledge than a regular expression here. More
than half the catalogue has no trailer at all; for those the binding resolves to
nothing and the widget renders nothing, so there is no empty player to hide.

Two things follow from that widget. For YouTube it mounts the player with
script, so nothing shows with JavaScript off; and the template sets it to
YouTube, which is the only thing Veezi has ever sent — a trailer hosted anywhere
else needs the type changed to match.

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

### Deactivating and deleting

Deactivating takes the scheduled sync off the queue and withdraws the film
routes, and leaves everything else where it is — a deactivated plugin should be
a reversible mistake.

Deleting removes what the plugin configured itself with: the access token, the
schedule, the lock, the cinema's timezone and the notes about the last run. It
does **not** delete films, sessions or posters. A film has a public address
somebody may have linked to, its poster is in the media library because this
plugin encourages reusing it, and WordPress does not delete a site's posts when
a plugin is deleted. To remove them as well:

```sh
wp post delete $( wp post list --post_type=veezi_session --format=ids ) --force
wp post delete $( wp post list --post_type=veezi_film --format=ids ) --force
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
