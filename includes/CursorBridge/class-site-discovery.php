<?php
/**
 * Site discovery for "add one more X like the others" tasks.
 *
 * Every site has its own way of placing content: a shop is a page, a tile on the home page,
 * a marker in a map plugin, a menu entry. Nobody lists these places. The reliable way to
 * find them: take the newest existing sibling (find-similar-pages), then list everything that
 * points at it (find-references). Each reference is a place the new item must be added too.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Site_Discovery {

	private const MAX_PAGES = 300;

	/**
	 * Every place that links to the post: Elementor elements (URL or dynamic internal-url
	 * tag with its id), post_content, other post meta (plugin data), menus, options.
	 *
	 * @return array<string, mixed>
	 */
	public static function find_references( int $post_id ): array {
		global $wpdb;
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}
		$url  = (string) get_permalink( $post_id );
		$path = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $path || '/' === $path ) {
			return array( 'ok' => false, 'error' => 'no_path', 'message' => 'The front page has no own path to search for.' );
		}
		$slash_escaped = str_replace( '/', '\\/', $path );
		// Dynamic tag settings are url-encoded JSON: {"type":"post","post_id":"123"} → %22post_id%22%3A%22123%22 (quotes optional).
		$tag_quoted = '%22post_id%22%3A%22' . $post_id . '%22';
		$tag_bare   = '%22post_id%22%3A' . $post_id . '%7D';
		$tag_comma  = '%22post_id%22%3A' . $post_id . '%2C';
		$like       = static fn( string $s ): string => '%' . $wpdb->esc_like( $s ) . '%';

		$elementor = array();
		$rows      = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT pm.post_id, p.post_title, p.post_type, p.post_status FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = '_elementor_data' AND p.post_type <> 'revision' AND pm.post_id <> %d
				AND ( pm.meta_value LIKE %s OR pm.meta_value LIKE %s OR pm.meta_value LIKE %s OR pm.meta_value LIKE %s OR pm.meta_value LIKE %s ) LIMIT 50",
				$post_id,
				$like( $tag_quoted ),
				$like( $tag_bare ),
				$like( $tag_comma ),
				$like( $slash_escaped . '\\/' ),
				$like( $path . '/' )
			)
		);
		foreach ( (array) $rows as $row ) {
			$data  = json_decode( (string) get_post_meta( (int) $row->post_id, '_elementor_data', true ), true );
			$hits  = array();
			self::walk(
				is_array( $data ) ? $data : array(),
				static function ( array $el, array $parents ) use ( &$hits, $tag_quoted, $tag_bare, $tag_comma, $path ): void {
					$json = (string) wp_json_encode( $el['settings'] ?? array(), JSON_UNESCAPED_SLASHES );
					$how  = false !== strpos( $json, $tag_quoted ) || false !== strpos( $json, $tag_bare ) || false !== strpos( $json, $tag_comma ) ? 'dynamic_tag' : ( false !== strpos( $json, $path . '/' ) ? 'url' : '' );
					if ( '' !== $how ) {
						$hits[] = array(
							'element_id' => (string) ( $el['id'] ?? '' ),
							'type'       => (string) ( $el['widgetType'] ?? ( $el['elType'] ?? '' ) ),
							'via'        => $how,
							'parents'    => $parents, // outermost first, with navigator titles — shows the row/grid it sits in
						);
					}
				}
			);
			$elementor[] = array( 'post_id' => (int) $row->post_id, 'title' => $row->post_title, 'post_type' => $row->post_type, 'status' => $row->post_status, 'elements' => $hits );
		}

		// ponytail: LIKE '%…%' scans postmeta/options fully — seconds on 100k+ rows. On-demand admin tool; add a meta_key filter if that ever hurts.
		$meta = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_key, p.post_title, p.post_type FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key NOT IN ('_elementor_data','_elementor_css','_elementor_element_cache','_elementor_page_assets') AND pm.meta_key NOT LIKE %s AND pm.meta_key NOT LIKE %s
				AND p.post_type <> 'revision' AND pm.post_id <> %d AND ( pm.meta_value LIKE %s OR pm.meta_value LIKE %s ) LIMIT 50",
				$wpdb->esc_like( Elementor_Editor::BACKUP_PREFIX ) . '%',
				$wpdb->esc_like( Content_Tools::META_BACKUP_PREFIX ) . '%',
				$post_id,
				$like( $path . '/' ),
				$like( $slash_escaped . '\\/' )
			),
			ARRAY_A
		);

		$content = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID AS post_id, post_title, post_type FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment','nav_menu_item') AND post_status IN ('publish','private','draft') AND ID <> %d AND post_content LIKE %s LIMIT 30",
				$post_id,
				$like( $path . '/' )
			),
			ARRAY_A
		);

		$menus = array();
		$items = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_menu_item_object_id' AND meta_value = %s",
				(string) $post_id
			)
		);
		foreach ( (array) $items as $item_id ) {
			$terms   = wp_get_object_terms( (int) $item_id, 'nav_menu', array( 'fields' => 'names' ) );
			$menus[] = array( 'menu_item_id' => (int) $item_id, 'menu' => is_array( $terms ) ? implode( ', ', $terms ) : '' );
		}

		$options = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND ( option_value LIKE %s OR option_value LIKE %s ) LIMIT 20",
				$wpdb->esc_like( '_transient' ) . '%',
				$like( $path . '/' ),
				$like( $slash_escaped . '\\/' )
			)
		);

		return array(
			'ok'           => true,
			'post_id'      => $post_id,
			'url'          => $url,
			'elementor'    => $elementor,
			'post_meta'    => $meta,
			'post_content' => $content,
			'menus'        => $menus,
			'options'      => $options,
			'next'         => 'Adding a sibling (new shop, new service page)? Every place listed here is where the new item has to appear too. Elementor hit → elementor-clone-element of its row/card; post_meta hit (e.g. map plugin) → get-post-meta/set-post-meta; menu hit → add a menu item. Places with hard-coded text (e.g. a footer list of city names) do not show up here — search them with find-content for the title of the sibling.',
		);
	}

	/**
	 * Pages built from the same template: same Elementor structure (widget types in order).
	 * Newest first — the newest sibling is usually what the site owner copies by hand.
	 *
	 * @return array<string, mixed>
	 */
	public static function find_similar_pages( int $post_id, int $min_similarity = 80 ): array {
		global $wpdb;
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}
		$base = self::signature( (string) get_post_meta( $post_id, '_elementor_data', true ) );
		if ( '' === $base ) {
			return array( 'ok' => false, 'error' => 'no_elementor_data' );
		}
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_elementor_data'
				WHERE p.post_type = %s AND p.post_status IN ('publish','private','draft') AND p.ID <> %d ORDER BY p.post_date DESC LIMIT %d",
				$post->post_type,
				$post_id,
				self::MAX_PAGES
			)
		);
		$similar = array();
		foreach ( (array) $ids as $id ) {
			$sig = self::signature( (string) get_post_meta( (int) $id, '_elementor_data', true ) );
			if ( '' === $sig ) {
				continue;
			}
			similar_text( $base, $sig, $percent );
			if ( $percent >= $min_similarity ) {
				$similar[] = array(
					'post_id'    => (int) $id,
					'title'      => get_the_title( (int) $id ),
					'url'        => get_permalink( (int) $id ),
					'date'       => get_post_field( 'post_date', (int) $id ),
					'similarity' => (int) round( $percent ),
				);
			}
		}
		return array(
			'ok'      => true,
			'post_id' => $post_id,
			'count'   => count( $similar ),
			'similar' => $similar,
			'next'    => 'Template family found. Use the newest one as the source for elementor-duplicate-post, then find-references on it to see where siblings are listed (home page grid, map, menu).',
		);
	}

	/** Widget/container types in document order — the "shape" of a page, without content. */
	private static function signature( string $raw ): string {
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return '';
		}
		$types = array();
		self::walk(
			$data,
			static function ( array $el ) use ( &$types ): void {
				$types[] = (string) ( $el['widgetType'] ?? ( $el['elType'] ?? '?' ) );
			}
		);
		return implode( '>', $types );
	}

	/**
	 * @param array<int, mixed> $elements
	 * @param list<string>      $parents
	 */
	private static function walk( array $elements, callable $fn, array $parents = array() ): void {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$fn( $el, $parents );
			if ( is_array( $el['elements'] ?? null ) ) {
				$label = (string) ( $el['id'] ?? '' ) . ( ! empty( $el['settings']['_title'] ) ? ' "' . $el['settings']['_title'] . '"' : '' );
				self::walk( $el['elements'], $fn, array_merge( $parents, array( $label ) ) );
			}
		}
	}
}
