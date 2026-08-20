# Freshet Pages Plus

Fixes the WP Admin list-table gripes on larger, deeply-structured sites: a sortable Modified column, the full URL path under every title, a parent filter, slug/path search and one-click Duplicate.

Part of the Freshet plugin suite. MIT.

WordPress list tables have no sortable "Modified" date, never surface the actual
URL path (so three pages all called "Overview" are indistinguishable), cannot be
searched by path, and give you no way to filter or sort by parent — let alone
duplicate a page in one click. This adds all of it, to Pages, Posts and every
public custom post type, with no settings screen.

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
- **Template column + filter** — the page template each row uses, and a
  dropdown to filter by it, on post types where the theme registers templates.
- **Duplicate — single + bulk** — one-click "Duplicate" on every row, plus a
  "Duplicate" bulk action. Clones content, excerpt, parent, menu order,
  taxonomy terms, and post meta into a new **draft** titled "… (Copy)"; the row
  action then opens it in the editor.

## Usage

Activate the plugin. That's it — it applies automatically to every public post
type, and there is no settings page.

- Click the **Modified** or **Parent** column header to sort.
- Pick a parent from the **All parents** dropdown and hit **Filter** to see
  that page and its whole branch (children, grandchildren, …), nested. Pick
  **Top level only** to list just the root pages.
- Click a value in the **Parent** column to jump to that parent's branch view.
- Search a slug or path segment (e.g. `about/team`) to list every page in that
  branch.
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
- Cloning copies meta verbatim — it does **not** remap stored post-ID
  references (relationship fields and the like) to the new post.

## Dev environment

Symlink or copy the plugin into a local WordPress install and activate it:

```bash
ln -s "$(pwd)" /path/to/wp/wp-content/plugins/freshet-pagesplus
```

No build step — plain PHP (7.4+), one file.

Lint: `php -l freshet-pagesplus.php`.

## License

MIT. Part of the [Freshet Studio](https://freshet.studio) plugin suite.
