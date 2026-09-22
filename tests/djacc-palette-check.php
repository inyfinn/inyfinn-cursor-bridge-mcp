<?php
/**
 * Contrast check for the DJ Accessibility skin palette — no WordPress needed:
 *   php tests/djacc-palette-check.php
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/CursorBridge/class-djacc-palette.php';

use Inyfinn_Cursor_Bridge\Djacc_Palette as P;

$palettes = array(
	'kubara (Vamtam)'        => array( 'forest' => '#1C4B42', 'lime' => '#B4E717', 'hover' => '#92C200', 'ink' => '#FFFFFF' ),
	'Elementor defaults'     => array( 'forest' => '#6EC1E4', 'lime' => '#61CE70', 'hover' => '#54595F', 'ink' => '#7A7A7A' ),
	'white brand'            => array( 'forest' => '#FFFFFF', 'lime' => '#000000', 'hover' => '', 'ink' => '' ),
	'yellow brand'           => array( 'forest' => '#FFD500', 'lime' => '#FFE14D', 'hover' => '', 'ink' => '#FFFFFF' ),
	'mid grey, same accent'  => array( 'forest' => '#7A7A7A', 'lime' => '#7A7A7A', 'hover' => '#7A7A7A', 'ink' => '#808080' ),
	'nothing mapped'         => array(),
	'alpha + short hex'      => array( 'forest' => '#000000CC', 'lime' => '#fc0' ),
);

$failures = 0;
foreach ( $palettes as $name => $roles ) {
	$p      = P::build( $roles );
	$checks = array(
		'ink/panel'     => P::contrast( $p['ink'], $p['panel'] ),
		'ink/btn'       => P::contrast( $p['ink'], $p['btn'] ),
		'on_lime/lime'  => P::contrast( $p['on_lime'], $p['lime'] ),
		'on_lime/hover' => P::contrast( $p['on_lime'], $p['hover'] ),
	);
	$line = array();
	foreach ( $checks as $label => $ratio ) {
		$ok     = $ratio >= 4.5;
		$line[] = sprintf( '%s %.1f%s', $label, $ratio, $ok ? '' : ' FAIL' );
		$failures += $ok ? 0 : 1;
	}
	$lift   = P::contrast( $p['btn'], $p['panel'] );
	$line[] = sprintf( 'btn/panel %.2f%s', $lift, $lift >= 1.2 ? '' : ' FAIL' );
	$failures += $lift >= 1.2 ? 0 : 1;
	$track  = P::contrast( $p['bar'], $p['btn'] );
	$line[] = sprintf( 'track/btn %.2f%s', $track, $track >= 1.2 ? '' : ' FAIL' );
	$failures += $track >= 1.2 ? 0 : 1;
	$distinct = P::contrast( $p['lime'], $p['btn'] );
	$line[]   = sprintf( 'active/btn %.1f%s', $distinct, $distinct >= 1.8 ? '' : ' FAIL' );
	$failures += $distinct >= 1.8 ? 0 : 1;
	printf( "%-24s %s\n  %s\n", $name, json_encode( $p ), implode( ', ', $line ) );
}

// kubara must keep its look: brand buttons, lime active with forest icons.
$k = P::build( $palettes['kubara (Vamtam)'] );
if ( '#1C4B42' !== $k['btn'] || '#B4E717' !== $k['lime'] || '#1C4B42' !== $k['on_lime'] || '#FFFFFF' !== $k['ink'] || '#92C200' !== $k['hover'] ) {
	echo "FAIL kubara look changed\n";
	++$failures;
}

echo $failures ? "\n{$failures} failure(s)\n" : "\nAll palette checks passed.\n";
exit( $failures ? 1 : 0 );
