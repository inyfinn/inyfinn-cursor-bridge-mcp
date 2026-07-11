<?php
/**
 * Bootstrap Cursor Bridge abilities inside forked MCP Adapter.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	public static function init(): void {
		register_activation_hook( INYFINN_CURSOR_BRIDGE_MCP_FILE, array( __CLASS__, 'on_activate' ) );

		add_action( 'plugins_loaded', array( __CLASS__, 'on_plugins_loaded' ), 25 );

		// Rejestracja abilities musi być przed/po równo z Abilities API — nie czekać na wp_mcp_init.
		Abilities::register_hooks();
	}

	public static function on_activate(): void {
		$profile = get_option( 'inyfinn_cursor_bridge_profile', false );
		if ( ! is_array( $profile ) ) {
			$profile = array();
		}

		if ( empty( $profile['hosting_provider'] ) || 'generic' === $profile['hosting_provider'] ) {
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( is_string( $host ) && false !== stripos( $host, 'seohost' ) ) {
				$profile['hosting_provider'] = 'seohost';
			} else {
				$profile['hosting_provider'] = $profile['hosting_provider'] ?? 'generic';
			}
		}

		if ( ! isset( $profile['notes'] ) ) {
			$profile['notes'] = '';
		}

		update_option( 'inyfinn_cursor_bridge_profile', $profile, false );
	}

	public static function on_plugins_loaded(): void {
		if ( class_exists( 'WooCommerce' ) && 'yes' !== get_option( 'woocommerce_feature_mcp_integration_enabled', 'no' ) ) {
			update_option( 'woocommerce_feature_mcp_integration_enabled', 'yes', false );
		}

		if ( ! get_option( 'inyfinn_cursor_bridge_bootstrapped', false ) ) {
			self::on_activate();
			update_option( 'inyfinn_cursor_bridge_bootstrapped', true, false );
		}
	}
}

Bootstrap::init();
