<?php
/**
 * Pure operations on an Elementor element tree (the decoded _elementor_data array).
 * No WordPress calls here, so tests/elementor-tree-check.php runs with plain PHP.
 *
 * Copy/paste in the Elementor editor does three things, and so do we:
 * - deep copy of the element with all settings (that is what "paste style" carries),
 * - fresh 7-hex ids for the copy and every child (duplicate ids break the editor),
 * - insert next to the original or into a chosen parent.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

final class Elementor_Tree {

	/**
	 * @param array<int, mixed> $elements
	 * @return array<string, true>
	 */
	public static function collect_ids( array $elements ): array {
		$ids = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$ids[ (string) ( $el['id'] ?? '' ) ] = true;
			$ids += self::collect_ids( is_array( $el['elements'] ?? null ) ? $el['elements'] : array() );
		}
		return $ids;
	}

	/**
	 * @param array<string, true> $used
	 */
	public static function new_id( array &$used ): string {
		do {
			$id = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
		} while ( isset( $used[ $id ] ) );
		$used[ $id ] = true;
		return $id;
	}

	/**
	 * New ids for the element and all children.
	 *
	 * @param array<string, mixed>  $el
	 * @param array<string, true>   $used
	 * @param array<string, string> $map old id => new id
	 * @return array<string, mixed>
	 */
	public static function reid( array $el, array &$used, array &$map ): array {
		$new                            = self::new_id( $used );
		$map[ (string) ( $el['id'] ?? '' ) ] = $new;
		$el['id']                       = $new;
		foreach ( is_array( $el['elements'] ?? null ) ? $el['elements'] : array() as $i => $child ) {
			if ( is_array( $child ) ) {
				$el['elements'][ $i ] = self::reid( $child, $used, $map );
			}
		}
		return $el;
	}

	/**
	 * @param array<int, mixed> $elements
	 * @return array<string, mixed>|null
	 */
	public static function find( array $elements, string $id ): ?array {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( (string) ( $el['id'] ?? '' ) === $id ) {
				return $el;
			}
			$hit = self::find( is_array( $el['elements'] ?? null ) ? $el['elements'] : array(), $id );
			if ( null !== $hit ) {
				return $hit;
			}
		}
		return null;
	}

	/**
	 * Settings patches keyed by element id. Each key replaces its whole value (same rule as
	 * elementor-patch-element); a null value removes the key.
	 *
	 * @param array<int, mixed>                   $elements
	 * @param array<string, array<string, mixed>> $patches
	 * @return list<string> ids that were not found
	 */
	public static function apply_patches( array &$elements, array $patches ): array {
		$missing = array();
		foreach ( $patches as $id => $settings ) {
			$found = self::edit( $elements, (string) $id, static function ( array &$el ) use ( $settings ): void {
				$current = is_array( $el['settings'] ?? null ) ? $el['settings'] : array();
				foreach ( (array) $settings as $key => $value ) {
					if ( null === $value ) {
						unset( $current[ $key ] );
					} else {
						$current[ $key ] = $value;
					}
				}
				$el['settings'] = $current;
			} );
			if ( ! $found ) {
				$missing[] = (string) $id;
			}
		}
		return $missing;
	}

	/**
	 * Insert $new right after the element with id $after_id (same parent).
	 *
	 * @param array<int, mixed>    $elements
	 * @param array<string, mixed> $new
	 */
	public static function insert_after( array &$elements, string $after_id, array $new ): bool {
		foreach ( $elements as $i => &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( (string) ( $el['id'] ?? '' ) === $after_id ) {
				array_splice( $elements, (int) $i + 1, 0, array( $new ) );
				return true;
			}
			if ( is_array( $el['elements'] ?? null ) && self::insert_after( $el['elements'], $after_id, $new ) ) {
				return true;
			}
		}
		unset( $el );
		return false;
	}

	/**
	 * Insert $new as a child of $parent_id at $position (-1 = last). Empty parent = top level.
	 *
	 * @param array<int, mixed>    $elements
	 * @param array<string, mixed> $new
	 */
	public static function insert_into( array &$elements, string $parent_id, array $new, int $position = -1 ): bool {
		if ( '' === $parent_id ) {
			array_splice( $elements, $position < 0 ? count( $elements ) : min( $position, count( $elements ) ), 0, array( $new ) );
			return true;
		}
		return self::edit( $elements, $parent_id, static function ( array &$el ) use ( $new, $position ): void {
			$kids = is_array( $el['elements'] ?? null ) ? $el['elements'] : array();
			array_splice( $kids, $position < 0 ? count( $kids ) : min( $position, count( $kids ) ), 0, array( $new ) );
			$el['elements'] = $kids;
		} );
	}

	/**
	 * @param array<int, mixed> $elements
	 */
	public static function edit( array &$elements, string $id, callable $fn ): bool {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( (string) ( $el['id'] ?? '' ) === $id ) {
				$fn( $el );
				return true;
			}
			if ( is_array( $el['elements'] ?? null ) && self::edit( $el['elements'], $id, $fn ) ) {
				return true;
			}
		}
		unset( $el );
		return false;
	}
}
