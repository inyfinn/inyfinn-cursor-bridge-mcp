<?php
/**
 * Safe Elementor editing for agents: element-level reads and patches with
 * backup, verification and cache purge. Every write path for _elementor_data
 * in this plugin goes through save().
 *
 * Rules baked in (each one comes from a real incident):
 * - never write to a revision — Elementor copies it back onto the live page;
 * - back up the old value before writing, keep the last MAX_BACKUPS;
 * - write with wp_slash( wp_json_encode() ) and re-read to verify the JSON;
 * - refuse writes that shrink the document by more than 20%;
 * - purge element cache and post CSS after writing.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Elementor_Editor {

	public const BACKUP_PREFIX = '_inyfinn_el_backup_';
	private const MAX_BACKUPS  = 5;

	/** Settings keys that usually carry visible text — used for outline previews. */
	private const TEXT_KEYS = array( 'title', 'editor', 'text', 'title_text', 'description_text', 'button_text', 'heading', 'caption', 'html', 'shortcode', 'tab_title', 'alert_title', 'testimonial_content', 'inner_text', 'content', 'sub_title', 'subtitle', 'description', 'tab_content', 'item_text' );

	/**
	 * @return array{raw:string,data:array<int,mixed>,post:\WP_Post}|\WP_Error
	 */
	public static function load( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'not_found', 'Post ' . $post_id . ' not found.' );
		}
		if ( 'revision' === $post->post_type ) {
			return new \WP_Error(
				'revision',
				'Post ' . $post_id . ' is a revision/autosave. Edit the parent post ' . $post->post_parent . ' — writing to a revision silently reverts the live page.'
			);
		}
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return new \WP_Error( 'no_elementor_data', 'Post ' . $post_id . ' has no _elementor_data (not built with Elementor). Use post_content instead.' );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'json_invalid', 'Stored _elementor_data is not valid JSON: ' . json_last_error_msg() );
		}
		return array(
			'raw'  => $raw,
			'data' => $data,
			'post' => $post,
		);
	}

	/**
	 * Flat, readable tree of the page: id, type, depth, parent, text preview, visibility.
	 *
	 * @return array<string, mixed>
	 */
	public static function outline( int $post_id ): array {
		$doc = self::load( $post_id );
		if ( is_wp_error( $doc ) ) {
			return self::error( $doc );
		}
		$rows = array();
		self::walk(
			$doc['data'],
			static function ( array $el, int $depth, string $parent ) use ( &$rows ): void {
				$settings = is_array( $el['settings'] ?? null ) ? $el['settings'] : array();
				$row      = array(
					'id'     => (string) ( $el['id'] ?? '' ),
					'type'   => (string) ( $el['widgetType'] ?? ( $el['elType'] ?? '' ) ),
					'depth'  => $depth,
					'parent' => $parent,
				);
				$text = self::preview( $settings );
				if ( '' !== $text ) {
					$row['text'] = $text;
				}
				$hidden = self::hidden_on( $settings );
				if ( $hidden ) {
					$row['hidden_on'] = $hidden;
				}
				if ( ! empty( $settings['_element_id'] ) ) {
					$row['css_id'] = (string) $settings['_element_id'];
				}
				if ( ! empty( $settings['_css_classes'] ) || ! empty( $settings['css_classes'] ) ) {
					$row['classes'] = (string) ( $settings['_css_classes'] ?? $settings['css_classes'] );
				}
				$rows[] = $row;
			}
		);
		return array(
			'ok'       => true,
			'post_id'  => $post_id,
			'title'    => $doc['post']->post_title,
			'url'      => get_permalink( $post_id ),
			'bytes'    => strlen( $doc['raw'] ),
			'count'    => count( $rows ),
			'elements' => $rows,
			'next'     => 'cursor-bridge/elementor-get-element {post_id, element_id} for full settings, then elementor-patch-element.',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_element( int $post_id, string $element_id ): array {
		$doc = self::load( $post_id );
		if ( is_wp_error( $doc ) ) {
			return self::error( $doc );
		}
		$found = null;
		self::walk(
			$doc['data'],
			static function ( array $el ) use ( $element_id, &$found ): void {
				if ( null === $found && (string) ( $el['id'] ?? '' ) === $element_id ) {
					$found = $el;
				}
			}
		);
		if ( null === $found ) {
			return self::not_found( $element_id );
		}
		$children = array();
		foreach ( (array) ( $found['elements'] ?? array() ) as $child ) {
			if ( is_array( $child ) ) {
				$children[] = array(
					'id'   => (string) ( $child['id'] ?? '' ),
					'type' => (string) ( $child['widgetType'] ?? ( $child['elType'] ?? '' ) ),
				);
			}
		}
		unset( $found['elements'] );
		$found['children'] = $children;
		return array(
			'ok'      => true,
			'post_id' => $post_id,
			'element' => $found,
		);
	}

	/**
	 * Merge settings into one element (shallow: each given key replaces the whole value).
	 *
	 * @param array<string, mixed> $settings
	 * @param list<string>         $unset
	 * @return array<string, mixed>
	 */
	public static function patch_element( int $post_id, string $element_id, array $settings, array $unset = array(), bool $dry_run = false ): array {
		$doc = self::load( $post_id );
		if ( is_wp_error( $doc ) ) {
			return self::error( $doc );
		}
		if ( array() === $settings && array() === $unset ) {
			return array( 'ok' => false, 'error' => 'nothing_to_change', 'message' => 'Pass settings and/or unset.' );
		}
		$unset  = array_values( array_map( 'strval', $unset ) );
		$data   = $doc['data'];
		$before = array();
		$after  = array();
		$found  = self::patch_in(
			$data,
			$element_id,
			static function ( array &$el ) use ( $settings, $unset, &$before, &$after ): void {
				$current = is_array( $el['settings'] ?? null ) ? $el['settings'] : array();
				$before  = array_intersect_key( $current, $settings + array_flip( $unset ) );
				foreach ( $unset as $key ) {
					unset( $current[ $key ] );
				}
				$el['settings'] = array_merge( $current, $settings );
				$after          = array_intersect_key( $el['settings'], $settings );
			}
		);
		if ( ! $found ) {
			return self::not_found( $element_id );
		}
		if ( $dry_run ) {
			return array(
				'ok'         => true,
				'dry_run'    => true,
				'element_id' => $element_id,
				'before'     => $before,
				'after'      => $after,
				'unset'      => $unset,
			);
		}
		return array_merge(
			self::save( $post_id, $doc['raw'], $data, true ),
			array(
				'element_id' => $element_id,
				'before'     => $before,
				'after'      => $after,
				'unset'      => $unset,
			)
		);
	}

	/**
	 * Replace the whole _elementor_data with a raw JSON string (used by update-post-meta).
	 *
	 * @return array<string, mixed>
	 */
	public static function save_raw( int $post_id, string $new_raw ): array {
		$doc = self::load( $post_id );
		if ( is_wp_error( $doc ) ) {
			return self::error( $doc );
		}
		$data = json_decode( $new_raw, true );
		if ( ! is_array( $data ) ) {
			return array( 'ok' => false, 'error' => 'json_invalid_after', 'detail' => json_last_error_msg() );
		}
		return self::save( $post_id, $doc['raw'], $data, true );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function list_backups( int $post_id ): array {
		$out = array();
		foreach ( self::backup_keys( $post_id ) as $key ) {
			$raw   = get_post_meta( $post_id, $key, true );
			$out[] = array(
				'backup_key' => $key,
				'bytes'      => is_string( $raw ) ? strlen( $raw ) : 0,
			);
		}
		return array(
			'ok'      => true,
			'post_id' => $post_id,
			'current_bytes' => strlen( (string) get_post_meta( $post_id, '_elementor_data', true ) ),
			'backups' => $out,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function restore_backup( int $post_id, string $backup_key ): array {
		if ( 0 !== strpos( $backup_key, self::BACKUP_PREFIX ) ) {
			return array( 'ok' => false, 'error' => 'invalid_backup_key', 'message' => 'Use a key from cursor-bridge/elementor-list-backups.' );
		}
		$doc = self::load( $post_id );
		if ( is_wp_error( $doc ) ) {
			return self::error( $doc );
		}
		$raw  = get_post_meta( $post_id, $backup_key, true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) ) {
			return array( 'ok' => false, 'error' => 'backup_invalid', 'message' => 'Backup missing or not valid JSON.' );
		}
		// Current state goes to a new backup first, so a restore can be undone too.
		return array_merge( self::save( $post_id, $doc['raw'], $data, false ), array( 'restored_from' => $backup_key ) );
	}

	/** Meta that belongs to one post only and must not travel with a duplicate. */
	private const DUPLICATE_SKIP_META = array( '_edit_lock', '_edit_last', '_elementor_data', '_elementor_css', '_elementor_element_cache', '_elementor_page_assets', '_elementor_data_bckp', '_wp_old_slug', '_wp_trash_meta_status', '_wp_trash_meta_time' );

	/**
	 * Copy one element (with all children and settings) and insert the copy — the same as
	 * copy/paste in the editor. Source may be another post ("copy the container from the
	 * shop page, paste on the home page"). Patches are keyed by SOURCE element ids and are
	 * applied to the copy before it gets new ids.
	 *
	 * @param array<string, mixed> $opts source_post_id, after_id, parent_id, position, patches, dry_run
	 * @return array<string, mixed>
	 */
	public static function clone_element( int $post_id, string $element_id, array $opts = array() ): array {
		$doc = self::load( $post_id );
		if ( is_wp_error( $doc ) ) {
			return self::error( $doc );
		}
		$source_id = (int) ( $opts['source_post_id'] ?? 0 );
		$same_post = ! $source_id || $source_id === $post_id;
		$source    = $same_post ? $doc : self::load( $source_id );
		if ( is_wp_error( $source ) ) {
			return self::error( $source );
		}
		$copy = Elementor_Tree::find( $source['data'], $element_id );
		if ( null === $copy ) {
			return self::not_found( $element_id );
		}

		$wrapped = array( $copy );
		$missing = Elementor_Tree::apply_patches( $wrapped, is_array( $opts['patches'] ?? null ) ? $opts['patches'] : array() );
		if ( $missing ) {
			return array( 'ok' => false, 'error' => 'patch_target_not_found', 'missing' => $missing, 'message' => 'Patch keys must be ids of the SOURCE element or its children (see elementor-outline of the source post).' );
		}

		$data = $doc['data'];
		$used = Elementor_Tree::collect_ids( $data ) + Elementor_Tree::collect_ids( $source['data'] );
		$map  = array();
		$new  = Elementor_Tree::reid( $wrapped[0], $used, $map );

		$after  = (string) ( $opts['after_id'] ?? '' );
		$parent = (string) ( $opts['parent_id'] ?? '' );
		if ( '' === $after && '' === $parent && $same_post ) {
			$after = $element_id; // default: paste right after the original
		}
		$inserted = '' !== $after
			? Elementor_Tree::insert_after( $data, $after, $new )
			: Elementor_Tree::insert_into( $data, $parent, $new, (int) ( $opts['position'] ?? -1 ) );
		if ( ! $inserted ) {
			return self::not_found( '' !== $after ? $after : $parent );
		}

		$result = array(
			'new_id' => $new['id'],
			'id_map' => $map,
			'next'   => 'id_map = source id → id in the copy. Change the copy with elementor-patch-element; hide unused slots with hide_desktop/hide_tablet/hide_mobile instead of deleting them.',
		);
		if ( ! empty( $opts['dry_run'] ) ) {
			return array_merge( array( 'ok' => true, 'dry_run' => true, 'before_bytes' => strlen( $doc['raw'] ), 'after_bytes' => strlen( (string) wp_json_encode( $data ) ) ), $result );
		}
		return array_merge( self::save( $post_id, $doc['raw'], $data, true ), $result );
	}

	/**
	 * Duplicate an Elementor page/post (like the Duplicate Page plugin): all meta except the
	 * per-post caches, fresh element ids, optional element patches (keyed by SOURCE ids).
	 *
	 * @param array<string, mixed> $opts title, slug, status (draft|publish|private), patches
	 * @return array<string, mixed>
	 */
	public static function duplicate_post( int $post_id, array $opts = array() ): array {
		$doc = self::load( $post_id );
		if ( is_wp_error( $doc ) ) {
			return self::error( $doc );
		}
		$data    = $doc['data'];
		$missing = Elementor_Tree::apply_patches( $data, is_array( $opts['patches'] ?? null ) ? $opts['patches'] : array() );
		if ( $missing ) {
			return array( 'ok' => false, 'error' => 'patch_target_not_found', 'missing' => $missing, 'message' => 'Nothing was created. Patch keys must be element ids of the source post.' );
		}
		$used = array();
		$map  = array();
		foreach ( $data as $i => $el ) {
			if ( is_array( $el ) ) {
				$data[ $i ] = Elementor_Tree::reid( $el, $used, $map );
			}
		}

		$src    = $doc['post'];
		$status = in_array( $opts['status'] ?? '', array( 'draft', 'publish', 'private', 'pending' ), true ) ? (string) $opts['status'] : 'draft';
		$new_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => $src->post_type,
					'post_status'    => $status,
					'post_title'     => (string) ( $opts['title'] ?? $src->post_title . ' (kopia)' ),
					'post_name'      => sanitize_title( (string) ( $opts['slug'] ?? '' ) ),
					'post_author'    => get_current_user_id() ? get_current_user_id() : (int) $src->post_author,
					'post_content'   => '', // Elementor renders from _elementor_data; old text here would leak into search/RSS.
					'post_excerpt'   => '',
					'post_parent'    => (int) $src->post_parent,
					'menu_order'     => (int) $src->menu_order,
					'comment_status' => $src->comment_status,
					'ping_status'    => $src->ping_status,
				)
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			return self::error( $new_id );
		}

		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			if ( in_array( $key, self::DUPLICATE_SKIP_META, true ) || 0 === strpos( (string) $key, self::BACKUP_PREFIX ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				add_post_meta( $new_id, (string) $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
		foreach ( get_object_taxonomies( $src->post_type ) as $tax ) {
			$terms = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'ids' ) );
			if ( is_array( $terms ) && $terms ) {
				wp_set_object_terms( $new_id, $terms, $tax );
			}
		}

		$json = (string) wp_json_encode( $data );
		update_post_meta( $new_id, '_elementor_data', wp_slash( $json ) );
		$stored = (string) get_post_meta( $new_id, '_elementor_data', true );
		$slug   = (string) get_post_field( 'post_name', $new_id );

		return array(
			'ok'           => is_array( json_decode( $stored, true ) ),
			'post_id'      => $new_id,
			'status'       => $status,
			'slug'         => $slug,
			'slug_changed' => isset( $opts['slug'] ) && '' !== (string) $opts['slug'] && sanitize_title( (string) $opts['slug'] ) !== $slug,
			'url'          => get_permalink( $new_id ),
			'source_bytes' => strlen( $doc['raw'] ),
			'bytes'        => strlen( $stored ),
			'id_map'       => $map,
			'purged'       => self::purge_post( $new_id ),
			'next'         => 'Check the copy: elementor-outline {post_id}. Open the url with ?nocache=<timestamp> and take a screenshot. Draft pages are visible only when logged in.',
		);
	}

	/**
	 * Find where a visible text really lives: Elementor elements, post_content,
	 * other post meta, options and files of the active theme / mu-plugins.
	 *
	 * @return array<string, mixed>
	 */
	public static function find_content( string $needle, int $limit = 20 ): array {
		global $wpdb;

		$needle = trim( $needle );
		if ( self::strlen( $needle ) < 3 ) {
			return array( 'ok' => false, 'error' => 'needle_too_short', 'message' => 'Give at least 3 characters.' );
		}
		$limit = max( 1, min( 50, $limit ) );

		// Elementor stores JSON with \uXXXX and \/ escapes; spaces may be &nbsp; or tags. Match words in order.
		$encoded  = substr( (string) wp_json_encode( $needle ), 1, -1 );
		$patterns = array_unique( array( self::like_words( $needle ), self::like_words( $encoded ) ) );
		$where    = static function ( string $column ) use ( $wpdb, $patterns ): string {
			$parts = array();
			foreach ( $patterns as $pattern ) {
				$parts[] = $wpdb->prepare( "{$column} LIKE %s", $pattern ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			return '(' . implode( ' OR ', $parts ) . ')';
		};

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$el_posts = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_type, p.post_status FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
			WHERE m.meta_key = '_elementor_data' AND p.post_type <> 'revision' AND " . $where( 'm.meta_value' ) . ' LIMIT ' . (int) $limit,
			ARRAY_A
		);
		$elementor = array();
		foreach ( (array) $el_posts as $row ) {
			$doc = self::load( (int) $row['ID'] );
			$hits = array();
			if ( ! is_wp_error( $doc ) ) {
				self::walk(
					$doc['data'],
					static function ( array $el ) use ( $needle, &$hits ): void {
						foreach ( self::flatten( is_array( $el['settings'] ?? null ) ? $el['settings'] : array() ) as $key => $value ) {
							if ( false !== self::stripos( self::normalize( $value ), self::normalize( $needle ) ) ) {
								$hits[] = array(
									'element_id' => (string) ( $el['id'] ?? '' ),
									'type'       => (string) ( $el['widgetType'] ?? ( $el['elType'] ?? '' ) ),
									'setting'    => $key,
								);
							}
						}
					}
				);
			}
			$row['elements'] = $hits;
			$elementor[]     = $row;
		}

		$content = $wpdb->get_results(
			"SELECT ID, post_title, post_type, post_status FROM {$wpdb->posts}
			WHERE post_type NOT IN ('revision') AND " . $where( 'post_content' ) . ' LIMIT ' . (int) $limit,
			ARRAY_A
		);
		$meta = $wpdb->get_results(
			"SELECT m.post_id, m.meta_key, p.post_type FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
			WHERE p.post_type <> 'revision' AND m.meta_key NOT IN ('_elementor_data', '_elementor_element_cache', '_elementor_css')
			AND m.meta_key NOT LIKE '%backup%' AND " . $where( 'm.meta_value' ) . ' LIMIT ' . (int) $limit,
			ARRAY_A
		);
		$options = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options}
			WHERE option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%' AND " . $where( 'option_value' ) . ' LIMIT ' . (int) $limit
		);
		// phpcs:enable

		return array(
			'ok'        => true,
			'needle'    => $needle,
			'elementor' => $elementor,
			'post_content' => (array) $content,
			'post_meta' => (array) $meta,
			'options'   => (array) $options,
			'files'     => self::find_in_files( $needle, $limit ),
			'hint'      => 'Same text in several places = change the one that renders. Elementor hit → elementor-patch-element; file hit → read/write-wp-content-file; option hit → large arrays (theme options), change with care.',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function purge_post( int $post_id ): array {
		$done = array();
		delete_post_meta( $post_id, '_elementor_element_cache' );
		$done[] = 'elementor_element_cache';
		// Missing _elementor_css makes Elementor regenerate the post CSS file on next view.
		delete_post_meta( $post_id, '_elementor_css' );
		$done[] = 'elementor_post_css';
		clean_post_cache( $post_id );
		$done[] = 'post_cache';
		if ( defined( 'LSCWP_V' ) ) {
			do_action( 'litespeed_purge_post', $post_id );
			$done[] = 'litespeed_post';
		}
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
			$done[] = 'wp_rocket_post';
		}
		return $done;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function purge_all(): array {
		global $wpdb;
		$done = array();

		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_element_cache'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$done[] = 'elementor_element_cache';
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
			$done[] = 'elementor_css';
		}
		wp_cache_flush();
		$done[] = 'object_cache';
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
			$done[] = 'woocommerce_transients';
		}
		if ( defined( 'LSCWP_V' ) ) {
			do_action( 'litespeed_purge_all' );
			$done[] = 'litespeed';
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$done[] = 'wp_rocket';
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$done[] = 'w3_total_cache';
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$done[] = 'wp_super_cache';
		}

		return array(
			'ok'      => true,
			'flushed' => true,
			'purged'  => $done,
			'note'    => 'CDN / hosting cache (Cloudflare, hosting panel) is not touched. Check pages with ?nocache=<timestamp>.',
		);
	}

	/**
	 * @param array<int, mixed> $data
	 * @return array<string, mixed>
	 */
	private static function save( int $post_id, string $old_raw, array $data, bool $check_size ): array {
		$new_raw = wp_json_encode( $data );
		if ( ! is_string( $new_raw ) ) {
			return array( 'ok' => false, 'error' => 'encode_failed' );
		}
		if ( $check_size && strlen( $new_raw ) < 0.8 * strlen( $old_raw ) ) {
			return array(
				'ok'           => false,
				'error'        => 'size_drop',
				'before_bytes' => strlen( $old_raw ),
				'after_bytes'  => strlen( $new_raw ),
				'message'      => 'Refused: the document would shrink by more than 20%. Nothing was written.',
			);
		}

		$backup_key = self::backup( $post_id, $old_raw );
		update_post_meta( $post_id, '_elementor_data', wp_slash( $new_raw ) );
		wp_cache_delete( $post_id, 'post_meta' );

		$stored = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! is_string( $stored ) || ! is_array( json_decode( $stored, true ) ) ) {
			update_post_meta( $post_id, '_elementor_data', wp_slash( $old_raw ) );
			return array(
				'ok'         => false,
				'error'      => 'verify_failed_restored',
				'backup_key' => $backup_key,
				'message'    => 'Written data did not parse back; the previous version was restored.',
			);
		}

		return array(
			'ok'           => true,
			'post_id'      => $post_id,
			'backup_key'   => $backup_key,
			'before_bytes' => strlen( $old_raw ),
			'after_bytes'  => strlen( $stored ),
			'purged'       => self::purge_post( $post_id ),
			'verify'       => 'Open ' . add_query_arg( 'nocache', time(), (string) get_permalink( $post_id ) ) . ' and look at it (screenshot). Undo: cursor-bridge/elementor-restore-backup with backup_key.',
		);
	}

	private static function backup( int $post_id, string $raw ): string {
		// Several writes in one second must not overwrite each other's backup.
		$base = self::BACKUP_PREFIX . gmdate( 'Ymd_His' );
		$key  = $base;
		for ( $n = 2; metadata_exists( 'post', $post_id, $key ); $n++ ) {
			$key = $base . '_' . $n;
		}
		add_post_meta( $post_id, $key, wp_slash( $raw ) );
		foreach ( array_slice( self::backup_keys( $post_id ), self::MAX_BACKUPS ) as $old ) {
			delete_post_meta( $post_id, $old );
		}
		return $key;
	}

	/**
	 * @return list<string> Newest first.
	 */
	private static function backup_keys( int $post_id ): array {
		$all  = get_post_meta( $post_id );
		$keys = array();
		foreach ( array_keys( is_array( $all ) ? $all : array() ) as $key ) {
			if ( 0 === strpos( (string) $key, self::BACKUP_PREFIX ) ) {
				$keys[] = (string) $key;
			}
		}
		rsort( $keys );
		return $keys;
	}

	/**
	 * @param array<int, mixed> $elements
	 */
	private static function walk( array $elements, callable $fn, int $depth = 0, string $parent = '' ): void {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$fn( $el, $depth, $parent );
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk( $el['elements'], $fn, $depth + 1, (string) ( $el['id'] ?? '' ) );
			}
		}
	}

	/**
	 * @param array<int, mixed> $elements
	 */
	private static function patch_in( array &$elements, string $element_id, callable $fn ): bool {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( (string) ( $el['id'] ?? '' ) === $element_id ) {
				$fn( $el );
				return true;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) && self::patch_in( $el['elements'], $element_id, $fn ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function preview( array $settings ): string {
		$parts = array();
		foreach ( self::TEXT_KEYS as $key ) {
			if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && '' !== trim( $settings[ $key ] ) ) {
				$parts[] = $settings[ $key ];
				break;
			}
		}
		// Theme widgets often keep text in repeaters (list of items with their own text keys).
		if ( ! $parts ) {
			foreach ( $settings as $value ) {
				if ( ! is_array( $value ) || ! isset( $value[0] ) || ! is_array( $value[0] ) ) {
					continue;
				}
				foreach ( $value as $item ) {
					$text = is_array( $item ) ? self::preview( $item ) : '';
					if ( '' !== $text ) {
						$parts[] = $text;
					}
				}
				if ( $parts ) {
					break;
				}
			}
		}
		$text = self::normalize( implode( ' | ', $parts ) );
		return self::strlen( $text ) > 90 ? self::substr( $text, 90 ) . '…' : $text;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return list<string>
	 */
	private static function hidden_on( array $settings ): array {
		$out = array();
		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
			if ( ! empty( $settings[ 'hide_' . $device ] ) ) {
				$out[] = $device;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, string> dotted.key => string value
	 */
	private static function flatten( array $settings, string $prefix = '' ): array {
		$out = array();
		foreach ( $settings as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( is_array( $value ) ) {
				$out += self::flatten( $value, $path );
			} elseif ( is_string( $value ) ) {
				$out[ $path ] = $value;
			}
		}
		return $out;
	}

	private static function normalize( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( (string) preg_replace( '/<[^>]+>/', ' ', $text ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
	}

	private static function like_words( string $text ): string {
		global $wpdb;
		$words = preg_split( '/[\s\x{00A0}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return '%' . implode( '%', array_map( array( $wpdb, 'esc_like' ), (array) $words ) ) . '%';
	}

	/**
	 * @return list<array{file:string,line:int}>
	 */
	private static function find_in_files( string $needle, int $limit ): array {
		$roots = array_unique( array( get_stylesheet_directory(), defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) );
		$hits  = array();
		$seen  = 0;
		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				// ponytail: 400 files / 512 KB each keeps one request fast; theme with more files → narrow with list-wp-content-dir.
				if ( ++$seen > 400 || count( $hits ) >= $limit ) {
					break 2;
				}
				if ( ! $file->isFile() || $file->getSize() > 524288 || ! preg_match( '/\.(php|css|js|html|json|twig)$/i', $file->getFilename() ) ) {
					continue;
				}
				$lines = @file( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				foreach ( (array) $lines as $i => $line ) {
					if ( false !== self::stripos( (string) $line, $needle ) ) {
						$hits[] = array(
							'file' => ltrim( str_replace( wp_normalize_path( WP_CONTENT_DIR ), '', wp_normalize_path( $file->getPathname() ) ), '/' ),
							'line' => $i + 1,
						);
						break;
					}
				}
			}
		}
		return $hits;
	}

	/**
	 * @return int|false
	 */
	private static function stripos( string $haystack, string $needle ) {
		return function_exists( 'mb_stripos' ) ? mb_stripos( $haystack, $needle ) : stripos( $haystack, $needle );
	}

	private static function strlen( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	}

	private static function substr( string $text, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function error( \WP_Error $error ): array {
		return array(
			'ok'      => false,
			'error'   => $error->get_error_code(),
			'message' => $error->get_error_message(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function not_found( string $element_id ): array {
		return array(
			'ok'      => false,
			'error'   => 'element_not_found',
			'message' => 'Element ' . $element_id . ' not found. List ids with cursor-bridge/elementor-outline or find them with cursor-bridge/find-content.',
		);
	}
}
