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
	 * @return array{djacc_compact:bool,form_min_seconds:int}
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args(
			$stored,
			array(
				'djacc_compact'     => true,
				'form_min_seconds'   => 15,
			)
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
		update_option(
			self::OPTION,
			array(
				'djacc_compact'   => ! empty( $_POST['front_djacc_compact'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'form_min_seconds' => $seconds,
			),
			false
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
		$ver     = defined( 'INYFINN_CURSOR_BRIDGE_MCP_VERSION' ) ? INYFINN_CURSOR_BRIDGE_MCP_VERSION : '1.6.2';
		$base    = plugin_dir_url( INYFINN_CURSOR_BRIDGE_MCP_FILE ) . 'assets/front/';
		$dir     = plugin_dir_path( INYFINN_CURSOR_BRIDGE_MCP_FILE ) . 'assets/front/';

		if ( ! empty( $s['djacc_compact'] ) ) {
			$css = $dir . 'djacc-compact.css';
			$js  = $dir . 'djacc-compact.js';
			wp_enqueue_style( 'inyfinn-djacc-compact', $base . 'djacc-compact.css', array(), file_exists( $css ) ? (string) filemtime( $css ) : $ver );
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
