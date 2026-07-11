<?php
/**
 * Hosting-specific setup instructions for Cursor (no secrets).
 *
 * @package Inyfinn_Cursor_Bridge
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Hosting_Profiles {

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		return array(
			'seohost' => array(
				'label'       => 'SEOHOST (shared hosting)',
				'env_keys'    => array(
					'WP_SITE_URL',
					'WP_MCP_API_URL',
					'WP_MCP_USERNAME',
					'WP_MCP_APP_PASSWORD',
					'SSH_HOST',
					'SSH_USER',
					'SSH_PORT',
					'SSH_REMOTE_PUBLIC_HTML',
					'WORKSPACE_PUBLIC_HTML',
					'WP_CLI_COMMAND',
				),
				'mcp_json'    => array(
					'server_name' => 'seohost-wordpress',
					'package'     => '@automattic/mcp-wordpress-remote@latest',
					'wp_api_url'  => '{WP_SITE_URL}/wp-json/mcp/mcp-adapter-default-server',
				),
				'cursor_steps'  => array(
					'Utwórz Application Password: WP Admin → Użytkownicy → Profil → Hasła aplikacji → nazwa „Cursor MCP”.',
					'Wklej hasło do .env jako WP_MCP_APP_PASSWORD (nigdy do repo).',
					'W ~/.cursor/mcp.json dodaj serwer @automattic/mcp-wordpress-remote z WP_API_URL, WP_API_USERNAME, WP_API_PASSWORD ze zmiennej środowiskowej.',
					'Zamontuj public_html w Cursorze (SFTP/dysk S:) — to warstwa plików; MCP to warstwa WordPress/DB.',
					'SSH: dodaj klucz w panelu SEOHOST; WP-CLI często jako: alias wp="php ~/wp-cli.phar" w ~/.bashrc.',
					'NIE używaj mariadb MCP do produkcyjnej bazy zdalnej — tylko lokalny dev (Local WP). Na SEOHOST używaj WP-CLI lub abilities MCP.',
					'Test: mcp-adapter-discover-abilities musi zwrócić listę z cursor-bridge/*.',
				),
				'wp_cli_hint' => 'php ~/wp-cli.phar',
				'ssh_hint'    => 'Klucz publiczny w panelu hostingu; ścieżka public_html z panelu (np. /home/user/domains/domena.pl/public_html).',
			),
			'generic' => array(
				'label'      => 'Generic WordPress hosting',
				'env_keys'   => array(
					'WP_SITE_URL',
					'WP_MCP_API_URL',
					'WP_MCP_USERNAME',
					'WP_MCP_APP_PASSWORD',
					'SSH_HOST',
					'SSH_USER',
					'SSH_REMOTE_PUBLIC_HTML',
					'WORKSPACE_PUBLIC_HTML',
				),
				'mcp_json'   => array(
					'server_name' => 'wordpress-remote',
					'package'     => '@automattic/mcp-wordpress-remote@latest',
					'wp_api_url'  => '{WP_SITE_URL}/wp-json/mcp/mcp-adapter-default-server',
				),
				'cursor_steps' => array(
					'Zainstaluj i aktywuj **Inyfinn Cursor Bridge MCP** (jedna wtyczka — fork MCP Adapter + abilities).',
					'Application Password dla użytkownika z uprawnieniami admin/editor.',
					'Skonfiguruj ~/.cursor/mcp.json z @automattic/mcp-wordpress-remote.',
					'Opcjonalnie: SSH + WP-CLI dla operacji batch; pliki edytuj przez zamontowany workspace.',
				),
				'wp_cli_hint' => 'wp',
				'ssh_hint'    => 'Zależnie od hostingu — panel lub support.',
			),
			'local'   => array(
				'label'      => 'Local WP (Local, Docker, Laragon)',
				'env_keys'   => array(
					'WP_SITE_URL',
					'WP_MCP_API_URL',
					'WP_MCP_USERNAME',
					'WP_MCP_APP_PASSWORD',
				),
				'mcp_json'   => array(
					'server_name' => 'wordpress-local',
					'package'     => '@automattic/mcp-wordpress-remote@latest',
					'wp_api_url'  => '{WP_SITE_URL}/wp-json/mcp/mcp-adapter-default-server',
				),
				'cursor_steps' => array(
					'mariadb MCP w mcp.json dotyczy TYLKO lokalnej bazy (np. Local WP port 10004) — to nie jest MCP Adapter.',
					'Dla lokalnego WP użyj wordpress-local (@verygoodplugins/mcp-local-wp) LUB remote + Application Password.',
					'Pliki: otwórz folder strony jako workspace w Cursorze.',
				),
				'wp_cli_hint' => 'wp',
				'ssh_hint'    => 'Zwykle brak SSH — użyj WP-CLI lokalnie lub MCP abilities.',
			),
		);
	}

	public static function get( string $slug ): array {
		$all = self::all();
		return $all[ $slug ] ?? $all['generic'];
	}

	public static function get_profile(): array {
		$stored = get_option( 'inyfinn_cursor_bridge_profile', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$provider = isset( $stored['hosting_provider'] ) ? sanitize_key( (string) $stored['hosting_provider'] ) : 'generic';

		return array_merge(
			array(
				'hosting_provider' => $provider,
				'notes'            => '',
			),
			$stored,
			self::get( $provider )
		);
	}
}
