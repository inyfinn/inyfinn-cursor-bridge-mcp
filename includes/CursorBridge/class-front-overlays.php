<?php
/**
 * Reusable front overlays: compact DJ Accessibility + Elementor form timer/spam copy.
 *
 * Site-specific blocklists stay in the theme. This class is the portable layer.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Front_Overlays {

	public const OPTION = 'inyfinn_cursor_bridge_front';
	public const TS_FIELD = 'inyfinn_form_ts';

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_action( 'elementor_pro/forms/validation', array( __CLASS__, 'validate_form' ), 4, 2 );
		add_filter( 'gettext', array( __CLASS__, 'gettext_error' ), 15, 3 );
	}

	/**
	 * @return array{
	 *   djacc_skin:bool,
	 *   djacc_compact:bool,
	 *   djacc_color_forest:string,
	 *   djacc_color_lime:string,
	 *   djacc_color_hover:string,
	 *   djacc_color_ink:string,
	 *   form_min_seconds:int
	 * }
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$defaults = self::default_settings();
		$parsed  = wp_parse_args( $stored, $defaults );
		foreach ( array( 'djacc_color_forest', 'djacc_color_lime', 'djacc_color_hover', 'djacc_color_ink' ) as $key ) {
			$parsed[ $key ] = self::sanitize_color_id( (string) $parsed[ $key ], (string) $defaults[ $key ] );
		}
		$parsed['djacc_skin']       = ! empty( $parsed['djacc_skin'] );
		$parsed['djacc_compact']    = ! empty( $parsed['djacc_compact'] );
		$parsed['form_min_seconds'] = (int) $parsed['form_min_seconds'];
		return $parsed;
	}

	/**
	 * @return array{
	 *   djacc_skin:bool,
	 *   djacc_compact:bool,
	 *   djacc_color_forest:string,
	 *   djacc_color_lime:string,
	 *   djacc_color_hover:string,
	 *   djacc_color_ink:string,
	 *   form_min_seconds:int
	 * }
	 */
	public static function default_settings(): array {
		return array(
			'djacc_skin'          => true,
			'djacc_compact'       => true,
			'djacc_color_forest'  => 'vamtam_accent_1',
			'djacc_color_lime'    => 'vamtam_accent_2',
			'djacc_color_hover'   => 'vamtam_accent_4',
			'djacc_color_ink'     => 'vamtam_accent_5',
			'form_min_seconds'    => 15,
		);
	}

	/**
	 * @return void
	 */
	public static function save_from_post(): void {
		$seconds = isset( $_POST['front_form_min_seconds'] ) ? absint( wp_unslash( $_POST['front_form_min_seconds'] ) ) : 15; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $seconds > 120 ) {
			$seconds = 120;
		}
		$defaults = self::default_settings();
		update_option(
			self::OPTION,
			array(
				'djacc_skin'          => ! empty( $_POST['front_djacc_skin'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'djacc_compact'       => ! empty( $_POST['front_djacc_compact'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'djacc_color_forest'  => self::sanitize_color_id( isset( $_POST['front_djacc_color_forest'] ) ? (string) wp_unslash( $_POST['front_djacc_color_forest'] ) : '', $defaults['djacc_color_forest'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'djacc_color_lime'    => self::sanitize_color_id( isset( $_POST['front_djacc_color_lime'] ) ? (string) wp_unslash( $_POST['front_djacc_color_lime'] ) : '', $defaults['djacc_color_lime'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'djacc_color_hover'   => self::sanitize_color_id( isset( $_POST['front_djacc_color_hover'] ) ? (string) wp_unslash( $_POST['front_djacc_color_hover'] ) : '', $defaults['djacc_color_hover'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'djacc_color_ink'     => self::sanitize_color_id( isset( $_POST['front_djacc_color_ink'] ) ? (string) wp_unslash( $_POST['front_djacc_color_ink'] ) : '', $defaults['djacc_color_ink'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'form_min_seconds'    => $seconds,
			),
			false
		);
	}

	public static function sanitize_color_id( string $id, string $fallback ): string {
		$id = strtolower( $id );
		$id = (string) preg_replace( '/[^a-z0-9_]/', '', $id );
		if ( '' === $id ) {
			return $fallback;
		}
		if ( '' === $fallback ) {
			return $id;
		}
		$allowed = self::kit_global_colors();
		if ( isset( $allowed[ $id ] ) ) {
			return $id;
		}
		return $fallback;
	}

	/**
	 * Elementor Site Settings → Global Colors (system + custom).
	 *
	 * @return array<string, string> id => label
	 */
	public static function kit_global_colors(): array {
		$out = array(
			'vamtam_accent_1' => 'Accent 1 (las / primary)',
			'vamtam_accent_2' => 'Accent 2 (wapno / accent)',
			'vamtam_accent_3' => 'Accent 3',
			'vamtam_accent_4' => 'Accent 4 (hover)',
			'vamtam_accent_5' => 'Accent 5 (biel / ink)',
		);
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return $out;
		}
		try {
			$kits = \Elementor\Plugin::$instance->kits_manager ?? null;
			if ( ! is_object( $kits ) || ! method_exists( $kits, 'get_active_kit_for_frontend' ) ) {
				return $out;
			}
			$kit = $kits->get_active_kit_for_frontend();
			if ( ! is_object( $kit ) || ! method_exists( $kit, 'get_settings' ) ) {
				return $out;
			}
			foreach ( array( 'system_colors', 'custom_colors' ) as $group ) {
				$rows = $kit->get_settings( $group );
				if ( ! is_array( $rows ) ) {
					continue;
				}
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) || empty( $row['_id'] ) ) {
						continue;
					}
					$cid = (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $row['_id'] ) );
					if ( '' === $cid ) {
						continue;
					}
					$title = isset( $row['title'] ) ? (string) $row['title'] : $cid;
					$hex   = isset( $row['color'] ) ? (string) $row['color'] : '';
					$out[ $cid ] = $hex ? ( $title . ' — ' . $hex ) : $title;
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $out;
	}

	public static function color_select( string $name, string $current ): void {
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';
		foreach ( self::kit_global_colors() as $id => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $id ),
				selected( $current, $id, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	public static function css_global_var( string $id ): string {
		$id = self::sanitize_color_id( $id, 'vamtam_accent_1' );
		return 'var(--e-global-color-' . $id . ')';
	}

	public static function skin_custom_properties(): string {
		$s = self::settings();
		return sprintf(
			'.djacc-popup{--inyfinn-djacc-forest:%1$s;--inyfinn-djacc-lime:%2$s;--inyfinn-djacc-hover:%3$s;--inyfinn-djacc-ink:%4$s;}',
			self::css_global_var( (string) $s['djacc_color_forest'] ),
			self::css_global_var( (string) $s['djacc_color_lime'] ),
			self::css_global_var( (string) $s['djacc_color_hover'] ),
			self::css_global_var( (string) $s['djacc_color_ink'] )
		);
	}

	public static function handles_timer(): bool {
		$s = self::settings();
		return (int) $s['form_min_seconds'] > 0;
	}

	public static function min_ms(): int {
		$s = self::settings();
		return max( 0, (int) $s['form_min_seconds'] ) * 1000;
	}

	/**
	 * @return void
	 */
	public static function enqueue(): void {
		if ( is_admin() ) {
			return;
		}
		$s       = self::settings();
		$ver     = defined( 'INYFINN_CURSOR_BRIDGE_MCP_VERSION' ) ? INYFINN_CURSOR_BRIDGE_MCP_VERSION : '1.6.6';
		$base    = plugin_dir_url( INYFINN_CURSOR_BRIDGE_MCP_FILE ) . 'assets/front/';
		$dir     = plugin_dir_path( INYFINN_CURSOR_BRIDGE_MCP_FILE ) . 'assets/front/';
		$css     = $dir . 'djacc-compact.css';
		$load_dj = ! empty( $s['djacc_skin'] ) || ! empty( $s['djacc_compact'] );

		if ( $load_dj ) {
			wp_enqueue_style( 'inyfinn-djacc-compact', $base . 'djacc-compact.css', array(), file_exists( $css ) ? (string) filemtime( $css ) : $ver );
			if ( ! empty( $s['djacc_skin'] ) ) {
				wp_add_inline_style( 'inyfinn-djacc-compact', self::skin_custom_properties() );
			}
		}

		if ( ! empty( $s['djacc_compact'] ) ) {
			$js = $dir . 'djacc-compact.js';
			wp_enqueue_script( 'inyfinn-djacc-compact', $base . 'djacc-compact.js', array(), file_exists( $js ) ? (string) filemtime( $js ) : $ver, true );
			wp_localize_script(
				'inyfinn-djacc-compact',
				'inyfinnDjaccCompact',
				array(
					'more' => self::is_english_request() ? 'Show more features' : 'Rozwiń więcej funkcji',
					'less' => self::is_english_request() ? 'Show fewer features' : 'Zwiń dodatkowe funkcje',
				)
			);
		}

		if ( self::handles_timer() ) {
			$js_form = $dir . 'form-antispam.js';
			wp_enqueue_script( 'inyfinn-form-antispam', $base . 'form-antispam.js', array(), file_exists( $js_form ) ? (string) filemtime( $js_form ) : $ver, true );
		}
	}

	/**
	 * @param mixed $record       Form record.
	 * @param mixed $ajax_handler Ajax handler.
	 * @return void
	 */
	public static function validate_form( $record, $ajax_handler ): void {
		if ( ! self::handles_timer() ) {
			return;
		}
		if ( ! is_object( $ajax_handler ) || ! method_exists( $ajax_handler, 'add_error' ) ) {
			return;
		}
		$ts = 0;
		if ( isset( $_POST[ self::TS_FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$ts = absint( wp_unslash( $_POST[ self::TS_FIELD ] ) );
		}
		$min = self::min_ms();
		if ( $ts <= 0 ) {
			self::reject( $record, $ajax_handler );
			return;
		}
		$age_ms = (int) round( microtime( true ) * 1000 ) - $ts;
		if ( $age_ms >= 0 && $age_ms < $min ) {
			self::reject( $record, $ajax_handler );
		}
	}

	/**
	 * @param mixed $record       Form record.
	 * @param mixed $ajax_handler Ajax handler.
	 * @return void
	 */
	public static function reject( $record, $ajax_handler ): void {
		$GLOBALS['inyfinn_form_is_spam']     = true;
		$GLOBALS['inyfinn_form_spam_message'] = self::message_for_record( $record );
		if ( is_object( $ajax_handler ) && method_exists( $ajax_handler, 'add_error' ) ) {
			$ajax_handler->add_error( 'form', self::visible_message() );
		}
	}

	public static function visible_message(): string {
		if ( ! empty( $GLOBALS['inyfinn_form_spam_message'] ) && is_string( $GLOBALS['inyfinn_form_spam_message'] ) ) {
			return (string) $GLOBALS['inyfinn_form_spam_message'];
		}
		return self::message_for_record( null );
	}

	/**
	 * @param mixed $record Form record.
	 * @return string
	 */
	public static function message_for_record( $record ): string {
		$email = self::inbox_from_record( $record );
		$en    = self::is_english_request();
		if ( $en ) {
			$tpl = 'We\'re sorry, but our systems flagged this activity as spam. Copy what you wrote and send it to {email} the usual way.';
		} else {
			$tpl = 'Przykro nam, ale nasze systemy wykryły tę aktywność jako spam. Skopiuj to, co jest napisane i' . "\u{00A0}" . 'wyślij nam na' . "\u{00A0}" . 'adres {email} tradycyjnym sposobem.';
		}
		$tpl = apply_filters( 'inyfinn_cursor_bridge_form_spam_message', $tpl, $en ? 'en' : 'pl', $email, $record );
		return str_replace( '{email}', $email, $tpl );
	}

	/**
	 * @param mixed $record Form record.
	 * @return string
	 */
	public static function inbox_from_record( $record ): string {
		$to = '';
		if ( is_object( $record ) && method_exists( $record, 'get_form_settings' ) ) {
			$to = (string) $record->get_form_settings( 'email_to' );
		}
		$first = trim( (string) explode( ',', $to )[0] );
		if ( is_email( $first ) ) {
			return apply_filters( 'inyfinn_cursor_bridge_form_spam_inbox', $first, $record );
		}
		$admin = (string) get_option( 'admin_email' );
		if ( ! is_email( $admin ) ) {
			$admin = 'info@' . wp_parse_url( home_url(), PHP_URL_HOST );
		}
		return apply_filters( 'inyfinn_cursor_bridge_form_spam_inbox', $admin, $record );
	}

	/**
	 * Map Elementor's generic ERROR string to the spam copy when we blocked the submit.
	 *
	 * @param string $translated Translated string.
	 * @param string $text       Msgid.
	 * @param string $domain     Text domain.
	 * @return string
	 */
	public static function gettext_error( $translated, $text, $domain ): string {
		if ( empty( $GLOBALS['inyfinn_form_is_spam'] ) ) {
			return $translated;
		}
		if ( 'elementor-pro' !== $domain ) {
			return $translated;
		}
		if ( 'Your submission failed because of an error.' === $text ) {
			return self::visible_message();
		}
		return $translated;
	}

	public static function is_english_request(): bool {
		if ( function_exists( 'kubara_pll_form_request_lang' ) && 'en' === kubara_pll_form_request_lang() ) {
			return true;
		}
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		if ( $ref && preg_match( '#/(en|en-gb|en-us)(/|$)#i', wp_parse_url( $ref, PHP_URL_PATH ) ?? '' ) ) {
			return true;
		}
		$lang = function_exists( 'pll_current_language' ) ? (string) pll_current_language() : '';
		return ( 'en' === $lang );
	}
}
