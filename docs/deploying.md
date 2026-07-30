# Deploying

Publishing a release puts that exact version on the live site, through
WordPress's own installer, without anyone opening a terminal. This file is
about the mechanism and how to undo it. It carries no server details on
purpose — the workflow it describes is public — so the host, the account and
the key live in repository secrets, and the runbook that names them lives with
the maintainers, not here.

## What pushing a tag does

`release.yml` already lints, tests, checks the tag against the declared
version, and builds and attaches the installable archive (see
[CONTRIBUTING.md](../CONTRIBUTING.md#releasing)). A `deploy` job runs last, on
that same tag push and nothing else — never on a pull request, and never for an
outside contributor, because the whole workflow is unreachable except by pushing
a `v*` tag.

It is a job in `release.yml` rather than a second workflow keyed off the release
event, and that is deliberate: a workflow listening for `release: published`
never fires for a release this pipeline creates, and fires *too early* for one
published by hand. A job with `needs: package` waits for the archive by
construction. The reasoning is written up at the top of the `deploy` job and in
the ticket that added it.

Only one deploy runs at a time, across every tag, and a deploy is never
cancelled part-way through — a half-replaced plugin on a live site is the one
outcome worth queueing behind.

## The `production` environment

The deploy reads everything about the server from a GitHub **environment** named
`production`. Create it under **Settings → Environments** and give it these
secrets:

| Secret | What it is |
|---|---|
| `DEPLOY_HOST` | The server's hostname or address |
| `DEPLOY_USER` | The SSH account to log in as |
| `DEPLOY_PORT` | The SSH port (optional; defaults to `22`) |
| `DEPLOY_PATH` | The WordPress root to run `wp` in |
| `DEPLOY_SSH_KEY` | The private half of a deploy key the server accepts |

Add a **required reviewer** to the same environment if you want a deploy to wait
for a human's yes before it touches the site. The environment is the natural
place for that gate; nothing else in the pipeline stops between a green suite and
a live install.

Authentication is key-based, over a single connection. The host allows no key we
can install ourselves and runs intrusion protection, so the key has to come from
the hosting panel, and the deploy avoids anything — a password prompt, a retry
loop — that a lock-out would punish.

The server's host key is **not** pinned. The deploy accepts it on first sight
(`accept-new`), and because each run is a fresh runner, that is trust-on-first-use
every time rather than a fixed identity. It is a deliberate trade — one fewer
secret to manage, in exchange for giving up protection against a man-in-the-middle
on the deploy connection. If that protection matters more than the convenience,
pin the key: capture it with `ssh-keyscan`, and have the deploy verify against it
before connecting.

## What the deploy proves before it is done

1. **The archive installs** through `wp plugin install --force` — the same code
   path a manual upload takes, so the result is the file the admin uploader would
   have left.
2. **The installed version is the tag's**, read back from the plugin, not
   assumed.
3. **A sync runs and returns something** — the deploy runs the sync directly and
   fails unless the plugin records a success with a non-zero session count. An
   install that boots but cannot reach Veezi does not pass silently.
4. **The page cache is purged**, so the first visitor after a deploy sees the new
   version. A purge that fails is a warning, not a rollback — a stale page
   expires on its own.

## Rolling back

Every release keeps its archive attached to it, so an earlier version is always
one install away. To undo a bad release, re-deploy a known-good earlier tag: the
fastest path is to install that release's `.zip` asset through the same
WordPress installer the deploy uses, over the same SSH access. It is the deploy's
own install step, pointed at an older asset, and it takes seconds because the
archive is already built and was already tested when that tag shipped.

Because the tag and the deploy are tied together, the durable fix is still to
ship a new version with the problem fixed — rollback buys the time to do that
without the broken version live.

## Recovery without CI

The release archive also installs by hand, with no pipeline involved. Download
the `.zip` asset from the release on GitHub, then in wp-admin go to **Plugins →
Add New → Upload Plugin**, choose the file, and when WordPress offers, **replace
the current version with the uploaded one**. Same installer, same result as a
deploy — this is the escape hatch for when CI itself is the thing that is broken.
