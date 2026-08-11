<?php
/**
 * Auto credentials: Application Password, wp-config DB, .env and mcp.json bundles.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Credentials {

	private const APP_PASSWORD_NAME   = 'Cursor MCP (Inyfinn)';
	private const OPTION_APP_UUID     = 'inyfinn_cursor_bridge_app_password_uuid';
	private const OPTION_APP_ENC      = 'inyfinn_cursor_bridge_app_password_enc';
	private const OPTION_MCP_USER_ID  = 'inyfinn_cursor_bridge_mcp_user_id';
	private const OPTION_CONNECTION   = 'inyfinn_cursor_bridge_connection';

	public static function get_connection(): array {
		$stored = get_option( self::OPTION_CONNECTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$defaults = array(
			'mcp_server_name'        => 'seohost-wordpress',
			'ssh_host'               => '',
			'ssh_user'               => '',
			'ssh_port'               => 22,
			'ssh_remote_public_html' => '',
			'workspace_public_html'  => '',
			'ftp_host'               => '',
			'ftp_user'               => '',
			'ftp_port'               => 21,
			'ftp_remote_path'        => '',
			'ftp_pass_encrypted'     => '',
		);

		return array_merge( $defaults, $stored );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function update_connection( array $input ): array {
		$current = self::get_connection();
		$allowed = array(
			'mcp_server_name',
			'ssh_host',
			'ssh_user',
			'ssh_port',
			'ssh_remote_public_html',
			'workspace_public_html',
			'ftp_host',
			'ftp_user',
			'ftp_port',
			'ftp_remote_path',
		);

		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$value = $input[ $key ];
				if ( in_array( $key, array( 'ssh_port', 'ftp_port' ), true ) ) {
					$current[ $key ] = max( 1, (int) $value );
				} else {
					$current[ $key ] = sanitize_text_field( (string) $value );
				}
			}
		}

		if ( ! empty( $input['ftp_pass'] ) ) {
			$current['ftp_pass_encrypted'] = self::encrypt_secret( (string) $input['ftp_pass'] );
		}

		update_option( self::OPTION_CONNECTION, $current, false );

		return $current;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_db_config(): array {
		global $table_prefix;

		return array(
			'DB_NAME'      => defined( 'DB_NAME' ) ? DB_NAME : '',
			'DB_USER'      => defined( 'DB_USER' ) ? DB_USER : '',
			'DB_PASSWORD'  => defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '',
			'DB_HOST'      => defined( 'DB_HOST' ) ? DB_HOST : '',
			'DB_CHARSET'   => defined( 'DB_CHARSET' ) ? DB_CHARSET : 'utf8mb4',
			'DB_COLLATE'   => defined( 'DB_COLLATE' ) ? DB_COLLATE : '',
			'table_prefix' => is_string( $table_prefix ) ? $table_prefix : 'wp_',
		);
	}

	public static function register_application_password_filters(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		$force = static function (): bool {
			return self::should_force_application_passwords();
		};

		add_filter(
			'wp_is_application_passwords_available',
			static function ( $available ) use ( $force ) {
				if ( $force() ) {
					return true;
				}
				return $available;
			},
			PHP_INT_MAX
		);

		add_filter(
			'wp_is_application_passwords_available_for_user',
			static function ( $available, $user ) use ( $force ) {
				if ( $force() ) {
					return true;
				}
				if ( $user instanceof \WP_User && user_can( $user, 'manage_options' ) ) {
					return true;
				}
				return $available;
			},
			PHP_INT_MAX,
			2
		);
	}

	private static function should_force_application_passwords(): bool {
		if ( self::site_uses_https() || self::wordpress_urls_use_https() ) {
			return true;
		}

		if ( self::count_any_privileged_application_passwords() > 0 ) {
			return true;
		}

		if ( self::has_stored_application_password() ) {
			return true;
		}

		$home = (string) get_option( 'home', '' );
		$site = (string) get_option( 'siteurl', '' );
		foreach ( array( $home, $site ) as $url ) {
			if ( '' !== $url && 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ) {
				return true;
			}
		}

		return false;
	}

	public static function site_uses_https(): bool {
		if ( is_ssl() ) {
			return true;
		}

		if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
			return true;
		}

		if ( isset( $_SERVER['HTTP_X_FORWARDED_SSL'] ) && 'on' === strtolower( (string) $_SERVER['HTTP_X_FORWARDED_SSL'] ) ) {
			return true;
		}

		if ( defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN ) {
			return true;
		}

		return self::wordpress_urls_use_https();
	}

	public static function wordpress_urls_use_https(): bool {
		$candidates = array(
			home_url(),
			site_url(),
			(string) get_option( 'home', '' ),
			(string) get_option( 'siteurl', '' ),
		);

		foreach ( $candidates as $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
			if ( 'https' === $scheme ) {
				return true;
			}
		}

		return false;
	}

	public static function application_passwords_available(): bool {
		self::register_application_password_filters();

		if ( self::should_force_application_passwords() ) {
			return true;
		}

		if ( function_exists( 'wp_is_application_passwords_available' ) ) {
			return wp_is_application_passwords_available();
		}

		return class_exists( '\WP_Application_Passwords' );
	}

	public static function has_stored_application_password(): bool {
		$plain = self::decrypt_secret( (string) get_option( self::OPTION_APP_ENC, '' ) );
		return '' !== $plain;
	}

	public static function count_user_application_passwords( ?int $user_id = null ): int {
		$user_id = $user_id ?? self::get_mcp_user_id();
		if ( ! $user_id ) {
			return 0;
		}

		return self::count_user_application_passwords_meta( $user_id );
	}

	public static function count_user_application_passwords_meta( int $user_id ): int {
		$meta = get_user_meta( $user_id, '_application_passwords', true );
		if ( ! is_array( $meta ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $meta as $item ) {
			if ( is_array( $item ) && ! empty( $item['password'] ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @return list<int>
	 */
	public static function get_privileged_user_ids(): array {
		$ids = array();

		$by_cap = get_users(
			array(
				'capability' => 'manage_options',
				'fields'     => array( 'ID' ),
			)
		);
		foreach ( $by_cap as $user ) {
			$ids[] = (int) $user->ID;
		}

		foreach ( get_users( array( 'role' => 'administrator', 'fields' => array( 'ID' ) ) ) as $admin ) {
			$ids[] = (int) $admin->ID;
		}

		$current = (int) get_current_user_id();
		if ( $current > 0 && user_can( $current, 'manage_options' ) ) {
			$ids[] = $current;
		}

		$mcp = (int) get_option( self::OPTION_MCP_USER_ID, 0 );
		if ( $mcp > 0 ) {
			$ids[] = $mcp;
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	public static function count_any_admin_application_passwords(): int {
		return self::count_any_privileged_application_passwords();
	}

	public static function count_any_privileged_application_passwords(): int {
		$total = 0;
		foreach ( self::get_privileged_user_ids() as $user_id ) {
			$total += self::count_user_application_passwords_meta( $user_id );
		}
		return $total;
	}

	public static function find_admin_with_application_passwords(): int {
		foreach ( self::get_privileged_user_ids() as $user_id ) {
			if ( self::count_user_application_passwords_meta( $user_id ) > 0 ) {
				return $user_id;
			}
		}
		return 0;
	}

	public static function has_application_password(): bool {
		if ( self::has_stored_application_password() ) {
			return true;
		}

		$uuid    = get_option( self::OPTION_APP_UUID, '' );
		$user_id = self::get_mcp_user_id();
		if ( ! $user_id ) {
			return self::count_any_privileged_application_passwords() > 0;
		}

		$meta = get_user_meta( $user_id, '_application_passwords', true );
		if ( ! is_array( $meta ) || empty( $meta ) ) {
			return self::count_any_privileged_application_passwords() > 0;
		}

		if ( is_string( $uuid ) && '' !== $uuid ) {
			foreach ( $meta as $item ) {
				if ( isset( $item['uuid'] ) && $item['uuid'] === $uuid ) {
					return true;
				}
			}
		}

		return count( $meta ) > 0;
	}

	public static function store_application_password( string $plain, ?int $user_id = null ): array {
		self::register_application_password_filters();

		$plain = preg_replace( '/\s+/', '', trim( $plain ) );
		if ( '' === $plain ) {
			return array(
				'ok'      => false,
				'message' => 'Puste hasło aplikacji.',
			);
		}

		$match = self::find_user_for_application_password( $plain, $user_id );
		if ( null === $match ) {
			return array(
				'ok'      => false,
				'message' => 'Hasło nie pasuje do żadnego hasła aplikacji na tej stronie. Utwórz nowe w profilu WP i wklej ponownie.',
			);
		}

		$user_id = (int) $match['user_id'];
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return array(
				'ok'      => false,
				'message' => 'Użytkownik MCP nie istnieje.',
			);
		}

		update_option( self::OPTION_MCP_USER_ID, $user_id, false );
		update_option( self::OPTION_APP_ENC, self::encrypt_secret( $plain ), false );
		update_option( self::OPTION_APP_UUID, (string) $match['uuid'], false );

		return array(
			'ok'           => true,
			'user_id'      => $user_id,
			'username'     => $user->user_login,
			'app_password' => $plain,
			'linked_uuid'  => true,
			'source'       => 'manual_store',
		);
	}

	/**
	 * @return array{user_id: int, uuid: string}|null
	 */
	private static function find_user_for_application_password( string $plain, ?int $preferred_user_id = null ): ?array {
		self::register_application_password_filters();

		$candidates = array();

		if ( null !== $preferred_user_id && $preferred_user_id > 0 ) {
			$candidates[] = (int) $preferred_user_id;
		}

		$current = (int) get_current_user_id();
		if ( $current > 0 && user_can( $current, 'manage_options' ) ) {
			$candidates[] = $current;
		}

		$mcp_user = self::get_mcp_user_id();
		if ( $mcp_user > 0 ) {
			$candidates[] = $mcp_user;
		}

		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => array( 'ID', 'user_login' ),
			)
		);
		foreach ( $admins as $admin ) {
			$candidates[] = (int) $admin->ID;
		}

		$candidates = array_values( array_unique( array_filter( $candidates ) ) );
		self::load_application_passwords_class();

		foreach ( $candidates as $user_id ) {
			$linked = self::match_application_password_in_user_meta( $user_id, $plain );
			if ( null !== $linked ) {
				return $linked;
			}

			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}

			$authenticated = wp_authenticate_application_password( null, $user->user_login, $plain );
			if ( $authenticated instanceof \WP_User ) {
				$linked = self::match_application_password_in_user_meta( $authenticated->ID, $plain );
				if ( null !== $linked ) {
					return $linked;
				}

				$passwords = \WP_Application_Passwords::get_user_application_passwords( $authenticated->ID );
				$uuid      = '';
				if ( is_array( $passwords ) && isset( $passwords[0]['uuid'] ) ) {
					$uuid = (string) $passwords[0]['uuid'];
				}

				return array(
					'user_id' => $authenticated->ID,
					'uuid'    => $uuid,
				);
			}
		}

		return null;
	}

	/**
	 * @return array{user_id: int, uuid: string}|null
	 */
	private static function match_application_password_in_user_meta( int $user_id, string $plain ): ?array {
		$meta = get_user_meta( $user_id, '_application_passwords', true );
		if ( ! is_array( $meta ) ) {
			return null;
		}

		foreach ( $meta as $item ) {
			if ( ! isset( $item['password'], $item['uuid'] ) ) {
				continue;
			}
			if ( wp_check_password( $plain, $item['password'], $user_id ) ) {
				return array(
					'user_id' => $user_id,
					'uuid'    => (string) $item['uuid'],
				);
			}
		}

		return null;
	}

	/**
	 * Jednorazowy import hasła z wp-content/inyfinn-cursor-bridge/manual-pass.txt (usuwany po sukcesie).
	 */
	public static function maybe_consume_manual_pass_file(): void {
		if ( self::has_stored_application_password() ) {
			return;
		}

		self::register_application_password_filters();

		$path = trailingslashit( WP_CONTENT_DIR ) . 'inyfinn-cursor-bridge/manual-pass.txt';
		if ( ! is_readable( $path ) ) {
			return;
		}

		$plain = trim( (string) file_get_contents( $path ) );
		if ( '' === $plain ) {
			return;
		}

		$result = self::store_application_password( $plain );
		if ( ! empty( $result['ok'] ) ) {
			@unlink( $path );
			Installer::write_setup_file( self::build_cursor_bundle( true, $result ) );
		}
	}

	public static function get_mcp_username(): string {
		$user_id = self::get_mcp_user_id();
		if ( ! $user_id ) {
			return '';
		}

		$user = get_userdata( $user_id );
		return $user ? (string) $user->user_login : '';
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function ensure_application_password( bool $rotate = false ): array {
		self::maybe_consume_manual_pass_file();

		if ( self::has_stored_application_password() && ! $rotate ) {
			$user_id = self::get_mcp_user_id();
			$user    = get_userdata( $user_id );
			$plain   = self::decrypt_secret( (string) get_option( self::OPTION_APP_ENC, '' ) );
			if ( $user && '' !== $plain ) {
				return array(
					'ok'           => true,
					'user_id'      => $user_id,
					'username'     => $user->user_login,
					'app_password' => $plain,
					'rotated'      => false,
					'source'       => 'stored_option',
				);
			}
		}

		if ( ! self::application_passwords_available() ) {
			if ( self::count_any_privileged_application_passwords() > 0 ) {
				return array(
					'ok'      => false,
					'message' => 'Hasło aplikacji istnieje w profilu WP, ale wtyczka nie ma kopii. Wklej je w Ustawienia → Cursor Bridge.',
				);
			}

			return array(
				'ok'      => false,
				'message' => 'Application passwords are not available on this site.',
			);
		}

		self::load_application_passwords_class();

		$user_id = self::get_mcp_user_id();
		if ( ! $user_id ) {
			return array(
				'ok'      => false,
				'message' => 'No administrator user found for MCP.',
			);
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array(
				'ok'      => false,
				'message' => 'MCP user not found.',
			);
		}

		$had_uuid = is_string( get_option( self::OPTION_APP_UUID, '' ) ) && '' !== get_option( self::OPTION_APP_UUID, '' );
		$plain    = null;
		$rotated  = false;

		if ( $rotate || ! self::has_application_password() ) {
			$created = self::create_application_password( $user_id );
			if ( empty( $created['ok'] ) ) {
				return $created;
			}
			$plain   = $created['app_password'];
			$rotated = true;
		} else {
			$plain = self::decrypt_secret( (string) get_option( self::OPTION_APP_ENC, '' ) );
			if ( '' === $plain ) {
				if ( self::count_user_application_passwords( $user_id ) > 0 ) {
					return array(
						'ok'      => false,
						'message' => 'Hasło aplikacji istnieje w profilu WP, ale wtyczka nie ma kopii. Wklej je w Ustawienia → Cursor Bridge.',
					);
				}

				// Encrypted copy lost or AUTH_KEY changed — rotate to recover.
				$created = self::create_application_password( $user_id );
				if ( empty( $created['ok'] ) ) {
					return $created;
				}
				$plain   = $created['app_password'];
				$rotated = true;
			}
		}

		if ( ! is_string( $plain ) || '' === $plain ) {
			return array(
				'ok'      => false,
				'message' => 'Application password could not be retrieved.',
			);
		}

		return array(
			'ok'           => true,
			'user_id'      => $user_id,
			'username'     => $user->user_login,
			'app_password' => $plain,
			'rotated'      => $rotated || ! $had_uuid,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function create_application_password( int $user_id ): array {
		self::delete_named_app_passwords( $user_id );

		$created = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => self::APP_PASSWORD_NAME )
		);

		if ( is_wp_error( $created ) ) {
			return array(
				'ok'      => false,
				'message' => $created->get_error_message(),
			);
		}

		$plain = $created[0];
		$item  = $created[1];

		update_option( self::OPTION_APP_UUID, $item['uuid'] ?? '', false );
		update_option( self::OPTION_APP_ENC, self::encrypt_secret( $plain ), false );
		update_option( self::OPTION_MCP_USER_ID, $user_id, false );

		return array(
			'ok'           => true,
			'user_id'      => $user_id,
			'app_password' => $plain,
		);
	}

	private static function delete_named_app_passwords( int $user_id ): void {
		foreach ( \WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( isset( $item['name'] ) && self::APP_PASSWORD_NAME === $item['name'] && isset( $item['uuid'] ) ) {
				\WP_Application_Passwords::delete_application_password( $user_id, $item['uuid'] );
			}
		}
	}

	private static function get_mcp_user_id(): int {
		$stored = (int) get_option( self::OPTION_MCP_USER_ID, 0 );
		if ( $stored > 0 ) {
			$user = get_userdata( $stored );
			if ( $user && user_can( $user, 'manage_options' ) ) {
				if ( self::count_user_application_passwords_meta( $stored ) > 0 ) {
					return $stored;
				}
			}
		}

		$with_passwords = self::find_admin_with_application_passwords();
		if ( $with_passwords > 0 ) {
			update_option( self::OPTION_MCP_USER_ID, $with_passwords, false );
			return $with_passwords;
		}

		$current = (int) get_current_user_id();
		if ( $current > 0 && user_can( $current, 'manage_options' ) ) {
			update_option( self::OPTION_MCP_USER_ID, $current, false );
			return $current;
		}

		if ( $stored > 0 ) {
			$user = get_userdata( $stored );
			if ( $user && user_can( $user, 'manage_options' ) ) {
				return $stored;
			}
		}

		$users = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		if ( ! empty( $users[0] ) ) {
			$user_id = (int) $users[0]->ID;
			update_option( self::OPTION_MCP_USER_ID, $user_id, false );
			return $user_id;
		}

		return 0;
	}

	/**
	 * Full bundle for Cursor — secrets only when requested.
	 *
	 * @param array<string, mixed>|null $app_context Optional result from ensure_application_password().
	 * @return array<string, mixed>
	 */
	public static function build_cursor_bundle( bool $include_secrets = false, ?array $app_context = null ): array {
		$profile    = Hosting_Profiles::get_profile();
		$connection = self::get_connection();
		$db         = self::get_db_config();
		$site_url   = untrailingslashit( home_url( '/' ) );
		$mcp_url    = rest_url( 'mcp/mcp-adapter-default-server' );
		$user_login = self::get_mcp_username();
		$plain_pass = '';

		if ( $include_secrets ) {
			if ( is_array( $app_context ) && ! empty( $app_context['ok'] ) && ! empty( $app_context['app_password'] ) ) {
				$user_login = (string) ( $app_context['username'] ?? $user_login );
				$plain_pass = (string) $app_context['app_password'];
			} else {
				$app = self::ensure_application_password( false );
				if ( ! empty( $app['ok'] ) && ! empty( $app['app_password'] ) ) {
					$user_login = (string) ( $app['username'] ?? $user_login );
					$plain_pass = (string) $app['app_password'];
				}
			}
		}

		$env = array(
			'WP_SITE_URL'            => $site_url,
			'WP_MCP_API_URL'         => $mcp_url,
			'WP_MCP_USERNAME'        => $user_login,
			'WP_MCP_APP_PASSWORD'    => $include_secrets && '' !== $plain_pass ? $plain_pass : '${env:WP_MCP_APP_PASSWORD}',
			'DB_NAME'                => $db['DB_NAME'],
			'DB_USER'                => $db['DB_USER'],
			'DB_PASSWORD'            => $include_secrets ? $db['DB_PASSWORD'] : '${env:DB_PASSWORD}',
			'DB_HOST'                => $db['DB_HOST'],
			'DB_TABLE_PREFIX'        => $db['table_prefix'],
			'MYSQL_DATABASE'         => $db['DB_NAME'],
			'MYSQL_USER'             => $db['DB_USER'],
			'MYSQL_PASSWORD'         => $include_secrets ? $db['DB_PASSWORD'] : '${env:MYSQL_PASSWORD}',
			'MYSQL_HOST'             => $db['DB_HOST'],
			'SSH_HOST'               => $connection['ssh_host'],
			'SSH_USER'               => $connection['ssh_user'],
			'SSH_PORT'               => (string) $connection['ssh_port'],
			'SSH_REMOTE_PUBLIC_HTML' => $connection['ssh_remote_public_html'],
			'WORKSPACE_PUBLIC_HTML'  => $connection['workspace_public_html'],
			'FTP_HOST'               => $connection['ftp_host'],
			'FTP_USER'               => $connection['ftp_user'],
			'FTP_PORT'               => (string) $connection['ftp_port'],
			'FTP_REMOTE_PATH'        => $connection['ftp_remote_path'],
			'WP_CLI_COMMAND'         => $profile['wp_cli_hint'] ?? 'wp',
			'WP_ENVIRONMENT'         => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
		);

		if ( $include_secrets && ! empty( $connection['ftp_pass_encrypted'] ) ) {
			$env['FTP_PASSWORD'] = self::decrypt_secret( $connection['ftp_pass_encrypted'] );
		} else {
			$env['FTP_PASSWORD'] = '${env:FTP_PASSWORD}';
		}

		$missing = array();
		foreach ( array( 'SSH_HOST', 'SSH_USER', 'SSH_REMOTE_PUBLIC_HTML', 'WORKSPACE_PUBLIC_HTML' ) as $key ) {
			if ( '' === trim( (string) ( $env[ $key ] ?? '' ) ) || 0 === strpos( (string) $env[ $key ], '${' ) ) {
				$missing[] = $key;
			}
		}

		$server_name = $connection['mcp_server_name'] ?: ( $profile['mcp_json']['server_name'] ?? 'seohost-wordpress' );

		$mcp_json = array(
			'mcpServers' => array(
				$server_name => array(
					'command' => 'npx',
					'args'    => array(
						'-y',
						$profile['mcp_json']['package'] ?? '@automattic/mcp-wordpress-remote@latest',
					),
					'env'     => array(
						'WP_API_URL'      => $mcp_url,
						'WP_API_USERNAME' => $include_secrets && '' !== $user_login ? $user_login : '${env:WP_MCP_USERNAME}',
						'WP_API_PASSWORD' => $include_secrets && '' !== $plain_pass ? $plain_pass : '${env:WP_MCP_APP_PASSWORD}',
					),
				),
			),
		);

		$env_lines = array();
		foreach ( $env as $key => $value ) {
			$env_lines[] = $key . '=' . self::env_escape( (string) $value );
		}

		return array(
			'site_url'            => $site_url,
			'mcp_endpoint'        => $mcp_url,
			'username'            => $user_login,
			'app_password'        => $include_secrets && '' !== $plain_pass ? $plain_pass : null,
			'has_app_password'    => self::has_application_password(),
			'env'                 => $env,
			'env_file_content'    => implode( "\n", $env_lines ) . "\n",
			'mcp_json'            => $mcp_json,
			'mcp_json_content'    => wp_json_encode( $mcp_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n",
			'missing_fields'      => $missing,
			'hosting_provider'    => $profile['hosting_provider'] ?? 'generic',
			'setup_file_relative' => Installer::setup_file_relative(),
			'cursor_steps'        => array(
				'1. Jeden serwer MCP (wordpress-remote) = WordPress + baza + pliki wp-content.',
				'2. Baza: cursor-bridge/db-query (wpdb na serwerze — NIE zdalny mariadb MCP).',
				'3. Pliki: workspace SFTP LUB cursor-bridge/read/write-wp-content-file.',
				'4. Zapisz env_file_content do public_html/.env (gitignored).',
				'5. Scal mcp_json do ~/.cursor/mcp.json — tylko jeden serwer WordPress.',
				'6. SSH opcjonalny — tylko dla WP-CLI w terminalu.',
				'7. Test: cursor-bridge/ping, cursor-bridge/db-query, cursor-bridge/health-check.',
				'8. Usuń cursor-setup.json po pierwszym udanym połączeniu.',
			),
			'access_model'        => array(
				'wordpress' => 'MCP cursor-bridge/* przez @automattic/mcp-wordpress-remote',
				'database'  => 'MCP cursor-bridge/db-query — wpdb on server (same as BSR)',
				'files'     => 'SFTP workspace OR MCP read/write-wp-content-file',
				'not_needed'=> 'Remote mariadb MCP — hosting blocks external port 3306',
			),
		);
	}

	private static function env_escape( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/[\s#="\']/', $value ) ) {
			return '"' . str_replace( '"', '\\"', $value ) . '"';
		}
		return $value;
	}

	public static function encrypt_secret( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$key = self::encryption_key();
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv  = random_bytes( 16 );
			$enc = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			if ( false !== $enc ) {
				return base64_encode( $iv . $enc );
			}
		}
		return base64_encode( $plain );
	}

	public static function decrypt_secret( string $encoded ): string {
		if ( '' === $encoded ) {
			return '';
		}
		$raw = base64_decode( $encoded, true );
		if ( false === $raw ) {
			return '';
		}
		$key = self::encryption_key();
		if ( function_exists( 'openssl_decrypt' ) && strlen( $raw ) > 16 ) {
			$iv  = substr( $raw, 0, 16 );
			$enc = substr( $raw, 16 );
			$dec = openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			if ( false !== $dec ) {
				return $dec;
			}
		}
		return $raw;
	}

	private static function encryption_key(): string {
		return hash( 'sha256', ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'inyfinn' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'bridge' ), true );
	}

	private static function load_application_passwords_class(): void {
		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
		}
	}
}
