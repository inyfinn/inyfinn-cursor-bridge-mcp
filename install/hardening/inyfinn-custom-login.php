<?php
/**
 * Plugin Name: Inyfinn Custom Login (/logowanie)
 * Description: Pretty login URL /logowanie → wp-login.php. Soft-redirects wp-login.php. Does NOT 404 wp-admin (safer). Managed by Inyfinn Cursor Bridge MCP.
 * Version: 1.0.0
 *
 * BEGIN Inyfinn Cursor Bridge: custom-login
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rewrite /logowanie → wp-login.php
 */
add_action(
	'init',
	static function () {
		add_rewrite_rule( '^logowanie/?$', 'wp-login.php', 'top' );
	}
);

/**
 * Replace wp-login.php in generated URLs (emails, redirects).
 */
add_filter(
	'site_url',
	static function ( $url, $path, $scheme ) {
		if ( 'login' === $scheme || false !== strpos( $url, 'wp-login.php' ) ) {
			$url = str_replace( 'wp-login.php', 'logowanie', $url );
		}
		return $url;
	},
	10,
	3
);

add_filter(
	'network_site_url',
	static function ( $url, $path, $scheme ) {
		if ( 'login' === $scheme || false !== strpos( $url, 'wp-login.php' ) ) {
			$url = str_replace( 'wp-login.php', 'logowanie', $url );
		}
		return $url;
	},
	10,
	3
);

add_filter(
	'wp_redirect',
	static function ( $location ) {
		if ( is_string( $location ) && false !== strpos( $location, 'wp-login.php' ) ) {
			$location = str_replace( 'wp-login.php', 'logowanie', $location );
		}
		return $location;
	}
);

/**
 * Soft-block direct wp-login.php: redirect to /logowanie (NOT 404).
 * Allows logout, postpass, reset key, AJAX-safe flows.
 */
add_action(
	'login_init',
	static function () {
		$request = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( false === stripos( $request, 'wp-login.php' ) ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['action'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification
		$allowed = array( 'logout', 'postpass', 'resetpass', 'rp', 'confirmaction' );
		if ( in_array( $action, $allowed, true ) || isset( $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$query = array();
		if ( ! empty( $_GET ) && is_array( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			foreach ( $_GET as $k => $v ) { // phpcs:ignore WordPress.Security.NonceVerification
				$query[ sanitize_key( (string) $k ) ] = sanitize_text_field( wp_unslash( (string) $v ) );
			}
		}

		wp_safe_redirect( add_query_arg( $query, home_url( '/logowanie' ) ) );
		exit;
	},
	1
);

/**
 * Flush rewrites once after install (option flag).
 */
add_action(
	'init',
	static function () {
		if ( get_option( 'inyfinn_cb_login_rewrite_flushed' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'inyfinn_cb_login_rewrite_flushed', 1, false );
	},
	99
);

// END Inyfinn Cursor Bridge: custom-login
