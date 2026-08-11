<?php
/**
 * Read-only SQL via wpdb (localhost MySQL on server — no remote DB needed).
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Db_Query {

	/**
	 * @param string $sql
	 * @return array<string, mixed>
	 */
	public static function run( string $sql ): array {
		global $wpdb;

		$sql = trim( $sql );
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

		if ( preg_match( '/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|REPLACE|GRANT|REVOKE)\b/i', $sql ) ) {
			return array(
				'ok'      => false,
				'message' => 'Destructive or write statements are blocked.',
			);
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( null === $rows && ! empty( $wpdb->last_error ) ) {
			return array(
				'ok'      => false,
				'message' => $wpdb->last_error,
			);
		}

		return array(
			'ok'          => true,
			'row_count'   => is_array( $rows ) ? count( $rows ) : 0,
			'rows'        => is_array( $rows ) ? $rows : array(),
			'table_prefix'=> $wpdb->prefix,
			'db_name'     => defined( 'DB_NAME' ) ? DB_NAME : '',
		);
	}
}
