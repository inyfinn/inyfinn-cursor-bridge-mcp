<?php
/**
 * WP Admin: Cursor Bridge setup (fields + one-click bootstrap).
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
			$result = Installer::full_bootstrap( true );
			if ( ! empty( $result['ok'] ) ) {
				add_settings_error( 'inyfinn_cursor_bridge', 'bootstrap', __( 'Auto-setup zakończony. Application Password utworzone. Plik setup gotowy dla Cursora.', 'inyfinn-cursor-bridge-mcp' ), 'success' );
			} else {
				add_settings_error( 'inyfinn_cursor_bridge', 'bootstrap', __( 'Auto-setup częściowo nieudany — sprawdź status poniżej.', 'inyfinn-cursor-bridge-mcp' ), 'warning' );
			}
		}
	}

	public static function admin_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = Installer::get_status();
		if ( $status['mu_plugin_loader'] && $status['app_password'] && $status['setup_file'] ) {
			return;
		}

		$url = admin_url( 'options-general.php?page=inyfinn-cursor-bridge' );
		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'Inyfinn Cursor Bridge: uruchom auto-setup dla Cursor IDE.', 'inyfinn-cursor-bridge-mcp' );
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Otwórz ustawienia', 'inyfinn-cursor-bridge-mcp' ) . '</a>';
		echo '</p></div>';
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$conn   = Credentials::get_connection();
		$status = Installer::get_status();
		$bundle = Credentials::build_cursor_bundle( false );
		$bootstrap_url = wp_nonce_url( admin_url( 'options-general.php?page=inyfinn-cursor-bridge&inyfinn_bootstrap=1' ), 'inyfinn_bootstrap' );

		settings_errors( 'inyfinn_cursor_bridge' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Inyfinn Cursor Bridge MCP', 'inyfinn-cursor-bridge-mcp' ); ?></h1>
			<p><?php esc_html_e( 'Wtyczka konfiguruje Cursor automatycznie. W Cursorze napisz: „uruchom wtyczkę inyfinn-cursor-bridge-mcp”.', 'inyfinn-cursor-bridge-mcp' ); ?></p>

			<h2><?php esc_html_e( 'Status', 'inyfinn-cursor-bridge-mcp' ); ?></h2>
			<table class="widefat striped" style="max-width:720px">
				<tbody>
					<tr><td>MU-plugin loader</td><td><?php echo $status['mu_plugin_loader'] ? '✓' : '✗'; ?></td></tr>
					<tr><td>Application Password</td><td><?php echo $status['app_password'] ? '✓' : '✗'; ?></td></tr>
					<tr><td>cursor-setup.json</td><td><?php echo $status['setup_file'] ? esc_html( $status['setup_file_path'] ) : '✗'; ?></td></tr>
					<tr><td>MCP endpoint</td><td><code><?php echo esc_html( rest_url( 'mcp/mcp-adapter-default-server' ) ); ?></code></td></tr>
				</tbody>
			</table>
			<p>
				<a href="<?php echo esc_url( $bootstrap_url ); ?>" class="button button-primary"><?php esc_html_e( 'Uruchom auto-setup (mu-plugin + hasło + plik dla Cursora)', 'inyfinn-cursor-bridge-mcp' ); ?></a>
			</p>

			<?php if ( ! empty( $bundle['missing_fields'] ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'Cursor zapyta tylko o brakujące pola:', 'inyfinn-cursor-bridge-mcp' ); ?>
					<code><?php echo esc_html( implode( ', ', $bundle['missing_fields'] ) ); ?></code>
				</p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'inyfinn_cursor_bridge_settings' ); ?>
				<h2><?php esc_html_e( 'Połączenie (uzupełnia Cursor lub Ty)', 'inyfinn-cursor-bridge-mcp' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="mcp_server_name">MCP server name</label></th>
						<td><input name="mcp_server_name" id="mcp_server_name" class="regular-text" value="<?php echo esc_attr( $conn['mcp_server_name'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="workspace_public_html">WORKSPACE_PUBLIC_HTML</label></th>
						<td><input name="workspace_public_html" id="workspace_public_html" class="large-text" value="<?php echo esc_attr( $conn['workspace_public_html'] ); ?>" placeholder="S:\domains\...\public_html" /></td>
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

			<h2><?php esc_html_e( 'DB (auto z wp-config)', 'inyfinn-cursor-bridge-mcp' ); ?></h2>
			<p><code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_HOST</code> — wypełniane automatycznie w pliku setup i .env (hasło tylko w cursor-setup.json / MCP dla admina).</p>
		</div>
		<?php
	}
}
