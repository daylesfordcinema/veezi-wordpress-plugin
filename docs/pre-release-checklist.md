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
tests — `DynamicTagsTest`, `SessionTimesTest`, `StarterTemplateTest`. The
starter templates are checked against the tags and widgets the plugin actually
registers, because a binding that no longer resolves renders an empty box rather
than an error.

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

- [ ] **A theme-builder Single renders the film page.** Import
      `templates/film-page.json` into a Single template for Films and view a
      film at `/film/<its name>/`. Credits, session times, synopsis and trailer
      all present.

- [ ] **An archived film page still renders.** Same template, a film whose last
      screening has passed. Session times report that the season has ended, the
      booking link resolves to nothing, and nothing on the page is an error or
      an empty player.

- [ ] **Both templates import cleanly.** From a standing start — Templates →
      Saved Templates → Import — with no notice about a missing widget. A
      template referring to a widget this version no longer registers imports
      silently and renders nothing.

## After tagging

Pushing a `v*` tag runs the ordinary checks, then builds the archive and
attaches it to the release — the README's "Releasing" section has the detail.
Confirm the release has a `veezi-wordpress-plugin-<version>.zip` on it, and that
installing that file through **Plugins → Add New → Upload Plugin** on a clean
site activates without error.
