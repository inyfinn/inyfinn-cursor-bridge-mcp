<?php
/**
 * DJ Accessibility skin palette: turns four brand colours (hex) into a full
 * set of panel colours with guaranteed contrast. Pure PHP, no WordPress calls,
 * so it can be checked in isolation (tests/djacc-palette-check.php).
 *
 * Roles: forest = brand surface, lime = active / accent, hover = active hover,
 * ink = text (optional — picked automatically when it would be unreadable).
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Djacc_Palette {

	public const FALLBACK_FOREST = '#1F2937';
	public const FALLBACK_LIME   = '#FACC15';

	private const TEXT_MIN   = 4.5;
	private const ACCENT_MIN = 1.8; // Active button must stand out from a normal one.

	/**
	 * @param array{forest?:string,lime?:string,hover?:string,ink?:string} $roles Hex colours; empty = not set.
	 * @return array<string, string> panel, btn, bar, ink, lime, on_lime, hover, border
	 */
	public static function build( array $roles ): array {
		$forest = self::norm( $roles['forest'] ?? '' ) ?: self::FALLBACK_FOREST;
		$lime   = self::norm( $roles['lime'] ?? '' ) ?: self::FALLBACK_LIME;
		$hover  = self::norm( $roles['hover'] ?? '' );
		$ink    = self::norm( $roles['ink'] ?? '' );

		// Dark brand colour: panel slightly darker than the brand, buttons in the brand colour.
		// Light brand colour (white, pastel, yellow): the panel stays light and the text goes dark.
		$dark = self::contrast( $forest, '#FFFFFF' ) >= 3.0;
		if ( $dark ) {
			$panel = self::mix( $forest, '#000000', 0.62 );
			$btn   = $forest;
			$bar   = self::mix( $forest, '#000000', 0.78 );
		} else {
			$panel = $forest;
			$btn   = self::mix( $forest, '#000000', 0.94 );
			$bar   = '';
		}

		// Near-black brand: lift the buttons, otherwise they vanish into the panel.
		if ( self::contrast( $btn, $panel ) < 1.2 ) {
			$panel = $dark ? $forest : $panel;
			$btn   = $dark ? self::mix( $forest, '#FFFFFF', 0.85 ) : self::mix( $forest, '#000000', 0.85 );
		}

		if ( '' === $ink || min( self::contrast( $ink, $panel ), self::contrast( $ink, $btn ) ) < self::TEXT_MIN ) {
			$ink = self::best_on( array( $panel, $btn ), array( '#FFFFFF', '#111111' ) );
		}
		// Mid-tone brand (neither white nor black text reaches 4.5:1): push the button away from the text.
		for ( $i = 0; $i < 10 && self::contrast( $ink, $btn ) < self::TEXT_MIN; $i++ ) {
			$btn = self::mix( $btn, '#FFFFFF' === $ink ? '#000000' : '#FFFFFF', 0.9 );
		}
		for ( $i = 0; $i < 10 && self::contrast( $ink, $panel ) < self::TEXT_MIN; $i++ ) {
			$panel = self::mix( $panel, '#FFFFFF' === $ink ? '#000000' : '#FFFFFF', 0.9 );
		}

		// Light panel: slider track from the text colour, otherwise it disappears into the button.
		if ( '' === $bar ) {
			$bar = self::mix( $ink, $btn, 0.28 );
		}
		for ( $i = 0; $i < 6 && self::contrast( $bar, $btn ) < 1.2; $i++ ) {
			$bar = self::mix( $bar, $dark ? '#000000' : $ink, 0.8 );
		}

		// Accent too close to the buttons → invert: active = text colour.
		if ( self::contrast( $lime, $btn ) < self::ACCENT_MIN ) {
			$lime = $ink;
		}
		$on_lime = self::best_on( array( $lime ), array( $forest, $panel, '#111111', '#FFFFFF' ) );

		if ( '' === $hover || self::contrast( $hover, $on_lime ) < self::TEXT_MIN ) {
			$hover = self::mix( $lime, $on_lime, 0.85 );
			if ( self::contrast( $hover, $on_lime ) < self::TEXT_MIN ) {
				$hover = $lime;
			}
		}

		return array(
			'panel'   => $panel,
			'btn'     => $btn,
			'bar'     => $bar,
			'ink'     => $ink,
			'lime'    => $lime,
			'on_lime' => $on_lime,
			'hover'   => $hover,
			'border'  => self::mix( $ink, $btn, 0.16 ),
		);
	}

	/**
	 * First candidate readable (≥ 4.5:1) on every background; otherwise the best one.
	 *
	 * @param list<string> $backgrounds
	 * @param list<string> $candidates
	 */
	public static function best_on( array $backgrounds, array $candidates ): string {
		$best       = $candidates[0];
		$best_score = -1.0;
		foreach ( $candidates as $candidate ) {
			$score = INF;
			foreach ( $backgrounds as $bg ) {
				$score = min( $score, self::contrast( $candidate, $bg ) );
			}
			if ( $score >= self::TEXT_MIN ) {
				return $candidate;
			}
			if ( $score > $best_score ) {
				$best       = $candidate;
				$best_score = $score;
			}
		}
		return $best;
	}

	/** WCAG 2.x contrast ratio. */
	public static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/** Weight $wa of colour $a, the rest of $b. */
	public static function mix( string $a, string $b, float $wa ): string {
		$ra  = self::rgb( $a );
		$rb  = self::rgb( $b );
		$out = '#';
		for ( $i = 0; $i < 3; $i++ ) {
			$out .= sprintf( '%02X', (int) round( $ra[ $i ] * $wa + $rb[ $i ] * ( 1 - $wa ) ) );
		}
		return $out;
	}

	/** '#abc', '#aabbcc' or '#aabbccdd' (alpha dropped) → '#AABBCC'; anything else → ''. */
	public static function norm( string $hex ): string {
		$hex = ltrim( trim( $hex ), '#' );
		if ( preg_match( '/^[0-9a-f]{3}$/i', $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( preg_match( '/^([0-9a-f]{6})([0-9a-f]{2})?$/i', $hex, $m ) ) {
			return '#' . strtoupper( $m[1] );
		}
		return '';
	}

	/**
	 * @return array{0:int,1:int,2:int}
	 */
	private static function rgb( string $hex ): array {
		$hex = ltrim( self::norm( $hex ) ?: '#000000', '#' );
		return array( (int) hexdec( substr( $hex, 0, 2 ) ), (int) hexdec( substr( $hex, 2, 2 ) ), (int) hexdec( substr( $hex, 4, 2 ) ) );
	}

	private static function luminance( string $hex ): float {
		$lum = 0.0;
		foreach ( self::rgb( $hex ) as $i => $channel ) {
			$c    = $channel / 255;
			$c    = $c <= 0.04045 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
			$lum += array( 0.2126, 0.7152, 0.0722 )[ $i ] * $c;
		}
		return $lum;
	}
}
