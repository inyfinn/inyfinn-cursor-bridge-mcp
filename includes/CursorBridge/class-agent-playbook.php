<?php
/**
 * What an AI agent needs to know to use this bridge well. The short version is
 * sent as MCP `instructions` in the initialize response, so every client shows
 * it to its agent on connect; the full version is the get-agent-playbook ability.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Agent_Playbook {

	/**
	 * Live facts about this site — cheap calls only, this runs on every MCP initialize.
	 *
	 * @return array<string, mixed>
	 */
	public static function site_facts(): array {
		global $wpdb, $wp_version;

		$active = (array) get_option( 'active_plugins', array() );
		$has    = static function ( string $needle ) use ( $active ): bool {
			foreach ( $active as $plugin ) {
				if ( false !== strpos( (string) $plugin, $needle ) ) {
					return true;
				}
			}
			return false;
		};

		$caches = array();
		foreach ( array( 'litespeed-cache' => 'LiteSpeed', 'wp-rocket' => 'WP Rocket', 'w3-total-cache' => 'W3 Total Cache', 'wp-super-cache' => 'WP Super Cache' ) as $slug => $label ) {
			if ( $has( $slug . '/' ) ) {
				$caches[] = $label;
			}
		}

		$theme = wp_get_theme();

		return array(
			'site_url'       => home_url( '/' ),
			'wp_version'     => (string) $wp_version,
			'table_prefix'   => $wpdb->prefix,
			'theme'          => $theme->get_stylesheet(),
			'child_theme'    => is_child_theme(),
			'parent_theme'   => $theme->get_template(),
			'builder'        => $has( 'elementor/' ) ? 'elementor' : 'block-editor/classic',
			'elementor_pro'  => $has( 'elementor-pro/' ),
			'woocommerce'    => $has( 'woocommerce/' ),
			'multilingual'   => $has( 'polylang' ) ? 'polylang' : ( $has( 'sitepress-multilingual-cms' ) ? 'wpml' : '' ),
			'page_caches'    => $caches,
			'bridge_version' => defined( 'INYFINN_CURSOR_BRIDGE_MCP_VERSION' ) ? INYFINN_CURSOR_BRIDGE_MCP_VERSION : '',
		);
	}

	/**
	 * Short text for MCP initialize `instructions`.
	 */
	public static function instructions(): string {
		$f = self::site_facts();

		$lines = array(
			'Inyfinn Cursor Bridge MCP — full access to this WordPress site (' . $f['site_url'] . ') from the agent: content, Elementor, database (read), wp-content files, caches.',
			'Site: WP ' . $f['wp_version'] . ', table prefix `' . $f['table_prefix'] . '`, theme `' . $f['theme'] . '`' . ( $f['child_theme'] ? ' (child of `' . $f['parent_theme'] . '` — edit only the child)' : '' ) . ', builder ' . $f['builder'] . ( $f['page_caches'] ? ', page cache: ' . implode( ', ', $f['page_caches'] ) : '' ) . ( $f['multilingual'] ? ', languages: ' . $f['multilingual'] . ' (each language is a separate post)' : '' ) . '.',
			'Tools: call mcp-adapter-execute-ability with ability_name `cursor-bridge/<name>`. Unsure of parameters → mcp-adapter-get-ability-info first. Full rules: cursor-bridge/get-agent-playbook.',
			'Start: cursor-bridge/verify-connection (ok:true = ready). Site overview: get-site-manifest, list-posts, list-plugins.',
			'Find where a visible text lives: cursor-bridge/find-content {text} — returns Elementor element ids, post_content, meta, options and theme files.',
			'Elementor pages: elementor-outline {post_id} → elementor-get-element → elementor-patch-element {post_id, element_id, settings, dry_run}. It skips revisions, backs up, verifies and purges cache for you. Undo: elementor-list-backups / elementor-restore-backup. Never write _elementor_data with raw SQL.',
			'Hide, do not delete: patch settings hide_desktop / hide_tablet / hide_mobile = "hidden-desktop" / "hidden-tablet" / "hidden-mobile".',
			'New page from an existing one (e.g. a new shop): elementor-duplicate-post with patches. Copy a section/row: elementor-clone-element (also from another page). Photos from URLs: media-sideload. Plugin data in meta (maps, sliders): get-post-meta then set-post-meta. No temporary PHP on the server needed.',
			'Database: db-query is read-only; write `{prefix}` for the table prefix (e.g. SELECT ID FROM {prefix}posts). Files: read-/write-wp-content-file (paths relative to wp-content); writes blocked by DISALLOW_FILE_EDIT → repair {action:"file_edit"} (install/update does it automatically).',
			'After changes: purge-caches, then verify on the real page with ?nocache=<timestamp> and a screenshot before saying done.',
			'Bare HTML "400 Bad Request" on write-wp-content-file = hosting firewall reading the body: resend as content_base64. Remove probe/temporary files with delete-wp-content-file (goes to the bridge trash).',
		);

		return implode( "\n", $lines );
	}

	/**
	 * Full playbook for the get-agent-playbook ability.
	 *
	 * @return array<string, mixed>
	 */
	public static function full(): array {
		return array(
			'site'      => self::site_facts(),
			'workflows' => array(
				'connect'         => array(
					'cursor-bridge/verify-connection → ok:true and all layers true.',
					'ok:false → follow next_steps (one per failed layer) and read rest_firewall; cursor-bridge/repair {action} fixes one component, run-auto-setup reruns the whole install.',
				),
				'change_text'     => array(
					'find-content {text: "exact visible words"} — the same text often lives in 2–3 places; change the one that renders.',
					'Elementor hit → elementor-get-element {post_id, element_id} → elementor-patch-element with only the keys you change (each key replaces its whole value).',
					'post_content hit (Gutenberg/classic) → core REST /wp/v2/posts/<id> or wp-admin; file hit → read-wp-content-file then write-wp-content-file.',
				),
				'change_layout'   => array(
					'elementor-outline {post_id} shows the tree with ids, text previews and hidden_on.',
					'Use dry_run:true first to see before/after of the settings.',
					'Responsive values use suffixes: padding / padding_tablet / padding_mobile. Dimension values are objects: {"unit":"px","top":"10","right":"10","bottom":"10","left":"10","isLinked":true}.',
					'Global styles live in the Elementor kit (elementor_active_kit option → post meta _elementor_page_settings).',
				),
				'css'             => array(
					'Custom CSS goes to the child theme (write-wp-content-file themes/<child>/…); never edit the parent theme or core.',
					'Elementor rules use element ids and CSS variables — if your rule does not apply, measure specificity instead of adding !important layers.',
				),
				'add_from_template' => array(
					'Find the template and the ids to change: elementor-outline {post_id} of the page the user copies by hand (e.g. the newest shop page).',
					'media-sideload {urls, name} gives attachment ids/urls. Gallery value: [{"id":ID,"url":"URL"}]; image/background: {"id":ID,"url":"URL","size":"","alt":"","source":"library"}.',
					'elementor-duplicate-post {post_id, title, slug, status, patches:{"<source id>":{...}}} — patches go by SOURCE ids, the copy gets new ids (id_map).',
					'Lists/grids on other pages (home page tiles): elementor-clone-element the last row after itself, fill the first slots via patches, hide empty slots (do not delete them — the user fills them later). Link to the new page: copy the __dynamic__ internal-url tag of an existing button and change post_id.',
					'Plugin data (MapGeo markers, sliders, ACF): get-post-meta, append to the array exactly like the existing items, set-post-meta. The old value is backed up.',
				),
				'environment'     => array(
					'Shared hosting FTP often allows few parallel connections (home.pl: 10). Prefer the bridge over the mounted drive; one file operation at a time.',
					'Plugin active but no /wp-json/mcp route and no cursor-bridge abilities: someone may have put "return;" at the top of the main plugin file to stop it. Update the plugin from its ZIP instead of editing that line.',
					'Keep .env with database/app passwords outside the web root — a .env in public_html can be downloaded.',
				),
				'verify'          => array(
					'purge-caches after writes (Elementor CSS, element cache, object cache, page cache plugins).',
					'Open the page with ?nocache=<timestamp>, scroll through it (entrance animations), check 375 / 768 / 1440 px, look at the screenshot.',
					'Hosting may answer HTTP 429 to rapid requests — space reloads out; a tiny PNG is an error page, not proof.',
				),
			),
			'rules'     => array(
				'Never write to post_type=revision; elementor-* abilities refuse it for you.',
				'Hide instead of delete (hide_desktop / hide_tablet / hide_mobile), so the user can restore with one click.',
				'Do not invent content (addresses, hours, phones, reviews) — use only what the site or the user provides.',
				'Do not run the theme demo import on a live site.',
				'Do not change slugs without a 301 redirect.',
				'Irreversible or bulk change → ask the user for a full backup (All-in-One WP Migration / hosting panel) first.',
				'Multilingual sites: every language version is its own post — change all of them.',
			),
			'abilities' => array(
				'verify-connection'          => 'Is the bridge ready (WordPress, DB, files, MCP REST, credentials)?',
				'get-site-manifest'          => 'Theme, plugins, versions — no secrets.',
				'list-posts'                 => 'Posts/pages by type and status.',
				'find-content'               => 'Where does this text live?',
				'elementor-outline'          => 'Tree of one Elementor page.',
				'elementor-get-element'      => 'Full settings of one element.',
				'elementor-patch-element'    => 'Change settings of one element safely.',
				'elementor-list-backups'     => 'Backups made before each write (last 5).',
				'elementor-restore-backup'   => 'Undo a write.',
				'purge-caches'               => 'Flush every cache layer the site has.',
				'db-query'                   => 'Read-only SQL; {prefix} = table prefix.',
				'db-list-tables / db-describe-table' => 'Schema.',
				'read-wp-content-file / write-wp-content-file / list-wp-content-dir' => 'Files under wp-content.',
				'update-post-meta'           => 'str_replace in one meta value (Elementor data goes through the same safe path).',
				'health-check / repair / run-auto-setup' => 'Fix the bridge itself.',
				'wc-list-products / wc-list-orders' => 'WooCommerce.',
				'elementor-duplicate-post'   => 'New page from a template page (all meta, new ids, patches).',
				'elementor-clone-element'    => 'Copy/paste an element, also from another page.',
				'media-sideload'             => 'Photos from URLs into the media library.',
				'get-post-meta / set-post-meta' => 'Structured meta (serialized arrays) with backup.',
				'db-write-probe'             => 'Proof that database writes work.',
				'delete-wp-content-file'     => 'Reversible file removal (bridge trash). After the first connection: repair {action:"remove_setup_file"}.',
			),
		);
	}
}
