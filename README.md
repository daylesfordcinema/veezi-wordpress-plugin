# Veezi for WordPress

[![CI](https://github.com/daylesfordcinema/veezi-wordpress-plugin/actions/workflows/ci.yml/badge.svg)](https://github.com/daylesfordcinema/veezi-wordpress-plugin/actions/workflows/ci.yml)

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
end of the film rather than the start of it, so the record does not disappear
while there is an audience sitting in it.

The two views differ deliberately about that last hour. **A chronological
listing drops a screening the moment it starts**, because a row nobody can get
to or buy is noise at the top of today — and the sync runs hourly, so leaving it
to the records alone would go on offering a missed screening for as long as an
hour. **A film's own list of times keeps it**, marked and unbookable, until it
ends: a card that dropped it would say the film next screens tomorrow while it
is on. Neither needs configuring, and there is nothing in either query for a
designer to get wrong.

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
drafts**: visible to an administrator, invisible to a visitor. Out of the box
nothing publishes next month's programme, because a planned session is not an
announcement — it is a row in a ticketing system, and it can still move.

**Settings → Veezi** is where a cinema says otherwise, with two controls:

| | |
|---|---|
| **Publish what is coming** | Off. Turning it on publishes planned screenings and files their films under a second listing, **Coming Soon**. |
| **How far ahead** | 14 days. Whole days in the cinema's own timezone, so a fortnight reaches the end of the fourteenth day. |

The horizon is the point of the pair. A cinema usually wants to advertise the
next fortnight without publishing a quarter of forward planning, so anything
scheduled beyond it stays a draft and publishes itself when the horizon reaches
it — no second decision, and nothing to remember.

Three things follow that are worth knowing before the switch is moved:

- **It is reversible.** Switching it off on the next sync withdraws what it
  published: the sessions go back to drafts, the films lose the Coming Soon
  listing, and a film that has *only* ever been coming soon goes back to a draft
  with them. A film that has been on sale keeps its page, because a link
  somebody shared has to go on working — that promise outranks this one.
- **A planned screening can never be booked.** Veezi has no booking link for one
  and does not send it through the feed the links come from, so the Booking Link
  field resolves to nothing and the seats are not offered.
- **A film that has anything on sale is never coming soon**, and holds its
  planned dates back for as long as that is true. A film screening on Sunday is
  not coming soon, it is here — so it stays in the current programme, showing
  the dates that can actually be bought, and its planned dates wait. The two
  listings never hold the same film.

That last one has a cost worth knowing before you turn the switch on: a season
already published is **retracted** the moment its first date goes on sale, and
the chronological listing carries a gap where that film's later dates would be
while showing exactly those dates for a film that has nothing selling. It is a
deliberate trade — what is bought is what is advertised, and a card mixing three
purchasable evenings with two you cannot yet reach answers "when can I see this"
worse than either half alone.

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

Eleven entries appear in Elementor's dynamic-data picker under **Veezi
Programme**:

| Tag | On a film | On a screening |
|---|---|---|
| **Poster** | the artwork, from the media library | — |
| **Runtime (minutes)** | a bare number — add "min" with the tag's own After control | — |
| **Classification** | the rating | — |
| **Genre** | every genre it is filed under | — |
| **Cast and Crew** | everybody credited, or one role at a time | — |
| **Trailer Link** | the trailer, as a link a video widget understands | — |
| **Film Title** | its own title | the title of the film screening |
| **Session Time** | when it next screens | that screening's time |
| **Availability** | whether that screening is gone or nearly | the same, for this one |
| **Booking Link** | the soonest screening still on sale | that screening, unless it is sold out |
| **Nothing Scheduled** | a sentence, when the cinema has nothing on at all | the same |

They read whichever record is being rendered, so the same card works inside a
loop and on a film's own page, with nothing to name and nothing to configure —
and a duplicated template behaves exactly like the one it was copied from.
**Nothing Scheduled** is the exception: it reads no record, because it is a fact
about the cinema rather than about a film.

**Session Time** carries a **Format** control, and it is what lets a listing
group itself: bind the tag once with a date format for the day a row belongs
under, and again with a time format for the row itself. The codes are the ones
from Settings → General. Left empty it is the site's own date and time, worked
out afresh on every page load, so changing the site's format changes what a card
says without waiting for a sync.

**Availability** reads "Sold out", "Few tickets left" or "On sale soon", in
whatever words the panel is given, and **nothing at all** the rest of the time —
a listing where every row carries a badge has no badge. Bind it to something you
are content to see render empty. The last of the three is only ever seen where
the cinema has asked to publish what is coming, and it wins over the other two:
Veezi goes on reporting sold-out and few-left on a screening nobody has been
able to buy yet, and printing "Sold out" against one of those would be flatly
untrue. The numbers behind it never reach the site: Veezi reports seats sold and
seats held on the same record, and the sync keeps the two flags and discards the
rest, so a page showing this cannot leak a night's takings.

Three of them answer differently on a film and on a screening, and **Booking Link**
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
the day and in what form, how many to show, and the wording of the three badges
— sold out, nearly sold out, and not on sale yet. Times are worked out in the
cinema's timezone, not the site's.

A screening nobody can book stays on the card, marked, with no link — somebody
scanning the week needs to see that Saturday is gone, and a button landing on
"no seats available" is a wasted trip. That covers all three: sold out, already
started, and announced but not yet selling.

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

### A listing by day, to start from

The other listing the cinema wants is the calendar: everything still to come, in
order, whatever the film — for somebody who has a free evening rather than a
film in mind. It is a loop grid pointed at **Sessions** instead of Films, sorted
by **Menu Order**, and it needs no widget of the plugin's at all. A row is one
screening, so every part of it is an ordinary widget bound to a field.

`templates/session-row.json` is that row: the day it belongs under, the time,
the film, whether the seats are going, and the time itself linking to those
seats. Import it as a **loop item** the same way as the card.

Two things about it are worth knowing.

**The day heading repeats on every row of that day.** A dynamic tag answers for
the record it is rendering and cannot see the one above it, so grouping is a
per-row heading and a designer's stylesheet, not a query. That was the choice
against a widget which emits one heading per day: the listing is buildable
without it, and the plugin owning a second widget is a permanent liability to
Elementor's widget API in exchange for something only nicer.

**The time is an icon list rather than a button**, deliberately, and it is the
one place in these templates where the choice of widget is load-bearing. Bind a
booking link that resolves to nothing to a **button** and Elementor renders it
anyway — styled, inviting, going nowhere. Bind it to an **icon list** item and
Elementor renders the text with no anchor around it at all. Which is exactly
what a sold-out row should be: still listed, still legible, nothing to click.

### A Coming Soon listing

Once **Settings → Veezi** has been asked to publish what is coming, the second
listing is the first one again with one dropdown changed: a loop grid over
**Films**, sorted by **Menu Order**, filtered to the **Coming Soon** term of the
**Listings** taxonomy instead of **Now Showing**. There is no separate widget and
no query to write — which is the whole reason the listings are terms rather than
something only code could express.

The loop item is its own template, though: `templates/coming-soon-card.json`,
downloadable from the same screen. It is the film card with two things taken
out, and both are deliberate.

**No session times.** These dates are planned rather than on sale, and the
settings screen says plainly that a planned time can still move. Printing one
invites somebody to turn up for it. The card says which film and that it is
coming; the date arrives with the tickets.

**No Book now button.** Not a styling preference — Elementor renders a button
whose link resolves to nothing as a button that goes *nowhere*: styled,
inviting, dead. On a Now Showing card that state is rare, because that listing
only holds films on sale. Here it would be certain.

What it keeps is a heading bound to **Availability**, which on these cards reads
"On sale soon". Without it the listing is a wall of posters indistinguishable
from the grid above it.

The two grids never hold the same film, so a page carrying both shows nothing
twice — see the retraction note above for what that costs.

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

A cinema between seasons has nothing on and is working perfectly. A cinema whose
token has stopped working also has nothing on. On the page those two look
identical — an empty grid — so the plugin gives you something to say.

Put a heading below the listing and bind it to **Nothing Scheduled**. It stays
empty for as long as there is a programme, and reads a sentence of your choosing
when there is not. Whoever is building the page gets more than that: if nothing
has *ever* synced, the same tag names the settings screen instead, because that
is a fault and they are the person who can go and fix it.

Plugin-rendered output does the same in the builder rather than collapsing to
nothing: a widget with nothing to show says whether the programme has not synced
yet, whether the preview is set to something that is not a film, or whether this
particular film has simply finished. A visitor sees nothing at all, which is
correct.

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
WP_TESTS_SKIP_INSTALL=1 vendor/bin/phpunit
composer lint
```

The install script downloads WordPress, the core test library and Elementor over
HTTPS, creates the test database and installs WordPress into it. It leaves the
downloads alone on subsequent runs; pass `--force` to reinstall.

Installing the database there rather than leaving it to the test library is what
`WP_TESTS_SKIP_INSTALL` above is for. Unset, the library drops and rebuilds every
table before each run — around 450ms, which on a single test file costs about as
much as the tests do. Set, a run goes straight to the tests, at the price of the
database outliving them: everything inside a test is in a transaction that gets
rolled back, but a write made outside one is no longer wiped between runs. Leave
it unset if that ever matters more than the time.

Elementor is a prerequisite rather than an optional extra tests skip around: the
dynamic tags and the session-times widget extend its own base classes, so a run
without it would report success while proving nothing about the half of the
plugin a visitor sees. Standing in a fake for it would prove only that the fake
matches what we believed the API to be, which is the one thing already known.

What that does **not** cover is Elementor Pro, which is commercial and cannot be
installed in a public pipeline. Loop grid, the query control and the theme
builder are all Pro, so anything that only happens inside them — a card
rendering for each film of a loop, ordering actually honouring menu order — is
checked by hand before release. That list is
[docs/pre-release-checklist.md](docs/pre-release-checklist.md), and it is
deliberately short: this is the argument for keeping the presentation layer
thin, because `src/Presentation` holds the answers and is fully tested, and
`src/Elementor` is the adapter that hands them over.

Fixtures are synthesised rather than captured. A real capture from a cinema's
account would carry its trading details, sales figures and unannounced
programming into a public repository.

### What CI runs

Pushes to `main` and pull requests run the coding-standards check and the whole
suite. PHP and MariaDB come from the runner, pinned in the workflow to match
what the plugin is deployed onto; WordPress and Elementor come from
`bin/install-wp-tests.sh` above, which CI runs exactly as a developer does — so
those two versions are written down in one place and a pipeline cannot drift
from a checkout.

The workflow declares read-only permissions and reads no secret, and it runs on
`pull_request` rather than `pull_request_target`, so a pull request from a fork
executes that fork's code with nothing in scope worth taking. Everything
privileged is in `release.yml`, which only a tag push reaches.

### Releasing

Bump the version in all three places it is written down — the plugin header, the
`VERSION` constant and `Stable tag` in `readme.txt` — commit, and tag that commit
`v<version>`. A test checks the three against each other, and the release
workflow checks them against the tag and refuses a tag that disagrees.

Pushing the tag runs the ordinary checks, then builds the archive and attaches it
to the release. It is one command, so it can be run by hand for a recovery
upload:

```sh
git archive --format=zip --prefix=veezi-wordpress-plugin/ \
  -o veezi-wordpress-plugin-0.1.0.zip v0.1.0
```

The prefix is the whole trick. WordPress names an installed plugin after the
archive's single top-level directory, which is why a repository's own generated
source archive — named for the tag — installs the plugin under the wrong
directory and then cannot update itself. What stays out of the archive is
declared in `.gitattributes`; pass `--worktree-attributes` to try a change to
that file before committing it.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
