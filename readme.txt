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

Cinemas running on Veezi keep their programme in one place and their website in
another, and the two drift apart the moment someone forgets to retype a session
time. This plugin reads the programme from the Veezi API and turns it into
ordinary WordPress content, so the website follows the ticketing system
automatically.

Because the result is native WordPress content, the presentation is built with
whatever page builder the site already uses. The plugin's own settings screen
carries only the decisions that need a human.

The integration is strictly read-only. Ticket sales stay with Veezi: the plugin
links out to the cinema's own booking pages and never handles a transaction.

= What is here so far =

This is an early release. It connects to Veezi, confirms which cinema the site
is talking to, and syncs the programme hourly: one record per film and one per
screening, with showtimes read in the cinema's own timezone, booking links, and
posters copied into the media library. It also gives Elementor the fields and
the one widget a film listing needs. The single film page and the chronological
calendar are the next piece of work.

= Keeping the programme up to date =

A sync runs hourly on WordPress's cron, so nobody has to remember anything, and
a host that drives that queue externally refreshes a site nobody has visited.
Settings → Veezi shows when the programme last synced and what that run did,
when the next one is due, and has a "Sync now" button for the last-minute
change. Only one sync runs at a time; anything that arrives while one is going
stands aside.

To sync more or less often:

`add_filter( 'veezi_sync_recurrence', fn() => 'twicedaily' );`

= If Veezi is unreachable =

An outage at the ticketing provider does not blank the website. Every feed is
fetched before anything is written, so a failed or partial fetch stops the run
and leaves the last good programme published, ordered and complete. A visitor
sees nothing amiss; the failure is written to the server's error log and raised
as an admin notice, so the cinema finds out before a customer does. The notice
goes away by itself on the next run that works.

Responses are cached for five minutes, since Veezi publishes no rate limits.
Failures are never cached, and "Sync now" and "Test connection" both ignore the
cache.

= Deactivating and deleting =

Deactivating takes the scheduled sync off the queue and leaves everything else
alone. Deleting removes what the plugin configured itself with — the access
token above all — and leaves the films, sessions and posters it published, since
those have public addresses and sit in the media library like any other content.

= Building a film card =

Seven fields appear in Elementor's dynamic-data picker under "Veezi Programme":
poster, runtime, classification, genre, trailer link, session time and booking
link. Bind them to ordinary widgets the way you would bind any custom field —
they read whichever record is being rendered, so the same card works inside a
loop and on a film's own page with nothing to configure.

A card also needs every time that film screens, which is a list inside a list:
loop widgets cannot nest and a dynamic tag can offer only one value. So the
plugin ships one widget of its own, "Session Times", which lists a film's
remaining screenings with a booking link for each, and carries controls for what
a row shows and how the times read. A sold-out screening stays on the card,
marked, with no link.

An importable starter card ships with the plugin — poster, title, details,
session times and a booking button, already bound. There is a link to it on
Settings → Veezi.

= Ordering a listing =

Films and screenings both carry a rank in WordPress's menu order field — films
by when they next screen, screenings chronologically — so choosing "Menu Order"
in a loop grid gives the right order with nothing to configure. The default
sort is publication date, which for synced content is when the sync happened to
create the record and means nothing at all.

Screenings are deleted once they finish, so a listing does not have to filter
them out. A film whose screenings have all passed leaves the current listing but
keeps its page, so a link shared while it was on still works.

= Posters =

Artwork is copied into the media library and set as the film's featured image,
so WordPress makes its own sizes from it and the cinema can reuse it elsewhere.
Veezi serves one full-resolution poster, and the only smaller variant it offers
is too small for a card, so linking to the originals would cost a visitor
several megabytes a page view.

= Getting an access token =

Veezi issues one access token per cinema, from Settings → Web in Veezi Back
Office. Paste it into Settings → Veezi and press "Test connection": a working
token answers with the cinema's name.

The token is a credential. It is stored as a site option, never rendered back
into the page once saved, and scrubbed out of any error message before it is
displayed or logged.

= Supplying the token without the database =

Defining a constant in `wp-config.php` overrides whatever is saved, which is
how a staging site points at a different Veezi account without a database
change:

`define( 'VEEZI_API_TOKEN', 'your-token-here' );`

The settings screen says when a constant is in force.

= Cinemas outside Australia and New Zealand =

Veezi issues an account against one regional endpoint. The plugin defaults to
the Australia/New Zealand one; point it elsewhere with a filter in a small
plugin of your own:

`add_filter( 'veezi_api_base_url', fn() => 'https://api.uk.veezi.com' );`

== Frequently Asked Questions ==

= Does this sell tickets? =

No. Ticket purchase, seat selection and payment stay in Veezi. The plugin
renders booking links that take a visitor to the cinema's Veezi checkout.

= Is any sales data published? =

No, and it is designed not to be. The API returns seat counts and price card
names alongside the programme, and the plugin discards them as it reads rather
than storing them, keeping only "sold out" and "few tickets left", which is all
a visitor needs. All API calls are made server-side, so the access token — which
can read those figures — never reaches a browser.

= Will it work with my theme? =

The plugin produces content, not layout. Nothing about it is tied to a
particular theme.

== Changelog ==

= 0.1.0 =
* First release: settings screen, access token handling, and a connection check
  that reports which cinema the site is connected to.
