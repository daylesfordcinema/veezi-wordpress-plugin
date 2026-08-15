# Veezi for WordPress

[![CI](https://github.com/daylesfordcinema/veezi-wordpress-plugin/actions/workflows/ci.yml/badge.svg)](https://github.com/daylesfordcinema/veezi-wordpress-plugin/actions/workflows/ci.yml)

Veezi for WordPress is a WordPress plugin that publishes a cinema's
[Veezi](https://www.veezi.com/) programme as WordPress content: one post per
film, one per screening, posters copied into the media library and booking links
attached.

It is read-only. Ticket sales, seat selection and payment all stay in Veezi.

## Requirements

- WordPress 6.5 or later
- PHP 8.2 or later
- A Veezi account, and the access token it issues
- Elementor, to build the listings. The free version covers the fields and the
  plugin's own widget; the loop grid and the theme builder are Elementor Pro
  features, and they are marked as such below.

## Overview

### The problem

A cinema's programme lives in its ticketing system and its website lives
somewhere else, so keeping the two in step means retyping session times every
week — and again whenever a session moves, sells out or is added. It drifts the
first time somebody is busy on a Tuesday.

### How it works

The plugin reads the programme from the Veezi API on a schedule and writes it
into WordPress as ordinary content. Films become posts of a `Films` post type,
screenings become posts of a `Sessions` post type, and the artwork goes into the
media library.

Because the result is native WordPress content, there is no special listing
mode and no shortcode to learn. You build the pages in Elementor the way you
build anything else — drag out a widget, bind a field, style it. What the plugin
adds is the fields to bind, one widget for the thing a page builder cannot
express, and four starter templates.

The cinema goes on managing its programme in Veezi. The website follows.

## Quick start

### 1. Install and activate

Upload the release `.zip` under **Plugins → Add New → Upload Plugin**, or copy
the folder into `wp-content/plugins/`. Activate it.

### 2. Get your access token

In Veezi Back Office, go to **Settings → Web** and copy the access token. Veezi
issues one per cinema. It is read-only as far as this plugin is concerned, but
treat it as a credential — see [Privacy and your access
token](#privacy-and-your-access-token).

### 3. Connect

In WordPress, go to **Settings → Veezi**, paste the token in, and press **Save
settings**.

The page comes back and tells you which cinema you are connected to. If it names
your cinema, the connection works. If it doesn't, see
[Troubleshooting](#troubleshooting).

### 4. Sync the programme

The programme syncs by itself every hour, so you can simply wait. To see it now,
press **Sync now** on the same screen. You should get something like:

> Synced 9 films and 16 sessions from Phoenix Cinema.

### 5. Check what arrived

There are new **Films** and **Sessions** menus in the WordPress sidebar. Open
**Films**: if your films are listed there with their posters, the setup is done
and everything after this is page building.

### Next steps

- [Building your listings](#building-your-listings) — the fields, the widget and
  the starter templates
- [The four listings](#the-four-listings) — Now Showing, what's on, Coming Soon
  and film pages
- [Coming Soon](#coming-soon-publishing-whats-not-on-sale-yet) — off until you
  turn it on

## What appears in WordPress

| | |
|---|---|
| **Films** | One per film you have something scheduled for. Carries the synopsis, runtime, classification, genre, distributor, release date, cast and crew, trailer link and poster. Each gets its own page at `/film/its-name/`. |
| **Sessions** | One per screening: when it starts and ends, its booking link, and whether it's sold out or nearly. These exist to be listed, not visited. |
| **Genres** and **Classifications** | Taxonomies on films, so visitors can browse by them. |
| **Listings** | A behind-the-scenes taxonomy with two terms, **Now Showing** and **Coming Soon**, that the sync keeps up to date. This is how you filter a listing without writing a query. |

Everything here is kept in step with Veezi automatically. **Don't edit these
records by hand** — the next sync will write over your changes. If you need
something to read differently on the website, change it in Veezi.

One thing worth knowing: your listings are built from what's *scheduled*, never
from your Veezi film catalogue. Every film in a Veezi account reports itself as
active, test records included, so a listing built from the catalogue would
advertise films that will never screen.

## Building your listings

### Start from a template

Four starter templates ship with the plugin, all downloadable from the bottom of
**Settings → Veezi**. Download one, then import it under **Templates → Saved
Templates → Import Templates**. Everything in them is already bound to the right
field — restyle them rather than starting from an empty canvas.

| Template | Use it as |
|---|---|
| **Film card** | The loop item of a Now Showing grid. Poster, title, details, session times, Book now. |
| **Coming soon card** | The loop item of a Coming Soon grid. Same, without the times or the button — see [Coming Soon](#coming-soon-publishing-whats-not-on-sale-yet). |
| **Session row** | The loop item of a chronological "what's on" grid over Sessions. |
| **Film page** | A theme-builder **Single** template for Films. |

### The fields you can bind

Eleven entries appear in Elementor's dynamic-data picker, grouped under **Veezi
Programme**. Bind them to ordinary widgets the way you'd bind any custom field.

| Field | On a film | On a screening |
|---|---|---|
| **Poster** | the artwork from your media library | the artwork of the film screening |
| **Runtime (minutes)** | a bare number — add "min" using the tag's own **After** box | the same, for the film screening |
| **Classification** | the rating | the rating of the film screening |
| **Genre** | every genre it's filed under | the same, for the film screening |
| **Cast and Crew** | everyone credited, or one role at a time | the same, for the film screening |
| **Trailer Link** | the trailer, in a form a video widget understands | the trailer of the film screening |
| **Film Title** | its own title | the title of the film screening |
| **Session Time** | when it next screens | that screening's time |
| **Availability** | "Sold out", "Few tickets left" or "On sale soon" | the same, for this one |
| **Booking Link** | the soonest screening still on sale | that screening, unless it can't be booked |
| **Nothing Scheduled** | a sentence for when the cinema has nothing on at all | the same |

They read **whichever record is being rendered**, so there's nothing to point at
and nothing to configure — the same card works inside a loop and on a film's own
page, and duplicating a template behaves exactly like the one you copied.

Every field answers on both, so a row of a chronological listing can carry the
poster, rating and genre of the film it screens rather than only a time and a
link. Where a field has to pick — a film screens several times, and only one of
those can be the headline — the time, the availability and the button all answer
from the **same** screening, so a card can never headline Saturday and sell
Sunday.

Three of them have controls worth knowing about:

- **Session Time → Format.** The same date codes as **Settings → General**
  (`l j F` for "Saturday 2 August", `g:i a` for "4:30 pm"). Leave it empty for
  your site's own format. This is what lets one field give a listing both its day
  heading and its row time.
- **Cast and Crew → Role.** Left alone it's the whole credit list with each
  person's role beside their name. Set to Director, Screenwriter, Producer or
  Actor, it's just those names, in Veezi's billing order. The heading above them
  is yours.
- **Availability → the three wordings.** Change "Sold out" to "Full house" if
  that's your house style. It renders **nothing at all** most of the time, which
  is deliberate: a listing where every row has a badge has no badge. Bind it to
  something you're happy to see render empty.

Anything Veezi doesn't have resolves to nothing rather than an error, so a card
built for a film with a trailer keeps working for the one without.

### The Session Times widget

A card showing every time a film screens this week is a list inside a list —
loop widgets can't nest, and a single field can only offer one value. So the
plugin ships **one** widget of its own, and only one.

Drop **Session Times** into a card and it lists that film's remaining screenings,
each one linking to the seats for that particular screening. Controls cover the
time format, whether to name the day and how, how many to show, and the wording
of the three badges.

A screening nobody can book stays in the list, marked, with no link — sold out,
already started, or announced but not selling yet. Someone scanning the week
needs to see that Saturday is gone, and a button landing on "no seats available"
is a wasted trip.

### Always sort by "Menu Order"

**In every loop grid you build, set the query order to Menu Order, ascending.**

The plugin numbers your films by when they next screen and your screenings
chronologically, and writes those numbers into WordPress's menu order field —
the one sortable field a page builder offers that can be made to mean something
here.

Leave the sort on its default and you get publication date, which for synced
content is *whenever the sync happened to create the record*. That's a
meaningless order, and nothing anywhere reports a problem. It's the single
easiest thing to get wrong.

### When there's nothing on

A cinema between seasons has nothing on and is working perfectly. A cinema whose
token has stopped working also has nothing on. On the page those look identical —
an empty grid — so put a heading below your listing and bind it to **Nothing
Scheduled**.

It stays empty while there's a programme and reads a sentence of your choosing
when there isn't. While you're in the editor it does more: if nothing has *ever*
synced it names the settings screen instead, because that's a fault and you're
the person who can fix it. Visitors never see that version.

## The four listings

### Now Showing

A loop grid over **Films**, sorted by **Menu Order**, filtered to the **Now
Showing** term of the **Listings** taxonomy, with the film card as its loop item.
That's the whole configuration. *(Loop grid is Elementor Pro.)*

### What's on — a listing by day

For the visitor who has a free Thursday rather than a film in mind. A loop grid
over **Sessions** instead of Films, sorted by **Menu Order**, with the session
row as its loop item. No filtering needed, and no widget of the plugin's at all —
every part of a row is an ordinary widget bound to a field.

Two things to expect:

- **The day heading repeats on every row of that day.** A field answers for the
  row it's rendering and can't see the one above it, so grouping is a per-row
  heading plus your stylesheet. Hiding the repeats is a few lines of CSS.
- **A row you can't book has no link on it** — but it's styled exactly like one
  you can, because the template gives the list one look. If you want them to look
  different, that's a style to add.

Set **Items Per Page** high enough to show the whole programme; it defaults to
six.

### Coming Soon

Films you have scheduled but not yet put on sale. Built the same way as Now
Showing, with the **Coming Soon** term and the coming soon card as its loop item
— but it publishes nothing until you switch it on, and there is more to know
before you do. It has [a section of its own
below](#coming-soon-publishing-whats-not-on-sale-yet).

### Film pages

Every film already has one, at `/film/its-name/`. The address is fixed the first
time the film is published and never recalculated, so a distributor renaming a
film upstream doesn't break links you've already shared. The page keeps working
after the last screening has passed, which is the point: a link someone shared in
August should still open in December.

Import the **film page** template into a theme-builder **Single** template for
Films. *(The theme builder is Elementor Pro. Without it, WordPress falls back to
your theme's own single-post template, which shows the title and synopsis and
nothing else the plugin knows about.)*

It ships with **no Book now button**, deliberately. Every film page eventually
becomes an archived film page, and a booking link with nothing to book renders as
a button that goes nowhere. The session times are the booking surface — each time
in the list links to the seats for that screening.

The trailer uses Elementor's own video widget. Veezi sends YouTube *watch* links,
which no player can be pointed at directly; the video widget takes that form and
works out the embed itself, along with every privacy and playback setting it
offers. More than half a typical catalogue has no trailer, and for those the
widget renders nothing rather than an empty player.

## Coming Soon: publishing what's not on sale yet

Veezi holds next season as well as this week. Sessions that are scheduled but not
yet selling are called *planned*, and out of the box **the plugin publishes none
of them**. A planned session isn't an announcement — it's a row in a ticketing
system, and its time can still change.

Two controls on **Settings → Veezi** change that:

| | |
|---|---|
| **Publish what is coming** | Off by default. On, it publishes planned screenings and files their films under the **Coming Soon** listing. |
| **How far ahead** | 14 days. Counted in whole days in your cinema's timezone, so a fortnight reaches the end of the fourteenth day. |

The horizon is the point of the pair: advertise the next fortnight without
publishing three months of forward planning. Anything beyond it waits, and
publishes itself when the horizon reaches it — no second decision, nothing to
remember.

Build the grid the same way as Now Showing, with the **Coming Soon** term and
the **coming soon card** as its loop item. That card has no session times and no
Book now button on purpose: a planned date can still move, and a button here
would have nowhere to go.

**Four things to know before you turn it on:**

- **Switching it on is an announcement.** It publishes programming you may not
  have announced anywhere else.
- **It's reversible.** Switch it off and the next sync takes it all back: the
  screenings return to drafts and the films leave the listing. A film that has
  *only* ever been coming soon goes back to a draft too. A film that has been on
  sale keeps its page, because a link somebody shared has to keep working.
- **A planned screening can never be booked.** Veezi has no booking link for one,
  so the Booking Link field resolves to nothing.
- **A film with anything on sale is never "coming soon"** — it's here. It stays
  in Now Showing showing the dates you can actually buy, and its planned dates
  wait. The two listings never hold the same film.

That last one has a cost worth understanding: **a season you've already published
is taken back down the moment its first date goes on sale.** Announce a film for
the 6th, 9th and 10th; put the 6th on sale; the 9th and 10th disappear from the
site until they're selling too. The trade is that what's advertised is what can
be bought.

Changes here take effect at the next sync, up to an hour away. Press **Sync now**
to publish — or take back — straight away.

## Keeping it up to date

A sync runs **hourly** on WordPress's cron, so nobody has to remember anything.
Hourly is more often than a cinema's programme changes and costs almost nothing.

**Settings → Veezi** shows when the programme last synced and what that run did,
when the next one is due, and has a **Sync now** button for the last-minute
change — a session added at the box office ten minutes ago on the website ten
minutes later, rather than at the top of the hour.

Only one sync runs at a time. Anything arriving while one is going stands aside,
which isn't a failure and isn't reported as one.

### If Veezi is unreachable

An outage at the ticketing provider will not blank your website. Every feed is
fetched before anything is written, so a failed or partial fetch stops the run
and leaves the last good programme published, ordered and complete.

A failed run:

- leaves the programme exactly as it was — **a visitor sees nothing amiss**;
- writes a line to the server's error log;
- raises an admin notice, so you find out before a customer does.

The notice puts itself away on the next run that works. Nothing else dismisses
it.

### Showtimes and timezones

Veezi reports showtimes with no timezone on them — `2026-08-02T16:30:00` is a
reading of a clock, not a moment. The plugin reads them in **your cinema's**
timezone, which it learns from your Veezi account, not the site's or the
server's. So showtimes are right even on a WordPress install whose timezone is
unset or wrong.

Everything *else* dated on your site does follow the site's timezone, though —
publication dates, anything a page builder prints from a date field. So when the
two disagree, you'll get an admin notice naming both and pointing at **Settings →
General**. It compares clocks rather than names: Melbourne and Sydney are two
names for one clock, and warning about that would only teach you to ignore the
warning.

## Posters

Artwork is copied into your media library and set as each film's featured image,
rather than hot-linked from Veezi. Two reasons, and the second is the one you'll
feel:

- **It's yours to reuse** — in a newsletter, a social post, anywhere — without
  going back to the ticketing system for it.
- **It's far smaller.** Veezi serves one full-resolution poster, around 1340×1920
  and up to five megabytes, and the only smaller version it offers is a 125×182
  box-office thumbnail. A nine-film listing linked to the originals is roughly
  eight megabytes per page view. Sideloaded and sized, the same nine cards come to
  about 800KB.

WordPress makes its own sizes from it, including `veezi-poster` at 600px wide,
which is what the templates ask for.

A sync running hourly re-downloads nothing — artwork is matched on Veezi's own
media reference, so a poster that changes twice a year is fetched twice a year. A
film whose artwork is missing or unreachable syncs without one rather than
failing.

## Privacy and your access token

Your Veezi token is a live credential for your ticketing account, and it can read
more than the programme: seat counts, price card names and sales figures. So:

- **Every call is made from your server.** The token never reaches a browser, so
  it can't end up in a page cache, a proxy log or someone's developer console.
- **It's never rendered back into the settings form.** Once saved, the field
  shows only the last few characters — enough to tell one token from another.
- **It's scrubbed out of error messages** before they're displayed or logged.
- **It's never put in a URL**, only in a request header.

As for the commercial figures: the plugin **discards them as it reads the
programme** rather than filtering them out later. Only "sold out" and "few
tickets left" are kept, because that's all a visitor needs. Nothing can leak seat
counts through a REST route, an export or a careless template, because they were
never written down in the first place.

## Known limitations

- **Elementor is the supported page builder.** The fields are Elementor dynamic
  tags, so they are not available to other builders or to a plain theme template.
- **The listings themselves need Elementor Pro**, because the loop grid is a Pro
  widget, as is the theme builder that puts the film page behind every film.
  Everything the plugin itself provides works with free Elementor.
- **In the "what's on" listing, the day heading repeats on every row of that
  day.** A field answers for the row it is rendering and cannot see the one above
  it, so grouping is a per-row heading plus a few lines of your CSS.
- **A row you can't book looks like one you can.** It correctly has no link on
  it, but the starter template gives every row the same styling, so the badge is
  the only visible difference. Style them apart if you want them apart.
- **A booking button with nothing to book still renders.** That is Elementor's
  button widget, not the plugin: given an empty link it draws the button anyway.
  The coming-soon card and the film page template both ship without one for this
  reason.
- **Films and sessions cannot be edited by hand** — the next sync overwrites
  them. Change the programme in Veezi.
- **One Veezi account per WordPress site.** A cinema with two Veezi sites needs
  two WordPress sites, or a plugin of its own.
- **Changes take up to an hour** unless you press **Sync now**, including
  turning Coming Soon on or off.

## Troubleshooting

| What you're seeing | What it usually means |
|---|---|
| **"No token is saved yet"** and nothing syncs | The token hasn't been saved. Paste it and press **Save settings** — pressing **Test connection** alone doesn't save anything. |
| **Test connection fails** | The token is wrong, has been revoked in Veezi, or has picked up stray characters when pasted. Copy it again from Veezi Back Office → Settings → Web. |
| **Connected fine, but no films** | Nothing is on sale. Check **Sessions** in the sidebar — if there are drafts there but no published ones, everything you have scheduled is *planned* rather than selling. See [Coming Soon](#coming-soon-publishing-whats-not-on-sale-yet). |
| **Films are in a strange order** | The loop grid's sort is on its default. Set it to **Menu Order**, ascending. |
| **Only six films show** | Elementor's **Items Per Page** defaults to six. Raise it. |
| **A card shows the same film over and over** | The grid isn't a loop grid, or the loop item template isn't set. |
| **The card is empty in the editor but fine on the page** | The editor previews against whichever post it picked, which is rarely a film. Set the template's preview to a film. |
| **A "Book now" button that goes nowhere** | That film has nothing on sale. Elementor renders a button whose link is empty as a button with no destination. Use the coming soon card for the Coming Soon grid, or give the button a display condition. |
| **Showtimes are hours out** | Almost certainly not the plugin — check whether you're looking at a showtime or at a WordPress date field, and see [Showtimes and timezones](#showtimes-and-timezones). |
| **The programme has quietly stopped changing** | Look at "Last synced" on **Settings → Veezi**. If it's old and there's no failure notice, the scheduled event may have been lost — deactivating and reactivating the plugin puts it back. |
| **An admin notice about a failed sync** | Veezi was unreachable or rejected the token. Your site is still showing the last good programme. It clears itself on the next run that works. |

## Snippets

A handful of things aren't worth a settings field but are one line in your
theme's `functions.php` or a small plugin of your own.

**Point at a different Veezi region.** Veezi issues an account against one
regional endpoint; the default is Australia/New Zealand.

```php
add_filter( 'veezi_api_base_url', fn() => 'https://api.uk.veezi.com' );
```

**Sync more or less often.** Any interval WordPress knows about.

```php
add_filter( 'veezi_sync_recurrence', fn() => 'twicedaily' );
```

**Name the cinema's timezone exactly.** Only needed if your region isn't in the
plugin's translation table, in which case it falls back to the site's timezone.

```php
add_filter( 'veezi_cinema_timezone', fn() => 'Australia/Melbourne' );
```

**Supply the token without the database.** A constant in `wp-config.php`
overrides whatever is saved, which is how a staging site points at a different
Veezi account without a database change. The settings screen says when one is in
force.

```php
define( 'VEEZI_API_TOKEN', 'your-token-here' );
```

## Uninstalling

**Deactivating** takes the scheduled sync off the queue and withdraws the film
page addresses. Everything else stays where it is — a deactivated plugin should
be a reversible mistake.

**Deleting** removes what the plugin configured itself with: the access token
above all, plus the schedule and its notes about the last run.

It does **not** delete your films, sessions or posters. A film has a public
address someone may have linked to, and the posters are in your media library
like any other image. WordPress doesn't delete a site's content when a plugin is
deleted, and neither does this. If you do want them gone:

```sh
wp post delete $( wp post list --post_type=veezi_session --format=ids ) --force
wp post delete $( wp post list --post_type=veezi_film --format=ids ) --force
```

## Support

Bug reports and questions go to
[GitHub issues](https://github.com/daylesfordcinema/veezi-wordpress-plugin/issues).

If you are reporting a sync problem, the useful things to include are what
**Settings → Veezi** says under "Last synced", whether **Test connection**
succeeds, and any Veezi-related lines from the server's PHP error log. **Never
paste your access token** into an issue — it reads your cinema's sales figures.

## Contributing

Development setup, the test suite, how the plugin is put together and the
decisions behind it are all in [CONTRIBUTING.md](CONTRIBUTING.md).

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
