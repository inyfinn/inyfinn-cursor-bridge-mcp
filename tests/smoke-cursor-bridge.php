<?php
/**
 * Smoke tests for Cursor Bridge — run via WP-CLI:
 *   wp eval-file wp-content/plugins/inyfinn-cursor-bridge-mcp/tests/smoke-cursor-bridge.php
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file inside WordPress.\n" );
	exit( 1 );
}

$failures = 0;

function smoke_assert( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		++$failures;
	} else {
		echo "OK: {$message}\n";
	}
}

// Classes loaded.
smoke_assert( class_exists( '\Inyfinn_Cursor_Bridge\GitHub_Updater' ), 'GitHub_Updater class' );
smoke_assert( class_exists( '\Inyfinn_Cursor_Bridge\Credentials' ), 'Credentials class' );
smoke_assert( class_exists( '\Inyfinn_Cursor_Bridge\File_Reader' ), 'File_Reader class' );

// Path sanitization.
smoke_assert( '' === \Inyfinn_Cursor_Bridge\File_Reader::sanitize_relative_path( '../etc/passwd' ), 'blocks traversal' );
smoke_assert( 'themes/foo.css' === \Inyfinn_Cursor_Bridge\File_Reader::sanitize_relative_path( '/themes/foo.css' ), 'normalizes leading slash' );
smoke_assert( 'themes/my file.css' === \Inyfinn_Cursor_Bridge\File_Reader::sanitize_relative_path( 'themes/my file.css' ), 'preserves spaces in paths' );

$blocked_read = \Inyfinn_Cursor_Bridge\File_Reader::read_file( 'inyfinn-cursor-bridge/cursor-setup.json' );
smoke_assert( is_wp_error( $blocked_read ), 'blocks read of cursor-setup.json' );
smoke_assert( is_wp_error( \Inyfinn_Cursor_Bridge\File_Reader::read_file( 'inyfinn-cursor-bridge/./cursor-setup.json' ) ), 'blocks ./ variant of cursor-setup.json' );
smoke_assert( 'themes/a.css' === \Inyfinn_Cursor_Bridge\File_Reader::sanitize_relative_path( './themes/./a.css' ), 'strips ./ segments' );

// Bundle without secrets — no side effects.
$before = \Inyfinn_Cursor_Bridge\Credentials::has_application_password();
$bundle = \Inyfinn_Cursor_Bridge\Credentials::build_cursor_bundle( false );
smoke_assert( is_array( $bundle ), 'build_cursor_bundle(false) returns array' );
smoke_assert( null === $bundle['app_password'], 'bundle without secrets has null app_password' );
smoke_assert( $before === \Inyfinn_Cursor_Bridge\Credentials::has_application_password(), 'bundle(false) did not change app password state' );

// Bootstrap status structure.
$status = \Inyfinn_Cursor_Bridge\Installer::get_status();
smoke_assert( array_key_exists( 'mu_plugin_loader', $status ), 'get_status has mu_plugin_loader' );
smoke_assert( array_key_exists( 'mcp_username', $status ), 'get_status has mcp_username' );

$removed = \Inyfinn_Cursor_Bridge\Installer::remove_mu_plugin_loader();
smoke_assert( ! empty( $removed['ok'] ), 'remove_mu_plugin_loader reports ok' );
smoke_assert( ! \Inyfinn_Cursor_Bridge\Installer::mu_plugin_loader_present(), 'legacy mu-loader absent after remove' );

// Abilities registered.
if ( function_exists( 'wp_get_abilities' ) ) {
	$abilities = wp_get_abilities();
	$names     = array_map( static fn( $a ) => $a->get_name(), $abilities );
	smoke_assert( in_array( 'cursor-bridge/ping', $names, true ), 'cursor-bridge/ping registered' );
	smoke_assert( in_array( 'cursor-bridge/run-auto-setup', $names, true ), 'cursor-bridge/run-auto-setup registered' );
} else {
	fwrite( STDERR, "SKIP: wp_get_abilities not available\n" );
}

// db-query: SQL errors surface, {prefix} works, LIKE with keywords inside literals is allowed.
$bad = \Inyfinn_Cursor_Bridge\Db_Query::run( 'SELECT 1 FROM no_such_table_xyz' );
smoke_assert( empty( $bad['ok'] ), 'db-query reports SQL errors' );
$pref = \Inyfinn_Cursor_Bridge\Db_Query::run( "SELECT COUNT(*) n FROM {prefix}posts WHERE post_title LIKE '%update%'" );
smoke_assert( ! empty( $pref['ok'] ), 'db-query {prefix} + keyword inside literal' );
$multi = \Inyfinn_Cursor_Bridge\Db_Query::run( 'SELECT 1; SELECT 2' );
smoke_assert( empty( $multi['ok'] ), 'db-query blocks multiple statements' );

// Agent instructions carry live facts.
smoke_assert( false !== strpos( \Inyfinn_Cursor_Bridge\Agent_Playbook::instructions(), $GLOBALS['wpdb']->prefix ), 'MCP instructions include table prefix' );

// Elementor editor: read-only checks on the front page, revision guard.
$front = (int) get_option( 'page_on_front' );
if ( $front && get_post_meta( $front, '_elementor_data', true ) ) {
	$outline = \Inyfinn_Cursor_Bridge\Elementor_Editor::outline( $front );
	smoke_assert( ! empty( $outline['ok'] ) && $outline['count'] > 0, 'elementor-outline on front page' );
	$first = $outline['elements'][0]['id'] ?? '';
	$dry   = \Inyfinn_Cursor_Bridge\Elementor_Editor::patch_element( $front, (string) $first, array( '_smoke' => '1' ), array(), true );
	smoke_assert( ! empty( $dry['dry_run'] ), 'elementor-patch-element dry_run writes nothing' );
	$revs = wp_get_post_revisions( $front, array( 'numberposts' => 1 ) );
	if ( $revs ) {
		$rev = \Inyfinn_Cursor_Bridge\Elementor_Editor::outline( (int) array_key_first( $revs ) );
		smoke_assert( 'revision' === ( $rev['error'] ?? '' ), 'elementor-* refuses revisions' );
	}
}

// Install state: either the current version is marked installed or the failure is recorded for the admin notice.
$installed = get_option( \Inyfinn_Cursor_Bridge\Installer::INSTALLED_VERSION_OPTION, '' );
$last      = get_option( \Inyfinn_Cursor_Bridge\Installer::LAST_RESULT_OPTION, array() );
smoke_assert(
	INYFINN_CURSOR_BRIDGE_MCP_VERSION === $installed || ( is_array( $last ) && isset( $last['ok'] ) && ! $last['ok'] ),
	'install completed or failure recorded (installed=' . $installed . ')'
);

echo $failures === 0 ? "\nAll smoke tests passed.\n" : "\n{$failures} test(s) failed.\n";
exit( $failures > 0 ? 1 : 0 );
