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

		Installer::init();
		Admin_Page::init();

		Abilities::register_hooks();
	}

	public static function on_activate(): void {
		Installer::full_bootstrap( true );
	}

	public static function on_plugins_loaded(): void {
		if ( class_exists( 'WooCommerce' ) && 'yes' !== get_option( 'woocommerce_feature_mcp_integration_enabled', 'no' ) ) {
			update_option( 'woocommerce_feature_mcp_integration_enabled', 'yes', false );
		}

		if ( ! get_option( 'inyfinn_cursor_bridge_bootstrapped', false ) ) {
			Installer::full_bootstrap( false );
			update_option( 'inyfinn_cursor_bridge_bootstrapped', true, false );
		}
	}
}

Bootstrap::init();
