# Veezi for WordPress

Publishes a cinema's [Veezi](https://www.veezi.com/) programme as WordPress
content — films, sessions, posters and booking links — so the website follows
the ticketing system instead of being retyped from it.

The integration is **read-only**. Ticket sales stay in Veezi; the plugin renders
links out to the cinema's own booking pages and never handles a transaction.

> **Status: early.** This release connects to Veezi and confirms which cinema
> the site is talking to. Syncing films and sessions is the next piece of work.

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
plugin makes is server-side. When session syncing lands, those commercial
fields will be discarded rather than stored, so that keeping them off the site
does not depend on remembering to filter them at render time.

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
