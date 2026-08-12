<?php
/**
 * REST/MCP access diagnostics — evidence from installed plugins, not guesses.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Rest_Firewall_Diagnostics {

	/**
	 * Known security plugins that may block external REST before WordPress runs.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const SECURITY_PLUGINS = array(
		'wordfence/wordfence.php' => array(
			'name'     => 'Wordfence Security',
			'has_waf'  => true,
			'fix_hint' => 'Wordfence → Firewall → dodaj IP do whitelist lub Learning Mode; ścieżki: /wp-json/mcp/*, /wp-json/cursor-bridge/*',
		),
		'all-in-one-wp-security-and-firewall/wp-security.php' => array(
			'name'     => 'All-In-One Security (AIOS)',
			'has_waf'  => true,
			'fix_hint' => 'AIOS → Firewall → whitelist IP lub reguła dla wp-json',
		),
		'better-wp-security/better-wp-security.php' => array(
			'name'     => 'iThemes Security',
			'has_waf'  => true,
			'fix_hint' => 'iThemes → Firewall / 404 Detection — whitelist REST',
		),
		'sg-security/sg-security.php' => array(
			'name'     => 'SiteGround Security',
			'has_waf'  => true,
			'fix_hint' => 'SiteGround Security → whitelist wp-json',
		),
		'jetpack/jetpack.php' => array(
			'name'     => 'Jetpack',
			'has_waf'  => false,
			'fix_hint' => 'Jetpack Protect / brute-force — sprawdź blokady IP',
		),
		'loginizer/loginizer.php' => array(
			'name'     => 'Loginizer',
			'has_waf'  => false,
			'fix_hint' => 'Loginizer — zwykle blokuje login, nie REST; sprawdź przy 403 na całą domenę',
		),
	);

	/**
	 * @return array<string, mixed>
	 */
	public static function report(): array {
		$active_waf = self::active_waf_plugins();
		$profile    = Hosting_Profiles::get_profile();
		$provider   = (string) ( $profile['hosting_provider'] ?? 'generic' );

		return array(
			'active_security_plugins' => self::active_security_plugins(),
			'active_waf_plugins'      => $active_waf,
			'waf_risk'                => ! empty( $active_waf ),
			'hosting_provider'        => $provider,
			'hosting_waf_note'        => self::hosting_waf_note( $provider ),
			'diagnose_403_steps'      => self::diagnose_403_steps(),
			'do_not_guess'            => 'Nie zakładaj Imunify360 ani innego WAF bez dowodu. Sprawdź: (1) treść odpowiedzi 403, (2) aktywne wtyczki security poniżej, (3) panel hostingu (WAF), (4) logi Wordfence w wp-content/wflogs/.',
			'test_urls'               => array(
				'ping'   => rest_url( 'cursor-bridge/v1/ping' ),
				'verify' => rest_url( 'cursor-bridge/v1/verify-connection' ),
				'mcp'    => rest_url( 'mcp/mcp-adapter-default-server' ),
			),
		);
	}

	/**
	 * Interpret external HTTP response body (from curl) — pattern matching only.
	 *
	 * @param string $body Response body snippet.
	 * @param int    $code HTTP status code.
	 * @return array<string, mixed>
	 */
	public static function interpret_http_block( string $body, int $code ): array {
		$body_lower = strtolower( substr( $body, 0, 2000 ) );
		$signals    = array();

		if ( str_contains( $body_lower, 'imunify360' ) || str_contains( $body_lower, 'imunify' ) ) {
			$signals[] = 'imunify360_in_response_body';
		}
		if ( str_contains( $body_lower, 'wordfence' ) ) {
			$signals[] = 'wordfence_in_response_body';
		}
		if ( str_contains( $body_lower, 'access denied' ) && str_contains( $body_lower, 'waf' ) ) {
			$signals[] = 'generic_waf_message';
		}
		if ( $code === 403 && '' === trim( $body ) ) {
			$signals[] = 'empty_403_body_possible_server_waf';
		}

		$likely = 'unknown';
		if ( in_array( 'wordfence_in_response_body', $signals, true ) || self::is_plugin_active( 'wordfence/wordfence.php' ) ) {
			$likely = 'wordfence_or_server_waf';
		} elseif ( in_array( 'imunify360_in_response_body', $signals, true ) ) {
			$likely = 'imunify360';
		} elseif ( ! empty( $signals ) ) {
			$likely = 'firewall_layer';
		}

		return array(
			'http_code'        => $code,
			'signals'          => $signals,
			'likely_blocker'   => $likely,
			'active_waf_plugins' => self::active_waf_plugins(),
			'next_steps'       => self::diagnose_403_steps(),
		);
	}

	/**
	 * @return list<array<string, string>>
	 */
	private static function active_security_plugins(): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$out = array();
		foreach ( self::SECURITY_PLUGINS as $file => $meta ) {
			if ( ! is_plugin_active( $file ) ) {
				continue;
			}
			$out[] = array(
				'file'     => $file,
				'name'     => (string) $meta['name'],
				'has_waf'  => ! empty( $meta['has_waf'] ),
				'fix_hint' => (string) $meta['fix_hint'],
			);
		}

		return $out;
	}

	/**
	 * @return list<array<string, string>>
	 */
	private static function active_waf_plugins(): array {
		$all = self::active_security_plugins();
		return array_values(
			array_filter(
				$all,
				static fn( array $p ): bool => ! empty( $p['has_waf'] )
			)
		);
	}

	private static function is_plugin_active( string $file ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( $file );
	}

	private static function hosting_waf_note( string $provider ): string {
		if ( 'seohost' === $provider ) {
			return 'SEOHost: panel hostingu może mieć „Zapora WAF” na domenie (niezależna od WordPress). Wyłączenie WAF w panelu lub whitelist IP potwierdza blokadę na poziomie serwera. To nie musi być Imunify360 — sprawdź treść 403 i panel.';
		}

		return 'Sprawdź panel hostingu (WAF / firewall) oraz aktywne wtyczki security w WordPress.';
	}

	/**
	 * @return list<string>
	 */
	private static function diagnose_403_steps(): array {
		return array(
			'1. Z zewnątrz: curl -u "user:app_password" -w "%{http_code}" ' . rest_url( 'cursor-bridge/v1/ping' ),
			'2. Odczytaj treść odpowiedzi (Wordfence, Imunify360, pusta strona = WAF serwera).',
			'3. WP Admin → Wtyczki: które security są aktywne (cursor-bridge/health-check → rest_firewall).',
			'4. Wordfence: Firewall → Whitelisted IP / Learning Mode; logi w wp-content/wflogs/.',
			'5. Panel hostingu: WAF domeny (SEOHost itd.) — whitelist IP developera.',
			'6. Po zmianie: restart MCP w Cursorze i ponowny curl.',
			'7. Workaround bez HTTP: Local Queue (SFTP → wp-content/inyfinn-cursor-bridge/queue/).',
		);
	}
}
