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
		if ( ! current_user_can( 'manage_options' ) || ! self::is_our_admin_request() ) {
			return;
		}

		if ( isset( $_POST['inyfinn_cursor_bridge_save'] ) ) {
			if ( ! self::verify_nonce( 'inyfinn_cursor_bridge_settings' ) ) {
				self::nonce_failed_notice();
				return;
			}

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

			if ( ! empty( $_POST['app_password_manual'] ) ) {
				$store = Credentials::store_application_password( (string) wp_unslash( $_POST['app_password_manual'] ) );
				if ( empty( $store['ok'] ) ) {
					add_settings_error(
						'inyfinn_cursor_bridge',
						'app_password',
						(string) ( $store['message'] ?? __( 'Nie udało się zapisać hasła aplikacji.', 'inyfinn-cursor-bridge-mcp' ) ),
						'error'
					);
				} else {
					Installer::write_setup_file( Credentials::build_cursor_bundle( true, $store ) );
					add_settings_error(
						'inyfinn_cursor_bridge',
						'app_password',
						sprintf(
							/* translators: %s: username */
							__( 'Hasło aplikacji zapisane dla: %s. cursor-setup.json odświeżony.', 'inyfinn-cursor-bridge-mcp' ),
							(string) ( $store['username'] ?? '' )
						),
						'success'
					);
				}
			} else {
				Installer::write_setup_file();
				add_settings_error( 'inyfinn_cursor_bridge', 'saved', __( 'Ustawienia zapisane. Plik cursor-setup.json odświeżony.', 'inyfinn-cursor-bridge-mcp' ), 'success' );
			}

			self::redirect_with_notices( self::admin_page_url() );
		}

		if ( isset( $_POST['inyfinn_bootstrap'] ) ) {
			if ( ! self::verify_nonce( 'inyfinn_bootstrap' ) ) {
				self::nonce_failed_notice();
				return;
			}
			self::handle_bootstrap_result( Installer::full_bootstrap( true ) );
			self::redirect_with_notices( self::admin_page_url() );
		}

		if ( isset( $_GET['inyfinn_bootstrap'] ) ) {
			if ( ! self::verify_nonce( 'inyfinn_bootstrap' ) ) {
				self::nonce_failed_notice();
				self::redirect_with_notices( self::admin_page_url() );
			}
			self::handle_bootstrap_result( Installer::full_bootstrap( true ) );
			self::redirect_with_notices( self::admin_page_url() );
		}

		if ( isset( $_POST['inyfinn_repair'] ) ) {
			if ( ! self::verify_nonce( 'inyfinn_repair' ) ) {
				self::nonce_failed_notice();
				return;
			}
			$action = sanitize_key( (string) wp_unslash( $_POST['inyfinn_repair'] ) );
			$rotate = ! empty( $_POST['rotate'] );
			self::flash_repair_result( Health::repair( $action, $rotate ), $action );
			self::redirect_with_notices( self::admin_page_url() );
		}

		if ( isset( $_GET['inyfinn_repair'] ) ) {
			if ( ! self::verify_nonce( 'inyfinn_repair' ) ) {
				self::nonce_failed_notice();
				self::redirect_with_notices( self::admin_page_url() );
			}
			$action = sanitize_key( (string) wp_unslash( $_GET['inyfinn_repair'] ) );
			$rotate = ! empty( $_GET['rotate'] );
			self::flash_repair_result( Health::repair( $action, $rotate ), $action );
			self::redirect_with_notices( self::admin_page_url() );
		}

		if ( isset( $_POST['inyfinn_apply_hardening'] ) ) {
			if ( ! self::verify_nonce( 'inyfinn_apply_hardening' ) ) {
				self::nonce_failed_notice();
				return;
			}
			$selected  = array_map( 'sanitize_key', (array) wp_unslash( $_POST['hardening_features'] ?? array() ) );
			$prefer_fn = ! empty( $_POST['prefer_functions_php'] );
			$force     = ! empty( $_POST['hardening_force'] );

			if ( empty( $selected ) ) {
				add_settings_error(
					'inyfinn_cursor_bridge',
					'hardening',
					__( 'Wybierz co najmniej jedną poprawkę do zastosowania.', 'inyfinn-cursor-bridge-mcp' ),
					'warning'
				);
			} else {
				$valid_ids = array_keys( (array) ( Hardening::status()['features'] ?? array() ) );
				$results   = array();
				$mu_snippets = array( 'svg-media', 'unique-uploads' );
				foreach ( $selected as $feature ) {
					if ( ! in_array( $feature, $valid_ids, true ) ) {
						continue;
					}
					$opts = array(
						'force'               => $force,
						'replace'             => $force,
						'allow_functions_php' => $prefer_fn,
						'prefer_functions_php' => $prefer_fn && in_array( $feature, $mu_snippets, true ),
					);
					$results[ $feature ] = Hardening::install( $feature, $opts );
				}
				self::flash_hardening_result( array( 'results' => $results ), 'batch' );
			}
			self::redirect_with_notices( self::admin_page_url() );
		}
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private static function flash_hardening_result( array $result, string $feature ): void {
		if ( isset( $result['results'] ) && is_array( $result['results'] ) ) {
			$ok   = 0;
			$skip = 0;
			$fail = 0;
			$lines = array();
			foreach ( $result['results'] as $id => $r ) {
				$msg = $r['message'] ?? '';
				if ( is_array( $msg ) ) {
					$msg = (string) ( $msg['message'] ?? wp_json_encode( $msg ) );
				}
				$lines[] = $id . ': ' . (string) $msg;
				if ( ! empty( $r['ok'] ) && empty( $r['skipped'] ) ) {
					++$ok;
				} elseif ( ! empty( $r['skipped'] ) ) {
					++$skip;
				} else {
					++$fail;
				}
			}
			add_settings_error(
				'inyfinn_cursor_bridge',
				'hardening',
				sprintf(
					/* translators: 1: installed, 2: skipped, 3: failed */
					__( 'Hardening: zastosowano %1$d, pominięto %2$d, błędy %3$d.', 'inyfinn-cursor-bridge-mcp' ),
					$ok,
					$skip,
					$fail
				) . ( $lines ? ' ' . implode( ' | ', $lines ) : '' ),
				$fail > 0 ? 'error' : ( $ok > 0 ? 'success' : 'warning' )
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
		$page_url      = self::admin_page_url();

		settings_errors( 'inyfinn_cursor_bridge' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Inyfinn Cursor Bridge MCP', 'inyfinn-cursor-bridge-mcp' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: version */
					esc_html__( 'Wersja %s — MCP + diagnostyka + poprawki strony (SVG, uploady, wp-config, limity 8000M).', 'inyfinn-cursor-bridge-mcp' ),
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
				<form method="post" action="<?php echo esc_url( $page_url ); ?>" style="display:inline">
					<?php wp_nonce_field( 'inyfinn_bootstrap' ); ?>
					<button type="submit" name="inyfinn_bootstrap" value="1" class="button button-primary">
						<?php esc_html_e( 'Pełny auto-setup MCP', 'inyfinn-cursor-bridge-mcp' ); ?>
					</button>
				</form>
			</p>

			<h2><?php esc_html_e( 'Poprawki strony (SVG, uploady, wp-config, limity PHP)', 'inyfinn-cursor-bridge-mcp' ); ?></h2>
			<p>
				<?php esc_html_e( 'Zaznacz poprawki i kliknij „Zastosuj” — wtyczka zrobi backup i wstrzyknie kod do mu-plugins, functions.php, wp-config.php lub .user.ini/.htaccess.', 'inyfinn-cursor-bridge-mcp' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( $page_url ); ?>">
				<?php wp_nonce_field( 'inyfinn_apply_hardening' ); ?>
				<table class="widefat striped" style="max-width:960px">
					<thead>
						<tr>
							<th style="width:40px"><?php esc_html_e( 'Zastosuj', 'inyfinn-cursor-bridge-mcp' ); ?></th>
							<th><?php esc_html_e( 'Funkcja', 'inyfinn-cursor-bridge-mcp' ); ?></th>
							<th><?php esc_html_e( 'Status', 'inyfinn-cursor-bridge-mcp' ); ?></th>
							<th><?php esc_html_e( 'Lokalizacja', 'inyfinn-cursor-bridge-mcp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $hardening['features'] as $feat ) : ?>
							<tr>
								<td>
									<input type="checkbox" name="hardening_features[]" value="<?php echo esc_attr( $feat['id'] ); ?>" <?php checked( empty( $feat['installed'] ) ); ?> />
								</td>
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
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p style="margin-top:1em">
					<label>
						<input type="checkbox" name="prefer_functions_php" value="1" />
						<?php esc_html_e( 'Wstrzyknij SVG i uploady do functions.php motywu (zamiast mu-plugins)', 'inyfinn-cursor-bridge-mcp' ); ?>
					</label>
				</p>
				<p>
					<label>
						<input type="checkbox" name="hardening_force" value="1" />
						<?php esc_html_e( 'Wymuś ponowne zastosowanie (nadpisz nasze istniejące bloki)', 'inyfinn-cursor-bridge-mcp' ); ?>
					</label>
				</p>
				<p>
					<button type="submit" name="inyfinn_apply_hardening" value="1" class="button button-primary">
						<?php esc_html_e( 'Zastosuj zaznaczone poprawki', 'inyfinn-cursor-bridge-mcp' ); ?>
					</button>
				</p>
			</form>
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

			<form method="post" action="<?php echo esc_url( $page_url ); ?>" style="margin-top:2em">
				<?php wp_nonce_field( 'inyfinn_cursor_bridge_settings' ); ?>
				<h2><?php esc_html_e( 'Połączenie (SSH / workspace)', 'inyfinn-cursor-bridge-mcp' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="app_password_manual"><?php esc_html_e( 'Hasło aplikacji MCP', 'inyfinn-cursor-bridge-mcp' ); ?></label></th>
						<td>
							<input name="app_password_manual" id="app_password_manual" type="password" class="large-text" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Wklej hasło z profilu WP (np. xxxx xxxx xxxx)', 'inyfinn-cursor-bridge-mcp' ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: username */
									esc_html__( 'Jeśli utworzyłeś hasło ręcznie w profilu — wklej je tutaj. MCP user: %s', 'inyfinn-cursor-bridge-mcp' ),
									esc_html( Credentials::get_mcp_username() )
								);
								?>
							</p>
						</td>
					</tr>
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

	private static function admin_page_url(): string {
		return admin_url( 'options-general.php?page=inyfinn-cursor-bridge' );
	}

	private static function is_our_admin_request(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'inyfinn-cursor-bridge' === $page ) {
			return true;
		}

		$post_keys = array(
			'inyfinn_cursor_bridge_save',
			'inyfinn_apply_hardening',
			'inyfinn_bootstrap',
			'inyfinn_repair',
		);
		foreach ( $post_keys as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				return true;
			}
		}

		return isset( $_GET['inyfinn_bootstrap'] ) || isset( $_GET['inyfinn_repair'] );
	}

	private static function verify_nonce( string $action, string $query_arg = '_wpnonce' ): bool {
		$nonce = '';
		if ( isset( $_REQUEST[ $query_arg ] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST[ $query_arg ] ) );
		}

		if ( '' === $nonce ) {
			return false;
		}

		return (bool) wp_verify_nonce( $nonce, $action );
	}

	private static function nonce_failed_notice(): void {
		add_settings_error(
			'inyfinn_cursor_bridge',
			'nonce',
			__( 'Sesja wygasła lub link jest nieaktualny — odśwież stronę Cursor Bridge i spróbuj ponownie.', 'inyfinn-cursor-bridge-mcp' ),
			'error'
		);
	}

	private static function redirect_with_notices( string $url ): void {
		if ( get_settings_errors( 'inyfinn_cursor_bridge' ) ) {
			set_transient( 'settings_errors', get_settings_errors(), 30 );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private static function flash_repair_result( array $result, string $action ): void {
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
			return;
		}

		$msg = $result['message'] ?? __( 'Naprawa nie powiodła się.', 'inyfinn-cursor-bridge-mcp' );
		if ( is_array( $msg ) ) {
			$msg = $msg['message'] ?? wp_json_encode( $msg );
		}
		add_settings_error( 'inyfinn_cursor_bridge', 'repair', (string) $msg, 'error' );
	}

	private static function repair_url( string $action ): string {
		return wp_nonce_url(
			admin_url( 'options-general.php?page=inyfinn-cursor-bridge&inyfinn_repair=' . rawurlencode( $action ) ),
			'inyfinn_repair'
		);
	}
}
