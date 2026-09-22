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
		Credentials::register_application_password_filters();

		register_activation_hook( INYFINN_CURSOR_BRIDGE_MCP_FILE, array( __CLASS__, 'on_activate' ) );
		register_deactivation_hook( INYFINN_CURSOR_BRIDGE_MCP_FILE, array( __CLASS__, 'on_deactivate' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'on_plugins_loaded' ), 25 );

		Installer::init();
		Local_Queue::init();
		Admin_Page::init();
		Front_Overlays::init();
		Abilities::register_hooks();
		GitHub_Updater::init();
	}

	public static function on_activate(): void {
		Installer::remove_mu_plugin_loader();
		Installer::run_install( 'activation' );
		// Jednorazowe przekierowanie do panelu — user od razu widzi wynik instalacji.
		set_transient( Installer::REDIRECT_TRANSIENT, 1, MINUTE_IN_SECONDS );
	}

	public static function on_deactivate(): void {
		Installer::remove_mu_plugin_loader();
		delete_option( Installer::INSTALLED_VERSION_OPTION );
	}

	public static function on_plugins_loaded(): void {
		Credentials::maybe_consume_manual_pass_file();

		if ( class_exists( 'WooCommerce' ) && 'yes' !== get_option( 'woocommerce_feature_mcp_integration_enabled', 'no' ) ) {
			update_option( 'woocommerce_feature_mcp_integration_enabled', 'yes', false );
		}

		// 1.5.x zostawiał mu-loader, który omijał przycisk Włącz — sprzątamy.
		Installer::remove_mu_plugin_loader();
	}
}

Bootstrap::init();
