=== Freshet Pages Plus ===
Contributors: kristoffbertram
Tags: pages, admin, list table, parent filter, duplicate
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Fixes the WP Admin list-table gripes: sortable Modified column, URL paths under titles, parent filter, slug search, one-click Duplicate.

== Description ==

WordPress' admin list tables were built for a blog with forty posts. On a site with four hundred pages nested six deep they stop answering the questions you actually have: where does this page live, which template renders it, what changed last, and who changed it.

Freshet Pages Plus puts those answers in the list tables you already use — Pages, Posts, and every public custom post type. There is no settings screen: each enhancement appears on the screens where it applies and nowhere else.

**What it adds**

* **Sortable "Modified" column** — when it was last edited, by whom, with the post ID underneath. On every public post type.
* **Full URL path under every title** — see where a page actually lives without opening it. Drafts show the path they will get.
* **Parent filter and sortable Parent column** — for hierarchical content, including a "Top level only" option.
* **Template column and filter** — see and filter by page template, wherever the theme registers templates for that post type.
* **Slug/path search** — find a page by its URL fragment instead of guessing the title someone gave it.
* **One-click and bulk Duplicate** — copies a page with its terms and meta, as a draft.

Part of the Freshet plugin suite. Full documentation: [freshet.studio/docs](https://freshet.studio/docs).

== Installation ==

1. Install and activate the plugin.
2. Open **Pages** (or any other public post type). The columns, filters and the Duplicate action are already there.

There is nothing to configure.

== Frequently Asked Questions ==

= Where are the settings? =

There are none. Every enhancement appears where it applies — the parent filter only on hierarchical post types, the template column only where the theme registers templates — and stays out of the way everywhere else.

= Which post types does it touch? =

Every public post type with an admin UI, except attachments: the media library is a different screen with its own column plumbing.

= What does Duplicate copy? =

Content, excerpt, parent, menu order, password, comment and ping status, taxonomy terms and post meta. The copy is created as a draft, owned by you, titled "… (Copy)", with an empty slug so it never collides with the original.

= The Template column isn't showing =

Then the active theme registers no page templates for that post type. The column and its filter appear only where there is something to show.

= Does it change the front end? =

No. Everything it does happens in wp-admin.

== Changelog ==

= 1.5.0 =
* Fix: Duplicate no longer strips backslashes from content and meta (wp_insert_post/add_post_meta expect slashed data).
* New: Template column + filter dropdown, on post types where the theme registers page templates.
* New: post ID in the Modified column subline.

= 1.4.0 =
* Plugin-check escaping pass.
