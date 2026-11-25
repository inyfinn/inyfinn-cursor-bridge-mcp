<?php
/**
 * Check for Audit_Log::summarize_args() and rotate_if_needed() — no WordPress needed:
 *   php tests/audit-log-check.php
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/CursorBridge/class-audit-log.php';

use Inyfinn_Cursor_Bridge\Audit_Log;

$failures = 0;
$check    = static function ( string $name, bool $ok ) use ( &$failures ): void {
	printf( "%-34s %s\n", $name, $ok ? 'OK' : 'FAIL' );
	$failures += $ok ? 0 : 1;
};

$long = str_repeat( 'a', 81 );
$sum  = Audit_Log::summarize_args(
	array(
		'short'  => 'themes/child/style.css',
		'edge'   => str_repeat( 'b', 80 ),
		'long'   => $long,
		'polish' => str_repeat( 'ż', 80 ),
		'list'   => array( 1, 2, 3 ),
		'number' => 42,
		'flag'   => true,
		'none'   => null,
		'path'         => 'themes/x.css',
		'app_password' => 'abc',
		'api_key'      => array( 1, 2 ),
	)
);
$check( 'short string verbatim', 'themes/child/style.css' === $sum['short'] );
$check( '80 chars verbatim', str_repeat( 'b', 80 ) === $sum['edge'] );
$check( '81 chars -> len:81', 'len:81' === $sum['long'] );
if ( function_exists( 'mb_strlen' ) ) { // WordPress always has it (polyfill); plain CLI PHP may not.
	$check( '80 multibyte chars verbatim', str_repeat( 'ż', 80 ) === $sum['polish'] );
} else {
	echo "80 multibyte chars verbatim        SKIP (no mbstring)
";
}
$check( 'array -> array:3', 'array:3' === $sum['list'] );
$check( 'number kept', 42 === $sum['number'] );
$check( 'bool and null kept', true === $sum['flag'] && null === $sum['none'] );
$check( 'secret-like keys -> ***', '***' === $sum['app_password'] && '***' === $sum['api_key'] && 'themes/x.css' === $sum['path'] );
$check( 'non-array input -> empty', array() === Audit_Log::summarize_args( null ) );

$file = __DIR__ . '/audit-log-check-' . getmypid() . '.tmp';
$old  = $file . '.1';
file_put_contents( $file, str_repeat( 'x', 100 ) );
file_put_contents( $old, 'previous' );
$check( 'small file not rotated', false === Audit_Log::rotate_if_needed( $file, 1000 ) && is_file( $file ) );
file_put_contents( $file, str_repeat( 'x', 2000 ) );
$check( 'big file rotated', true === Audit_Log::rotate_if_needed( $file, 1000 ) );
$check( 'old file gone, .1 replaced', ! is_file( $file ) && 2000 === filesize( $old ) );
$check( 'missing file not rotated', false === Audit_Log::rotate_if_needed( $file, 1000 ) );
@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
@unlink( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

echo $failures ? "\n{$failures} failure(s)\n" : "\nAll audit-log checks passed.\n";
exit( $failures ? 1 : 0 );
