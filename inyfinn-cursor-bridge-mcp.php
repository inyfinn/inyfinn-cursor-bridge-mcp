<?php
/**
 * Inyfinn Cursor Bridge MCP
 *
 * Fork MCP Adapter (WordPress 0.5.0) + wbudowane abilities, hosting profiles,
 * manifest i instrukcje dla Cursor IDE. Jedna wtyczka — bez osobnego kompaniona.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 * @license GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Inyfinn Cursor Bridge MCP
 * Plugin URI:        https://inyfinn.pl
 * Description:       MCP Adapter + Cursor abilities (SSH/WP-CLI setup guides, site manifest, WooCommerce). Uruchamiane z Cursor IDE przez @automattic/mcp-wordpress-remote.
 * Requires at least: 6.8
 * Version:           1.0.0
 * Requires PHP:      7.4
 * Author:            Inyfinn
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
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
function inyfinn_cursor_bridge_mcp_constants(): void {
	if ( ! defined( 'WP_MCP_DIR' ) ) {
		define( 'WP_MCP_DIR', plugin_dir_path( __FILE__ ) );
	}
	if ( ! defined( 'WP_MCP_VERSION' ) ) {
		define( 'WP_MCP_VERSION', '1.0.0' );
	}
	if ( ! defined( 'INYFINN_CURSOR_BRIDGE_MCP_VERSION' ) ) {
		define( 'INYFINN_CURSOR_BRIDGE_MCP_VERSION', '1.0.0' );
	}
	if ( ! defined( 'INYFINN_CURSOR_BRIDGE_MCP_FILE' ) ) {
		define( 'INYFINN_CURSOR_BRIDGE_MCP_FILE', __FILE__ );
	}
	if ( ! defined( 'INYFINN_CURSOR_BRIDGE_MCP_DIR' ) ) {
		define( 'INYFINN_CURSOR_BRIDGE_MCP_DIR', plugin_dir_path( __FILE__ ) );
	}
	if ( ! defined( 'WORDPRESS_MCP_ADAPTER_VERSION' ) ) {
		define( 'WORDPRESS_MCP_ADAPTER_VERSION', '1.0.0' );
	}
	if ( ! defined( 'INYFINN_CURSOR_BRIDGE_VERSION' ) ) {
		define( 'INYFINN_CURSOR_BRIDGE_VERSION', '1.0.0' );
	}
	if ( ! defined( 'INYFINN_CURSOR_BRIDGE_DIR' ) ) {
		define( 'INYFINN_CURSOR_BRIDGE_DIR', plugin_dir_path( __FILE__ ) );
	}
	if ( ! defined( 'INYFINN_CURSOR_BRIDGE_LOADED' ) ) {
		define( 'INYFINN_CURSOR_BRIDGE_LOADED', true );
	}
}

inyfinn_cursor_bridge_mcp_constants();

require_once __DIR__ . '/includes/Autoloader.php';

if ( ! Autoloader::autoload() ) {
	return;
}

// Cursor Bridge (abilities, hosting, manifest).
require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-hosting-profiles.php';
require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-site-manifest.php';
require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-file-reader.php';
require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-abilities.php';
require_once INYFINN_CURSOR_BRIDGE_MCP_DIR . 'includes/CursorBridge/class-bootstrap.php';

if ( class_exists( Plugin::class ) ) {
	Plugin::instance();
}
