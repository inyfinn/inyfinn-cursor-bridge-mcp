<?php
/**
 * Call log: one JSON line per cursor-bridge/* ability call in wp-content/inyfinn-cursor-bridge/audit.log.
 * Records what was called, by whom, with a short summary of the input (never file or page bodies).
 * Meant for finding and undoing mistakes — it does not restrict anything.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Audit_Log {

	private const FILE_RELATIVE = 'inyfinn-cursor-bridge/audit.log';
	private const MAX_BYTES     = 1048576;
	private const MAX_SCALAR    = 80;

	public static function init(): void {
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'wrap_ability_args' ), 10, 2 );
	}

	public static function log_path(): string {
		return trailingslashit( WP_CONTENT_DIR ) . self::FILE_RELATIVE;
	}

	/**
	 * Filter wp_register_ability_args: wrap execute_callback of our abilities.
	 *
	 * @param mixed $args Ability args.
	 * @param mixed $name Ability name.
	 * @return mixed
	 */
	public static function wrap_ability_args( $args, $name ) {
		if ( ! is_array( $args ) || ! is_string( $name ) || 0 !== strpos( $name, 'cursor-bridge/' ) || 'cursor-bridge/audit-list' === $name ) {
			return $args;
		}
		if ( ! isset( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) {
			return $args;
		}

		$original                 = $args['execute_callback'];
		$args['execute_callback'] = static function ( ...$call_args ) use ( $original, $name ) {
			$started = microtime( true );
			try {
				$result = $original( ...$call_args );
			} catch ( \Throwable $e ) {
				self::record( $name, $call_args[0] ?? null, false, $started );
				throw $e;
			}
			self::record( $name, $call_args[0] ?? null, ! self::is_failure( $result ), $started );

			return $result;
		};

		return $args;
	}

	/**
	 * @param mixed $result Ability result.
	 */
	private static function is_failure( $result ): bool {
		return is_wp_error( $result ) || ( is_array( $result ) && array_key_exists( 'error', $result ) );
	}

	/**
	 * Append one line. A logging failure never reaches the caller.
	 *
	 * @param mixed $input Ability input.
	 */
	private static function record( string $ability, $input, bool $ok, float $started ): void {
		try {
			$user  = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
			$line  = wp_json_encode(
				array(
					't'       => gmdate( 'c' ),
					'user'    => ( $user && $user->exists() ) ? (string) $user->user_login : '',
					'ability' => $ability,
					'ok'      => $ok,
					'ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
					'args'    => self::summarize_args( $input ),
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			if ( ! is_string( $line ) ) {
				return;
			}

			Installer::ensure_setup_directory_public(); // the log sits in the protected bridge directory (Deny from all)
			$path = self::log_path();
			self::rotate_if_needed( $path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			@file_put_contents( $path, $line . "\n", FILE_APPEND | LOCK_EX );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Short, safe summary of an ability input: scalar up to 80 chars as is, longer string as "len:<n>", array as "array:<n>",
	 * value of a key that looks like a secret (pass, secret, token, key, auth) as "***".
	 * No WordPress needed (tests/audit-log-check.php).
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function summarize_args( $input ): array {
		$out = array();
		if ( ! is_array( $input ) ) {
			return $out;
		}
		foreach ( $input as $key => $value ) {
			if ( 1 === preg_match( '/pass|secret|token|key|auth/i', (string) $key ) ) {
				$out[ $key ] = '***';
			} elseif ( is_array( $value ) ) {
				$out[ $key ] = 'array:' . count( $value );
			} elseif ( is_string( $value ) ) {
				$length      = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
				$out[ $key ] = $length > self::MAX_SCALAR ? 'len:' . $length : $value;
			} elseif ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = $value;
			} else {
				$out[ $key ] = gettype( $value );
			}
		}

		return $out;
	}

	/**
	 * Over 1 MB: move to <file>.1 (replaces the previous .1). No WordPress needed.
	 */
	public static function rotate_if_needed( string $path, int $max_bytes = self::MAX_BYTES ): bool {
		if ( ! is_file( $path ) || filesize( $path ) <= $max_bytes ) {
			return false;
		}

		return @rename( $path, $path . '.1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Last $limit entries, newest first.
	 *
	 * @return array{entries: list<array<string, mixed>>, file_size: int}
	 */
	public static function list_entries( int $limit ): array {
		$path  = self::log_path();
		$limit = max( 1, min( 500, $limit ) );
		$lines = is_file( $path ) ? file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : array();
		$items = array();
		foreach ( array_reverse( is_array( $lines ) ? $lines : array() ) as $line ) {
			$entry = json_decode( $line, true );
			if ( is_array( $entry ) ) {
				$items[] = $entry;
				if ( count( $items ) >= $limit ) {
					break;
				}
			}
		}

		return array(
			'entries'   => $items,
			'file_size' => is_file( $path ) ? (int) filesize( $path ) : 0,
		);
	}
}
