<?php
/**
 * Automatic installation: mu-plugin loader, setup file, conflicting plugins.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Installer {

	private const SETUP_DIR = 'inyfinn-cursor-bridge';

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_self_heal' ), 5 );
	}

	/**
	 * Full bootstrap — activation hook and MCP ability.
	 *
	 * @return array<string, mixed>
	 */
	public static function full_bootstrap( bool $rotate_password = true ): array {
		$results = array(
			'mu_plugin'       => self::ensure_mu_plugin_loader(),
			'conflicts'       => self::deactivate_conflicting_plugins(),
			'profile'         => self::ensure_hosting_profile(),
			'app_password'    => Credentials::ensure_application_password( $rotate_password ),
			'setup_file'      => self::write_setup_file(),
			'permalink_flush' => self::flush_permalinks(),
		);

		update_option( 'inyfinn_cursor_bridge_last_bootstrap', gmdate( 'c' ), false );

		$bundle = Credentials::build_cursor_bundle( true );

		return array_merge(
			$results,
			array(
				'ok'     => true,
				'bundle' => $bundle,
			)
		);
	}

	public static function maybe_self_heal(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! self::mu_plugin_loader_present() ) {
			self::ensure_mu_plugin_loader();
		}

		if ( ! Credentials::has_application_password() ) {
			Credentials::ensure_application_password( false );
			self::write_setup_file();
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function ensure_mu_plugin_loader(): array {
		$mu_dir   = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		$dest     = trailingslashit( $mu_dir ) . '000-inyfinn-cursor-bridge-mcp-loader.php';
		$source   = INYFINN_CURSOR_BRIDGE_MCP_DIR . 'install/mu-plugins/000-inyfinn-cursor-bridge-mcp-loader.php';
		$created  = false;
		$updated  = false;

		if ( ! is_dir( $mu_dir ) ) {
			wp_mkdir_p( $mu_dir );
		}

		if ( ! is_readable( $source ) ) {
			return array(
				'ok'      => false,
				'message' => 'Loader source missing in plugin package.',
			);
		}

		if ( ! file_exists( $dest ) ) {
			$created = (bool) copy( $source, $dest );
		} else {
			$existing = file_get_contents( $dest );
			$incoming = file_get_contents( $source );
			if ( false !== $existing && false !== $incoming && $existing !== $incoming ) {
				$updated = (bool) copy( $source, $dest );
			}
		}

		return array(
			'ok'      => file_exists( $dest ),
			'path'    => $dest,
			'created' => $created,
			'updated' => $updated,
		);
	}

	public static function mu_plugin_loader_present(): bool {
		$mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		$dest   = trailingslashit( $mu_dir ) . '000-inyfinn-cursor-bridge-mcp-loader.php';

		return is_readable( $dest );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function deactivate_conflicting_plugins(): array {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$conflicts = array(
			'mcp-adapter/mcp-adapter.php',
			'wordpress-mcp-adapter/mcp-adapter.php',
		);

		$deactivated = array();
		foreach ( $conflicts as $plugin ) {
			if ( is_plugin_active( $plugin ) ) {
				deactivate_plugins( $plugin, true );
				$deactivated[] = $plugin;
			}
		}

		return array(
			'ok'          => true,
			'deactivated' => $deactivated,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function ensure_hosting_profile(): array {
		$profile = get_option( 'inyfinn_cursor_bridge_profile', array() );
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

		update_option( 'inyfinn_cursor_bridge_profile', $profile, false );

		return $profile;
	}

	/**
	 * Write setup JSON for Cursor workspace (SFTP) — zero manual Application Password.
	 *
	 * @return array<string, mixed>
	 */
	public static function write_setup_file(): array {
		self::ensure_setup_directory();

		$bundle = Credentials::build_cursor_bundle( true );
		$path   = self::setup_file_path();

		$payload = array_merge(
			$bundle,
			array(
				'plugin'       => 'inyfinn-cursor-bridge-mcp',
				'version'      => defined( 'INYFINN_CURSOR_BRIDGE_MCP_VERSION' ) ? INYFINN_CURSOR_BRIDGE_MCP_VERSION : '1.1.0',
				'generated_at' => gmdate( 'c' ),
				'cursor_task'  => 'Przeczytaj ten plik z workspace (SFTP). Uzupełnij ~/.cursor/mcp.json i .env w public_html. Wywołaj cursor-bridge/run-auto-setup przez MCP. Usuń ten plik po sukcesie.',
			)
		);

		$written = file_put_contents( $path, wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		return array(
			'ok'               => false !== $written,
			'path'             => $path,
			'path_relative'    => 'wp-content/' . self::SETUP_DIR . '/cursor-setup.json',
			'workspace_hint'   => 'Otwórz folder public_html w Cursorze — agent znajdzie plik bez ręcznej konfiguracji.',
			'missing_fields'   => $bundle['missing_fields'] ?? array(),
		);
	}

	public static function setup_file_path(): string {
		return trailingslashit( WP_CONTENT_DIR ) . self::SETUP_DIR . '/cursor-setup.json';
	}

	public static function setup_file_relative(): string {
		return self::SETUP_DIR . '/cursor-setup.json';
	}

	private static function ensure_setup_directory(): void {
		$dir = trailingslashit( WP_CONTENT_DIR ) . self::SETUP_DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function flush_permalinks(): array {
		flush_rewrite_rules( false );

		return array(
			'ok'             => true,
			'permalink_ok'   => (bool) get_option( 'permalink_structure' ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_status(): array {
		return array(
			'mu_plugin_loader' => self::mu_plugin_loader_present(),
			'setup_file'       => is_readable( self::setup_file_path() ),
			'setup_file_path'  => self::setup_file_path(),
			'app_password'     => Credentials::has_application_password(),
			'last_bootstrap'   => get_option( 'inyfinn_cursor_bridge_last_bootstrap', null ),
		);
	}
}
