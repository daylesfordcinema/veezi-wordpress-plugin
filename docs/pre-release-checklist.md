# Before tagging a release

Everything the plugin itself does is covered by the suite, and CI runs it on
every push. This list is the remainder: what **Elementor Pro** does with the
plugin's output. Pro is commercial and cannot be installed in a public pipeline,
so nothing below can be automated here — it is a human, in a local WordPress
with Pro licensed and a working Veezi token, half an hour before a tag.

Keep it short. Every item that can be moved into the suite should be.

## What is already covered, and why it is not on this list

The plugin registers dynamic tags and one widget, and both of those are **free**
Elementor APIs. So tag resolution, the session-times widget's rendered output,
its controls, and the notice it shows when nothing has synced are all ordinary
tests — `DynamicTagsTest`, `SessionTimesTest`, `StarterTemplateTest`. So is the
rule that keeps a screening under way out of a chronological listing, which is
an ordinary WordPress query filter: `CalendarTest`. So is everything about which
records coming-soon publication creates, publishes and withdraws, which is the
sync writing statuses and terms: `ComingSoonTest`. The starter templates are
checked against the tags and widgets the plugin actually registers, because a
binding that no longer resolves renders an empty box rather than an error.

None of that belongs here. What belongs here is only the part where Pro reads
what the plugin produced.

## The checks

Against a site with the programme synced and at least one film screening.

- [ ] **A loop grid renders the card.** Import `templates/film-card.json`, point
      a loop grid at Films with it as the item template. Every bound field
      resolves for every film — poster, runtime, classification, genre, session
      times, booking link — rather than one film's values repeating across the
      grid. That failure mode is specific to a loop: the tags read whichever
      record is being rendered, and only a loop renders more than one.

- [ ] **Menu Order orders it correctly.** Set the loop's query order to **Menu
      Order**, ascending. Films come out in the order they next screen. The rank
      itself is tested; that Pro's query control honours it is not.

- [ ] **Sessions is in the loop grid's Source list.** Open the control and read
      it. This is a precondition of the next check rather than part of it, and it
      is here because it was once false while everything below still passed: Pro
      builds that list from post types registered with `show_in_nav_menus`, which
      sessions deliberately do not have, so the plugin has to name the post type
      through `elementor_pro/utils/get_public_post_types`. When it is missing
      there is no error — the calendar simply cannot be built, and whoever is in
      the builder reaches for Films instead and gets one row per film rather than
      one per screening. `ContentModelTest` pins our half; only this reads Pro's.

- [ ] **A loop grid over Sessions renders the calendar.** Import
      `templates/session-row.json`, point a loop grid at Sessions with it as the
      item template and the query sorted by **Menu Order**. Every screening
      still to come comes out in chronological order, each row under the day it
      is on, with the film's title rather than the session record's own — and
      nothing planned among them, whichever way coming-soon publication is set —
      throw that switch and press **Sync now**, and this grid must not change.
      Set **Items Per Page** high
      enough to see the whole programme; it defaults to six.

- [ ] **A screening that has begun is gone from that grid.** Move one session's
      `_veezi_starts_at` to twenty minutes ago. The calendar drops the row; the
      film's own Session Times keeps it, with no link. Only Pro's loop grid can
      show the first half of that.

- [ ] **A sold-out row has no anchor at all.** Set `_veezi_sold_out` on a
      session. The row keeps its time as plain text with the badge beside it —
      not a link, and not a button that goes nowhere. Read the markup rather than
      the accessibility snapshot, which does not report unlinked text.

- [ ] **The empty state appears, and only then.** With a programme, the heading
      bound to **Nothing Scheduled** renders empty. Shift every
      `_veezi_starts_at` a year into the past and it reads its sentence while
      the grid renders nothing.

- [ ] **A Coming Soon grid holds what has been announced, and only that.** With
      **Publish what is coming** on at Settings → Veezi, copy the Now Showing
      grid, switch its Listings term to **Coming Soon** and its loop item to
      `templates/coming-soon-card.json`. It holds the films with planned
      screenings inside the horizon and no others — **nothing that is already on
      sale**, and nothing beyond the horizon. Which term a grid filters by is
      Pro's query control; that the terms are right is `ComingSoonTest`.

- [ ] **No card in it offers a date or a button.** Every card is poster, title,
      details and "On sale soon". A button here would render with nowhere to go
      and a date here is one the cinema has not committed to, so seeing either
      means the loop item is still the Now Showing card.

- [ ] **Switching it off empties that grid again.** Turn the setting off and
      **Sync now**. The grid renders nothing, and a film that had only ever been
      coming soon 404s while one that has been on sale still resolves.

- [ ] **A theme-builder Single renders the film page.** Import
      `templates/film-page.json` into a Single template for Films and view a
      film at `/film/<its name>/`. Credits, session times, synopsis and trailer
      all present.

- [ ] **An archived film page still renders.** Same template, a film whose last
      screening has passed. Session times report that the season has ended, the
      booking link resolves to nothing, and nothing on the page is an error or
      an empty player.

- [ ] **All four templates import cleanly.** From a standing start — Templates
      → Saved Templates → Import — with no notice about a missing widget. A
      template referring to a widget this version no longer registers imports
      silently and renders nothing.

## After tagging

Pushing a `v*` tag runs the ordinary checks, then builds the archive and
attaches it to the release — the README's "Releasing" section has the detail.
Confirm the release has a `veezi-wordpress-plugin-<version>.zip` on it, and that
installing that file through **Plugins → Add New → Upload Plugin** on a clean
site activates without error.
