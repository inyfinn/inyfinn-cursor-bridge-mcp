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
smoke_assert( class_exists( '\Inyfinn_Cursor_Bridge\Installer' ), 'Installer class' );
smoke_assert( class_exists( '\Inyfinn_Cursor_Bridge\Credentials' ), 'Credentials class' );
smoke_assert( class_exists( '\Inyfinn_Cursor_Bridge\File_Reader' ), 'File_Reader class' );

// Path sanitization.
smoke_assert( '' === \Inyfinn_Cursor_Bridge\File_Reader::sanitize_relative_path( '../etc/passwd' ), 'blocks traversal' );
smoke_assert( 'themes/foo.css' === \Inyfinn_Cursor_Bridge\File_Reader::sanitize_relative_path( '/themes/foo.css' ), 'normalizes leading slash' );
smoke_assert( 'themes/my file.css' === \Inyfinn_Cursor_Bridge\File_Reader::sanitize_relative_path( 'themes/my file.css' ), 'preserves spaces in paths' );

$blocked_read = \Inyfinn_Cursor_Bridge\File_Reader::read_file( 'inyfinn-cursor-bridge/cursor-setup.json' );
smoke_assert( is_wp_error( $blocked_read ), 'blocks read of cursor-setup.json' );

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

// Abilities registered.
if ( function_exists( 'wp_get_abilities' ) ) {
	$abilities = wp_get_abilities();
	$names     = array_map( static fn( $a ) => $a->get_name(), $abilities );
	smoke_assert( in_array( 'cursor-bridge/ping', $names, true ), 'cursor-bridge/ping registered' );
	smoke_assert( in_array( 'cursor-bridge/run-auto-setup', $names, true ), 'cursor-bridge/run-auto-setup registered' );
} else {
	fwrite( STDERR, "SKIP: wp_get_abilities not available\n" );
}

echo $failures === 0 ? "\nAll smoke tests passed.\n" : "\n{$failures} test(s) failed.\n";
exit( $failures > 0 ? 1 : 0 );
