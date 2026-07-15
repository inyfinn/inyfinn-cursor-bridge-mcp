<?php
/**
 * Health checks and targeted repairs for Cursor Bridge.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Health {

	/**
	 * @return array<string, mixed>
	 */
	public static function run_checks(): array {
		$checks = array(
			self::check_wordpress_version(),
			self::check_plugin_active(),
			self::check_mu_plugin_loader(),
			self::check_application_passwords_api(),
			self::check_app_password(),
			self::check_setup_file(),
			self::check_setup_directory_protection(),
			self::check_permalinks(),
			self::check_mcp_adapter(),
			self::check_abilities_registered(),
			self::check_conflicting_plugins(),
			self::check_mcp_rest_route(),
		);

		$failed  = 0;
		$warning = 0;
		foreach ( $checks as $check ) {
			if ( 'error' === $check['status'] ) {
				++$failed;
			} elseif ( 'warning' === $check['status'] ) {
				++$warning;
			}
		}

		$overall = 'ok';
		if ( $failed > 0 ) {
			$overall = 'error';
		} elseif ( $warning > 0 ) {
			$overall = 'warning';
		}

		return array(
			'overall'        => $overall,
			'healthy'        => 'ok' === $overall,
			'version'        => defined( 'INYFINN_CURSOR_BRIDGE_MCP_VERSION' ) ? INYFINN_CURSOR_BRIDGE_MCP_VERSION : 'unknown',
			'checks'         => $checks,
			'failed_count'   => $failed,
			'warning_count'  => $warning,
			'mcp_endpoint'   => rest_url( 'mcp/mcp-adapter-default-server' ),
			'cursor_prompt'  => 'uruchom wtyczkę inyfinn-cursor-bridge-mcp',
			'verify_in_cursor' => array(
				'1. W Cursorze: Settings → MCP → serwer WordPress połączony',
				'2. W chacie: „cursor-bridge/ping” lub „wywołaj cursor-bridge/ping przez MCP”',
				'3. Oczekiwany wynik: ok:true, bridge_version zgodny z panelem',
				'4. discover-abilities: lista zawiera cursor-bridge/* (min. 17)',
			),
			'timestamp'      => gmdate( 'c' ),
		);
	}

	public static function is_healthy(): bool {
		$report = self::run_checks();
		return ! empty( $report['healthy'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function repair( string $action, bool $rotate_password = false ): array {
		$action = sanitize_key( $action );

		switch ( $action ) {
			case 'activate_plugin':
				$result = Installer::ensure_plugin_active();
				break;
			case 'mu_plugin':
				$result = Installer::ensure_mu_plugin_loader();
				break;
			case 'app_password':
				$app = Credentials::ensure_application_password( $rotate_password );
				if ( ! empty( $app['ok'] ) ) {
					$file = Installer::write_setup_file( Credentials::build_cursor_bundle( true, $app ) );
					$result = array_merge( $app, array( 'setup_file' => $file ) );
				} else {
					$result = $app;
				}
				break;
			case 'setup_file':
				$result = Installer::write_setup_file();
				break;
			case 'setup_directory':
				$result = Installer::ensure_setup_directory_public();
				break;
			case 'permaliinks':
			case 'permalinks':
				$result = Installer::flush_permalinks_public();
				break;
			case 'conflicts':
				$result = Installer::deactivate_conflicting_plugins();
				break;
			case 'profile':
				$result = Installer::ensure_hosting_profile_public();
				break;
			case 'full_bootstrap':
				$result = Installer::full_bootstrap( $rotate_password );
				break;
			default:
				return array(
					'ok'      => false,
					'message' => 'Unknown repair action: ' . $action,
				);
		}

		return array_merge(
			is_array( $result ) ? $result : array(),
			array(
				'action'  => $action,
				'health'  => self::run_checks(),
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_wordpress_version(): array {
		global $wp_version;
		$required = '6.8';
		$ok       = version_compare( (string) $wp_version, $required, '>=' );

		return array(
			'id'            => 'wordpress_version',
			'label'         => 'WordPress ' . $required . '+ (Abilities API)',
			'status'        => $ok ? 'ok' : 'error',
			'message'       => $ok ? 'WP ' . $wp_version : 'Wymagane WP ' . $required . '+, masz ' . $wp_version,
			'repair_action' => null,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_plugin_active(): array {
		$status = Installer::get_status();
		$ok     = ! empty( $status['plugin_active'] );

		return array(
			'id'            => 'plugin_active',
			'label'         => 'Wtyczka aktywna w WordPress',
			'status'        => $ok ? 'ok' : 'error',
			'message'       => $ok ? 'inyfinn-cursor-bridge-mcp w active_plugins' : 'Wtyczka nieaktywna — działa tylko mu-loader lub wcale',
			'repair_action' => $ok ? null : 'activate_plugin',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_mu_plugin_loader(): array {
		$ok = Installer::mu_plugin_loader_present();

		return array(
			'id'            => 'mu_plugin_loader',
			'label'         => 'MU-plugin loader',
			'status'        => $ok ? 'ok' : 'error',
			'message'       => $ok ? '000-inyfinn-cursor-bridge-mcp-loader.php' : 'Brak loadera w wp-content/mu-plugins/',
			'repair_action' => $ok ? null : 'mu_plugin',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_application_passwords_api(): array {
		$ok = Credentials::application_passwords_available();

		return array(
			'id'            => 'app_passwords_api',
			'label'         => 'Application Passwords (WordPress)',
			'status'        => $ok ? 'ok' : 'error',
			'message'       => $ok ? 'Dostępne' : 'Wyłączone na tej instalacji (HTTPS/filtr)',
			'repair_action' => null,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_app_password(): array {
		$ok       = Credentials::has_application_password();
		$username = Credentials::get_mcp_username();

		return array(
			'id'            => 'app_password',
			'label'         => 'Application Password MCP',
			'status'        => $ok ? 'ok' : 'error',
			'message'       => $ok ? 'Użytkownik: ' . $username : 'Brak hasła „Cursor MCP (Inyfinn)”',
			'repair_action' => $ok ? null : 'app_password',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_setup_file(): array {
		$path = Installer::setup_file_path();
		$ok   = is_readable( $path );

		return array(
			'id'            => 'setup_file',
			'label'         => 'cursor-setup.json',
			'status'        => $ok ? 'ok' : 'warning',
			'message'       => $ok ? $path : 'Plik nie istnieje — Cursor nie odczyta konfiguracji z SFTP',
			'repair_action' => $ok ? null : 'setup_file',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_setup_directory_protection(): array {
		$dir      = trailingslashit( WP_CONTENT_DIR ) . 'inyfinn-cursor-bridge';
		$htaccess = $dir . '/.htaccess';
		$ok       = is_dir( $dir ) && file_exists( $htaccess );

		return array(
			'id'            => 'setup_directory',
			'label'         => 'Katalog setup chroniony (.htaccess)',
			'status'        => $ok ? 'ok' : 'warning',
			'message'       => $ok ? 'Deny from all aktywny' : 'Brak .htaccess w inyfinn-cursor-bridge/',
			'repair_action' => $ok ? null : 'setup_directory',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_permalinks(): array {
		$ok = (bool) get_option( 'permalink_structure' );

		return array(
			'id'            => 'permalinks',
			'label'         => 'Permalinki (pretty URLs)',
			'status'        => $ok ? 'ok' : 'warning',
			'message'       => $ok ? 'Skonfigurowane' : 'Plain permalinks — REST/MCP może wymagać ?rest_route=',
			'repair_action' => $ok ? null : 'permalinks',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_mcp_adapter(): array {
		$ok = class_exists( 'WP\MCP\Core\McpAdapter' );

		return array(
			'id'            => 'mcp_adapter',
			'label'         => 'MCP Adapter (wbudowany)',
			'status'        => $ok ? 'ok' : 'error',
			'message'       => $ok ? 'Klasa McpAdapter załadowana' : 'Fork MCP Adapter nie załadowany',
			'repair_action' => $ok ? null : 'full_bootstrap',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_abilities_registered(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array(
				'id'            => 'abilities',
				'label'         => 'Abilities cursor-bridge/*',
				'status'        => 'error',
				'message'       => 'wp_get_abilities() niedostępne — WordPress < 6.8?',
				'repair_action' => null,
			);
		}

		$count  = 0;
		$ping   = false;
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( 0 === strpos( $name, 'cursor-bridge/' ) ) {
				++$count;
				if ( 'cursor-bridge/ping' === $name ) {
					$ping = true;
				}
			}
		}

		$ok = $count >= 19 && $ping;

		return array(
			'id'            => 'abilities',
			'label'         => 'Abilities cursor-bridge/*',
			'status'        => $ok ? 'ok' : 'error',
			'message'       => $ok ? $count . ' abilities (w tym ping)' : 'Znaleziono ' . $count . ' — oczekiwano ≥19',
			'repair_action' => $ok ? null : 'full_bootstrap',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_conflicting_plugins(): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$conflicts = array(
			'mcp-adapter/mcp-adapter.php',
			'wordpress-mcp-adapter/mcp-adapter.php',
		);
		$active = array();
		foreach ( $conflicts as $plugin ) {
			if ( is_plugin_active( $plugin ) ) {
				$active[] = $plugin;
			}
		}

		$ok = empty( $active );

		return array(
			'id'            => 'conflicts',
			'label'         => 'Brak konfliktowego mcp-adapter',
			'status'        => $ok ? 'ok' : 'warning',
			'message'       => $ok ? 'Brak duplikatu' : 'Aktywny: ' . implode( ', ', $active ),
			'repair_action' => $ok ? null : 'conflicts',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_mcp_rest_route(): array {
		$routes = rest_get_server() ? rest_get_server()->get_routes() : array();
		$ok     = isset( $routes['/mcp/mcp-adapter-default-server'] );

		return array(
			'id'            => 'mcp_rest',
			'label'         => 'REST route MCP',
			'status'        => $ok ? 'ok' : 'error',
			'message'       => $ok ? rest_url( 'mcp/mcp-adapter-default-server' ) : 'Brak trasy /mcp/mcp-adapter-default-server',
			'repair_action' => $ok ? null : 'permalinks',
		);
	}
}
