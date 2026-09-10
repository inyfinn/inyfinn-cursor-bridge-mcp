<?php
/**
 * Aktualizacje z GitHub Releases (publiczne ZIP-y).
 *
 * WordPress 5.8+: nagłówek Update URI + filtr update_plugins_{hostname}.
 * Źródło: https://make.wordpress.org/core/2021/06/29/introducing-update-uri-plugin-header-in-wordpress-5-8/
 * API:    https://docs.github.com/en/rest/releases/releases#get-the-latest-release
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class GitHub_Updater {

	private const REPO        = 'inyfinn/inyfinn-cursor-bridge-mcp';
	private const HOMEPAGE    = 'https://github.com/inyfinn/inyfinn-cursor-bridge-mcp';
	private const SLUG        = 'inyfinn-cursor-bridge-mcp';
	private const TRANSIENT   = 'inyfinn_cursor_bridge_github_latest';
	private const ASSET_REGEX = '/^inyfinn-cursor-bridge-mcp-.*\.zip$/i';

	public static function init(): void {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'filter_update' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugins_api' ), 10, 3 );
	}

	/**
	 * @param array<string, mixed>|false $update
	 * @param array<string, mixed>       $plugin_data
	 * @param string[]                   $locales
	 * @return array<string, mixed>|false
	 */
	public static function filter_update( $update, array $plugin_data, string $plugin_file, $locales ) {
		unset( $locales );

		if ( $plugin_file !== plugin_basename( INYFINN_CURSOR_BRIDGE_MCP_FILE ) ) {
			return $update;
		}

		$payload = self::build_update_payload( $plugin_data );

		return is_array( $payload ) ? $payload : $update;
	}

	/**
	 * Szczegóły wersji w WP Admin (zamiast wordpress.org).
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object|array
	 */
	public static function filter_plugins_api( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) ) {
			return $result;
		}

		if ( empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = self::fetch_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$version = self::version_from_tag( (string) ( $release['tag_name'] ?? '' ) );
		$package = self::zip_download_url( $release );
		$body    = isset( $release['body'] ) ? (string) $release['body'] : '';

		return (object) array(
			'name'           => 'Inyfinn Cursor Bridge MCP',
			'slug'           => self::SLUG,
			'version'        => $version,
			'author'         => '<a href="https://github.com/inyfinn">Inyfinn</a>',
			'homepage'       => self::HOMEPAGE,
			'download_link'  => $package ? $package : '',
			'requires'       => '6.8',
			'requires_php'   => '7.4',
			'last_updated'   => isset( $release['published_at'] ) ? (string) $release['published_at'] : '',
			'external'       => true,
			'sections'       => array(
				'description' => '<p>MCP Adapter + abilities Cursor. Witryna i aktualizacje: GitHub Releases.</p>',
				'changelog'   => self::format_release_notes( $body ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $plugin_data
	 * @return array<string, mixed>|false
	 */
	private static function build_update_payload( array $plugin_data ) {
		$release = self::fetch_latest_release();
		if ( ! $release ) {
			return false;
		}

		$version = self::version_from_tag( (string) ( $release['tag_name'] ?? '' ) );
		$package = self::zip_download_url( $release );

		if ( '' === $version || ! $package ) {
			return false;
		}

		return array(
			'slug'         => self::SLUG,
			'version'      => $version,
			'url'          => isset( $release['html_url'] ) ? (string) $release['html_url'] : self::HOMEPAGE,
			'package'      => $package,
			'requires_php' => isset( $plugin_data['RequiresPHP'] ) ? (string) $plugin_data['RequiresPHP'] : '7.4',
			'requires'     => isset( $plugin_data['RequiresWP'] ) ? (string) $plugin_data['RequiresWP'] : '6.8',
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function fetch_latest_release(): ?array {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return isset( $cached['tag_name'] ) ? $cached : null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'               => 'application/vnd.github+json',
					'User-Agent'           => 'inyfinn-cursor-bridge-mcp',
					'X-GitHub-Api-Version' => '2022-11-28',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( self::TRANSIENT, array(), 15 * MINUTE_IN_SECONDS );
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_site_transient( self::TRANSIENT, array(), 15 * MINUTE_IN_SECONDS );
			return null;
		}

		set_site_transient( self::TRANSIENT, $body, 12 * HOUR_IN_SECONDS );

		return $body;
	}

	/**
	 * @param array<string, mixed> $release
	 */
	private static function zip_download_url( array $release ): string {
		if ( empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
			return '';
		}

		foreach ( $release['assets'] as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
			$url  = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
			if ( $url && preg_match( self::ASSET_REGEX, $name ) ) {
				return $url;
			}
		}

		return '';
	}

	private static function version_from_tag( string $tag ): string {
		$tag = trim( $tag );
		if ( 0 === stripos( $tag, 'v' ) ) {
			$tag = substr( $tag, 1 );
		}

		return $tag;
	}

	private static function format_release_notes( string $markdown ): string {
		$markdown = trim( $markdown );
		if ( '' === $markdown ) {
			return '<p>Zobacz wydanie na GitHub.</p>';
		}

		return wp_kses_post( wpautop( esc_html( $markdown ) ) );
	}
}
