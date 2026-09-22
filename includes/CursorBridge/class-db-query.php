<?php
/**
 * Database access via wpdb on the server — same path as WordPress core and BSR.
 * No remote MySQL host, no extra firewall rules. Works on every shared host.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Db_Query {

	/**
	 * @return array<string, mixed>
	 */
	public static function info(): array {
		global $wpdb;

		return array(
			'ok'            => true,
			'access_method' => 'wpdb on server (localhost from PHP — same as WordPress CMS)',
			'db_name'       => defined( 'DB_NAME' ) ? DB_NAME : '',
			'db_host'       => defined( 'DB_HOST' ) ? DB_HOST : '',
			'table_prefix'  => $wpdb->prefix,
			'charset'       => defined( 'DB_CHARSET' ) ? DB_CHARSET : '',
			'note'          => 'Use cursor-bridge/db-query — not remote mariadb MCP.',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function list_tables(): array {
		global $wpdb;

		$tables = $wpdb->get_col( 'SHOW TABLES' );
		if ( ! is_array( $tables ) ) {
			return array(
				'ok'      => false,
				'message' => $wpdb->last_error ?: 'Could not list tables.',
			);
		}

		return array(
			'ok'           => true,
			'table_count'  => count( $tables ),
			'tables'       => $tables,
			'table_prefix' => $wpdb->prefix,
			'db_name'      => defined( 'DB_NAME' ) ? DB_NAME : '',
		);
	}

	/**
	 * @param string $table
	 * @return array<string, mixed>
	 */
	public static function describe_table( string $table ): array {
		global $wpdb;

		$table = self::sanitize_table_name( $table );
		if ( '' === $table ) {
			return array(
				'ok'      => false,
				'message' => 'Invalid table name.',
			);
		}

		$columns = $wpdb->get_results( 'DESCRIBE `' . esc_sql( $table ) . '`', ARRAY_A );
		if ( null === $columns && ! empty( $wpdb->last_error ) ) {
			return array(
				'ok'      => false,
				'message' => $wpdb->last_error,
			);
		}

		return array(
			'ok'      => true,
			'table'   => $table,
			'columns' => is_array( $columns ) ? $columns : array(),
		);
	}

	/**
	 * @param string $sql
	 * @return array<string, mixed>
	 */
	public static function run( string $sql ): array {
		global $wpdb;

		// {prefix} lets agents write portable SQL without guessing wp_ vs a custom prefix.
		$sql = str_replace( '{prefix}', $wpdb->prefix, trim( $sql ) );
		if ( '' === $sql ) {
			return array(
				'ok'      => false,
				'message' => 'Empty SQL.',
			);
		}

		if ( ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\s/i', $sql ) ) {
			return array(
				'ok'      => false,
				'message' => 'Only read-only queries allowed (SELECT, SHOW, DESCRIBE, EXPLAIN).',
			);
		}

		// Check keywords outside string literals, so LIKE '%update%' is allowed.
		$code = preg_replace( '/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', "''", $sql );
		if ( ! is_string( $code ) || preg_match( '/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|REPLACE|GRANT|REVOKE|OUTFILE|DUMPFILE)\b|;\s*\S/i', $code ) ) {
			return array(
				'ok'      => false,
				'message' => 'Destructive or write statements are blocked (one read-only statement per call).',
			);
		}

		$wpdb->last_error = '';
		$rows             = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// get_results() returns an empty array on SQL errors too — check last_error, not the return value.
		if ( '' !== $wpdb->last_error ) {
			$message = $wpdb->last_error;
			if ( false !== stripos( $message, "doesn't exist" ) ) {
				$message .= ' — table prefix on this site is `' . $wpdb->prefix . '`; write {prefix}posts instead of wp_posts.';
			}
			return array(
				'ok'           => false,
				'message'      => $message,
				'table_prefix' => $wpdb->prefix,
			);
		}

		return array(
			'ok'            => true,
			'access_method' => 'wpdb on server',
			'row_count'     => is_array( $rows ) ? count( $rows ) : 0,
			'rows'          => is_array( $rows ) ? $rows : array(),
			'table_prefix'  => $wpdb->prefix,
			'db_name'       => defined( 'DB_NAME' ) ? DB_NAME : '',
		);
	}

	/**
	 * Smoke test for diagnostics.
	 *
	 * @return array<string, mixed>
	 */
	public static function ping(): array {
		$result = self::run( 'SELECT 1 AS db_ok' );
		if ( empty( $result['ok'] ) ) {
			return $result;
		}

		return array(
			'ok'            => true,
			'db_ok'         => true,
			'access_method' => 'wpdb on server',
			'db_name'       => defined( 'DB_NAME' ) ? DB_NAME : '',
		);
	}

	private static function sanitize_table_name( string $table ): string {
		$table = trim( $table );
		if ( '' === $table || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
			return '';
		}
		return $table;
	}
}
