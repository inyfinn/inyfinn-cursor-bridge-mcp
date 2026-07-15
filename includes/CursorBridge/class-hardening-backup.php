<?php
/**
 * Safe backups before any hardening file mutation.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Hardening_Backup {

	private const BACKUP_ROOT = 'inyfinn-cursor-bridge/backups';

	/**
	 * @return array{ok:bool,path?:string,message?:string}
	 */
	public static function backup_file( string $absolute_path ): array {
		if ( ! file_exists( $absolute_path ) || ! is_readable( $absolute_path ) ) {
			return array(
				'ok'      => false,
				'message' => 'Source missing or unreadable: ' . $absolute_path,
			);
		}

		$dir = self::ensure_backup_dir();
		if ( empty( $dir['ok'] ) ) {
			return $dir;
		}

		$base     = basename( $absolute_path );
		$stamp    = gmdate( 'Ymd-His' );
		$dest     = trailingslashit( $dir['path'] ) . $stamp . '__' . preg_replace( '/[^a-zA-Z0-9._-]/', '_', $base );
		$copied   = copy( $absolute_path, $dest );

		if ( ! $copied || ! is_readable( $dest ) ) {
			return array(
				'ok'      => false,
				'message' => 'copy() failed for backup',
			);
		}

		@chmod( $dest, 0600 );

		return array(
			'ok'   => true,
			'path' => $dest,
		);
	}

	/**
	 * Restore file from backup path.
	 *
	 * @return array{ok:bool,message?:string}
	 */
	public static function restore( string $backup_path, string $absolute_target ): array {
		if ( ! is_readable( $backup_path ) ) {
			return array( 'ok' => false, 'message' => 'Backup not readable' );
		}
		$ok = copy( $backup_path, $absolute_target );
		return $ok
			? array( 'ok' => true )
			: array( 'ok' => false, 'message' => 'Restore copy failed' );
	}

	/**
	 * @return array{ok:bool,path?:string,message?:string}
	 */
	public static function ensure_backup_dir(): array {
		$path = trailingslashit( WP_CONTENT_DIR ) . self::BACKUP_ROOT;
		if ( ! is_dir( $path ) ) {
			wp_mkdir_p( $path );
		}
		$ht = $path . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			file_put_contents( $ht, "Deny from all\n" );
		}
		$index = $path . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		if ( ! is_dir( $path ) || ! is_writable( $path ) ) {
			return array(
				'ok'      => false,
				'message' => 'Backup directory not writable',
			);
		}

		return array(
			'ok'   => true,
			'path' => $path,
		);
	}

	public static function backup_root_relative(): string {
		return 'wp-content/' . self::BACKUP_ROOT;
	}
}
