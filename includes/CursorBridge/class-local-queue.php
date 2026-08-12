<?php
/**
 * Local queue: SFTP → plik JSON → wykonanie na serwerze (wpdb / pliki).
 * Omija zewnętrzny HTTP do REST (gdy WAF serwera lub Wordfence blokuje Cursor).
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Local_Queue {

	private const DIR_NAME = 'inyfinn-cursor-bridge';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'process_pending' ), 2 );
	}

	public static function base_dir(): string {
		return trailingslashit( WP_CONTENT_DIR ) . self::DIR_NAME;
	}

	public static function queue_dir(): string {
		return trailingslashit( self::base_dir() ) . 'queue';
	}

	public static function results_dir(): string {
		return trailingslashit( self::base_dir() ) . 'queue-results';
	}

	public static function ensure_dirs(): void {
		$dirs = array( self::base_dir(), self::queue_dir(), self::results_dir() );
		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
		}
		self::write_htaccess_deny();
	}

	private static function write_htaccess_deny(): void {
		$htaccess = trailingslashit( self::base_dir() ) . '.htaccess';
		if ( file_exists( $htaccess ) ) {
			return;
		}
		$rules = "# Inyfinn Cursor Bridge — deny web access\nRequire all denied\n";
		if ( function_exists( 'insert_with_markers' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, $rules );
		}
	}

	public static function get_queue_key(): string {
		$key_file = trailingslashit( self::base_dir() ) . 'queue-key.txt';
		$key      = (string) get_option( 'inyfinn_cursor_bridge_local_queue_key', '' );

		if ( '' === $key && is_readable( $key_file ) ) {
			$key = trim( (string) file_get_contents( $key_file ) );
			if ( '' !== $key ) {
				update_option( 'inyfinn_cursor_bridge_local_queue_key', $key, false );
			}
		}

		if ( '' === $key ) {
			$key = wp_generate_password( 32, false );
			update_option( 'inyfinn_cursor_bridge_local_queue_key', $key, false );
		}

		self::ensure_dirs();
		if ( ! file_exists( $key_file ) || trim( (string) file_get_contents( $key_file ) ) !== $key ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $key_file, $key );
		}

		return $key;
	}

	public static function process_pending(): void {
		self::ensure_dirs();
		self::get_queue_key();

		$files = glob( self::queue_dir() . '/*.json' );
		if ( ! is_array( $files ) || empty( $files ) ) {
			return;
		}

		foreach ( $files as $file ) {
			self::process_file( $file );
		}
	}

	private static function process_file( string $file ): void {
		$raw = file_get_contents( $file );
		if ( false === $raw ) {
			return;
		}

		$task = json_decode( $raw, true );
		if ( ! is_array( $task ) ) {
			self::finish( $file, array( 'ok' => false, 'error' => 'invalid_json' ) );
			return;
		}

		$key = (string) ( $task['key'] ?? '' );
		if ( $key !== self::get_queue_key() ) {
			self::finish( $file, array( 'ok' => false, 'error' => 'invalid_key' ) );
			return;
		}

		$action = sanitize_key( (string) ( $task['action'] ?? '' ) );
		$args   = is_array( $task['args'] ?? null ) ? $task['args'] : array();

		switch ( $action ) {
			case 'db_query':
				$result = Db_Query::run( (string) ( $args['sql'] ?? '' ) );
				break;
			case 'db_replace':
				$result = self::db_replace( $args );
				break;
			case 'db_replace_post_meta':
				$result = self::db_replace_post_meta( $args );
				break;
			case 'write_file':
				$result = self::write_file( $args );
				break;
			default:
				$result = array( 'ok' => false, 'error' => 'unknown_action', 'action' => $action );
		}

		self::finish( $file, $result );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private static function db_replace( array $args ): array {
		$from = (string) ( $args['from'] ?? '' );
		$to   = (string) ( $args['to'] ?? '' );
		if ( '' === $from ) {
			return array( 'ok' => false, 'error' => 'missing_from' );
		}

		global $wpdb;
		$updated_meta = 0;
		$updated_opts = 0;

		$meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
				'%' . $wpdb->esc_like( $from ) . '%'
			),
			ARRAY_A
		);

		if ( is_array( $meta_rows ) ) {
			foreach ( $meta_rows as $row ) {
				$old = get_post_meta( (int) $row['post_id'], $row['meta_key'], true );
				if ( is_string( $old ) && str_contains( $old, $from ) ) {
					update_post_meta( (int) $row['post_id'], $row['meta_key'], str_replace( $from, $to, $old ) );
					$updated_meta++;
				}
			}
		}

		$opt_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s",
				'%' . $wpdb->esc_like( $from ) . '%'
			),
			ARRAY_A
		);

		if ( is_array( $opt_rows ) ) {
			foreach ( $opt_rows as $row ) {
				$val = get_option( $row['option_name'] );
				if ( is_string( $val ) && str_contains( $val, $from ) ) {
					update_option( $row['option_name'], str_replace( $from, $to, $val ) );
					$updated_opts++;
				}
			}
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		return array(
			'ok'             => true,
			'from'           => $from,
			'to'             => $to,
			'meta_updated'   => $updated_meta,
			'option_updated' => $updated_opts,
		);
	}

	/**
	 * Public entry for targeted postmeta replace (MCP / queue).
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function run_replace_post_meta( array $args ): array {
		return self::db_replace_post_meta( $args );
	}

	/**
	 * Targeted postmeta replace with JSON validation for Elementor data.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private static function db_replace_post_meta( array $args ): array {
		$post_id  = (int) ( $args['post_id'] ?? 0 );
		$meta_key = (string) ( $args['meta_key'] ?? '' );
		$from     = (string) ( $args['from'] ?? '' );
		$to       = (string) ( $args['to'] ?? '' );

		if ( $post_id <= 0 || '' === $meta_key || '' === $from ) {
			return array( 'ok' => false, 'error' => 'missing_args' );
		}

		$old = get_post_meta( $post_id, $meta_key, true );
		if ( ! is_string( $old ) || ! str_contains( $old, $from ) ) {
			return array( 'ok' => false, 'error' => 'substring_not_found', 'post_id' => $post_id );
		}

		if ( '_elementor_data' === $meta_key ) {
			$check_old = json_decode( $old, true );
			if ( ! is_array( $check_old ) ) {
				return array( 'ok' => false, 'error' => 'json_invalid_before', 'detail' => json_last_error_msg() );
			}
		}

		$new = str_replace( $from, $to, $old );

		if ( '_elementor_data' === $meta_key ) {
			$check_new = json_decode( $new, true );
			if ( ! is_array( $check_new ) ) {
				return array( 'ok' => false, 'error' => 'json_invalid_after', 'detail' => json_last_error_msg() );
			}
		}

		update_post_meta( $post_id, $meta_key, wp_slash( $new ) );

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		if ( class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		return array(
			'ok'      => true,
			'post_id' => $post_id,
			'meta_key' => $meta_key,
			'from'    => $from,
			'to'      => $to,
		);
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private static function write_file( array $args ): array {
		$path    = (string) ( $args['path'] ?? '' );
		$content = (string) ( $args['content'] ?? '' );
		if ( '' === $path ) {
			return array( 'ok' => false, 'error' => 'missing_path' );
		}

		$path = ltrim( str_replace( '\\', '/', $path ), '/' );
		if ( str_contains( $path, '..' ) ) {
			return array( 'ok' => false, 'error' => 'invalid_path' );
		}

		$full = trailingslashit( WP_CONTENT_DIR ) . $path;
		$dir  = dirname( $full );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$written = (bool) file_put_contents( $full, $content );
		return array(
			'ok'   => $written,
			'path' => 'wp-content/' . $path,
		);
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private static function finish( string $file, array $result ): void {
		$id = basename( $file, '.json' );
		self::ensure_dirs();
		$result['processed_at'] = gmdate( 'c' );
		$out = self::results_dir() . '/' . $id . '.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $out, wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		wp_delete_file( $file );
	}
}
