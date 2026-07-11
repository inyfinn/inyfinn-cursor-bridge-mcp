<?php
/**
 * Safe read/list within wp-content for Cursor agents.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class File_Reader {

	private const MAX_BYTES = 524288; // 512 KB

	/**
	 * Resolve path relative to WP_CONTENT_DIR. Blocks traversal outside wp-content.
	 *
	 * @return string|false Absolute path or false.
	 */
	public static function resolve_safe_path( string $relative ) {
		$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			return false;
		}

		$candidate = WP_CONTENT_DIR . '/' . $relative;
		if ( ! file_exists( $candidate ) ) {
			return false;
		}

		$content_root = realpath( WP_CONTENT_DIR );
		$resolved     = realpath( $candidate );
		if ( ! $content_root || ! $resolved ) {
			return false;
		}

		$prefix = trailingslashit( wp_normalize_path( $content_root ) );
		$path   = wp_normalize_path( $resolved );

		if ( 0 !== strpos( $path, $prefix ) ) {
			return false;
		}

		return $resolved;
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function read_file( string $relative ) {
		$path = self::resolve_safe_path( $relative );
		if ( ! $path || ! is_file( $path ) ) {
			return new \WP_Error( 'not_found', 'File not found or path not allowed (must be under wp-content, no ..).' );
		}

		$size = filesize( $path );
		if ( false === $size ) {
			return new \WP_Error( 'read_error', 'Cannot read file size.' );
		}
		if ( $size > self::MAX_BYTES ) {
			return new \WP_Error(
				'too_large',
				sprintf( 'File exceeds %d bytes. Use SSH/workspace for large files.', self::MAX_BYTES )
			);
		}

		$content = file_get_contents( $path );
		if ( false === $content ) {
			return new \WP_Error( 'read_error', 'Cannot read file.' );
		}

		return array(
			'path'     => $relative,
			'size'     => $size,
			'mime'     => wp_check_filetype( $path )['type'] ?? 'application/octet-stream',
			'modified' => gmdate( 'c', (int) filemtime( $path ) ),
			'content'  => $content,
		);
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_directory( string $relative, int $depth = 1 ) {
		$depth    = max( 1, min( 4, $depth ) );
		$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );

		if ( false !== strpos( $relative, '..' ) ) {
			return new \WP_Error( 'not_found', 'Directory not found or path not allowed.' );
		}

		$candidate = '' === $relative ? WP_CONTENT_DIR : WP_CONTENT_DIR . '/' . $relative;
		$content_root = realpath( WP_CONTENT_DIR );
		$resolved     = realpath( $candidate );

		if ( ! $content_root || ! $resolved || ! is_dir( $resolved ) ) {
			return new \WP_Error( 'not_found', 'Directory not found or path not allowed.' );
		}

		$prefix = trailingslashit( wp_normalize_path( $content_root ) );
		if ( 0 !== strpos( wp_normalize_path( $resolved ), $prefix ) ) {
			return new \WP_Error( 'not_found', 'Directory not found or path not allowed.' );
		}

		return array(
			'path'    => $relative,
			'entries' => self::scan_dir( $resolved, $depth ),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function scan_dir( string $absolute, int $depth ): array {
		$entries = array();
		$items   = scandir( $absolute );
		if ( ! is_array( $items ) ) {
			return $entries;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$full = $absolute . DIRECTORY_SEPARATOR . $item;
			$is_dir = is_dir( $full );
			$entry  = array(
				'name'  => $item,
				'type'  => $is_dir ? 'dir' : 'file',
				'size'  => $is_dir ? null : (int) filesize( $full ),
			);

			if ( $is_dir && $depth > 1 ) {
				$entry['children'] = self::scan_dir( $full, $depth - 1 );
			}

			$entries[] = $entry;
		}

		usort(
			$entries,
			static function ( array $a, array $b ): int {
				if ( $a['type'] !== $b['type'] ) {
					return 'dir' === $a['type'] ? -1 : 1;
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $entries;
	}
}
