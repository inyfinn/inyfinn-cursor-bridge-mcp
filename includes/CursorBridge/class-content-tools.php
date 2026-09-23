<?php
/**
 * Content tools that used to need a temporary mu-plugin on the server:
 * media import from URL, structured post meta (serialized arrays such as MapGeo
 * map_info), a database write probe and reversible file removal.
 *
 * Each one comes from a real session (liquidjungle.pl, 2026-09-22) where the agent
 * had to upload PHP by hand to do it.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Content_Tools {

	public const META_BACKUP_PREFIX = '_inyfinn_meta_backup_';
	public const TRASH_DIR          = 'inyfinn-cursor-bridge/trash';
	private const MAX_MEDIA         = 20;
	private const MAX_META_BACKUPS  = 5;

	/**
	 * Download images (or other allowed files) into the media library.
	 *
	 * @param list<string> $urls
	 * @return array<string, mixed>
	 */
	public static function media_sideload( array $urls, string $name = '', string $title = '', int $parent = 0 ): array {
		$urls = array_values( array_filter( array_map( 'strval', $urls ) ) );
		if ( ! $urls || count( $urls ) > self::MAX_MEDIA ) {
			return array( 'ok' => false, 'error' => 'bad_urls', 'message' => 'Pass 1–' . self::MAX_MEDIA . ' URLs per call.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$out    = array();
		$errors = array();
		foreach ( $urls as $i => $url ) {
			if ( ! wp_http_validate_url( $url ) ) {
				$errors[] = array( 'url' => $url, 'error' => 'invalid_url' );
				continue;
			}
			$tmp = download_url( $url, 60 );
			if ( is_wp_error( $tmp ) ) {
				$errors[] = array( 'url' => $url, 'error' => $tmp->get_error_message() );
				continue;
			}
			$ext      = strtolower( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			$filename = '' !== $name ? sanitize_file_name( $name . '-' . ( $i + 1 ) . ( $ext ? '.' . $ext : '' ) ) : wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			$id       = media_handle_sideload( array( 'name' => $filename, 'tmp_name' => $tmp ), $parent, '' !== $title ? $title . ' ' . ( $i + 1 ) : null );
			if ( is_wp_error( $id ) ) {
				wp_delete_file( $tmp );
				$errors[] = array( 'url' => $url, 'error' => $id->get_error_message() );
				continue;
			}
			$out[] = array( 'id' => $id, 'url' => wp_get_attachment_url( $id ), 'source' => $url );
		}
		return array(
			'ok'     => ! $errors,
			'media'  => $out,
			'errors' => $errors,
			'next'   => 'Elementor gallery value = [{"id":ID,"url":"URL"},…]; image/background value = {"id":ID,"url":"URL","size":"","alt":"","source":"library"}.',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_meta( int $post_id, string $key ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}
		if ( ! metadata_exists( 'post', $post_id, $key ) ) {
			return array( 'ok' => false, 'error' => 'meta_not_found', 'message' => 'List keys: db-query SELECT meta_key FROM {prefix}postmeta WHERE post_id=' . $post_id );
		}
		$value = get_post_meta( $post_id, $key, true );
		return array(
			'ok'      => true,
			'post_id' => $post_id,
			'key'     => $key,
			'type'    => gettype( $value ),
			'value'   => $value,
			'backups' => self::meta_backup_keys( $post_id, $key ),
		);
	}

	/**
	 * Replace one post meta value with a structured value (array/object/scalar). WordPress
	 * serializes it — never str_replace inside a serialized string (lengths break).
	 *
	 * @param mixed $value
	 * @return array<string, mixed>
	 */
	public static function set_meta( int $post_id, string $key, $value ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}
		if ( 'revision' === $post->post_type ) {
			return array( 'ok' => false, 'error' => 'revision', 'message' => 'Edit the parent post ' . $post->post_parent . '.' );
		}
		if ( '_elementor_data' === $key || 0 === strpos( $key, self::META_BACKUP_PREFIX ) || 0 === strpos( $key, Elementor_Editor::BACKUP_PREFIX ) ) {
			return array( 'ok' => false, 'error' => 'use_elementor_tools', 'message' => '_elementor_data: elementor-patch-element / elementor-clone-element. Backups are read-only.' );
		}

		$had    = metadata_exists( 'post', $post_id, $key );
		$old    = $had ? get_post_meta( $post_id, $key, true ) : null;
		$backup = null;
		if ( $had ) {
			$backup = self::META_BACKUP_PREFIX . $key . '_' . gmdate( 'Ymd_His' );
			for ( $n = 2; metadata_exists( 'post', $post_id, $backup ); $n++ ) {
				$backup = self::META_BACKUP_PREFIX . $key . '_' . gmdate( 'Ymd_His' ) . '_' . $n;
			}
			add_post_meta( $post_id, $backup, wp_slash( $old ) );
			foreach ( array_slice( self::meta_backup_keys( $post_id, $key ), self::MAX_META_BACKUPS ) as $stale ) {
				delete_post_meta( $post_id, $stale );
			}
		}

		update_post_meta( $post_id, $key, wp_slash( $value ) );
		wp_cache_delete( $post_id, 'post_meta' );
		clean_post_cache( $post_id );
		$stored = get_post_meta( $post_id, $key, true );

		return array(
			'ok'           => $stored == $value, // phpcs:ignore Universal.Operators.StrictComparisonOperator -- "1" vs 1 after a DB round trip is fine.
			'post_id'      => $post_id,
			'key'          => $key,
			'backup_key'   => $backup,
			'before_bytes' => $had ? strlen( (string) maybe_serialize( $old ) ) : 0,
			'after_bytes'  => strlen( (string) maybe_serialize( $stored ) ),
			'undo'         => $backup ? 'get-post-meta {post_id, key:"' . $backup . '"} then set-post-meta with that value.' : 'Key did not exist before.',
		);
	}

	/**
	 * Write / read / delete a temporary option — proves database writes work end to end.
	 *
	 * @return array<string, mixed>
	 */
	public static function db_write_probe(): array {
		$value = 'probe-' . wp_generate_password( 12, false );
		$set   = update_option( 'inyfinn_bridge_probe', $value, false );
		wp_cache_delete( 'inyfinn_bridge_probe', 'options' );
		$read  = get_option( 'inyfinn_bridge_probe' ) === $value;
		$gone  = delete_option( 'inyfinn_bridge_probe' ) && false === get_option( 'inyfinn_bridge_probe' );
		return array( 'ok' => $set && $read && $gone, 'written' => $set, 'read_back' => $read, 'deleted' => $gone );
	}

	/**
	 * Move a file under wp-content to wp-content/inyfinn-cursor-bridge/trash/<date>/<path>.
	 * Reversible: restore = move it back (list-wp-content-dir shows the trash).
	 *
	 * @return array<string, mixed>
	 */
	public static function trash_file( string $relative, bool $allow_protected = false ): array {
		$relative = File_Reader::sanitize_relative_path( $relative );
		if ( '' === $relative || 0 === strpos( $relative, self::TRASH_DIR . '/' ) ) {
			return array( 'ok' => false, 'error' => 'invalid_path', 'message' => 'Path must be a file under wp-content, outside the bridge trash.' );
		}
		if ( ! $allow_protected && File_Reader::is_protected( $relative ) ) {
			return array( 'ok' => false, 'error' => 'blocked', 'message' => 'Protected file. cursor-setup.json: repair {action:"remove_setup_file"}.' );
		}
		$source = File_Reader::resolve_safe_path( $relative );
		if ( ! $source || ! is_file( $source ) ) {
			return array( 'ok' => false, 'error' => 'not_found', 'message' => 'Only single files can be removed (no directories).' );
		}
		$target = trailingslashit( WP_CONTENT_DIR ) . self::TRASH_DIR . '/' . gmdate( 'Ymd_His' ) . '/' . $relative;
		wp_mkdir_p( dirname( $target ) );
		Installer::ensure_setup_directory_public(); // trash sits in the protected bridge directory (Deny from all)
		if ( ! @rename( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return array( 'ok' => false, 'error' => 'move_failed' );
		}
		return array(
			'ok'      => true,
			'moved'   => $relative,
			'to'      => ltrim( str_replace( wp_normalize_path( WP_CONTENT_DIR ), '', wp_normalize_path( $target ) ), '/' ),
			'restore' => 'Move the file back (it keeps its path under the dated folder).',
		);
	}

	/**
	 * @return list<string> newest first
	 */
	private static function meta_backup_keys( int $post_id, string $key ): array {
		$prefix = self::META_BACKUP_PREFIX . $key . '_';
		$keys   = array();
		foreach ( array_keys( (array) get_post_meta( $post_id ) ) as $k ) {
			if ( 0 === strpos( (string) $k, $prefix ) ) {
				$keys[] = (string) $k;
			}
		}
		rsort( $keys );
		return $keys;
	}
}
