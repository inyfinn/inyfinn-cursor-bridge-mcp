<?php
/**
 * Site manifest for Cursor agents.
 *
 * @package Inyfinn_Cursor_Bridge
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Site_Manifest {

	public static function build(): array {
		$profile = Hosting_Profiles::get_profile();
		$plugins = get_option( 'active_plugins', array() );
		$theme   = wp_get_theme();

		$abilities = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();
		$public    = array();
		foreach ( $abilities as $ability ) {
			$meta = $ability->get_meta();
			if ( ! empty( $meta['mcp']['public'] ) ) {
				$public[] = $ability->get_name();
			}
		}

		return array(
			'plugin'             => 'inyfinn-cursor-bridge-mcp',
			'plugin_version'     => defined( 'INYFINN_CURSOR_BRIDGE_MCP_VERSION' ) ? INYFINN_CURSOR_BRIDGE_MCP_VERSION : null,
			'fork_based_on'      => 'wordpress/mcp-adapter 0.5.0',
			'bridge_version'     => defined( 'INYFINN_CURSOR_BRIDGE_MCP_VERSION' ) ? INYFINN_CURSOR_BRIDGE_MCP_VERSION : INYFINN_CURSOR_BRIDGE_VERSION,
			'mcp_adapter'        => defined( 'INYFINN_CURSOR_BRIDGE_MCP_VERSION' ) ? INYFINN_CURSOR_BRIDGE_MCP_VERSION : ( defined( 'WORDPRESS_MCP_ADAPTER_VERSION' ) ? WORDPRESS_MCP_ADAPTER_VERSION : null ),
			'site_url'           => home_url( '/' ),
			'admin_url'          => admin_url(),
			'rest_url'           => rest_url(),
			'mcp_endpoint'       => rest_url( 'mcp/mcp-adapter-default-server' ),
			'wp_version'         => get_bloginfo( 'version' ),
			'php_version'        => PHP_VERSION,
			'environment'        => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'woocommerce'        => class_exists( 'WooCommerce' ),
			'wc_version'         => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'active_theme'       => array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'parent'  => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
			),
			'active_plugins'     => is_array( $plugins ) ? $plugins : array(),
			'content_paths'      => array(
				'ABSPATH'      => ABSPATH,
				'wp_content'   => WP_CONTENT_DIR,
				'mu_plugins'   => WPMU_PLUGIN_DIR,
				'themes'       => get_theme_root(),
				'plugins'      => WP_PLUGIN_DIR,
				'uploads'      => wp_upload_dir()['basedir'] ?? '',
			),
			'hosting_profile'    => array(
				'provider' => $profile['hosting_provider'] ?? 'generic',
				'label'    => $profile['label'] ?? '',
			),
			'public_abilities'     => $public,
			'cursor_layers'        => array(
				'files'     => 'Cursor workspace (SFTP/dysk) — edycja PHP/CSS/JS',
				'mcp'       => '@automattic/mcp-wordpress-remote → Inyfinn Cursor Bridge MCP → abilities',
				'ssh_wpcli' => 'Terminal Cursor + .env (SSH_*, WP_CLI_COMMAND) — batch, cache, DB',
				'not_mcp'   => array(
					'mariadb'         => 'Lokalna baza MySQL — NIE zdalny produkcyjny WordPress',
					'wordpress-local' => 'Osobny pakiet dla Local WP — nie zastępuje MCP Adapter',
				),
			),
			'env_file_location'  => 'Plik .env w katalogu public_html na maszynie deweloperskiej (Cursor). Wtyczka WP nie czyta .env — tylko Cursor.',
			'auto_setup'         => array(
				'status'              => Installer::get_status(),
				'setup_file'          => Installer::setup_file_path(),
				'setup_file_relative' => 'wp-content/' . Installer::setup_file_relative(),
				'cursor_prompt'       => 'uruchom wtyczkę inyfinn-cursor-bridge-mcp',
				'first_ability'       => 'cursor-bridge/get-cursor-bundle',
			),
			'timestamp'          => gmdate( 'c' ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function setup_guide(): array {
		$profile  = Hosting_Profiles::get_profile();
		$manifest = self::build();
		$site_url = home_url( '/' );

		$mcp_template = array(
			'mcpServers' => array(
				$profile['mcp_json']['server_name'] ?? 'wordpress-remote' => array(
					'command' => 'npx',
					'args'    => array(
						'-y',
						$profile['mcp_json']['package'] ?? '@automattic/mcp-wordpress-remote@latest',
					),
					'env'     => array(
						'WP_API_URL'      => str_replace( '{WP_SITE_URL}', untrailingslashit( $site_url ), $profile['mcp_json']['wp_api_url'] ?? '' ),
						'WP_API_USERNAME' => '${env:WP_MCP_USERNAME}',
						'WP_API_PASSWORD' => '${env:WP_MCP_APP_PASSWORD}',
					),
				),
			),
		);

		return array(
			'hosting_provider' => $profile['hosting_provider'] ?? 'generic',
			'label'            => $profile['label'] ?? '',
			'steps'            => $profile['cursor_steps'] ?? array(),
			'env_keys'         => $profile['env_keys'] ?? array(),
			'wp_cli_hint'      => $profile['wp_cli_hint'] ?? 'wp',
			'ssh_hint'         => $profile['ssh_hint'] ?? '',
			'mcp_json_snippet' => $mcp_template,
			'agents_doc'       => ( defined( 'INYFINN_CURSOR_BRIDGE_MCP_DIR' ) ? INYFINN_CURSOR_BRIDGE_MCP_DIR : INYFINN_CURSOR_BRIDGE_DIR ) . 'AGENTS.md',
			'checks'           => array(
				'mcp_adapter_active' => class_exists( 'WP\MCP\Core\McpAdapter' ),
				'plugin'             => 'inyfinn-cursor-bridge-mcp',
				'public_abilities'   => count( $manifest['public_abilities'] ),
				'permalink_ok'       => (bool) get_option( 'permalink_structure' ),
			),
		);
	}
}
