<?php
/**
 * Inyfinn Cursor Bridge MCP
 *
 * Silnik: oficjalny WordPress MCP Adapter 0.7.2 bez zmian (includes/ poza
 * CursorBridge + vendor/, patrz docs/ENGINE.md) + nasza warstwa includes/CursorBridge:
 * abilities, hosting profiles, manifest i instrukcje dla Cursor IDE.
 * Jedna wtyczka — bez osobnego kompaniona.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 * @license GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Inyfinn Cursor Bridge MCP
 * Plugin URI:        https://github.com/inyfinn/inyfinn-cursor-bridge-mcp
 * Description:       MCP Adapter + Cursor abilities (SSH/WP-CLI setup guides, site manifest, WooCommerce). Uruchamiane z Cursor IDE przez @automattic/mcp-wordpress-remote.
 * Requires at least: 6.9
 * Version:           1.7.3
 * Requires PHP:      7.4
 * Author:            Inyfinn
 * Author URI:        https://github.com/inyfinn
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Update URI:        https://github.com/inyfinn/inyfinn-cursor-bridge-mcp
 * Text Domain:       inyfinn-cursor-bridge-mcp
 */

declare( strict_types=1 );

namespace WP\MCP;

defined( 'ABSPATH' ) || exit();

if ( defined( 'INYFINN_CURSOR_BRIDGE_MCP_LOADED' ) ) {
	return;
}

define( 'INYFINN_CURSOR_BRIDGE_MCP_LOADED', true );

/**
 * Plugin constants.
 */
if ( ! function_exists( __NAMESPACE__ . '\\inyfinn_cursor_bridge_mcp_constants' ) ) {
	function inyfinn_cursor_bridge_mcp_constants(): void {
		// WP_MCP_DIR and WP_MCP_VERSION belong to the engine: Autoloader::autoload() and Plugin::constants()
		// define them without a defined() check, so defining them here would raise "already defined" warnings.
		if ( ! defined( 'INYFINN_CURSOR_BRIDGE_ENGINE_VERSION' ) ) {
			define( 'INYFINN_CURSOR_BRIDGE_ENGINE_VERSION', '0.7.2' );
		}
		if ( ! defined( 'INYFINN_CURSOR_BRIDGE_MCP_VERSION' ) ) {
			define( 'INYFINN_CURSOR_BRIDGE_MCP_VERSION', '1.7.3' );
		}
		if ( ! defined( 'INYFINN_CURSOR_BRIDGE_MCP_FILE' ) ) {
			define( 'INYFINN_CURSOR_BRIDGE_MCP_FILE', __FILE__ );
		}
		if ( ! defined( 'INYFINN_CURSOR_BRIDGE_MCP_DIR' ) ) {
			define( 'INYFINN_CURSOR_BRIDGE_MCP_DIR', plugin_dir_path( __FILE__ ) );
		}
		// Legacy constant from 1.0.0, unused by the engine and by CursorBridge; kept for external code that may check it.
		if ( ! defined( 'WORDPRESS_MCP_ADAPTER_VERSION' ) ) {
			define( 'WORDPRESS_MCP_ADAPTER_VERSION', '1.5.5' );
		}
		if ( ! defined( 'INYFINN_CURSOR_BRIDGE_VERSION' ) ) {
			define( 'INYFINN_CURSOR_BRIDGE_VERSION', '1.7.3' );
		}
		if ( ! defined( 'INYFINN_CURSOR_BRIDGE_DIR' ) ) {
			define( 'INYFINN_CURSOR_BRIDGE_DIR', plugin_dir_path( __FILE__ ) );
		}
		if ( ! defined( 'INYFINN_CURSOR_BRIDGE_LOADED' ) ) {
			define( 'INYFINN_CURSOR_BRIDGE_LOADED', true );
		}
	}
}

inyfinn_cursor_bridge_mcp_constants();

try {
	// Engine bootstrap = upstream mcp-adapter.php. A copy loaded first (e.g. network-active official MCP Adapter)
	// already declared Autoloader: use it instead of a fatal redeclare; Installer deactivates that plugin on activation/repair.
	if ( ! class_exists( Autoloader::class, false ) ) {
		require_once __DIR__ . '/includes/Autoloader.php';
	}

	if ( ! Autoloader::autoload() ) {
		return;
	}

	// Cursor Bridge (abilities, hosting, manifest).
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-hosting-profiles.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-credentials.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-installer.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-health.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-hardening-messages.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-hardening-backup.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-hardening.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-site-manifest.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-file-reader.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-db-query.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-rest-firewall-diagnostics.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-connection-verify.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-elementor-editor.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-agent-playbook.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-local-queue.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-admin-page.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-djacc-palette.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-front-overlays.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-elementor-tree.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-content-tools.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-site-discovery.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-abilities.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-github-updater.php';
	require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-bootstrap.php';

	if ( class_exists( Plugin::class ) ) {
		Plugin::instance();
	}
} catch ( \Throwable $e ) {
	error_log( 'Inyfinn Cursor Bridge MCP boot failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
	add_action(
		'admin_notices',
		static function () use ( $e ): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			echo esc_html( 'Inyfinn Cursor Bridge MCP nie wystartowała: ' . $e->getMessage() );
			echo '</p></div>';
		}
	);
}
