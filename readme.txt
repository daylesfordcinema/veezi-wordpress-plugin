=== Veezi for WordPress ===
Contributors: daylesfordcinema
Tags: cinema, veezi, showtimes, film, elementor
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publishes a cinema's Veezi programme as WordPress content — films, sessions, posters and booking links.

== Description ==

Your cinema's programme already exists, in Veezi. This plugin reads it and turns
it into ordinary WordPress content, so your website follows the ticketing system
instead of being retyped from it every week.

Films become posts. Screenings become posts. Posters land in your media library.
Booking links point at your own Veezi checkout. From there you build the listings
with whatever page builder the site already uses — the plugin's own settings
screen carries only the decisions that need a human.

The integration is strictly read-only. Ticket sales, seat selection and payment
all stay in Veezi: the plugin links out to your own booking pages and never
handles a transaction.

= Getting started =

1. Activate the plugin.
2. In Veezi Back Office, go to Settings → Web and copy your access token.
3. In WordPress, go to Settings → Veezi, paste it in and press "Save settings".
   The page comes back naming the cinema you are connected to.
4. Press "Sync now", or wait — the programme syncs by itself every hour.

New "Films" and "Sessions" menus appear in the sidebar. If films are listed there
with their posters, everything after that is page building.

= What you can build =

Four listings, all from ordinary page-builder widgets bound to the fields this
plugin adds:

* Now Showing — a grid of the films you can buy a ticket for.
* What's on — every upcoming screening in order, grouped by day, for the visitor
  with a free Thursday rather than a film in mind.
* Coming Soon — films you have scheduled but not yet put on sale. Off until you
  turn it on; see below.
* A page for every film, at /film/its-name/, with its credits, screenings,
  synopsis and trailer.

Four importable starter templates ship with the plugin, one for each, with every
field already bound. There are links to them on Settings → Veezi. Import one and
restyle it rather than starting from an empty canvas.

= Fields you can bind =

Eleven entries appear in Elementor's dynamic-data picker under "Veezi Programme":
poster, runtime, classification, genre, cast and crew, trailer link, film title,
session time, availability, booking link, and a "nothing scheduled" sentence for
when the cinema is between seasons.

They read whichever record is being rendered, so the same card works inside a
loop and on a film's own page with nothing to configure, and duplicating a
template behaves exactly like the one you copied.

Three carry controls: Session Time takes a date format, Cast and Crew takes a
role (director, screenwriter, producer or actor, or everyone), and Availability
takes the wording of its three badges.

A card also needs every time that film screens, which is a list inside a list:
loop widgets cannot nest and one field can offer only one value. So the plugin
ships one widget of its own, "Session Times", which lists a film's remaining
screenings with a booking link for each. A screening nobody can book stays in the
list, marked, with no link.

= Getting the order right =

Set every loop grid's sort to "Menu Order", ascending. The plugin numbers films
by when they next screen and screenings chronologically, and writes those numbers
into that field. Left on the default you get publication date, which for synced
content is whenever the sync happened to create the record — a meaningless order
that reports no error anywhere.

= Coming soon =

Veezi holds next season as well as this week. Sessions that are scheduled but not
yet selling are called planned, and out of the box the plugin publishes none of
them: a planned session is not an announcement, and its time can still change.

Two controls on Settings → Veezi change that — whether to publish what is coming,
and how far ahead to look, counted in whole days in the cinema's own timezone and
defaulting to a fortnight. Anything beyond the horizon waits, and publishes itself
when the horizon reaches it.

Switching it on is an announcement, so the screen says so. It is reversible:
switch it off and the next sync takes it all back. A planned screening never
carries a booking link, because Veezi has none to give. And a film with anything
on sale is never "coming soon" — it is here, so it stays in the current listing
showing the dates you can actually buy.

= Keeping the programme up to date =

A sync runs hourly on WordPress's cron, so nobody has to remember anything, and a
host that drives that queue externally refreshes a site nobody has visited.
Settings → Veezi shows when the programme last synced and what that run did, when
the next one is due, and has a "Sync now" button for the last-minute change. Only
one sync runs at a time; anything arriving while one is going stands aside.

= If Veezi is unreachable =

An outage at the ticketing provider does not blank the website. Every feed is
fetched before anything is written, so a failed or partial fetch stops the run and
leaves the last good programme published, ordered and complete. A visitor sees
nothing amiss; the failure is written to the server's error log and raised as an
admin notice, so the cinema finds out before a customer does. The notice goes away
by itself on the next run that works.

= Showtimes and timezones =

Veezi reports showtimes with no timezone on them. The plugin reads them in the
cinema's own timezone, which it learns from the Veezi account, so showtimes are
right even on a WordPress install whose timezone is unset or wrong. Everything
else dated on the site follows the site's timezone, so when the two disagree you
get an admin notice naming both.

= Posters =

Artwork is copied into the media library and set as the film's featured image, so
WordPress makes its own sizes from it and the cinema can reuse it in a newsletter
without going back to the ticketing system. Veezi serves one full-resolution
poster and the only smaller variant it offers is a box-office thumbnail, so
linking to the originals would cost a visitor several megabytes a page view.

= Deactivating and deleting =

Deactivating takes the scheduled sync off the queue and leaves everything else
alone. Deleting removes what the plugin configured itself with — the access token
above all — and leaves the films, sessions and posters it published, since those
have public addresses and sit in the media library like any other content.

== Installation ==

1. Upload the plugin zip under Plugins → Add New → Upload Plugin, or copy the
   folder into wp-content/plugins/.
2. Activate it.
3. Go to Settings → Veezi and paste the access token from Veezi Back Office
   (Settings → Web).
4. Press "Save settings". The screen names the cinema you are connected to.

Requires WordPress 6.5 and PHP 8.2. Elementor is free and does most of what is
described here; the loop grid and the theme builder are Elementor Pro features.

== Frequently Asked Questions ==

= Does this sell tickets? =

No. Ticket purchase, seat selection and payment stay in Veezi. The plugin renders
booking links that take a visitor to your own Veezi checkout.

= Is any sales data published? =

No, and it is designed not to be. The API returns seat counts and price card
names alongside the programme, and the plugin discards them as it reads rather
than storing them, keeping only "sold out" and "few tickets left", which is all a
visitor needs. Every API call is made server-side, so the access token — which
can read those figures — never reaches a browser.

= Will it work with my theme? =

The plugin produces content, not layout. Nothing about it is tied to a particular
theme.

= Do I need Elementor Pro? =

Not for the fields, the Session Times widget or the film records themselves,
which all work with free Elementor. The loop grid — which is what turns those
into a listing — and the theme builder are Pro features.

= I am connected but no films appear. =

Check Sessions in the sidebar. If there are drafts there but nothing published,
everything you have scheduled is planned rather than on sale. See "Coming soon"
above.

= My films are in a strange order. =

Set the loop grid's sort to "Menu Order", ascending. See "Getting the order
right" above.

= Only six films are showing. =

That is Elementor's "Items Per Page", which defaults to six. Raise it.

= Can I use this outside Australia and New Zealand? =

Yes. Veezi issues an account against one regional endpoint and the plugin
defaults to the Australia/New Zealand one; a one-line filter points it elsewhere.
The plugin's README has the snippet.

= Can I edit the films and sessions it creates? =

No — the next sync will write over your changes. Change it in Veezi instead.

== Changelog ==

= 0.1.0 =
* Syncs films and screenings from Veezi hourly, with posters copied into the
  media library and booking links attached.
* Showtimes read in the cinema's own timezone, not the site's.
* Eleven dynamic fields and a Session Times widget for building listings.
* A page for every film, which keeps working after its season ends.
* A chronological "what's on" listing, grouped by day.
* Coming Soon, off by default, with a configurable horizon.
* Four importable starter templates.
* Settings screen with a connection test, sync status and a "Sync now" button.
