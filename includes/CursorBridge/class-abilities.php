<?php
/**
 * MCP-public abilities for Cursor.
 *
 * @package Inyfinn_Cursor_Bridge
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Abilities {

	public static function register_hooks(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 5 );
	}

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'cursor-bridge',
			array(
				'label'       => 'Cursor Bridge',
				'description' => 'Abilities for Cursor IDE via MCP Adapter default server.',
			)
		);
	}

	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_ping();
		self::register_site_manifest();
		self::register_setup_guide();
		self::register_configure_profile();
		self::register_site_info();
		self::register_list_plugins();
		self::register_list_themes();
		self::register_list_posts();
		self::register_flush_caches();
		self::register_file_abilities();
		self::register_woocommerce_abilities();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function mcp_meta( bool $readonly = true ): array {
		$meta = array(
			'mcp' => array(
				'public' => true,
				'type'   => 'tool',
			),
		);

		if ( $readonly ) {
			$meta['annotations'] = array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			);
		}

		return $meta;
	}

	private static function register_ping(): void {
		wp_register_ability(
			'cursor-bridge/ping',
			array(
				'label'               => 'MCP Ping',
				'description'         => 'Health check: bridge version, MCP Adapter, public abilities count.',
				'category'            => 'cursor-bridge',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function (): array {
					$manifest = Site_Manifest::build();
					return array(
						'ok'                => true,
						'bridge_version'    => defined( 'INYFINN_CURSOR_BRIDGE_MCP_VERSION' ) ? INYFINN_CURSOR_BRIDGE_MCP_VERSION : INYFINN_CURSOR_BRIDGE_VERSION,
						'mcp_adapter'       => $manifest['mcp_adapter'],
						'site_url'          => $manifest['site_url'],
						'public_abilities'  => count( $manifest['public_abilities'] ),
						'timestamp'         => gmdate( 'c' ),
					);
				},
				'permission_callback' => static fn() => current_user_can( 'read' ),
				'meta'                => self::mcp_meta(),
			)
		);
	}

	private static function register_site_manifest(): void {
		wp_register_ability(
			'cursor-bridge/get-site-manifest',
			array(
				'label'               => 'Get Site Manifest',
				'description'         => 'Full site context for Cursor: URLs, paths, plugins, MCP endpoint, hosting profile. No secrets.',
				'category'            => 'cursor-bridge',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => Site_Manifest::build(),
				'permission_callback' => static fn() => current_user_can( 'read' ),
				'meta'                => self::mcp_meta(),
			)
		);
	}

	private static function register_setup_guide(): void {
		wp_register_ability(
			'cursor-bridge/get-setup-guide',
			array(
				'label'               => 'Get Cursor Setup Guide',
				'description'         => 'Hosting-specific steps for .env, mcp.json, SSH, WP-CLI. Read this first on new projects.',
				'category'            => 'cursor-bridge',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => Site_Manifest::setup_guide(),
				'permission_callback' => static fn() => current_user_can( 'read' ),
				'meta'                => self::mcp_meta(),
			)
		);
	}

	private static function register_configure_profile(): void {
		wp_register_ability(
			'cursor-bridge/configure-profile',
			array(
				'label'               => 'Configure Hosting Profile',
				'description'         => 'Set hosting provider slug (seohost, generic, local) and optional notes. No secrets.',
				'category'            => 'cursor-bridge',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'hosting_provider' => array(
							'type' => 'string',
							'enum' => array( 'seohost', 'generic', 'local' ),
						),
						'notes'            => array( 'type' => 'string' ),
					),
					'required'   => array( 'hosting_provider' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function ( $input = array() ): array {
					$input    = is_array( $input ) ? $input : array();
					$provider = sanitize_key( (string) ( $input['hosting_provider'] ?? 'generic' ) );
					$allowed  = array( 'seohost', 'generic', 'local' );
					if ( ! in_array( $provider, $allowed, true ) ) {
						$provider = 'generic';
					}

					$profile = array(
						'hosting_provider' => $provider,
						'notes'            => sanitize_textarea_field( (string) ( $input['notes'] ?? '' ) ),
					);
					update_option( 'inyfinn_cursor_bridge_profile', $profile, false );

					return array(
						'saved'       => true,
						'profile'     => $profile,
						'setup_guide' => Site_Manifest::setup_guide(),
					);
				},
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'meta'                => self::mcp_meta( false ),
			)
		);
	}

	private static function register_site_info(): void {
		wp_register_ability(
			'cursor-bridge/get-site-info',
			array(
				'label'               => 'Get Site Info',
				'description'         => 'Title, tagline, URLs, language, environment.',
				'category'            => 'cursor-bridge',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function (): array {
					return array(
						'name'        => get_bloginfo( 'name' ),
						'description' => get_bloginfo( 'description' ),
						'url'         => home_url( '/' ),
						'wpurl'       => site_url( '/' ),
						'language'    => get_bloginfo( 'language' ),
						'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
					);
				},
				'permission_callback' => static fn() => current_user_can( 'read' ),
				'meta'                => self::mcp_meta(),
			)
		);
	}

	private static function register_list_plugins(): void {
		wp_register_ability(
			'cursor-bridge/list-plugins',
			array(
				'label'               => 'List Plugins',
				'description'         => 'Active and inactive plugins with versions.',
				'category'            => 'cursor-bridge',
				'output_schema'       => array( 'type' => 'array' ),
				'execute_callback'    => static function (): array {
					if ( ! function_exists( 'get_plugins' ) ) {
						require_once ABSPATH . 'wp-admin/includes/plugin.php';
					}
					$all     = get_plugins();
					$active  = (array) get_option( 'active_plugins', array() );
					$out     = array();
					foreach ( $all as $file => $data ) {
						$out[] = array(
							'file'    => $file,
							'name'    => $data['Name'] ?? '',
							'version' => $data['Version'] ?? '',
							'active'  => in_array( $file, $active, true ),
						);
					}
					return $out;
				},
				'permission_callback' => static fn() => current_user_can( 'activate_plugins' ),
				'meta'                => self::mcp_meta(),
			)
		);
	}

	private static function register_list_themes(): void {
		wp_register_ability(
			'cursor-bridge/list-themes',
			array(
				'label'               => 'List Themes',
				'description'         => 'Installed themes and active theme.',
				'category'            => 'cursor-bridge',
				'output_schema'       => array( 'type' => 'array' ),
				'execute_callback'    => static function (): array {
					$themes  = wp_get_themes();
					$current = get_stylesheet();
					$out     = array();
					foreach ( $themes as $slug => $theme ) {
						$out[] = array(
							'slug'    => $slug,
							'name'    => $theme->get( 'Name' ),
							'version' => $theme->get( 'Version' ),
							'active'  => $slug === $current,
							'parent'  => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
						);
					}
					return $out;
				},
				'permission_callback' => static fn() => current_user_can( 'switch_themes' ),
				'meta'                => self::mcp_meta(),
			)
		);
	}

	private static function register_list_posts(): void {
		wp_register_ability(
			'cursor-bridge/list-posts',
			array(
				'label'               => 'List Posts',
				'description'         => 'List posts/pages/products by post_type.',
				'category'            => 'cursor-bridge',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string', 'default' => 'post' ),
						'per_page'  => array( 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50 ),
						'status'    => array( 'type' => 'string', 'default' => 'publish' ),
					),
				),
				'output_schema'       => array( 'type' => 'array' ),
				'execute_callback'    => static function ( $input = array() ): array {
					$input = is_array( $input ) ? $input : array();
					$q     = new \WP_Query(
						array(
							'post_type'      => sanitize_key( (string) ( $input['post_type'] ?? 'post' ) ),
							'post_status'    => sanitize_key( (string) ( $input['status'] ?? 'publish' ) ),
							'posts_per_page' => max( 1, min( 50, (int) ( $input['per_page'] ?? 10 ) ) ),
							'no_found_rows'  => true,
						)
					);
					$out = array();
					foreach ( $q->posts as $post ) {
						$out[] = array(
							'id'      => $post->ID,
							'title'   => get_the_title( $post ),
							'status'  => $post->post_status,
							'type'    => $post->post_type,
							'url'     => get_permalink( $post ),
							'modified'=> $post->post_modified_gmt,
						);
					}
					return $out;
				},
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'meta'                => self::mcp_meta(),
			)
		);
	}

	private static function register_flush_caches(): void {
		wp_register_ability(
			'cursor-bridge/flush-caches',
			array(
				'label'               => 'Flush Caches',
				'description'         => 'Flush object cache and WooCommerce transients.',
				'category'            => 'cursor-bridge',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function (): array {
					wp_cache_flush();
					if ( function_exists( 'wc_delete_product_transients' ) ) {
						wc_delete_product_transients();
					}
					return array( 'flushed' => true );
				},
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'meta'                => self::mcp_meta( false ),
			)
		);
	}

	private static function register_file_abilities(): void {
		wp_register_ability(
			'cursor-bridge/read-wp-content-file',
			array(
				'label'               => 'Read wp-content File',
				'description'         => 'Read a file under wp-content (max 512KB). Path relative to wp-content, e.g. themes/pharma-child/style.css',
				'category'            => 'cursor-bridge',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'path' => array( 'type' => 'string' ),
					),
					'required'   => array( 'path' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function ( $input = array() ) {
					$input = is_array( $input ) ? $input : array();
					$path  = sanitize_text_field( (string) ( $input['path'] ?? '' ) );
					$result = File_Reader::read_file( $path );
					if ( is_wp_error( $result ) ) {
						return array( 'error' => $result->get_error_message() );
					}
					return $result;
				},
				'permission_callback' => static fn() => current_user_can( 'edit_themes' ) || current_user_can( 'edit_plugins' ),
				'meta'                => self::mcp_meta(),
			)
		);

		wp_register_ability(
			'cursor-bridge/list-wp-content-dir',
			array(
				'label'               => 'List wp-content Directory',
				'description'         => 'List files/dirs under wp-content. Path relative to wp-content, depth 1-4.',
				'category'            => 'cursor-bridge',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'path'  => array( 'type' => 'string', 'default' => '' ),
						'depth' => array( 'type' => 'integer', 'default' => 2, 'minimum' => 1, 'maximum' => 4 ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function ( $input = array() ) {
					$input = is_array( $input ) ? $input : array();
					$path  = sanitize_text_field( (string) ( $input['path'] ?? '' ) );
					$depth = (int) ( $input['depth'] ?? 2 );
					$result = File_Reader::list_directory( $path, $depth );
					if ( is_wp_error( $result ) ) {
						return array( 'error' => $result->get_error_message() );
					}
					return $result;
				},
				'permission_callback' => static fn() => current_user_can( 'edit_themes' ) || current_user_can( 'edit_plugins' ),
				'meta'                => self::mcp_meta(),
			)
		);
	}

	private static function register_woocommerce_abilities(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		wp_register_ability(
			'cursor-bridge/wc-list-products',
			array(
				'label'               => 'List WooCommerce Products',
				'description'         => 'Paginated product list.',
				'category'            => 'cursor-bridge',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'per_page' => array( 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50 ),
						'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
						'search'   => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array( 'type' => 'array' ),
				'execute_callback'    => static function ( $input = array() ): array {
					$input    = is_array( $input ) ? $input : array();
					$per_page = max( 1, min( 50, (int) ( $input['per_page'] ?? 10 ) ) );
					$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
					$args     = array(
						'limit'  => $per_page,
						'page'   => $page,
						'return' => 'objects',
						'status' => array( 'publish', 'draft', 'private', 'pending' ),
					);
					if ( ! empty( $input['search'] ) ) {
						$args['s'] = sanitize_text_field( (string) $input['search'] );
					}
					$result = array();
					foreach ( wc_get_products( $args ) as $product ) {
						$result[] = array(
							'id'     => $product->get_id(),
							'name'   => $product->get_name(),
							'sku'    => $product->get_sku(),
							'status' => $product->get_status(),
							'price'  => $product->get_price(),
						);
					}
					return $result;
				},
				'permission_callback' => static fn() => current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' ),
				'meta'                => self::mcp_meta(),
			)
		);

		wp_register_ability(
			'cursor-bridge/wc-list-orders',
			array(
				'label'               => 'List WooCommerce Orders',
				'description'         => 'Recent orders.',
				'category'            => 'cursor-bridge',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array( 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50 ),
					),
				),
				'output_schema'       => array( 'type' => 'array' ),
				'execute_callback'    => static function ( $input = array() ): array {
					$input = is_array( $input ) ? $input : array();
					$limit = max( 1, min( 50, (int) ( $input['limit'] ?? 10 ) ) );
					$result = array();
					foreach ( wc_get_orders( array( 'limit' => $limit, 'orderby' => 'date', 'order' => 'DESC' ) ) as $order ) {
						$result[] = array(
							'id'     => $order->get_id(),
							'status' => $order->get_status(),
							'total'  => $order->get_total(),
							'email'  => $order->get_billing_email(),
						);
					}
					return $result;
				},
				'permission_callback' => static fn() => current_user_can( 'manage_woocommerce' ),
				'meta'                => self::mcp_meta(),
			)
		);
	}
}
