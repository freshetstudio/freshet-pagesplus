# Freshet Pages Plus

_1.4.0_

By [Freshet Studio](https://freshet.studio).

Fixes the things that make WP Admin list tables (Pages, Posts, and any custom
post type) painful on larger, deeply-structured sites.

## Features

- **Sortable "Modified" column** — on every public post type. Shows the last
  modified date plus a muted "_x ago · by Author_" line, and sorts (it maps to
  `WP_Query`'s native `modified` orderby).
- **Full URL path under the title** — the relative permalink (e.g.
  `/services/europe/pricing/`) rendered as a muted line beneath each title, so
  you can tell apart similarly-named pages at a glance. Links to the live page
  when published; drafts show their intended pretty path (no link).
- **Full-path search** — the list-table search box also matches the full URL
  path (a page's own slug plus every ancestor slug). Searching a section slug
  returns the whole branch beneath it, not just the pages whose own slug
  matches — so titles colliding across sections is no longer a problem.
- **Parent filter + sortable Parent column** — for hierarchical post types
  (Pages and hierarchical CPTs):
  - a **Parent** dropdown in the table toolbar that shows the whole **branch**
    — the chosen page itself plus every descendant, nested with indentation,
    not just its direct children — plus a **Top level only** option that lists
    just the root pages (`post_parent = 0`), flat;
  - a **Parent** column showing the parent title (linked to that branch view)
    and sortable **by parent title** (self-join, not parent ID);
  - the branch keeps the **manual (menu) order** intact, not A–Z.
- **Duplicate — single + bulk** — one-click "Duplicate" on every row, plus a
  "Duplicate" bulk action. Clones content, excerpt, parent, menu order,
  taxonomy terms, and post meta into a new **draft** titled "… (Copy)"; the row
  action then opens it in the editor.

## Why this exists

WordPress list tables have no default sortable "Modified" date, never surface
the actual URL path (so on a big site three pages all called "Overview" are
indistinguishable), can't be searched by path, and give you no way to filter or
sort by parent — let alone duplicate a page in one click. This fixes all of
that.

## Usage

Activate the plugin. That's it — it applies automatically to every public post
type. There is no settings page.

- Click the **Modified** or **Parent** column header to sort.
- Pick a parent from the **All parents** dropdown and hit **Filter** to see
  that page and its whole branch (children, grandchildren, …), nested. Pick
  **Top level only** to list just the root pages.
- Click a value in the **Parent** column to jump to that parent's branch view.
- Search a slug or path segment (e.g. `max-your-cool`) to list every page in
  that branch.
- Select rows and choose **Duplicate** from the Bulk actions menu to clone
  several at once.

## Technical notes

- Admin only; everything is scoped to `edit.php` and the screen's post type.
- The path is captured during a scoped `the_title` filter and injected under
  the title with a small footer script — WP `esc_html()`s the list-table title,
  so appended markup can't render there directly.
- Modified sorting uses native `WP_Query` orderby; Parent sorting adds a
  `posts_clauses` self-join to order by the parent's title.
- Path search computes each post's ancestor-slug path in PHP (one lightweight
  query per search) and OR-injects the matching IDs into WP's `posts_search`
  clause; existing title/content search is untouched, and the outer
  `post_status` WHERE still scopes results to the current view.
- Duplicate (single + bulk) shares one clone routine. The row action runs
  through `admin_action_*` with a per-post nonce; both paths check `edit_post`.
- Attachments are skipped (the media library is a separate screen).
- Note: cloning copies meta verbatim — it does **not** remap stored post-ID
  references (e.g. ACF relationship fields) to the new post.

## Development

No build step — plain PHP.

## License

MIT — see `LICENSE`.

## Changelog

- 1.4.0 Added a **Top level only** parent-filter option (root pages, flat).
- 1.3.0 Parent filter now shows the whole **branch** (the page itself + all
  descendants, nested) instead of only direct children.
- 1.2.1 Search now matches the **full path** (own slug + ancestor slugs), so a
  section search returns the whole branch — not just leaf-slug matches.
- 1.2.0 Slug/path search, bulk Duplicate, path links to the live page,
  "by Author" in the Modified column, and Parent sorts by title (not ID).
- 1.1.0 Path now renders reliably (footer-injected, was escaped by core).
  Parent filter preserves manual menu order. Added Duplicate row action.
- 1.0.0 Release.

## Disclaimer

- Built out of personal necessity for managing large WordPress sites.
- No configuration UI, by design. It either helps you or it doesn't.
