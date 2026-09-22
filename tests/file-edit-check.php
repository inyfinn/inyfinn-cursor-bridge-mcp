<?php
/**
 * Check for Installer::comment_out_disallow_file_edit() — no WordPress needed:
 *   php tests/file-edit-check.php
 * Each sample goes through the transform, then a child PHP process lints it and
 * reports whether DISALLOW_FILE_EDIT is still defined.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/CursorBridge/class-installer.php';

use Inyfinn_Cursor_Bridge\Installer;

$samples = array(
	'plain'            => "define( 'DISALLOW_FILE_EDIT', true );",
	'compact + dq'     => 'define("DISALLOW_FILE_EDIT",true);',
	'if wrapper'       => "if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) { define( 'DISALLOW_FILE_EDIT', true ); }",
	'two defines'      => "define( 'DISALLOW_FILE_EDIT', true );\ndefine( 'DISALLOW_FILE_EDIT', 1 );",
	'active + comment' => "// define( 'DISALLOW_FILE_EDIT', false );\ndefine( 'DISALLOW_FILE_EDIT', true );",
	'only commented'   => "// define( 'DISALLOW_FILE_EDIT', true );",
	'in block comment' => "/*\n * define( 'DISALLOW_FILE_EDIT', true );\n */",
	'hash comment'     => "# define( 'DISALLOW_FILE_EDIT', true );",
	'none'             => "define( 'WP_DEBUG', false );",
);
$expect_null = array( 'only commented', 'in block comment', 'hash comment', 'none' );

$failures = 0;
$tmp      = sys_get_temp_dir() . '/inyfinn-file-edit-check.php';
foreach ( $samples as $name => $body ) {
	$raw = "<?php\ndefine( 'DB_NAME', 'x' );\n{$body}\ndefine( 'WP_DEBUG_LOG', true );\necho defined( 'DISALLOW_FILE_EDIT' ) ? 'DEFINED' : 'FREE';\n";
	$new = Installer::comment_out_disallow_file_edit( $raw, 'Inyfinn Cursor Bridge 2026-09-22: test */ note.' );

	if ( in_array( $name, $expect_null, true ) ) {
		$ok = null === $new;
		printf( "%-18s %s\n", $name, $ok ? 'OK (no change)' : 'FAIL (changed)' );
		$failures += $ok ? 0 : 1;
		continue;
	}
	if ( null === $new ) {
		printf( "%-18s FAIL (not changed)\n", $name );
		++$failures;
		continue;
	}
	file_put_contents( $tmp, $new );
	$lint = shell_exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $tmp ) . ' 2>&1' );
	$run  = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1' );
	$ok   = false !== strpos( (string) $lint, 'No syntax errors' ) && 'FREE' === trim( (string) $run );
	printf( "%-18s %s\n", $name, $ok ? 'OK' : 'FAIL lint=' . trim( (string) $lint ) . ' run=' . trim( (string) $run ) );
	$failures += $ok ? 0 : 1;
}
@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

echo $failures ? "\n{$failures} failure(s)\n" : "\nAll file-edit checks passed.\n";
exit( $failures ? 1 : 0 );
