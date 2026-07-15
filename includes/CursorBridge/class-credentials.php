<?php
/**
 * Auto credentials: Application Password, wp-config DB, .env and mcp.json bundles.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Credentials {

	private const APP_PASSWORD_NAME = 'Cursor MCP (Inyfinn)';
	private const OPTION_APP_UUID     = 'inyfinn_cursor_bridge_app_password_uuid';
	private const OPTION_CONNECTION = 'inyfinn_cursor_bridge_connection';

	public static function get_connection(): array {
		$stored = get_option( self::OPTION_CONNECTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$defaults = array(
			'mcp_server_name'         => 'seohost-wordpress',
			'ssh_host'                => '',
			'ssh_user'                => '',
			'ssh_port'                => 22,
			'ssh_remote_public_html'  => '',
			'workspace_public_html'   => '',
			'ftp_host'                => '',
			'ftp_user'                => '',
			'ftp_port'                => 21,
			'ftp_remote_path'         => '',
			'ftp_pass_encrypted'      => '',
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

	public static function has_application_password(): bool {
		$uuid = get_option( self::OPTION_APP_UUID, '' );
		if ( ! is_string( $uuid ) || '' === $uuid ) {
			return false;
		}

		$user_id = self::get_mcp_user_id();
		if ( ! $user_id ) {
			return false;
		}

		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
		}

		foreach ( \WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( isset( $item['uuid'] ) && $item['uuid'] === $uuid ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function ensure_application_password( bool $rotate = false ): array {
		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
		}

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

		$existing_uuid = get_option( self::OPTION_APP_UUID, '' );
		$plain         = null;

		if ( $rotate || ! self::has_application_password() ) {
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
			update_option( 'inyfinn_cursor_bridge_app_password_enc', self::encrypt_secret( $plain ), false );
		} else {
			$plain = self::decrypt_secret( (string) get_option( 'inyfinn_cursor_bridge_app_password_enc', '' ) );
		}

		return array(
			'ok'           => true,
			'user_id'      => $user_id,
			'username'     => $user->user_login,
			'app_password' => $plain,
			'rotated'      => $rotate || ! $existing_uuid,
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
		$users = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		if ( ! empty( $users[0] ) ) {
			return (int) $users[0]->ID;
		}

		return (int) get_current_user_id();
	}

	/**
	 * Full bundle for Cursor — includes secrets (admin / setup file only).
	 *
	 * @return array<string, mixed>
	 */
	public static function build_cursor_bundle( bool $include_secrets = false ): array {
		$profile    = Hosting_Profiles::get_profile();
		$connection = self::get_connection();
		$db         = self::get_db_config();
		$site_url   = untrailingslashit( home_url( '/' ) );
		$mcp_url    = rest_url( 'mcp/mcp-adapter-default-server' );

		$app = self::ensure_application_password( false );
		$user_login = $app['username'] ?? '';

		$env = array(
			'WP_SITE_URL'              => $site_url,
			'WP_MCP_API_URL'           => $mcp_url,
			'WP_MCP_USERNAME'          => $user_login,
			'WP_MCP_APP_PASSWORD'      => $include_secrets ? ( $app['app_password'] ?? '' ) : '${env:WP_MCP_APP_PASSWORD}',
			'DB_NAME'                  => $db['DB_NAME'],
			'DB_USER'                  => $db['DB_USER'],
			'DB_PASSWORD'              => $include_secrets ? $db['DB_PASSWORD'] : '${env:DB_PASSWORD}',
			'DB_HOST'                  => $db['DB_HOST'],
			'DB_TABLE_PREFIX'          => $db['table_prefix'],
			'MYSQL_DATABASE'           => $db['DB_NAME'],
			'MYSQL_USER'               => $db['DB_USER'],
			'MYSQL_PASSWORD'           => $include_secrets ? $db['DB_PASSWORD'] : '${env:MYSQL_PASSWORD}',
			'MYSQL_HOST'               => $db['DB_HOST'],
			'SSH_HOST'                 => $connection['ssh_host'],
			'SSH_USER'                 => $connection['ssh_user'],
			'SSH_PORT'                 => (string) $connection['ssh_port'],
			'SSH_REMOTE_PUBLIC_HTML'   => $connection['ssh_remote_public_html'],
			'WORKSPACE_PUBLIC_HTML'    => $connection['workspace_public_html'],
			'FTP_HOST'                 => $connection['ftp_host'],
			'FTP_USER'                 => $connection['ftp_user'],
			'FTP_PORT'                 => (string) $connection['ftp_port'],
			'FTP_REMOTE_PATH'          => $connection['ftp_remote_path'],
			'WP_CLI_COMMAND'           => $profile['wp_cli_hint'] ?? 'wp',
			'WP_ENVIRONMENT'           => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
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
						'WP_API_USERNAME' => $include_secrets ? $user_login : '${env:WP_MCP_USERNAME}',
						'WP_API_PASSWORD' => $include_secrets ? ( $app['app_password'] ?? '' ) : '${env:WP_MCP_APP_PASSWORD}',
					),
				),
			),
		);

		$env_lines = array();
		foreach ( $env as $key => $value ) {
			$env_lines[] = $key . '=' . self::env_escape( (string) $value );
		}

		return array(
			'site_url'           => $site_url,
			'mcp_endpoint'       => $mcp_url,
			'username'           => $user_login,
			'app_password'       => $include_secrets ? ( $app['app_password'] ?? '' ) : null,
			'env'                => $env,
			'env_file_content'   => implode( "\n", $env_lines ) . "\n",
			'mcp_json'           => $mcp_json,
			'mcp_json_content'   => wp_json_encode( $mcp_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n",
			'missing_fields'     => $missing,
			'hosting_provider'   => $profile['hosting_provider'] ?? 'generic',
			'setup_file_relative'=> Installer::setup_file_relative(),
			'cursor_steps'       => array(
				'1. Otwórz workspace public_html w Cursorze (SFTP/dysk).',
				'2. Przeczytaj wp-content/inyfinn-cursor-bridge/cursor-setup.json (ten plik).',
				'3. Zapisz env_file_content do public_html/.env (gitignored).',
				'4. Scal mcp_json do ~/.cursor/mcp.json.',
				'5. Uzupełnij missing_fields (SSH, WORKSPACE_PUBLIC_HTML) — zapytaj użytkownika tylko o te.',
				'6. Wywołaj MCP: cursor-bridge/ping, potem cursor-bridge/get-site-manifest.',
				'7. Edycja plików: workspace SFTP LUB cursor-bridge/write-wp-content-file przez MCP.',
				'8. Usuń cursor-setup.json po pierwszym udanym połączeniu.',
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
			$iv = random_bytes( 16 );
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
}
