<?php
/**
 * Automatic installation: setup file, conflicting plugins. No force-load mu-plugin.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Installer {

	private const SETUP_DIR = 'inyfinn-cursor-bridge';

	public const INSTALLED_VERSION_OPTION = 'inyfinn_cursor_bridge_installed_version';
	public const LAST_RESULT_OPTION       = 'inyfinn_cursor_bridge_last_install_result';
	public const REDIRECT_TRANSIENT       = 'inyfinn_cursor_bridge_activation_redirect';
	private const RETRY_TRANSIENT         = 'inyfinn_cursor_bridge_install_retry';

	/** @var bool */
	private static $defer_conflict_deactivation = false;

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_after_activation' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_complete_install' ), 4 );
		add_action( 'rest_api_init', array( __CLASS__, 'maybe_complete_install' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_self_heal' ), 5 );
		add_action( 'shutdown', array( __CLASS__, 'run_deferred_conflict_deactivation' ), 1 );
	}

	/**
	 * Install/upgrade entry point. Records the result (no secrets) and marks the
	 * version as installed only on success, so a failed install is retried.
	 *
	 * @param string $trigger activation|manual|admin|rest.
	 * @return array<string, mixed>
	 */
	public static function run_install( string $trigger ): array {
		// Plik z sekretami tylko przy Włącz albo gdy hasła jeszcze nie ma — upgrade nie odtwarza pliku, który user skasował.
		$write_setup = in_array( $trigger, array( 'activation', 'manual' ), true ) || ! Credentials::has_stored_application_password();
		Front_Overlays::preserve_legacy_defaults();
		$result      = self::full_bootstrap( true, $write_setup );
		$version     = defined( 'INYFINN_CURSOR_BRIDGE_MCP_VERSION' ) ? INYFINN_CURSOR_BRIDGE_MCP_VERSION : '';

		update_option(
			self::LAST_RESULT_OPTION,
			array(
				'ok'      => ! empty( $result['ok'] ),
				'errors'  => $result['errors'] ?? array(),
				'trigger' => $trigger,
				'version' => $version,
				'at'      => gmdate( 'c' ),
			),
			false
		);

		if ( ! empty( $result['ok'] ) ) {
			update_option( self::INSTALLED_VERSION_OPTION, $version, true );
			delete_transient( self::RETRY_TRANSIENT );
		} else {
			set_transient( self::RETRY_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Activation hook does not fire on update (GitHub updater, FTP, git pull) nor
	 * retry after a failure. Finish the install on the first admin or REST request
	 * made by an administrator (REST covers agents using an application password).
	 */
	public static function maybe_complete_install(): void {
		$version = defined( 'INYFINN_CURSOR_BRIDGE_MCP_VERSION' ) ? INYFINN_CURSOR_BRIDGE_MCP_VERSION : '';
		if ( get_option( self::INSTALLED_VERSION_OPTION, '' ) === $version ) {
			return;
		}
		if ( doing_action( 'activate_plugin' ) || get_transient( self::RETRY_TRANSIENT ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::run_install( doing_action( 'rest_api_init' ) ? 'rest' : 'admin' );
	}

	public static function maybe_redirect_after_activation(): void {
		if ( ! get_transient( self::REDIRECT_TRANSIENT ) ) {
			return;
		}
		delete_transient( self::REDIRECT_TRANSIENT );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag set by WP core.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=inyfinn-cursor-bridge' ) );
		exit;
	}

	/**
	 * Full bootstrap — activation hook and MCP ability.
	 *
	 * @param bool $write_setup Write cursor-setup.json (contains secrets).
	 * @return array<string, mixed>
	 */
	public static function full_bootstrap( bool $rotate_password = true, bool $write_setup = true ): array {
		Credentials::register_application_password_filters();
		Credentials::maybe_consume_manual_pass_file();

		$rotate       = $rotate_password && ! Credentials::has_stored_application_password();
		$app_password = Credentials::ensure_application_password( $rotate );

		$results = array(
			'plugin_active'   => self::ensure_plugin_active(),
			'mu_plugin'       => self::remove_mu_plugin_loader(),
			'conflicts'       => self::deactivate_conflicting_plugins(),
			'profile'         => self::ensure_hosting_profile(),
			'app_password'    => $app_password,
			'setup_file'      => array( 'ok' => false, 'message' => 'Skipped — app password not ready.' ),
			'permalink_flush' => self::flush_permalinks(),
		);

		if ( ! $write_setup ) {
			$results['setup_file'] = array( 'ok' => true, 'skipped' => true, 'message' => 'Upgrade — plik setup nie jest odtwarzany.' );
			$results['bundle']     = Credentials::build_cursor_bundle( false );
		} elseif ( ! empty( $app_password['ok'] ) ) {
			$bundle                 = Credentials::build_cursor_bundle( true, $app_password );
			$results['setup_file']  = self::write_setup_file( $bundle );
			$results['bundle']      = $bundle;
		} else {
			$results['bundle'] = Credentials::build_cursor_bundle( false );
		}

		$results['ok']     = self::is_bootstrap_successful( $results );
		$results['errors'] = self::collect_bootstrap_errors( $results );

		update_option( 'inyfinn_cursor_bridge_last_bootstrap', gmdate( 'c' ), false );

		return $results;
	}

	/**
	 * @param array<string, mixed> $results
	 */
	private static function is_bootstrap_successful( array $results ): bool {
		foreach ( array( 'plugin_active', 'app_password', 'setup_file' ) as $key ) {
			if ( empty( $results[ $key ]['ok'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $results
	 * @return list<string>
	 */
	private static function collect_bootstrap_errors( array $results ): array {
		$steps  = array( 'plugin_active', 'mu_plugin', 'conflicts', 'profile', 'app_password', 'setup_file', 'permalink_flush' );
		$errors = array();

		foreach ( $steps as $step ) {
			if ( ! isset( $results[ $step ] ) || ! is_array( $results[ $step ] ) ) {
				continue;
			}
			$result = $results[ $step ];
			if ( ! empty( $result['ok'] ) ) {
				continue;
			}
			if ( ! empty( $result['message'] ) ) {
				$errors[] = $step . ': ' . $result['message'];
			} else {
				$errors[] = $step . ': failed';
			}
		}

		return $errors;
	}

	public static function maybe_self_heal(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		Credentials::register_application_password_filters();

		if ( get_transient( 'inyfinn_cursor_bridge_self_heal' ) ) {
			return;
		}
		set_transient( 'inyfinn_cursor_bridge_self_heal', 1, 10 * MINUTE_IN_SECONDS );

		$healed = false;

		if ( self::mu_plugin_loader_present() ) {
			self::remove_mu_plugin_loader();
			$healed = true;
		}

		if ( ! Credentials::has_application_password() ) {
			Credentials::maybe_consume_manual_pass_file();
			if ( ! Credentials::has_stored_application_password() ) {
				$app = Credentials::ensure_application_password( false );
				if ( ! empty( $app['ok'] ) ) {
					self::write_setup_file( Credentials::build_cursor_bundle( true, $app ) );
				}
			} else {
				self::write_setup_file( Credentials::build_cursor_bundle( true ) );
			}
			$healed = true;
		}

		if ( $healed ) {
			update_option( 'inyfinn_cursor_bridge_last_self_heal', gmdate( 'c' ), false );
		}
	}

	public static function mu_plugin_loader_path(): string {
		$mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';

		return trailingslashit( $mu_dir ) . '000-inyfinn-cursor-bridge-mcp-loader.php';
	}

	/**
	 * Usuń leftover z 1.5.x — wtyczka ma się ładować tylko po Włącz (active_plugins).
	 *
	 * @return array<string, mixed>
	 */
	public static function remove_mu_plugin_loader(): array {
		$dest    = self::mu_plugin_loader_path();
		$existed = file_exists( $dest );

		if ( $existed ) {
			if ( function_exists( 'wp_delete_file' ) ) {
				wp_delete_file( $dest );
			}
			if ( file_exists( $dest ) ) {
				@unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		$gone = ! file_exists( $dest );

		return array(
			'ok'      => $gone,
			'removed' => $existed && $gone,
			'path'    => $dest,
			'message' => $gone
				? ( $existed ? 'Usunięto legacy mu-loader — wtyczka startuje tylko po Włącz.' : 'Brak mu-loadera (ładuje tylko Włącz).' )
				: 'Nie udało się usunąć mu-loadera: ' . $dest,
		);
	}

	/**
	 * @deprecated 1.6.0 Napraw z panelu usuwa loader, nie instaluje.
	 *
	 * @return array<string, mixed>
	 */
	public static function ensure_mu_plugin_loader(): array {
		return self::remove_mu_plugin_loader();
	}

	public static function mu_plugin_loader_present(): bool {
		return is_readable( self::mu_plugin_loader_path() );
	}

	/**
	 * Raportuje, czy WP ma wtyczkę w active_plugins. Nie woła activate_plugin().
	 *
	 * Źródło: https://developer.wordpress.org/plugins/plugin-basics/activation-deactivation-hooks/
	 *
	 * @return array<string, mixed>
	 */
	public static function ensure_plugin_active(): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin = plugin_basename( INYFINN_CURSOR_BRIDGE_MCP_FILE );

		if ( is_plugin_active( $plugin ) ) {
			return array(
				'ok'        => true,
				'activated' => false,
			);
		}

		if ( doing_action( 'activate_plugin' ) || doing_action( 'activate_' . $plugin ) ) {
			return array(
				'ok'        => true,
				'activated' => false,
				'skipped'   => 'activation_in_progress',
			);
		}

		return array(
			'ok'        => false,
			'activated' => false,
			'message'   => 'Włącz wtyczkę przyciskiem Włącz na ekranie Wtyczki. Kod nie wywołuje activate_plugin().',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function deactivate_conflicting_plugins(): array {
		if ( self::should_defer_conflict_deactivation() ) {
			self::$defer_conflict_deactivation = true;
			return array(
				'ok'        => true,
				'deactivated' => array(),
				'scheduled' => true,
			);
		}

		return self::run_conflict_deactivation();
	}

	public static function run_deferred_conflict_deactivation(): void {
		if ( ! self::$defer_conflict_deactivation ) {
			return;
		}
		self::$defer_conflict_deactivation = false;
		self::run_conflict_deactivation();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function run_conflict_deactivation(): array {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$conflicts   = array(
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

		$duplicate_dirs = self::remove_duplicate_plugin_directories();
		if ( ! empty( $duplicate_dirs ) ) {
			$deactivated = array_merge( $deactivated, $duplicate_dirs );
		}

		return array(
			'ok'          => true,
			'deactivated' => $deactivated,
		);
	}

	/**
	 * Dezaktywuj kopie wtyczki w innych folderach (np. inyfinn-cursor-bridge-mcp-1.3.1).
	 * Nie kasuje katalogów — stała INYFINN_CURSOR_BRIDGE_MCP_LOADED i tak blokuje podwójne ładowanie.
	 *
	 * @return list<string> Dezaktywowane foldery.
	 */
	private static function remove_duplicate_plugin_directories(): array {
		$deactivated = array();
		$canonical   = dirname( plugin_basename( INYFINN_CURSOR_BRIDGE_MCP_FILE ) );
		$plugin_root = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '';

		if ( '' === $plugin_root || ! is_dir( $plugin_root ) || ! function_exists( 'deactivate_plugins' ) ) {
			return $deactivated;
		}

		$matches = glob( trailingslashit( $plugin_root ) . 'inyfinn-cursor-bridge-mcp*', GLOB_ONLYDIR );
		if ( ! is_array( $matches ) ) {
			return $deactivated;
		}

		foreach ( $matches as $dir ) {
			$basename = basename( $dir );
			$plugin   = $basename . '/inyfinn-cursor-bridge-mcp.php';
			if ( $basename === $canonical || ! is_plugin_active( $plugin ) ) {
				continue;
			}
			deactivate_plugins( $plugin, true );
			$deactivated[] = $basename;
		}

		return $deactivated;
	}

	private static function should_defer_conflict_deactivation(): bool {
		if ( doing_action( 'activate_plugin' ) || doing_action( 'activate_' . plugin_basename( INYFINN_CURSOR_BRIDGE_MCP_FILE ) ) ) {
			return true;
		}
		return ! did_action( 'plugins_loaded' );
	}

	public static function ensure_hosting_profile_public(): array {
		return self::ensure_hosting_profile();
	}

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

		return array_merge( $profile, array( 'ok' => true ) );
	}

	/**
	 * Write setup JSON for Cursor workspace (SFTP).
	 *
	 * @param array<string, mixed>|null $bundle
	 * @return array<string, mixed>
	 */
	public static function write_setup_file( ?array $bundle = null ): array {
		self::ensure_setup_directory();

		if ( null === $bundle ) {
			$bundle = Credentials::build_cursor_bundle( true );
		}

		$path = self::setup_file_path();

		$payload = array_merge(
			$bundle,
			array(
				'plugin'       => 'inyfinn-cursor-bridge-mcp',
				'version'      => defined( 'INYFINN_CURSOR_BRIDGE_MCP_VERSION' ) ? INYFINN_CURSOR_BRIDGE_MCP_VERSION : '1.1.1',
				'generated_at' => gmdate( 'c' ),
				'verify_connection' => Connection_Verify::run(),
				'cursor_task'  => 'Przeczytaj ten plik z workspace (SFTP). Uzupełnij ~/.cursor/mcp.json i .env w public_html. Wywołaj cursor-bridge/verify-connection. Usuń ten plik po sukcesie.',
			)
		);

		$written = file_put_contents(
			$path,
			wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			LOCK_EX
		);

		if ( false !== $written && file_exists( $path ) ) {
			@chmod( $path, 0600 );
		}

		return array(
			'ok'             => false !== $written,
			'path'           => $path,
			'path_relative'  => 'wp-content/' . self::SETUP_DIR . '/cursor-setup.json',
			'workspace_hint' => 'Otwórz folder public_html w Cursorze — agent znajdzie plik bez ręcznej konfiguracji.',
			'missing_fields' => $bundle['missing_fields'] ?? array(),
		);
	}

	public static function setup_file_path(): string {
		return trailingslashit( WP_CONTENT_DIR ) . self::SETUP_DIR . '/cursor-setup.json';
	}

	public static function setup_file_relative(): string {
		return self::SETUP_DIR . '/cursor-setup.json';
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function ensure_setup_directory_public(): array {
		self::ensure_setup_directory();
		$dir = trailingslashit( WP_CONTENT_DIR ) . self::SETUP_DIR;

		return array(
			'ok'       => is_dir( $dir ) && file_exists( $dir . '/.htaccess' ),
			'path'     => $dir,
			'htaccess' => file_exists( $dir . '/.htaccess' ),
		);
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
	public static function flush_permalinks_public(): array {
		return self::flush_permalinks();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function flush_permalinks(): array {
		flush_rewrite_rules( false );

		return array(
			'ok'           => true,
			'permalink_ok' => (bool) get_option( 'permalink_structure' ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_status(): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_file = plugin_basename( INYFINN_CURSOR_BRIDGE_MCP_FILE );

		return array(
			'plugin_active'    => is_plugin_active( $plugin_file ),
			'mu_plugin_loader' => self::mu_plugin_loader_present(),
			'setup_file'       => is_readable( self::setup_file_path() ),
			'setup_file_path'  => self::setup_file_path(),
			'app_password'     => Credentials::has_application_password(),
			'mcp_username'     => Credentials::get_mcp_username(),
			'last_bootstrap'   => get_option( 'inyfinn_cursor_bridge_last_bootstrap', null ),
			'last_self_heal'   => get_option( 'inyfinn_cursor_bridge_last_self_heal', null ),
		);
	}
}
