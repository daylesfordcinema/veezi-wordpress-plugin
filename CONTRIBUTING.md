# Contributing

This is the developer's half of the documentation. What the plugin does and how
to use it is in [README.md](README.md); what follows is how to work on it, and
the decisions that will bite you if you change them without knowing they were
decisions.

## Getting set up

```sh
composer install
bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
WP_TESTS_SKIP_INSTALL=1 vendor/bin/phpunit
composer lint
```

The plugin itself has **no runtime dependencies**. Composer is for linting and
testing only, and `vendor/` is not shipped — it is build-free plain PHP using
WordPress's own HTTP and media APIs.

`bin/install-wp-tests.sh` downloads WordPress, the core test library and
Elementor over HTTPS, creates the test database and installs WordPress into it.
It leaves the downloads alone on subsequent runs; pass `--force` to reinstall.

Installing the database there rather than leaving it to the test library is what
`WP_TESTS_SKIP_INSTALL` is for. Unset, the library drops and rebuilds every table
before each run — around 450ms, which on a single test file costs about as much
as the tests do. Set, a run goes straight to the tests, at the price of the
database outliving them: everything inside a test is in a transaction that gets
rolled back, but a write made outside one is no longer wiped between runs. Leave
it unset if that ever matters more than the time.

## Running the tests

Tests run against **real WordPress** — real post types, real options, real media
handling, real rendering — and against **real Elementor**, on the same PHP and
WordPress versions as production. There is exactly one seam:

> **`pre_http_request`.** Everything above it is the code that ships. A request
> no test arranged an answer for **fails the run** rather than reaching the
> network — which matters here, because the real endpoint belongs to a working
> cinema.

The only other injected thing is the clock: `Sync::attempt()` and
`SyncLock::acquire()` both take an optional current time, so horizon boundaries
and abandoned locks are deterministic.

Elementor is a prerequisite rather than an optional extra to skip around. The
dynamic tags and the session-times widget extend its own base classes, so a run
without it would report success while proving nothing about the half of the
plugin a visitor sees. Standing in a fake would prove only that the fake matches
what we believed the API to be, which is the one thing already known.

**Fixtures are synthesised, never captured.** A real capture from a cinema's
account would carry its trading details, sales figures and unannounced
programming into a public repository.

### What a good test looks like here

One that states an outcome a user or administrator would recognise, and would
still pass if the internals were rewritten. *"Syncing a programme with two
sold-out sessions marks both as sold out"* is a good test. *"The transform method
returns an array with a particular key"* is not — it pins the implementation in
place and needs rewriting the first time the code improves.

Assert on observable results: which records exist, which terms they carry, what
the rendered output contains, what a second identical sync does. Not on the shape
of intermediate values.

## Coding standards

`composer lint` runs `phpcs` against `.phpcs.xml.dist` (WordPress-Extra plus
WordPress-Docs). `vendor/bin/phpcbf` fixes most of what it finds.

Beyond what the sniffs enforce, the house style in this codebase is that **every
class and every non-obvious method carries a docblock explaining why**, not what.
Comments name the trade-off that was made and the failure mode being designed
out. If you find yourself writing a comment that restates the line beneath it,
delete it; if you can't explain why a piece of code is shaped the way it is, that
is usually the finding.

## What CI runs

Pushes to `main` and pull requests run the coding-standards check and the whole
suite — the same two commands as above. PHP and MariaDB come from the runner,
pinned in the workflow to match what the plugin is deployed onto; WordPress and
Elementor come from `bin/install-wp-tests.sh`, which CI runs exactly as you do,
so those versions live in one place and a pipeline cannot drift from a checkout.

A green suite and a green lint locally therefore mean a green pipeline. Note the
trigger, though: **`main` and pull requests only.** A topic branch pushed with no
pull request behind it has been checked by nothing but you.

The workflow declares read-only permissions and reads no secret, and it runs on
`pull_request` rather than `pull_request_target`, so a pull request from a fork
executes that fork's code with nothing in scope worth taking. Everything
privileged lives in `release.yml`, which only a tag push reaches.

### What CI cannot run

Two things, and neither is a gap to be closed with more tests.

**Elementor Pro** — the loop grid, the query control and the theme builder. It is
commercial and cannot be installed in a public pipeline, so anything that only
happens inside it is checked by hand before a release against
[docs/pre-release-checklist.md](docs/pre-release-checklist.md). That list is
deliberately short, and keeping it short is the argument for a thin presentation
layer: `src/Presentation` holds the answers and is fully tested, `src/Elementor`
is only the adapter that hands them over.

**A live Veezi account.** See fixtures, above.

## How it is put together

```
src/
  Client.php  Token.php  ResponseCache.php   the Veezi API, and nothing else
  Session.php  Film.php  Person.php          what Veezi said, parsed
  Programme.php                              the three feeds joined; every
                                             decision about what is visible
  Repository.php  ContentModel.php           WordPress: posts, terms, meta
  PosterLibrary.php                          media sideloading
  Sync.php  Schedule.php  SyncLock.php       when it runs, and only once
  SyncLog.php  SyncResult.php
  Settings.php  ComingSoon.php               configuration
  Presentation/                              the answers a page needs,
                                             with no Elementor in them
  Elementor/                                 the adapter: tags and one widget
  Admin/                                     the settings screen and notices
```

The line that matters most is between `Presentation` and `Elementor`.
`Presentation\Fields` answers "what does this record say" and knows nothing about
any page builder; `Elementor\Tags\*` are thin wrappers that ask it. Custom
Elementor widgets are the largest ongoing maintenance liability in this project —
larger than the Veezi integration, which is a stable read-only API — so the
coupling is confined to one directory and the plugin owns exactly **one** widget.

## Decisions worth knowing before you change them

**The listing is driven by sessions, never by the film catalogue.** Every film in
a Veezi account reports `Status: Active`, test records included.

**Three API calls, and they are all needed.** `/v1/session` is the spine and the
only feed carrying planned sessions, but it has no booking link in it at all;
`/v1/websession` has the booking links and drops planned sessions entirely;
`/v4/film` has the artwork and synopsis and no idea what is scheduled.

**Ranking lives in `menu_order`.** Elementor's loop grid sorts by published date,
title, menu order, last modified, comment count or random — there is no
order-by-meta-value, so `_veezi_next_screening` is invisible to the thing that has
to do the ordering. `menu_order` is the one sortable field that can be made to
mean something. It holds a **position**, not a timestamp: the column is a signed
32-bit integer and epoch seconds stop fitting in 2038.

**A sync that changes nothing writes nothing.** Records are matched on Veezi's
identifiers and compared field by field before saving, so an hourly run against
unchanged data leaves modification dates, revisions and caches alone. The
comparison list in `Repository::update_if_changed()` is *every field written* and
has to stay that way — a field left off is one whose changes are computed,
discarded and computed again next run, which presents as the feature silently not
working.

**Screenings are deleted once they end; listings filter from the moment they
start.** Two different cutoffs, deliberately. The record survives until the film
ends so a card doesn't claim the film next screens tomorrow while an audience is
sitting in it; the chronological listing drops it at the start via a
`pre_get_posts` rule, because the sync runs hourly and would otherwise go on
offering a missed screening for up to an hour. Anything that needs the records
regardless — the sync's own lookup above all — asks for
`ContentModel::EVERY_SCREENING`. A record the sync fails to find is one it creates
a second copy of.

**Coming-soon publication is one rule at the screening.** A planned screening is
published only while nothing of its film is on sale and it falls inside the
horizon; the film's listing term falls out of that rather than being decided
separately. It costs two passes over the sessions, because whether a screening is
published is a question about its *film*. And `_veezi_coming_soon_only` marks a
film published solely because the switch is on, so the switch stays reversible
while a film that has been on sale keeps its page for good.

**Commercial fields are discarded on read, not filtered on render.** Seat counts
and price card names never enter the database. Only the two booleans survive.
This is also why the response cache holds `/v4/film` and nothing else — caching
the session feeds would write box-office figures into `wp_options`.

**Posters are keyed on Veezi's media reference** so sideloading is idempotent,
and lossless originals are recompressed as WebP with the file exactly as Veezi
sent it kept alongside as the attachment's original.

**Phase two is deferred, not forgotten.** A day-grouped calendar widget and an
injected loop-grid query control are both conveniences — the views they serve are
buildable without them. The line is *can this be built at all*, not how pleasant
it is to build. Anything that merely saves effort waits.

## Documentation

Three files, three audiences:

| | |
|---|---|
| `readme.txt` | The WordPress plugin readme format. This is what WordPress itself renders under **Plugins → View details**, so it is the documentation that reaches a site administrator without them leaving their own admin. Keep it current. |
| `README.md` | The repo's front door, written for the person installing and using the plugin. |
| `CONTRIBUTING.md` | This file. |

Changing behaviour usually means touching at least two of them, plus
`docs/pre-release-checklist.md` if the change is one only Elementor Pro can
exercise.

## Releasing

Bump the version in all three places it is written down — the plugin header, the
`VERSION` constant and `Stable tag` in `readme.txt` — commit, and tag that commit
`v<version>`. A test checks the three against each other, and the release workflow
checks them against the tag and refuses a tag that disagrees.

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

Pushing the tag also **deploys** that archive to the live site, through a job in
the same workflow. What that job checks, the secrets it reads, how to roll back,
and how to install the archive by hand when CI itself is broken are all in
[docs/deploying.md](docs/deploying.md).

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE). Contributions are accepted under the
same licence.
