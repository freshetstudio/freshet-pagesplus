=== Freshet Pages Plus ===
Contributors: kristoffbertram
Tags: pages, admin, list table, parent filter, duplicate
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.5.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Fixes the WP Admin list-table gripes: sortable Modified column, URL paths under titles, parent filter, slug search, one-click Duplicate.

== Description ==

Improves WP Admin list tables (Pages, Posts, and any custom post type) on larger, deeply-structured sites:

* **Sortable "Modified" column** — with the post ID and last editing author, on every public post type.
* **Template column + filter** — see and filter by page template, wherever the theme registers templates.
* **Full URL path under every title** — see where a page actually lives without opening it.
* **Parent filter + sortable Parent column** — for hierarchical content, including a "Top level only" option.
* **Slug/path search** — find pages by their URL fragment.
* **One-click + bulk Duplicate** — copy pages with their meta, as drafts.

No settings screen; everything activates where it makes sense.

== Installation ==

1. Install and activate. The enhancements appear on all list tables of public post types.

== Changelog ==

= 1.5.0 =
* Fix: Duplicate no longer strips backslashes from content and meta (wp_insert_post/add_post_meta expect slashed data).
* New: Template column + filter dropdown, on post types where the theme registers page templates.
* New: post ID in the Modified column subline.

= 1.4.0 =
* Plugin-check escaping pass.
