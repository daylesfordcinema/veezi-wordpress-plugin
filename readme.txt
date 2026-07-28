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

This is an early release. It connects to Veezi and confirms which cinema the
site is talking to. Syncing films and sessions is the next piece of work.

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
names alongside the programme; when session syncing lands, the plugin will
discard them rather than store them, keeping only "sold out" and "few tickets
left", which is all a visitor needs. All API calls are made server-side, so the
access token — which can read those figures — never reaches a browser.

= Will it work with my theme? =

The plugin produces content, not layout. Nothing about it is tied to a
particular theme.

== Changelog ==

= 0.1.0 =
* First release: settings screen, access token handling, and a connection check
  that reports which cinema the site is connected to.
