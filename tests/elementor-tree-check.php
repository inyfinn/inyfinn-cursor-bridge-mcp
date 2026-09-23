<?php
/**
 * Check for Elementor_Tree (clone / patch / insert) — no WordPress needed:
 *   php tests/elementor-tree-check.php
 * Scenario from a real task: a home page grid with rows of 5 shop cards; the last row is
 * copied after itself, the first 3 cards get new data, the last 2 are hidden.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

require __DIR__ . '/../includes/CursorBridge/class-elementor-tree.php';

use Inyfinn_Cursor_Bridge\Elementor_Tree;

$card = static function ( string $id, string $city ): array {
	return array(
		'id'       => $id,
		'elType'   => 'container',
		'settings' => array( '_title' => $city, 'border_width' => array( 'left' => '2' ) ),
		'elements' => array(
			array( 'id' => $id . 'h', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => $city ), 'elements' => array() ),
			array( 'id' => $id . 'b', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array( 'text' => 'Zobacz' ), 'elements' => array() ),
		),
	);
};
$row  = static function ( string $id, string $title, array $cards ): array {
	return array( 'id' => $id, 'elType' => 'container', 'settings' => array( '_title' => $title ), 'elements' => $cards );
};
$page = array(
	array(
		'id'       => 'grid',
		'elType'   => 'container',
		'settings' => array(),
		'elements' => array(
			$row( 'row4', 'TABLICA 4', array( $card( 'c41', 'A' ) ) ),
			$row( 'row5', 'TABLICA 5', array( $card( 'c51', 'PLOCK' ), $card( 'c52', 'RZESZOW' ), $card( 'c53', 'PRUSZKOW' ), $card( 'c54', 'BIELSKO' ), $card( 'c55', 'SUWALKI' ) ) ),
		),
	),
	array( 'id' => 'footer', 'elType' => 'container', 'settings' => array(), 'elements' => array() ),
);

$failures = 0;
$check    = static function ( string $name, bool $ok ) use ( &$failures ): void {
	printf( "%-44s %s\n", $name, $ok ? 'OK' : 'FAIL' );
	$failures += $ok ? 0 : 1;
};

// Copy TABLICA 5, patch by SOURCE ids, then new ids.
$copy    = array( Elementor_Tree::find( $page, 'row5' ) );
$missing = Elementor_Tree::apply_patches(
	$copy,
	array(
		'row5'  => array( '_title' => 'TABLICA 6' ),
		'c51h'  => array( 'title' => 'WROCLAW' ),
		'c54'   => array( 'hide_desktop' => 'hidden-desktop', 'hide_tablet' => 'hidden-tablet', 'hide_mobile' => 'hidden-mobile' ),
		'c55'   => array( 'hide_desktop' => 'hidden-desktop', 'border_width' => null ),
	)
);
$check( 'patches found every source id', array() === $missing );
$check( 'unknown patch id is reported', array( 'nope' ) === Elementor_Tree::apply_patches( $copy, array( 'nope' => array( 'x' => 1 ) ) ) );

$used = Elementor_Tree::collect_ids( $page );
$map  = array();
$new  = Elementor_Tree::reid( $copy[0], $used, $map );
$check( 'id_map covers row + 5 cards + 10 widgets', 16 === count( $map ) );
$check( 'all new ids are 7 hex chars', array() === array_filter( $map, static fn( $id ) => ! preg_match( '/^[0-9a-f]{7}$/', $id ) ) );
$check( 'no new id collides with the page', array() === array_intersect_key( array_flip( $map ), Elementor_Tree::collect_ids( $page ) ) );

$before = $page;
$check( 'insert_after finds the row', Elementor_Tree::insert_after( $page, 'row5', $new ) );
$grid = $page[0]['elements'];
$check( 'copy sits right after TABLICA 5', 3 === count( $grid ) && 'row5' === $grid[1]['id'] && $new['id'] === $grid[2]['id'] );
$check( 'original row untouched', $before[0]['elements'][1] === $grid[1] );
$check( 'patched title in copy', 'WROCLAW' === $grid[2]['elements'][0]['elements'][0]['settings']['title'] );
$check( 'copy keeps card style (paste style)', array( 'left' => '2' ) === $grid[2]['elements'][0]['settings']['border_width'] );
$check( 'null removes a setting', ! isset( $grid[2]['elements'][4]['settings']['border_width'] ) );
$check( 'slot 4 hidden on all devices', 'hidden-mobile' === $grid[2]['elements'][3]['settings']['hide_mobile'] );
$check( 'ids unique after insert', count( Elementor_Tree::collect_ids( $page ) ) === count( Elementor_Tree::collect_ids( $before ) ) + 16 );

// insert_into: top level and inside a parent at a position.
$top = array( 'id' => 'x1', 'elType' => 'container', 'settings' => array(), 'elements' => array() );
Elementor_Tree::insert_into( $page, '', $top, 1 );
$check( 'insert_into top level at position 1', 'x1' === $page[1]['id'] && 'footer' === $page[2]['id'] );
$check( 'insert_into parent as first child', Elementor_Tree::insert_into( $page, 'grid', array( 'id' => 'x2', 'elements' => array() ), 0 ) && 'x2' === $page[0]['elements'][0]['id'] );
$check( 'insert_into unknown parent fails', ! Elementor_Tree::insert_into( $page, 'missing', array( 'id' => 'x3' ) ) );
$check( 'insert_after unknown id fails', ! Elementor_Tree::insert_after( $page, 'missing', array( 'id' => 'x4' ) ) );

echo $failures ? "\n{$failures} FAILED\n" : "\nAll checks passed.\n";
exit( $failures ? 1 : 0 );
