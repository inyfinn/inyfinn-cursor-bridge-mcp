<?php
/**
 * Live verification: WordPress + database (wpdb) + files — one report for humans and Cursor.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Connection_Verify {

	/**
	 * Full connection report — use in MCP, REST, and WP Admin.
	 *
	 * @return array<string, mixed>
	 */
	public static function run(): array {
		$wp      = self::check_wordpress();
		$db      = self::check_database();
		$files   = self::check_files();
		$mcp     = self::check_mcp_endpoint();
		$secrets = self::check_credentials();

		$layers = array(
			'wordpress' => $wp['ok'],
			'database'  => $db['ok'],
			'files'     => $files['ok'],
			'mcp_rest'  => $mcp['ok'],
			'credentials' => $secrets['ok'],
		);

		$all_ok = $wp['ok'] && $db['ok'] && $files['ok'] && $mcp['ok'] && $secrets['ok'];

		return array(
			'ok'              => $all_ok,
			'ready_for_cursor'=> $all_ok,
			'bridge_version'  => defined( 'INYFINN_CURSOR_BRIDGE_MCP_VERSION' ) ? INYFINN_CURSOR_BRIDGE_MCP_VERSION : 'unknown',
			'site_url'        => home_url( '/' ),
			'mcp_endpoint'    => rest_url( 'mcp/mcp-adapter-default-server' ),
			'mcp_username'    => Credentials::get_mcp_username(),
			'has_app_password'=> Credentials::has_stored_application_password() || Credentials::has_application_password(),
			'layers'          => $layers,
			'checks'          => array(
				'wordpress'   => $wp,
				'database'    => $db,
				'files'       => $files,
				'mcp_rest'    => $mcp,
				'credentials' => $secrets,
			),
			'how_to_verify_in_cursor' => array(
				'1. Cursor → Settings → MCP → serwer WordPress = połączony (zielony)',
				'2. W chacie: cursor-bridge/verify-connection',
				'3. Oczekiwany wynik: ok:true, layers.database:true, layers.files:true',
				'4. Baza: cursor-bridge/db-query z sql SELECT COUNT(*) FROM ' . self::sample_posts_table(),
			),
			'rest_verify_urls' => array(
				'ping'       => rest_url( 'cursor-bridge/v1/ping' ),
				'verify'     => rest_url( 'cursor-bridge/v1/verify-connection' ),
				'db_tables'  => rest_url( 'cursor-bridge/v1/db-tables' ),
				'db_query'   => rest_url( 'cursor-bridge/v1/db-query' ),
			),
			'why_not_remote_mysql' => 'Baza działa przez wpdb na serwerze (jak Better Search Replace). Zdalny port 3306 z Twojego IP jest blokowany przez hosting — to normalne.',
			'next_steps'           => self::next_steps( $layers ),
			'rest_firewall'        => Rest_Firewall_Diagnostics::report(),
			'timestamp'       => gmdate( 'c' ),
		);
	}

	/**
	 * One concrete action per failed layer, so an agent knows what to call next.
	 *
	 * @param array<string, bool> $layers
	 * @return list<string>
	 */
	private static function next_steps( array $layers ): array {
		$fixes = array(
			'wordpress'   => 'WordPress itself is not answering — check the site in a browser and the PHP error log.',
			'database'    => 'Call cursor-bridge/db-info; wpdb errors usually mean wrong DB credentials in wp-config.php.',
			'files'       => 'wp-content is not writable — call cursor-bridge/repair {action:"setup_directory"} or fix permissions in the hosting panel.',
			'mcp_rest'    => 'MCP route missing — call cursor-bridge/repair {action:"permalinks"}; if REST answers 403, read rest_firewall (Wordfence / hosting WAF).',
			'credentials' => 'No application password stored — call cursor-bridge/run-auto-setup, or paste one in Settings → Cursor Bridge.',
		);
		$steps = array();
		foreach ( $layers as $layer => $ok ) {
			if ( ! $ok && isset( $fixes[ $layer ] ) ) {
				$steps[] = $fixes[ $layer ];
			}
		}
		if ( Abilities::file_write_blocked() ) {
			$steps[] = 'File writes are blocked by DISALLOW_FILE_EDIT (reads work). Ask the site owner to enable Settings → Cursor Bridge → "Zapis plików przez MCP mimo DISALLOW_FILE_EDIT".';
		}
		return $steps ? $steps : array( 'Ready. Read cursor-bridge/get-agent-playbook, then find-content / elementor-outline to start editing.' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_wordpress(): array {
		global $wp_version;

		$ok = is_blog_installed() && ! empty( $wp_version );

		return array(
			'ok'      => $ok,
			'label'   => 'WordPress CMS',
			'message' => $ok ? 'WP ' . $wp_version . ' — ' . get_bloginfo( 'name' ) : 'WordPress nie odpowiada',
			'method'  => 'PHP on server',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_database(): array {
		$ping = Db_Query::ping();
		$tables = Db_Query::list_tables();
		$ok     = ! empty( $ping['ok'] ) && ! empty( $tables['ok'] );

		$posts_table = self::sample_posts_table();
		$count       = Db_Query::run( 'SELECT COUNT(*) AS n FROM `' . esc_sql( $posts_table ) . '`' );
		$post_count  = 0;
		if ( ! empty( $count['ok'] ) && isset( $count['rows'][0]['n'] ) ) {
			$post_count = (int) $count['rows'][0]['n'];
		}

		return array(
			'ok'           => $ok && ! empty( $count['ok'] ),
			'label'        => 'Baza danych',
			'message'      => $ok
				? sprintf(
					'wpdb OK — %d tabel, %d postów w %s',
					(int) ( $tables['table_count'] ?? 0 ),
					$post_count,
					$posts_table
				)
				: (string) ( $ping['message'] ?? $tables['message'] ?? 'Brak dostępu do bazy' ),
			'method'       => 'wpdb on server (localhost from PHP)',
			'db_name'      => defined( 'DB_NAME' ) ? DB_NAME : '',
			'table_count'  => (int) ( $tables['table_count'] ?? 0 ),
			'post_count'   => $post_count,
			'access_method'=> 'cursor-bridge/db-query — NOT remote mariadb MCP',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_files(): array {
		$content_ok = is_dir( WP_CONTENT_DIR ) && is_readable( WP_CONTENT_DIR );
		$list       = File_Reader::list_directory( 'plugins' );
		$list_ok    = ! is_wp_error( $list ) && is_array( $list );

		$plugin_file = defined( 'INYFINN_CURSOR_BRIDGE_MCP_FILE' )
			? INYFINN_CURSOR_BRIDGE_MCP_FILE
			: WP_PLUGIN_DIR . '/inyfinn-cursor-bridge-mcp/inyfinn-cursor-bridge-mcp.php';
		$plugin_ok   = is_readable( $plugin_file );

		$ok            = $content_ok && $list_ok && $plugin_ok && true === Abilities::file_permission( false );
		$write_blocked = Abilities::file_write_blocked();

		return array(
			'ok'            => $ok,
			'write_allowed' => $ok && ! $write_blocked,
			'label'         => 'Pliki (wp-content)',
			'message'       => $ok
				? ( $write_blocked
					? 'Odczyt wp-content OK. Zapis przez MCP zablokowany (DISALLOW_FILE_EDIT) — Ustawienia → Cursor Bridge → „Zapis plików przez MCP mimo DISALLOW_FILE_EDIT”.'
					: 'Odczyt i zapis wp-content OK — MCP read/write-wp-content-file lub SFTP workspace' )
				: 'Brak odczytu wp-content',
			'method'  => 'MCP file abilities or SFTP mount of public_html',
			'paths'   => array(
				'wp_content' => WP_CONTENT_DIR,
				'plugins'  => WP_PLUGIN_DIR,
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_mcp_endpoint(): array {
		$routes = rest_get_server() ? rest_get_server()->get_routes() : array();
		$ok     = isset( $routes['/mcp/mcp-adapter-default-server'] );

		return array(
			'ok'      => $ok,
			'label'   => 'MCP REST endpoint',
			'message' => $ok ? rest_url( 'mcp/mcp-adapter-default-server' ) : 'Brak trasy MCP',
			'method'  => '@automattic/mcp-wordpress-remote',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_credentials(): array {
		$username = Credentials::get_mcp_username();
		$stored   = Credentials::has_stored_application_password();
		$has      = Credentials::has_application_password();
		$ok       = '' !== $username && ( $stored || $has );

		return array(
			'ok'       => $ok,
			'label'    => 'Application Password',
			'message'  => $ok
				? 'MCP user: ' . $username . ( $stored ? ' (hasło zapisane w wtyczce)' : ' (hasło w profilu WP)' )
				: 'Brak hasła — wklej w polu poniżej lub kliknij Napraw',
			'username' => $username,
			'stored'   => $stored,
		);
	}

	private static function sample_posts_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'posts';
	}
}
