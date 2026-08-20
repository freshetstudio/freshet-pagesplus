<?php
/**
 * Plugin Name:       Freshet Pages Plus
 * Plugin URI:        https://freshet.studio
 * Description:       Fixes the WP Admin list-table gripes: a sortable "Modified" column (with ID and author), the full URL path under every title, a parent filter + sortable Parent column for hierarchical content, a template column + filter, slug/path search, and one-click + bulk Duplicate.
 * Version:           1.5.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Freshet Studio
 * Author URI:        https://freshet.studio
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       freshet-pagesplus
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

// Single version source; keep in sync with the header + readme stable tag
// (portfolio convention, same as freshet-editjump / freshet-uploadmax).
define('FRESHET_PAGESPLUS_VERSION', '1.5.0');

const FRESHET_PAGESPLUS_COL_MODIFIED = 'freshet_pagesplus_modified';
const FRESHET_PAGESPLUS_COL_PARENT   = 'freshet_pagesplus_parent';
const FRESHET_PAGESPLUS_PARENT_QV    = 'freshet_pagesplus_parent';
const FRESHET_PAGESPLUS_PARENT_SORT  = 'freshet_pagesplus_parent';
const FRESHET_PAGESPLUS_TOP_VALUE    = 'top'; // parent-filter sentinel: top level only
const FRESHET_PAGESPLUS_DUP_ACTION   = 'freshet_pagesplus_duplicate';
const FRESHET_PAGESPLUS_COL_TEMPLATE = 'freshet_pagesplus_template';
const FRESHET_PAGESPLUS_TEMPLATE_QV  = 'freshet_pagesplus_template';
// Shared handle for the (src-less) style + script registrations the inline CSS
// and the path payload hang off. No build step, so there is no file to point at.
const FRESHET_PAGESPLUS_HANDLE       = 'freshet-pagesplus';

// Translations shipped inside the plugin's own /languages need this call —
// without a custom path the textdomain registry only looks in WP_LANG_DIR, so
// wp.org-delivered translations load either way but a bundled .mo never would.
// On init: nothing here translates earlier.
add_action('init', function () {
	load_plugin_textdomain('freshet-pagesplus', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

/**
 * Post types we touch: anything public with an admin UI, minus attachments
 * (the media library is a different screen with its own column plumbing).
 */
function freshet_pagesplus_target_post_types(): array {
	$types = get_post_types(['public' => true, 'show_ui' => true], 'names');
	unset($types['attachment']);
	return $types;
}

function freshet_pagesplus_is_hierarchical(string $post_type): bool {
	return $post_type !== '' && is_post_type_hierarchical($post_type);
}

/**
 * Theme page templates available for a post type, file => name, name-sorted.
 * Empty when the theme registers none for this type (column/filter stay hidden).
 */
function freshet_pagesplus_templates(string $post_type): array {
	$templates = wp_get_theme()->get_page_templates(null, $post_type);
	natcasesort($templates);
	return $templates;
}

/**
 * Relative URL path for a post, decoded for readability.
 * Published/private/future use the real permalink; drafts fall back to the
 * intended pretty path so you still see where the content will live.
 */
function freshet_pagesplus_relative_path(WP_Post $post): string {
	if ( in_array(get_post_status($post), ['publish', 'private', 'future'], true) ) {
		$url = get_permalink($post);
	} else {
		if ( ! function_exists('get_sample_permalink') ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$sample = get_sample_permalink($post->ID);
		$name   = $post->post_name ?: sanitize_title($post->post_title);
		$url    = is_array($sample)
			? str_replace(['%pagename%', '%postname%'], $name, $sample[0])
			: get_permalink($post);
	}

	return urldecode( wp_make_link_relative( (string) $url ) );
}

/**
 * Per-request store of post ID => ['path' => string, 'href' => string].
 * Filled while the list table renders titles, drained by the footer injector.
 */
function &freshet_pagesplus_path_store(): array {
	static $paths = [];
	return $paths;
}

/**
 * Wire everything up only on edit.php, only for the screen's post type.
 */
add_action('load-edit.php', function () {
	$screen = get_current_screen();
	$pt     = $screen ? $screen->post_type : '';

	if ( ! in_array($pt, freshet_pagesplus_target_post_types(), true) ) {
		return;
	}

	add_filter("manage_{$pt}_posts_columns", 'freshet_pagesplus_columns');
	add_action("manage_{$pt}_posts_custom_column", 'freshet_pagesplus_render_column', 10, 2);
	add_filter("manage_edit-{$pt}_sortable_columns", 'freshet_pagesplus_sortable_columns');
	add_filter('the_title', 'freshet_pagesplus_capture_path', 10, 2);
	add_action('restrict_manage_posts', 'freshet_pagesplus_parent_filter', 10, 2);
	add_action('restrict_manage_posts', 'freshet_pagesplus_template_filter', 10, 2);
	add_filter('post_row_actions', 'freshet_pagesplus_duplicate_link', 10, 2);
	add_filter('page_row_actions', 'freshet_pagesplus_duplicate_link', 10, 2);
	add_filter("bulk_actions-edit-{$pt}", 'freshet_pagesplus_register_bulk_duplicate');
	add_filter("handle_bulk_actions-edit-{$pt}", 'freshet_pagesplus_handle_bulk_duplicate', 10, 3);
	add_action('admin_notices', 'freshet_pagesplus_bulk_notice');
	add_action('admin_enqueue_scripts', 'freshet_pagesplus_enqueue_assets');
	add_action('admin_footer', 'freshet_pagesplus_print_paths');
});

/**
 * Raw parent-filter value for an edit.php main query on a hierarchical type:
 * '' (none/N/A), 'top' (top level only), or a numeric string (a parent ID).
 */
function freshet_pagesplus_parent_filter_raw(WP_Query $query): string {
	global $pagenow;
	if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
		return '';
	}
	if ( ! isset($_GET[FRESHET_PAGESPLUS_PARENT_QV]) ) {
		return '';
	}
	$post_type = $query->get('post_type') ?: 'post';
	if ( is_array($post_type) ) {
		$post_type = (string) reset($post_type);
	}
	if ( ! freshet_pagesplus_is_hierarchical((string) $post_type) ) {
		return '';
	}
	return sanitize_text_field( wp_unslash( (string) $_GET[FRESHET_PAGESPLUS_PARENT_QV] ) );
}

/**
 * Currently-filtered parent ID for the branch view (0 if none / top / N/A).
 */
function freshet_pagesplus_current_parent_filter(WP_Query $query): int {
	$raw = freshet_pagesplus_parent_filter_raw($query);
	return ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : 0;
}

/**
 * The whole branch (the page itself + every descendant) of $root, depth-first.
 */
function freshet_pagesplus_subtree_ids(string $post_type, int $root): array {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_parent FROM {$wpdb->posts}
		 WHERE post_type = %s AND post_status NOT IN ('auto-draft', 'inherit', 'trash')",
		$post_type
	) );
	$children = [];
	foreach ( $rows as $r ) {
		$children[(int) $r->post_parent][] = (int) $r->ID;
	}

	$ids   = [$root];
	$stack = [$root];
	$guard = 0;
	while ( $stack && $guard++ < 100000 ) {
		$cur = array_pop($stack);
		foreach ( $children[$cur] ?? [] as $child ) {
			$ids[]   = $child;
			$stack[] = $child;
		}
	}

	return array_values( array_unique($ids) );
}

/**
 * Parent filter. Two modes:
 *  - 'top'        -> only top-level pages (post_parent = 0), flat.
 *  - a parent ID  -> the whole branch (the page itself + all descendants),
 *                    nested, not just direct children.
 * Either way the manual (menu) order is kept. Top-level hook: the main
 * edit.php query runs after load-edit.php.
 */
add_action('pre_get_posts', function (WP_Query $query) {
	$raw = freshet_pagesplus_parent_filter_raw($query);
	if ( '' === $raw ) {
		return;
	}

	if ( FRESHET_PAGESPLUS_TOP_VALUE === $raw ) {
		$query->set('post_parent', 0);
	} elseif ( ctype_digit($raw) && (int) $raw > 0 ) {
		$post_type = $query->get('post_type') ?: 'page';
		if ( is_array($post_type) ) {
			$post_type = (string) reset($post_type);
		}
		$query->set('post__in', freshet_pagesplus_subtree_ids((string) $post_type, (int) $raw));
		$query->set('posts_per_page', -1); // full branch; the tree renderer paginates.
	} else {
		return; // '0' = all parents, no filter.
	}

	if ( ! isset($_GET['orderby']) ) {
		$query->set('orderby', 'menu_order title');
		$query->set('order', 'ASC');
	}
});

/**
 * Re-root the filtered branch: the admin tree renderer only nests under posts
 * whose post_parent is 0, so present the filtered page as top-level. Done on a
 * clone so the object cache (and the Parent column) keep the real parent.
 */
add_filter('the_posts', function (array $posts, WP_Query $query): array {
	if ( empty($posts) ) {
		return $posts;
	}
	$parent = freshet_pagesplus_current_parent_filter($query);
	if ( $parent <= 0 ) {
		return $posts;
	}
	foreach ( $posts as $i => $post ) {
		if ( (int) $post->ID === $parent && 0 !== (int) $post->post_parent ) {
			$clone              = clone $post;
			$clone->post_parent = 0;
			$posts[$i]          = $clone;
			break;
		}
	}
	return $posts;
}, 10, 2);

/**
 * IDs of posts whose full URL path (own slug + every ancestor slug) contains
 * the search term. Lets you search by any segment of the path — searching a
 * section slug returns the whole branch beneath it, not just the leaf.
 */
function freshet_pagesplus_path_search_ids(string $post_type, string $term): array {
	global $wpdb;

	$needle  = strtolower($term);
	$needles = array_unique(array_filter([$needle, str_replace(' ', '-', $needle)]));
	if ( empty($needles) ) {
		return [];
	}

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_parent, post_name FROM {$wpdb->posts}
		 WHERE post_type = %s AND post_status NOT IN ('auto-draft', 'inherit')",
		$post_type
	) );
	if ( empty($rows) ) {
		return [];
	}

	$name = $parent = [];
	foreach ( $rows as $r ) {
		$name[(int) $r->ID]   = $r->post_name;
		$parent[(int) $r->ID] = (int) $r->post_parent;
	}

	$ids = [];
	foreach ( $rows as $r ) {
		$segments = [];
		$cur      = (int) $r->ID;
		$guard    = 0;
		while ( $cur && isset($name[$cur]) && $guard++ < 100 ) {
			if ( '' !== $name[$cur] ) {
				array_unshift($segments, $name[$cur]);
			}
			$cur = $parent[$cur];
		}
		$path = strtolower( implode('/', $segments) );
		if ( '' === $path ) {
			continue;
		}
		foreach ( $needles as $n ) {
			if ( false !== strpos($path, $n) ) {
				$ids[] = (int) $r->ID;
				break;
			}
		}
	}

	return $ids;
}

/**
 * Match the full URL path when searching the list table, so you can find pages
 * by any segment of their path on sites full of similarly-titled content.
 */
add_filter('posts_search', function ($search, WP_Query $query) {
	global $pagenow, $wpdb;
	if ( '' === $search || ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
		return $search;
	}
	$term = trim( (string) $query->get('s') );
	if ( '' === $term ) {
		return $search;
	}
	$post_type = $query->get('post_type') ?: 'post';
	if ( is_array($post_type) ) {
		$post_type = (string) reset($post_type);
	}

	$ids = freshet_pagesplus_path_search_ids((string) $post_type, $term);
	if ( empty($ids) ) {
		return $search;
	}
	$clause = "{$wpdb->posts}.ID IN (" . implode(',', array_map('absint', $ids)) . ')';

	// WP wraps the whole search in one trailing ")". Inject " OR (ids)" just
	// inside it so a row matches the normal search OR the path match. The outer
	// post_status WHERE still applies, so this never leaks cross-view results.
	$trimmed = rtrim($search);
	$pos     = strrpos($trimmed, ')');
	if ( false === $pos ) {
		return $search;
	}

	return substr($trimmed, 0, $pos) . ' OR ' . $clause . substr($trimmed, $pos) . ' ';
}, 10, 2);

/**
 * Sort the Parent column by the parent's title (not its ID) via a self-join.
 */
add_filter('posts_clauses', function (array $clauses, WP_Query $query): array {
	global $pagenow, $wpdb;
	if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
		return $clauses;
	}
	if ( FRESHET_PAGESPLUS_PARENT_SORT !== $query->get('orderby') ) {
		return $clauses;
	}
	$order = 'DESC' === strtoupper((string) $query->get('order')) ? 'DESC' : 'ASC';
	$clauses['join']    .= " LEFT JOIN {$wpdb->posts} AS twpp_parent ON {$wpdb->posts}.post_parent = twpp_parent.ID ";
	$clauses['orderby']  = "twpp_parent.post_title {$order}, {$wpdb->posts}.post_title ASC";

	return $clauses;
}, 10, 2);

function freshet_pagesplus_columns(array $columns): array {
	$screen        = get_current_screen();
	$hierarchical  = $screen && freshet_pagesplus_is_hierarchical($screen->post_type);
	$has_templates = $screen && [] !== freshet_pagesplus_templates($screen->post_type);
	$new           = [];

	foreach ( $columns as $key => $label ) {
		$new[$key] = $label;
		if ( 'title' === $key && $hierarchical ) {
			$new[FRESHET_PAGESPLUS_COL_PARENT] = __('Parent', 'freshet-pagesplus');
		}
		if ( 'title' === $key && $has_templates ) {
			$new[FRESHET_PAGESPLUS_COL_TEMPLATE] = __('Template', 'freshet-pagesplus');
		}
		if ( 'date' === $key ) {
			$new[FRESHET_PAGESPLUS_COL_MODIFIED] = __('Modified', 'freshet-pagesplus');
		}
	}
	if ( ! isset($new[FRESHET_PAGESPLUS_COL_MODIFIED]) ) {
		$new[FRESHET_PAGESPLUS_COL_MODIFIED] = __('Modified', 'freshet-pagesplus');
	}

	return $new;
}

function freshet_pagesplus_sortable_columns(array $columns): array {
	$columns[FRESHET_PAGESPLUS_COL_MODIFIED] = 'modified';

	$screen = get_current_screen();
	if ( $screen && freshet_pagesplus_is_hierarchical($screen->post_type) ) {
		$columns[FRESHET_PAGESPLUS_COL_PARENT] = FRESHET_PAGESPLUS_PARENT_SORT;
	}

	return $columns;
}

function freshet_pagesplus_render_column(string $column, int $post_id): void {
	if ( FRESHET_PAGESPLUS_COL_MODIFIED === $column ) {
		echo esc_html( get_the_modified_date('', $post_id) );

		$ts     = (int) get_post_modified_time('U', true, $post_id);
		$author = get_the_author_meta('display_name', (int) get_post_field('post_author', $post_id));
		$meta   = ['#' . $post_id];
		if ( $ts ) {
			/* translators: %s: human-readable time difference, e.g. "3 days". */
			$meta[] = sprintf(__('%s ago', 'freshet-pagesplus'), human_time_diff($ts));
		}
		if ( $author ) {
			/* translators: %s: author display name. */
			$meta[] = sprintf(__('by %s', 'freshet-pagesplus'), $author);
		}
		if ( $meta ) {
			echo '<br><span class="freshet-pagesplus-sub">' . esc_html( implode(' · ', $meta) ) . '</span>';
		}
		return;
	}

	if ( FRESHET_PAGESPLUS_COL_PARENT === $column ) {
		$post = get_post($post_id);
		if ( ! $post || ! $post->post_parent ) {
			echo '<span aria-hidden="true">&#8212;</span>';
			return;
		}
		// Raw title on purpose: avoids re-triggering the_title path capture.
		$parent = get_post($post->post_parent);
		if ( ! $parent ) {
			echo '<span aria-hidden="true">&#8212;</span>';
			return;
		}
		$label = $parent->post_title !== '' ? $parent->post_title : __('(no title)', 'freshet-pagesplus');
		$url   = add_query_arg(
			['post_type' => $parent->post_type, FRESHET_PAGESPLUS_PARENT_QV => $parent->ID],
			admin_url('edit.php')
		);
		printf('<a href="%s">%s</a>', esc_url($url), esc_html($label));
		return;
	}

	if ( FRESHET_PAGESPLUS_COL_TEMPLATE === $column ) {
		$file = (string) get_post_meta($post_id, '_wp_page_template', true);
		if ( '' === $file || 'default' === $file ) {
			echo '<span aria-hidden="true">&#8212;</span>';
			return;
		}
		$post      = get_post($post_id);
		$post_type = $post ? $post->post_type : 'page';
		$templates = freshet_pagesplus_templates($post_type);
		// Fall back to the raw filename when the template no longer exists in the theme.
		$label = $templates[$file] ?? $file;
		// rawurlencode: add_query_arg doesn't encode values, and template files contain "/".
		$url = add_query_arg(
			['post_type' => $post_type, FRESHET_PAGESPLUS_TEMPLATE_QV => rawurlencode($file)],
			admin_url('edit.php')
		);
		printf('<a href="%s">%s</a>', esc_url($url), esc_html($label));
	}
}

/**
 * Record the relative path (and a live link when viewable) for the row, but
 * return the title untouched. WP wraps the list-table title in esc_html() (via
 * _draft_or_post_title), so markup appended here would show literally; we inject
 * it with JS instead.
 */
function freshet_pagesplus_capture_path($title, $post_id = 0) {
	if ( ! $post_id ) {
		return $title;
	}
	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	if ( ! $screen || 'edit' !== $screen->base ) {
		return $title;
	}
	$post = get_post($post_id);
	if ( ! $post || $post->post_type !== $screen->post_type ) {
		return $title;
	}
	$path = freshet_pagesplus_relative_path($post);
	if ( '' !== $path ) {
		$linkable        = in_array(get_post_status($post), ['publish', 'private'], true);
		$store           =& freshet_pagesplus_path_store();
		$store[$post_id] = ['path' => $path, 'href' => $linkable ? $path : ''];
	}

	return $title;
}

function freshet_pagesplus_print_paths(): void {
	$paths = freshet_pagesplus_path_store();
	if ( empty($paths) ) {
		return;
	}
	// JSON_HEX_TAG/AMP: slugs may carry percent-encoded octets (sanitize_title
	// preserves them) and the paths are urldecoded, so keep <, > and & out of
	// the inline script rather than relying on PHP's default \/ escaping.
	// wp_add_inline_script() does not escape for us, so the flags stay.
	$js = 'window.freshetPagesplusPaths=' . wp_json_encode($paths, JSON_HEX_TAG | JSON_HEX_AMP) . ';';
	$js .= <<<'JS'
(function(){
  var p = window.freshetPagesplusPaths || {};
  for (var id in p) {
    if (!Object.prototype.hasOwnProperty.call(p, id)) continue;
    var row = document.getElementById('post-' + id);
    if (!row) continue;
    var cell = row.querySelector('.column-title');
    if (!cell || cell.querySelector('.freshet-pagesplus-path')) continue;
    var strong = cell.querySelector('strong');
    if (!strong) continue;
    var el;
    if (p[id].href) {
      el = document.createElement('a');
      el.href = p[id].href;
      el.target = '_blank';
      el.rel = 'noopener';
    } else {
      el = document.createElement('span');
    }
    el.className = 'freshet-pagesplus-path';
    el.textContent = p[id].path;
    strong.insertAdjacentElement('afterend', el);
  }
})();
JS;

	// admin_footer runs before admin_print_footer_scripts, so the handle
	// registered on admin_enqueue_scripts has not printed yet.
	wp_add_inline_script(FRESHET_PAGESPLUS_HANDLE, $js);
}

function freshet_pagesplus_parent_filter(string $post_type, string $which): void {
	if ( 'top' !== $which || ! freshet_pagesplus_is_hierarchical($post_type) ) {
		return;
	}
	$raw    = isset($_GET[FRESHET_PAGESPLUS_PARENT_QV]) ? sanitize_text_field( wp_unslash( (string) $_GET[FRESHET_PAGESPLUS_PARENT_QV] ) ) : '';
	$is_top = FRESHET_PAGESPLUS_TOP_VALUE === $raw;
	// -1 when "top" is active so wp_dropdown_pages doesn't auto-select "All parents" (0).
	$selected = ctype_digit($raw) ? (int) $raw : ( $is_top ? -1 : 0 );

	$dropdown = wp_dropdown_pages([
		'post_type'         => sanitize_key($post_type),
		'selected'          => (int) $selected,
		'name'              => esc_attr(FRESHET_PAGESPLUS_PARENT_QV),
		'id'                => esc_attr(FRESHET_PAGESPLUS_PARENT_QV),
		'show_option_none'  => esc_html__('All parents', 'freshet-pagesplus'),
		'option_none_value' => '0',
		'sort_column'       => 'menu_order, post_title',
		'echo'              => 0,
	]);
	if ( ! $dropdown ) {
		return;
	}

	// Insert "Top level only" right after the "All parents" option.
	$top_option = '<option value="' . FRESHET_PAGESPLUS_TOP_VALUE . '"' . selected($is_top, true, false) . '>'
		. esc_html__('— Top level only —', 'freshet-pagesplus') . '</option>';
	$dropdown = preg_replace('#</option>#', '</option>' . $top_option, $dropdown, 1);

	echo $dropdown; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_dropdown_pages returns escaped markup.
}

/**
 * Template filter dropdown, shown only when the theme registers page templates
 * for this post type. "Default template" matches posts with no explicit choice.
 */
function freshet_pagesplus_template_filter(string $post_type, string $which): void {
	if ( 'top' !== $which ) {
		return;
	}
	$templates = freshet_pagesplus_templates($post_type);
	if ( empty($templates) ) {
		return;
	}
	$current = isset($_GET[FRESHET_PAGESPLUS_TEMPLATE_QV])
		? sanitize_text_field( wp_unslash( (string) $_GET[FRESHET_PAGESPLUS_TEMPLATE_QV] ) )
		: '';

	echo '<select name="' . esc_attr(FRESHET_PAGESPLUS_TEMPLATE_QV) . '" id="' . esc_attr(FRESHET_PAGESPLUS_TEMPLATE_QV) . '">';
	echo '<option value="">' . esc_html__('All templates', 'freshet-pagesplus') . '</option>';
	echo '<option value="default"' . selected('default', $current, false) . '>' . esc_html__('— Default template —', 'freshet-pagesplus') . '</option>';
	foreach ( $templates as $file => $name ) {
		echo '<option value="' . esc_attr($file) . '"' . selected($file, $current, false) . '>' . esc_html($name) . '</option>';
	}
	echo '</select>';
}

/**
 * Apply the template filter. "default" means no explicit template: the meta is
 * missing, empty, or literally "default". Top-level hook, same reason as the
 * parent filter: the main edit.php query runs after load-edit.php.
 */
add_action('pre_get_posts', function (WP_Query $query) {
	global $pagenow;
	if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
		return;
	}
	if ( ! isset($_GET[FRESHET_PAGESPLUS_TEMPLATE_QV]) ) {
		return;
	}
	$tpl = sanitize_text_field( wp_unslash( (string) $_GET[FRESHET_PAGESPLUS_TEMPLATE_QV] ) );
	if ( '' === $tpl ) {
		return;
	}

	if ( 'default' === $tpl ) {
		$clause = [
			'relation' => 'OR',
			['key' => '_wp_page_template', 'compare' => 'NOT EXISTS'],
			['key' => '_wp_page_template', 'value' => ['', 'default'], 'compare' => 'IN'],
		];
	} else {
		$clause = ['key' => '_wp_page_template', 'value' => $tpl];
	}

	// Append instead of overwrite, in case another plugin already set one.
	$meta_query   = (array) $query->get('meta_query');
	$meta_query[] = $clause;
	$query->set('meta_query', $meta_query);
});

/**
 * Core clone routine, shared by the single row action and the bulk action.
 * Copies content, parent, menu order, taxonomy terms and meta into a new draft.
 *
 * @return int|WP_Error New post ID, or WP_Error on failure.
 */
function freshet_pagesplus_duplicate_post(int $source_id) {
	$post = get_post($source_id);
	if ( ! $post ) {
		return new WP_Error('freshet_pagesplus_no_source', __('Item not found.', 'freshet-pagesplus'));
	}

	// wp_slash(): wp_insert_post() expects slashed data and unslashes internally,
	// so raw DB values would lose their backslashes (JSON in content, code, etc.).
	$new_id = wp_insert_post( wp_slash([
		'comment_status' => $post->comment_status,
		'ping_status'    => $post->ping_status,
		'post_author'    => get_current_user_id(),
		'post_content'   => $post->post_content,
		'post_excerpt'   => $post->post_excerpt,
		'post_name'      => '',
		'post_parent'    => $post->post_parent,
		'post_password'  => $post->post_password,
		'post_status'    => 'draft',
		'post_title'     => $post->post_title . ' (Copy)',
		'post_type'      => $post->post_type,
		'to_ping'        => $post->to_ping,
		'menu_order'     => $post->menu_order,
	]), true);

	if ( is_wp_error($new_id) ) {
		return $new_id;
	}

	// Copy taxonomy terms.
	foreach ( get_object_taxonomies($post->post_type) as $taxonomy ) {
		$terms = wp_get_post_terms($source_id, $taxonomy, ['fields' => 'ids']);
		if ( ! is_wp_error($terms) ) {
			wp_set_object_terms($new_id, $terms, $taxonomy);
		}
	}

	// Copy post meta (skip old-slug history).
	foreach ( get_post_meta($source_id) as $meta_key => $meta_values ) {
		if ( '_wp_old_slug' === $meta_key ) {
			continue;
		}
		foreach ( $meta_values as $meta_value ) {
			// add_post_meta() also expects slashed key + value (it unslashes both).
			add_post_meta($new_id, wp_slash($meta_key), wp_slash(maybe_unserialize($meta_value)));
		}
	}

	return $new_id;
}

/**
 * "Duplicate" row action.
 */
function freshet_pagesplus_duplicate_link(array $actions, WP_Post $post): array {
	if ( ! current_user_can('edit_post', $post->ID) ) {
		return $actions;
	}
	$url = wp_nonce_url(
		admin_url('admin.php?action=' . FRESHET_PAGESPLUS_DUP_ACTION . '&post=' . $post->ID),
		FRESHET_PAGESPLUS_DUP_ACTION . '_' . $post->ID,
		'freshet_pagesplus_nonce'
	);
	$actions['freshet_pagesplus_duplicate'] = sprintf(
		'<a href="%s" rel="permalink">%s</a>',
		esc_url($url),
		esc_html__('Duplicate', 'freshet-pagesplus')
	);

	return $actions;
}

function freshet_pagesplus_duplicate_handler(): void {
	$post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
	if ( ! $post_id ) {
		wp_die(esc_html__('Missing post ID.', 'freshet-pagesplus'));
	}
	check_admin_referer(FRESHET_PAGESPLUS_DUP_ACTION . '_' . $post_id, 'freshet_pagesplus_nonce');
	if ( ! current_user_can('edit_post', $post_id) ) {
		wp_die(esc_html__('You are not allowed to duplicate this item.', 'freshet-pagesplus'));
	}

	$new_id = freshet_pagesplus_duplicate_post($post_id);
	if ( is_wp_error($new_id) ) {
		wp_die(esc_html(sprintf(
			/* translators: %s: the reason the duplicate failed. */
			__('Could not duplicate this item: %s', 'freshet-pagesplus'),
			$new_id->get_error_message()
		)));
	}

	wp_safe_redirect(admin_url('post.php?action=edit&post=' . $new_id));
	exit;
}
add_action('admin_action_' . FRESHET_PAGESPLUS_DUP_ACTION, 'freshet_pagesplus_duplicate_handler');

function freshet_pagesplus_register_bulk_duplicate(array $actions): array {
	$actions['freshet_pagesplus_duplicate'] = __('Duplicate', 'freshet-pagesplus');
	return $actions;
}

function freshet_pagesplus_handle_bulk_duplicate(string $redirect, string $action, array $post_ids): string {
	if ( 'freshet_pagesplus_duplicate' !== $action ) {
		return $redirect;
	}
	$done = 0;
	foreach ( $post_ids as $post_id ) {
		$post_id = (int) $post_id;
		if ( ! current_user_can('edit_post', $post_id) ) {
			continue;
		}
		if ( ! is_wp_error( freshet_pagesplus_duplicate_post($post_id) ) ) {
			$done++;
		}
	}

	return add_query_arg('freshet_pagesplus_duplicated', $done, $redirect);
}

function freshet_pagesplus_bulk_notice(): void {
	if ( ! isset($_GET['freshet_pagesplus_duplicated']) ) {
		return;
	}
	$count = (int) $_GET['freshet_pagesplus_duplicated'];
	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html( sprintf(
			/* translators: %s: number of duplicated items. */
			_n('%s item duplicated to draft.', '%s items duplicated to draft.', $count, 'freshet-pagesplus'),
			number_format_i18n($count)
		) )
	);
}

/**
 * Register the two src-less handles this plugin's inline CSS and JS attach to,
 * and add the stylesheet. Only ever reached from the load-edit.php wiring, so
 * both stay scoped to the list-table screens we enhance.
 *
 * The script handle is registered here but filled in the footer: the paths are
 * only known once the list table has rendered its titles (see
 * freshet_pagesplus_print_paths). With no inline script attached it prints
 * nothing at all.
 */
function freshet_pagesplus_enqueue_assets(): void {
	wp_register_style(FRESHET_PAGESPLUS_HANDLE, false, [], FRESHET_PAGESPLUS_VERSION);
	wp_enqueue_style(FRESHET_PAGESPLUS_HANDLE);
	wp_add_inline_style(FRESHET_PAGESPLUS_HANDLE, '
.column-title .freshet-pagesplus-path{display:block;margin:.25em 0 0;font-weight:400;font-size:12px;color:#646970;}
a.freshet-pagesplus-path{text-decoration:none;}
a.freshet-pagesplus-path:hover{text-decoration:underline;color:#2271b1;}
.freshet-pagesplus-sub{color:#646970;}
.fixed .column-' . esc_attr(FRESHET_PAGESPLUS_COL_MODIFIED) . ',.fixed .column-' . esc_attr(FRESHET_PAGESPLUS_COL_PARENT) . ',.fixed .column-' . esc_attr(FRESHET_PAGESPLUS_COL_TEMPLATE) . '{width:12%;}
');

	wp_register_script(FRESHET_PAGESPLUS_HANDLE, false, [], FRESHET_PAGESPLUS_VERSION, true);
	wp_enqueue_script(FRESHET_PAGESPLUS_HANDLE);
}
