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
			'Database: db-query is read-only; write `{prefix}` for the table prefix (e.g. SELECT ID FROM {prefix}posts). Files: read-/write-wp-content-file (paths relative to wp-content); writes blocked by DISALLOW_FILE_EDIT need the owner opt-in (verify-connection says so).',
			'After changes: purge-caches, then verify on the real page with ?nocache=<timestamp> and a screenshot before saying done.',
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
			),
		);
	}
}
