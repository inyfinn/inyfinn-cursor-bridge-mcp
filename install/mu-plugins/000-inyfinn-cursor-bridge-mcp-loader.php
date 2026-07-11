<?php
/**
 * Auto-load Inyfinn Cursor Bridge MCP (fork MCP Adapter + abilities).
 *
 * Skopiuj ten plik do: wp-content/mu-plugins/
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'INYFINN_CURSOR_BRIDGE_MCP_LOADED' ) ) {
	return;
}

$plugin = WP_PLUGIN_DIR . '/inyfinn-cursor-bridge-mcp/inyfinn-cursor-bridge-mcp.php';

if ( is_readable( $plugin ) ) {
	require_once $plugin;
}
