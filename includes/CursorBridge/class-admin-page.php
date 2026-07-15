<?php
/**
 * WP Admin: Cursor Bridge — health, repair, hardening, connection settings.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Admin_Page {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( INYFINN_CURSOR_BRIDGE_MCP_FILE ), array( __CLASS__, 'plugin_action_links' ) );
	}

	/**
	 * @param list<string> $links
	 * @return list<string>
	 */
	public static function plugin_action_links( array $links ): array {
		$url = admin_url( 'options-general.php?page=inyfinn-cursor-bridge' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Diagnostyka', 'inyfinn-cursor-bridge-mcp' ) . '</a>'
		);
		return $links;
	}

	public static function register_menu(): void {
		add_options_page(
			__( 'Cursor Bridge MCP', 'inyfinn-cursor-bridge-mcp' ),
			__( 'Cursor Bridge', 'inyfinn-cursor-bridge-mcp' ),
			'manage_options',
			'inyfinn-cursor-bridge',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['inyfinn_cursor_bridge_save'] ) && check_admin_referer( 'inyfinn_cursor_bridge_settings' ) ) {
			Credentials::update_connection(
				array(
					'mcp_server_name'        => sanitize_text_field( wp_unslash( $_POST['mcp_server_name'] ?? '' ) ),
					'ssh_host'               => sanitize_text_field( wp_unslash( $_POST['ssh_host'] ?? '' ) ),
					'ssh_user'               => sanitize_text_field( wp_unslash( $_POST['ssh_user'] ?? '' ) ),
					'ssh_port'               => (int) ( $_POST['ssh_port'] ?? 22 ),
					'ssh_remote_public_html' => sanitize_text_field( wp_unslash( $_POST['ssh_remote_public_html'] ?? '' ) ),
					'workspace_public_html'  => sanitize_text_field( wp_unslash( $_POST['workspace_public_html'] ?? '' ) ),
					'ftp_host'               => sanitize_text_field( wp_unslash( $_POST['ftp_host'] ?? '' ) ),
					'ftp_user'               => sanitize_text_field( wp_unslash( $_POST['ftp_user'] ?? '' ) ),
					'ftp_port'               => (int) ( $_POST['ftp_port'] ?? 21 ),
					'ftp_remote_path'        => sanitize_text_field( wp_unslash( $_POST['ftp_remote_path'] ?? '' ) ),
					'ftp_pass'               => isset( $_POST['ftp_pass'] ) ? (string) wp_unslash( $_POST['ftp_pass'] ) : '',
				)
			);
			Installer::write_setup_file();
			add_settings_error( 'inyfinn_cursor_bridge', 'saved', __( 'Ustawienia zapisane. Plik cursor-setup.json odświeżony.', 'inyfinn-cursor-bridge-mcp' ), 'success' );
		}

		if ( isset( $_GET['inyfinn_bootstrap'] ) && check_admin_referer( 'inyfinn_bootstrap' ) ) {
			self::handle_bootstrap_result( Installer::full_bootstrap( true ) );
		}

		if ( isset( $_GET['inyfinn_repair'] ) && check_admin_referer( 'inyfinn_repair' ) ) {
			$action = sanitize_key( (string) wp_unslash( $_GET['inyfinn_repair'] ) );
			$rotate = ! empty( $_GET['rotate'] );
			$result = Health::repair( $action, $rotate );
			if ( ! empty( $result['ok'] ) || ! empty( $result['health']['healthy'] ) ) {
				add_settings_error(
					'inyfinn_cursor_bridge',
					'repair',
					sprintf(
						/* translators: %s: repair action id */
						__( 'Naprawa „%s” wykonana. Odśwież diagnostykę poniżej.', 'inyfinn-cursor-bridge-mcp' ),
						$action
					),
					'success'
				);
			} else {
				$msg = $result['message'] ?? __( 'Naprawa nie powiodła się.', 'inyfinn-cursor-bridge-mcp' );
				if ( is_array( $msg ) ) {
					$msg = $msg['message'] ?? wp_json_encode( $msg );
				}
				add_settings_error( 'inyfinn_cursor_bridge', 'repair', (string) $msg, 'error' );
			}
		}

		if ( isset( $_GET['inyfinn_hardening'] ) && check_admin_referer( 'inyfinn_hardening' ) ) {
			$feature = sanitize_key( (string) wp_unslash( $_GET['inyfinn_hardening'] ) );
			$force   = ! empty( $_GET['force'] );
			$allow_fn = ! empty( $_GET['allow_functions_php'] );
			$result  = ( 'all' === $feature )
				? Hardening::install_all( array( 'force' => $force, 'allow_functions_php' => $allow_fn ) )
				: Hardening::install( $feature, array( 'force' => $force, 'allow_functions_php' => $allow_fn ) );

			self::flash_hardening_result( $result, $feature );
		}
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private static function flash_hardening_result( array $result, string $feature ): void {
		if ( isset( $result['results'] ) && is_array( $result['results'] ) ) {
			$ok = 0;
			$skip = 0;
			foreach ( $result['results'] as $r ) {
				if ( ! empty( $r['ok'] ) && empty( $r['skipped'] ) ) {
					++$ok;
				} elseif ( ! empty( $r['skipped'] ) ) {
					++$skip;
				}
			}
			add_settings_error(
				'inyfinn_cursor_bridge',
				'hardening',
				sprintf(
					/* translators: 1: installed count, 2: skipped count */
					__( 'Hardening: zainstalowano %1$d, pominięto %2$d (duplikaty/konflikty chronione).', 'inyfinn-cursor-bridge-mcp' ),
					$ok,
					$skip
				),
				$ok > 0 ? 'success' : 'info'
			);
			return;
		}

		$msg = $result['message'] ?? array();
		$text = is_array( $msg ) ? (string) ( $msg['message'] ?? '' ) : (string) $msg;
		$type = 'error';
		if ( ! empty( $result['ok'] ) ) {
			$type = 'success';
		} elseif ( ! empty( $result['skipped'] ) ) {
			$type = 'warning';
		}
		add_settings_error( 'inyfinn_cursor_bridge', 'hardening', $text ? $text : $feature, $type );
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private static function handle_bootstrap_result( array $result ): void {
		if ( ! empty( $result['ok'] ) ) {
			add_settings_error( 'inyfinn_cursor_bridge', 'bootstrap', __( 'Auto-setup zakończony pomyślnie.', 'inyfinn-cursor-bridge-mcp' ), 'success' );
			return;
		}
		$detail = ! empty( $result['errors'] ) ? implode( '; ', (array) $result['errors'] ) : __( 'Nieznany błąd.', 'inyfinn-cursor-bridge-mcp' );
		add_settings_error(
			'inyfinn_cursor_bridge',
			'bootstrap',
			sprintf(
				/* translators: %s: error details */
				__( 'Auto-setup nieudany: %s', 'inyfinn-cursor-bridge-mcp' ),
				$detail
			),
			'error'
		);
	}

	public static function admin_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( Health::is_healthy() ) {
			return;
		}
		$url = admin_url( 'options-general.php?page=inyfinn-cursor-bridge' );
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Inyfinn Cursor Bridge: wykryto problemy w diagnostyce.', 'inyfinn-cursor-bridge-mcp' );
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Otwórz panel i napraw', 'inyfinn-cursor-bridge-mcp' ) . '</a>';
		echo '</p></div>';
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$conn          = Credentials::get_connection();
		$bundle        = Credentials::build_cursor_bundle( false );
		$health        = Health::run_checks();
		$hardening     = Hardening::status();
		$bootstrap_url = wp_nonce_url( admin_url( 'options-general.php?page=inyfinn-cursor-bridge&inyfinn_bootstrap=1' ), 'inyfinn_bootstrap' );

		settings_errors( 'inyfinn_cursor_bridge' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Inyfinn Cursor Bridge MCP', 'inyfinn-cursor-bridge-mcp' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: version */
					esc_html__( 'Wersja %s — MCP + diagnostyka + hardening (SVG, uploady, login, limity).', 'inyfinn-cursor-bridge-mcp' ),
					esc_html( $health['version'] ?? '' )
				);
				?>
			</p>

			<?php self::render_health_banner( $health ); ?>

			<h2><?php esc_html_e( 'Diagnostyka MCP', 'inyfinn-cursor-bridge-mcp' ); ?></h2>
			<table class="widefat striped" style="max-width:960px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Test', 'inyfinn-cursor-bridge-mcp' ); ?></th>
						<th style="width:90px"><?php esc_html_e( 'Status', 'inyfinn-cursor-bridge-mcp' ); ?></th>
						<th><?php esc_html_e( 'Szczegóły', 'inyfinn-cursor-bridge-mcp' ); ?></th>
						<th style="width:120px"><?php esc_html_e( 'Akcja', 'inyfinn-cursor-bridge-mcp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $health['checks'] as $check ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
							<td><?php self::render_status_badge( $check['status'] ); ?></td>
							<td><code style="word-break:break-all"><?php echo esc_html( $check['message'] ); ?></code></td>
							<td>
								<?php if ( ! empty( $check['repair_action'] ) ) : ?>
									<a class="button button-small" href="<?php echo esc_url( self::repair_url( $check['repair_action'] ) ); ?>">
										<?php esc_html_e( 'Napraw', 'inyfinn-cursor-bridge-mcp' ); ?>
									</a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p style="margin-top:1em">
				<a href="<?php echo esc_url( $bootstrap_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Pełny auto-setup MCP', 'inyfinn-cursor-bridge-mcp' ); ?>
				</a>
			</p>

			<h2><?php esc_html_e( 'Hardening strony (SVG, uploady, login, limity)', 'inyfinn-cursor-bridge-mcp' ); ?></h2>
			<p>
				<?php esc_html_e( 'Zawsze tworzy backup przed zmianą. Nie wkleja duplikatów. Preferuje mu-plugin; functions.php tylko jako ostateczność (świadomie).', 'inyfinn-cursor-bridge-mcp' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Odrzucone z paczki (niebezpieczne): memory 8000M, max_execution_time=0, WP_ALLOW_REPAIR, WP_DEBUG=true domyślnie, blokada wp-admin→404.', 'inyfinn-cursor-bridge-mcp' ); ?>
			</p>
			<table class="widefat striped" style="max-width:960px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Funkcja', 'inyfinn-cursor-bridge-mcp' ); ?></th>
						<th><?php esc_html_e( 'Status', 'inyfinn-cursor-bridge-mcp' ); ?></th>
						<th><?php esc_html_e( 'Lokalizacja', 'inyfinn-cursor-bridge-mcp' ); ?></th>
						<th style="width:160px"><?php esc_html_e( 'Akcja', 'inyfinn-cursor-bridge-mcp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $hardening['features'] as $feat ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $feat['label'] ); ?></strong><br><code><?php echo esc_html( $feat['id'] ); ?></code></td>
							<td>
								<?php
								if ( ! empty( $feat['installed'] ) ) {
									self::render_status_badge( 'ok' );
									echo ' ' . esc_html__( 'Zainstalowane', 'inyfinn-cursor-bridge-mcp' );
								} elseif ( ! empty( $feat['similar'] ) ) {
									self::render_status_badge( 'warning' );
									echo ' ' . esc_html__( 'Podobny kod istnieje', 'inyfinn-cursor-bridge-mcp' );
								} else {
									self::render_status_badge( 'warning' );
									echo ' ' . esc_html__( 'Brak', 'inyfinn-cursor-bridge-mcp' );
								}
								?>
							</td>
							<td><code style="word-break:break-all"><?php echo esc_html( (string) ( $feat['location'] ?? '—' ) ); ?></code></td>
							<td>
								<?php if ( empty( $feat['installed'] ) ) : ?>
									<a class="button button-small button-primary" href="<?php echo esc_url( self::hardening_url( $feat['id'] ) ); ?>">
										<?php esc_html_e( 'Zainstaluj', 'inyfinn-cursor-bridge-mcp' ); ?>
									</a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( self::hardening_url( 'all' ) ); ?>">
					<?php esc_html_e( 'Zainstaluj wszystkie brakujące (bezpiecznie)', 'inyfinn-cursor-bridge-mcp' ); ?>
				</a>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: backup path */
					esc_html__( 'Backupi: %s', 'inyfinn-cursor-bridge-mcp' ),
					esc_html( $hardening['backup_root'] ?? '' )
				);
				?>
			</p>

			<h2><?php esc_html_e( 'Jak sprawdzić, że MCP działa', 'inyfinn-cursor-bridge-mcp' ); ?></h2>
			<div class="card" style="max-width:960px;padding:1em 1.2em">
				<ol>
					<li><?php esc_html_e( 'Aktywuj wtyczkę → ta strona: diagnostyka zielona.', 'inyfinn-cursor-bridge-mcp' ); ?></li>
					<li><code>uruchom wtyczkę inyfinn-cursor-bridge-mcp</code></li>
					<li><code>cursor-bridge/ping</code> → <code>ok: true</code></li>
					<li><code>cursor-bridge/health-check</code> → <code>healthy: true</code></li>
					<li><code>cursor-bridge/hardening-status</code> → lista funkcji</li>
				</ol>
			</div>

			<?php if ( ! empty( $bundle['missing_fields'] ) ) : ?>
				<div class="notice notice-warning inline" style="margin-top:1em"><p>
					<?php esc_html_e( 'Cursor zapyta o brakujące pola w .env:', 'inyfinn-cursor-bridge-mcp' ); ?>
					<code><?php echo esc_html( implode( ', ', $bundle['missing_fields'] ) ); ?></code>
				</p></div>
			<?php endif; ?>

			<form method="post" style="margin-top:2em">
				<?php wp_nonce_field( 'inyfinn_cursor_bridge_settings' ); ?>
				<h2><?php esc_html_e( 'Połączenie (SSH / workspace)', 'inyfinn-cursor-bridge-mcp' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="mcp_server_name">MCP server name</label></th>
						<td><input name="mcp_server_name" id="mcp_server_name" class="regular-text" value="<?php echo esc_attr( $conn['mcp_server_name'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="workspace_public_html">WORKSPACE_PUBLIC_HTML</label></th>
						<td><input name="workspace_public_html" id="workspace_public_html" class="large-text" value="<?php echo esc_attr( $conn['workspace_public_html'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="ssh_host">SSH_HOST</label></th>
						<td><input name="ssh_host" id="ssh_host" class="regular-text" value="<?php echo esc_attr( $conn['ssh_host'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="ssh_user">SSH_USER</label></th>
						<td><input name="ssh_user" id="ssh_user" class="regular-text" value="<?php echo esc_attr( $conn['ssh_user'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="ssh_port">SSH_PORT</label></th>
						<td><input name="ssh_port" id="ssh_port" type="number" value="<?php echo esc_attr( (string) $conn['ssh_port'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="ssh_remote_public_html">SSH_REMOTE_PUBLIC_HTML</label></th>
						<td><input name="ssh_remote_public_html" id="ssh_remote_public_html" class="large-text" value="<?php echo esc_attr( $conn['ssh_remote_public_html'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="ftp_host">FTP_HOST</label></th>
						<td><input name="ftp_host" id="ftp_host" class="regular-text" value="<?php echo esc_attr( $conn['ftp_host'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="ftp_user">FTP_USER</label></th>
						<td><input name="ftp_user" id="ftp_user" class="regular-text" value="<?php echo esc_attr( $conn['ftp_user'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="ftp_pass">FTP_PASSWORD</label></th>
						<td><input name="ftp_pass" id="ftp_pass" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Zostaw puste aby nie zmieniać', 'inyfinn-cursor-bridge-mcp' ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="ftp_port">FTP_PORT</label></th>
						<td><input name="ftp_port" id="ftp_port" type="number" value="<?php echo esc_attr( (string) $conn['ftp_port'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="ftp_remote_path">FTP_REMOTE_PATH</label></th>
						<td><input name="ftp_remote_path" id="ftp_remote_path" class="large-text" value="<?php echo esc_attr( $conn['ftp_remote_path'] ); ?>" /></td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="inyfinn_cursor_bridge_save" class="button button-secondary"><?php esc_html_e( 'Zapisz i odśwież cursor-setup.json', 'inyfinn-cursor-bridge-mcp' ); ?></button>
				</p>
			</form>

			<h2><?php esc_html_e( 'Dokumentacja', 'inyfinn-cursor-bridge-mcp' ); ?></h2>
			<ul>
				<li><code>docs/HARDENING.md</code></li>
				<li><code>docs/INSTALLATION.md</code></li>
				<li><code>docs/TROUBLESHOOTING.md</code></li>
				<li><code>docs/ABILITIES.md</code></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $health
	 */
	private static function render_health_banner( array $health ): void {
		$overall = $health['overall'] ?? 'error';
		if ( 'ok' === $overall ) {
			echo '<div class="notice notice-success inline" style="padding:12px 16px;max-width:960px"><p><strong>';
			esc_html_e( 'MCP działa — wtyczka gotowa dla Cursor IDE.', 'inyfinn-cursor-bridge-mcp' );
			echo '</strong></p></div>';
			return;
		}
		$class = 'warning' === $overall ? 'notice-warning' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' inline" style="padding:12px 16px;max-width:960px"><p><strong>';
		printf(
			/* translators: 1: failed count, 2: warning count */
			esc_html__( 'Problemy MCP: %1$d błędów, %2$d ostrzeżeń.', 'inyfinn-cursor-bridge-mcp' ),
			(int) ( $health['failed_count'] ?? 0 ),
			(int) ( $health['warning_count'] ?? 0 )
		);
		echo '</strong></p></div>';
	}

	private static function render_status_badge( string $status ): void {
		$labels = array(
			'ok'      => '<span style="color:#00a32a;font-weight:600">✓ OK</span>',
			'warning' => '<span style="color:#dba617;font-weight:600">⚠</span>',
			'error'   => '<span style="color:#d63638;font-weight:600">✗</span>',
		);
		echo wp_kses_post( $labels[ $status ] ?? esc_html( $status ) );
	}

	private static function repair_url( string $action ): string {
		return wp_nonce_url(
			admin_url( 'options-general.php?page=inyfinn-cursor-bridge&inyfinn_repair=' . rawurlencode( $action ) ),
			'inyfinn_repair'
		);
	}

	private static function hardening_url( string $feature ): string {
		return wp_nonce_url(
			admin_url( 'options-general.php?page=inyfinn-cursor-bridge&inyfinn_hardening=' . rawurlencode( $feature ) ),
			'inyfinn_hardening'
		);
	}
}
