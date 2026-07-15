<?php
/**
 * Site hardening installer: SVG, unique uploads, wp-config, PHP limits.
 *
 * Safety rules:
 * 1. Always backup before mutate
 * 2. Detect markers / signatures — never duplicate (unless replace/force)
 * 3. mu-plugin default; functions.php when user selects in admin
 * 4. Validate PHP after write; rollback on failure
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Hardening {

	private const MARKER_PREFIX = 'BEGIN Inyfinn Cursor Bridge:';
	private const MARKER_END    = 'END Inyfinn Cursor Bridge:';

	/**
	 * Feature registry.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function features(): array {
		$dir = INYFINN_CURSOR_BRIDGE_MCP_DIR . 'install/hardening/';

		return array(
			'svg-media'      => array(
				'label'       => 'SVG + media library',
				'type'        => 'mu_plugin',
				'source'      => $dir . 'inyfinn-svg-media.php',
				'mu_filename' => 'inyfinn-svg-media.php',
				'marker'      => 'svg-media',
				'signatures'  => array(
					'inyfinn_cb_svg_metadata',
					'nexta_svg_metadata',
					"mimes['svg']",
					'image/svg+xml',
					'BEGIN Inyfinn Cursor Bridge: svg-media',
				),
			),
			'unique-uploads' => array(
				'label'       => 'Unikalne nazwy uploadów (data)',
				'type'        => 'mu_plugin',
				'source'      => $dir . 'inyfinn-unique-uploads.php',
				'mu_filename' => 'inyfinn-unique-uploads.php',
				'marker'      => 'unique-uploads',
				'signatures'  => array(
					'BEGIN Inyfinn Cursor Bridge: unique-uploads',
					'Ymd-His',
					'wp_unique_filename',
				),
			),
			'wp-config'      => array(
				'label'      => 'Stałe wp-config.php',
				'type'       => 'wp_config',
				'marker'     => 'wp-config',
				'signatures' => array(
					'BEGIN Inyfinn Cursor Bridge: wp-config',
					'BEGIN Inyfinn Cursor Bridge: wp-config-safe',
					'FORCE_SSL_ADMIN',
					'HTTP_X_FORWARDED_PROTO',
				),
			),
			'php-limits'     => array(
				'label'      => 'Limity PHP 8000M (.user.ini + .htaccess)',
				'type'       => 'php_limits',
				'marker'     => 'php-limits',
				'signatures' => array(
					'BEGIN Inyfinn Cursor Bridge: php-limits',
					'upload_max_filesize',
				),
			),
		);
	}

	/**
	 * Status of all features.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$out = array();
		foreach ( self::features() as $id => $meta ) {
			$detection = self::detect( $id );
			$out[ $id ] = array(
				'id'         => $id,
				'label'      => $meta['label'],
				'type'       => $meta['type'],
				'installed'  => ! empty( $detection['ours'] ),
				'similar'    => ! empty( $detection['similar'] ),
				'location'   => $detection['location'] ?? null,
				'can_install'=> empty( $detection['ours'] ) && empty( $detection['similar'] ),
				'detection'  => $detection,
			);
		}

		return array(
			'features'     => $out,
			'backup_root'  => Hardening_Backup::backup_root_relative(),
			'prefer'       => 'mu-plugin default; functions.php when selected in admin',
			'timestamp'    => gmdate( 'c' ),
		);
	}

	/**
	 * Install one feature.
	 *
	 * @param array<string, mixed> $opts dry_run, force, allow_functions_php
	 * @return array<string, mixed>
	 */
	public static function install( string $feature_id, array $opts = array() ): array {
		$feature_id = sanitize_key( $feature_id );
		$features   = self::features();
		if ( ! isset( $features[ $feature_id ] ) ) {
			return array(
				'ok'      => false,
				'message' => Hardening_Messages::get( 'unknown', $feature_id ),
			);
		}

		$meta     = $features[ $feature_id ];
		$dry_run  = ! empty( $opts['dry_run'] );
		$force    = ! empty( $opts['force'] );
		$replace  = ! empty( $opts['replace'] ) || $force;
		$allow_fn = ! empty( $opts['allow_functions_php'] ) || ! empty( $opts['prefer_functions_php'] );
		$prefer_fn = ! empty( $opts['prefer_functions_php'] );

		$detection = self::detect( $feature_id );

		if ( ! empty( $detection['ours'] ) && ! $replace ) {
			return array(
				'ok'      => false,
				'skipped' => true,
				'message' => Hardening_Messages::get( 'already_exists', $feature_id ),
				'detection' => $detection,
			);
		}

		if ( ! empty( $detection['similar'] ) && empty( $detection['ours'] ) && ! $force ) {
			return array(
				'ok'      => false,
				'skipped' => true,
				'message' => Hardening_Messages::get(
					'similar_exists',
					$feature_id,
					array( 'hint' => implode( ', ', $detection['matched_signatures'] ?? array() ) )
				),
				'detection' => $detection,
			);
		}

		if ( $dry_run ) {
			return array(
				'ok'      => true,
				'dry_run' => true,
				'message' => Hardening_Messages::get(
					'dry_run',
					$feature_id,
					array(
						'target' => $meta['mu_filename'] ?? $meta['type'],
						'backup' => Hardening_Backup::backup_root_relative(),
					)
				),
				'would' => $meta['type'],
			);
		}

		switch ( $meta['type'] ) {
			case 'mu_plugin':
				return self::install_mu_plugin( $feature_id, $meta, $allow_fn, $prefer_fn, $replace );
			case 'wp_config':
				return self::install_wp_config( $feature_id, $replace );
			case 'php_limits':
				return self::install_php_limits( $feature_id, $replace );
			default:
				return array(
					'ok'      => false,
					'message' => Hardening_Messages::get( 'unknown', $feature_id ),
				);
		}
	}

	/**
	 * Install all safe features that are not present.
	 *
	 * @return array<string, mixed>
	 */
	public static function install_all( array $opts = array() ): array {
		$results = array();
		foreach ( array_keys( self::features() ) as $id ) {
			$results[ $id ] = self::install( $id, $opts );
		}
		return array(
			'ok'      => true,
			'results' => $results,
			'status'  => self::status(),
		);
	}

	/**
	 * @param array<string, mixed> $meta
	 * @return array<string, mixed>
	 */
	private static function install_mu_plugin( string $feature_id, array $meta, bool $allow_functions_php, bool $prefer_functions_php, bool $replace ): array {
		$source = $meta['source'];
		if ( ! is_readable( $source ) ) {
			return array(
				'ok'      => false,
				'message' => Hardening_Messages::get( 'target_missing', $feature_id, array( 'target' => $source ) ),
			);
		}

		$content = file_get_contents( $source );
		if ( false === $content ) {
			return array(
				'ok'      => false,
				'message' => Hardening_Messages::get( 'not_writable', $feature_id, array( 'target' => $source ) ),
			);
		}

		if ( $prefer_functions_php && $allow_functions_php ) {
			return self::install_into_functions_php( $feature_id, $meta, $content, $replace );
		}

		$mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		if ( ! is_dir( $mu_dir ) ) {
			wp_mkdir_p( $mu_dir );
		}

		$dest = trailingslashit( $mu_dir ) . $meta['mu_filename'];

		if ( is_writable( $mu_dir ) || ( file_exists( $dest ) && is_writable( $dest ) ) ) {
			$backup = array( 'ok' => true, 'path' => null );
			if ( file_exists( $dest ) ) {
				$backup = Hardening_Backup::backup_file( $dest );
				if ( empty( $backup['ok'] ) ) {
					return array(
						'ok'      => false,
						'message' => Hardening_Messages::get( 'backup_failed', $feature_id, array( 'target' => $dest ) ),
					);
				}
			}

			$written = file_put_contents( $dest, $content, LOCK_EX );
			if ( false === $written ) {
				return array(
					'ok'      => false,
					'message' => Hardening_Messages::get( 'not_writable', $feature_id, array( 'target' => $dest ) ),
				);
			}

			$lint = self::lint_php_file( $dest );
			if ( empty( $lint['ok'] ) ) {
				if ( ! empty( $backup['path'] ) ) {
					Hardening_Backup::restore( $backup['path'], $dest );
				} else {
					@unlink( $dest );
				}
				return array(
					'ok'      => false,
					'message' => Hardening_Messages::get(
						'syntax_invalid',
						$feature_id,
						array(
							'target' => $dest,
							'backup' => (string) ( $backup['path'] ?? '' ),
						)
					),
					'lint'    => $lint,
				);
			}

			return array(
				'ok'      => true,
				'path'    => $dest,
				'backup'  => $backup['path'] ?? null,
				'message' => Hardening_Messages::get(
					'installed_mu',
					$feature_id,
					array( 'target' => $dest )
				),
			);
		}

		if ( ! $allow_functions_php ) {
			return array(
				'ok'      => false,
				'message' => Hardening_Messages::get(
					'not_writable',
					$feature_id,
					array( 'target' => $mu_dir . ' (zaznacz „Wstrzyknij do functions.php” w panelu)' )
				),
			);
		}

		return self::install_into_functions_php( $feature_id, $meta, $content, $replace );
	}

	/**
	 * @param array<string, mixed> $meta
	 * @return array<string, mixed>
	 */
	private static function install_into_functions_php( string $feature_id, array $meta, string $mu_content, bool $replace = false ): array {
		$functions = get_stylesheet_directory() . '/functions.php';
		if ( ! file_exists( $functions ) ) {
			return array(
				'ok'      => false,
				'message' => Hardening_Messages::get( 'target_missing', $feature_id, array( 'target' => $functions ) ),
			);
		}

		$existing = file_get_contents( $functions );
		if ( false === $existing ) {
			return array(
				'ok'      => false,
				'message' => Hardening_Messages::get( 'not_writable', $feature_id, array( 'target' => $functions ) ),
			);
		}

		$snippet = self::extract_snippet_body( $mu_content, $meta['marker'] );
		if ( '' === $snippet ) {
			return array(
				'ok'      => false,
				'message' => Hardening_Messages::get( 'unknown', $feature_id ),
			);
		}

		if ( false !== strpos( $existing, self::MARKER_PREFIX . ' ' . $meta['marker'] ) ) {
			if ( ! $replace ) {
				return array(
					'ok'      => false,
					'skipped' => true,
					'message' => Hardening_Messages::get( 'already_exists', $feature_id ),
				);
			}
			$existing = self::strip_functions_marker_block( $existing, $meta['marker'] );
		}

		$backup = Hardening_Backup::backup_file( $functions );
		if ( empty( $backup['ok'] ) ) {
			return array(
				'ok'      => false,
				'message' => Hardening_Messages::get( 'backup_failed', $feature_id, array( 'target' => $functions ) ),
			);
		}

		$block  = "\n\n/**\n * " . self::MARKER_PREFIX . ' ' . $meta['marker'] . "\n */\n";
		$block .= $snippet . "\n";
		$block .= '// ' . self::MARKER_END . ' ' . $meta['marker'] . "\n";

		$new = rtrim( $existing ) . $block;
		if ( false === file_put_contents( $functions, $new, LOCK_EX ) ) {
			return array(
				'ok'      => false,
				'message' => Hardening_Messages::get( 'not_writable', $feature_id, array( 'target' => $functions ) ),
			);
		}

		$lint = self::lint_php_file( $functions );
		if ( empty( $lint['ok'] ) ) {
			Hardening_Backup::restore( $backup['path'], $functions );
			return array(
				'ok'      => false,
				'message' => Hardening_Messages::get(
					'syntax_invalid',
					$feature_id,
					array(
						'target' => $functions,
						'backup' => $backup['path'],
					)
				),
			);
		}

		return array(
			'ok'      => true,
			'path'    => $functions,
			'backup'  => $backup['path'],
			'message' => Hardening_Messages::get(
				'installed_functions',
				$feature_id,
				array( 'backup' => $backup['path'] )
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function install_wp_config( string $feature_id, bool $replace = false ): array {
		$config = ABSPATH . 'wp-config.php';
		if ( ! is_readable( $config ) ) {
			$parent = dirname( ABSPATH ) . '/wp-config.php';
			$config = is_readable( $parent ) ? $parent : $config;
		}
		if ( ! is_readable( $config ) ) {
			return array(
				'ok'      => false,
				'message' => Hardening_Messages::get( 'target_missing', $feature_id, array( 'target' => 'wp-config.php' ) ),
			);
		}

		$raw = file_get_contents( $config );
		if ( false === $raw ) {
			return array(
				'ok'      => false,
				'message' => Hardening_Messages::get( 'not_writable', $feature_id, array( 'target' => $config ) ),
			);
		}

		$has_ours = false !== strpos( $raw, 'BEGIN Inyfinn Cursor Bridge: wp-config' );

		if ( $has_ours && ! $replace ) {
			return array(
				'ok'      => false,
				'skipped' => true,
				'message' => Hardening_Messages::get( 'already_exists', $feature_id ),
			);
		}

		if ( $has_ours && $replace ) {
			$raw = self::strip_wp_config_blocks( $raw );
		}

		$marker = "/* That's all, stop editing!";
		$pos    = strpos( $raw, $marker );
		if ( false === $pos ) {
			$marker = "/* That's all, stop editing";
			$pos    = strpos( $raw, $marker );
		}
		if ( false === $pos ) {
			return array(
				'ok'      => false,
				'message' => Hardening_Messages::get( 'wp_config_marker', $feature_id ),
			);
		}

		$backup = Hardening_Backup::backup_file( $config );
		if ( empty( $backup['ok'] ) ) {
			return array(
				'ok'      => false,
				'message' => Hardening_Messages::get( 'backup_failed', $feature_id, array( 'target' => $config ) ),
			);
		}

		$block = self::wp_config_block( $raw );
		$new   = substr( $raw, 0, $pos ) . $block . "\n" . substr( $raw, $pos );

		if ( ! is_writable( $config ) ) {
			return array(
				'ok'      => false,
				'message' => Hardening_Messages::get( 'not_writable', $feature_id, array( 'target' => $config ) ),
			);
		}

		if ( false === file_put_contents( $config, $new, LOCK_EX ) ) {
			return array(
				'ok'      => false,
				'message' => Hardening_Messages::get( 'not_writable', $feature_id, array( 'target' => $config ) ),
			);
		}

		$lint = self::lint_php_file( $config );
		if ( empty( $lint['ok'] ) ) {
			Hardening_Backup::restore( $backup['path'], $config );
			return array(
				'ok'      => false,
				'message' => Hardening_Messages::get(
					'syntax_invalid',
					$feature_id,
					array(
						'target' => $config,
						'backup' => $backup['path'],
					)
				),
			);
		}

		return array(
			'ok'      => true,
			'path'    => $config,
			'backup'  => $backup['path'],
			'message' => Hardening_Messages::get(
				'installed_config',
				$feature_id,
				array( 'backup' => $backup['path'] )
			),
		);
	}

	/**
	 * Build wp-config block — only defines not already present outside our block.
	 */
	private static function wp_config_block( string $existing ): string {
		$lines   = array();
		$lines[] = '';
		$lines[] = '// BEGIN Inyfinn Cursor Bridge: wp-config';

		if ( false === strpos( $existing, 'HTTP_X_FORWARDED_PROTO' ) ) {
			$lines[] = "if ( isset( \$_SERVER['HTTP_X_FORWARDED_PROTO'] ) && false !== strpos( \$_SERVER['HTTP_X_FORWARDED_PROTO'], 'https' ) ) {";
			$lines[] = "\t\$_SERVER['HTTPS'] = 'on';";
			$lines[] = '}';
		}

		$defines = array(
			'WP_DEBUG'                    => 'true',
			'WP_DEBUG_LOG'                => 'true',
			'WP_DEBUG_DISPLAY'            => 'false',
			'WPLANG'                      => "'pl_PL'",
			'DISABLE_WP_CRON'             => 'false',
			'FORCE_SSL_ADMIN'             => 'true',
			'FS_METHOD'                   => "'direct'",
			'WP_ALLOW_REPAIR'             => 'true',
			'WP_AUTO_UPDATE_CORE'         => 'false',
			'AUTOMATIC_UPDATER_DISABLED'  => 'true',
			'AUTOSAVE_INTERVAL'           => '60',
			'WP_CACHE'                    => 'true',
			'WP_MEMORY_LIMIT'             => "'8000M'",
			'WP_MAX_MEMORY_LIMIT'         => "'512M'",
		);

		foreach ( $defines as $const => $value ) {
			if ( false !== strpos( $existing, "'" . $const . "'" ) || false !== strpos( $existing, '"' . $const . '"' ) || false !== preg_match( '/define\s*\(\s*[\'"]' . preg_quote( $const, '/' ) . '[\'"]/', $existing ) ) {
				continue;
			}
			$lines[] = "if ( ! defined( '{$const}' ) ) { define( '{$const}', {$value} ); }";
		}

		$lines[] = '// END Inyfinn Cursor Bridge: wp-config';
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function install_php_limits( string $feature_id, bool $replace = false ): array {
		$results  = array();
		$ini_body = self::php_limits_ini_body();
		$user_ini = ABSPATH . '.user.ini';

		if ( file_exists( $user_ini ) ) {
			$cur = file_get_contents( $user_ini );
			if ( ! is_string( $cur ) ) {
				$cur = '';
			}
			if ( false !== strpos( $cur, 'BEGIN Inyfinn Cursor Bridge: php-limits' ) ) {
				if ( ! $replace ) {
					$results['user_ini'] = array(
						'ok'      => false,
						'skipped' => true,
						'message' => Hardening_Messages::get( 'already_exists', $feature_id ),
					);
				} else {
					$backup = Hardening_Backup::backup_file( $user_ini );
					if ( empty( $backup['ok'] ) ) {
						return array(
							'ok'      => false,
							'message' => Hardening_Messages::get( 'backup_failed', $feature_id, array( 'target' => $user_ini ) ),
						);
					}
					$new = self::replace_marked_block( $cur, '; BEGIN Inyfinn Cursor Bridge: php-limits', '; END Inyfinn Cursor Bridge: php-limits', $ini_body );
					file_put_contents( $user_ini, $new, LOCK_EX );
					$results['user_ini'] = array(
						'ok'      => true,
						'message' => Hardening_Messages::get( 'installed_userini', $feature_id, array( 'backup' => $backup['path'] ) ),
					);
				}
			} else {
				$backup = Hardening_Backup::backup_file( $user_ini );
				if ( empty( $backup['ok'] ) ) {
					return array(
						'ok'      => false,
						'message' => Hardening_Messages::get( 'backup_failed', $feature_id, array( 'target' => $user_ini ) ),
					);
				}
				$new = rtrim( $cur ) . "\n\n" . $ini_body;
				file_put_contents( $user_ini, $new, LOCK_EX );
				$results['user_ini'] = array(
					'ok'      => true,
					'message' => Hardening_Messages::get( 'installed_userini', $feature_id, array( 'backup' => $backup['path'] ) ),
				);
			}
		} else {
			$bak_dir = Hardening_Backup::ensure_backup_dir();
			if ( empty( $bak_dir['ok'] ) ) {
				return array(
					'ok'      => false,
					'message' => Hardening_Messages::get( 'backup_failed', $feature_id, array( 'target' => $user_ini ) ),
				);
			}
			file_put_contents( $user_ini, $ini_body, LOCK_EX );
			$results['user_ini'] = array(
				'ok'      => true,
				'message' => Hardening_Messages::get( 'installed_userini', $feature_id, array( 'backup' => '(new file)' ) ),
			);
		}

		$htaccess = ABSPATH . '.htaccess';
		$ht_block = self::php_limits_htaccess_block();
		if ( file_exists( $htaccess ) && is_writable( $htaccess ) ) {
			$ht = file_get_contents( $htaccess );
			if ( ! is_string( $ht ) ) {
				$ht = '';
			}
			if ( false !== strpos( $ht, 'BEGIN Inyfinn Cursor Bridge: php-limits' ) ) {
				if ( $replace ) {
					$backup = Hardening_Backup::backup_file( $htaccess );
					if ( ! empty( $backup['ok'] ) ) {
						$new = self::replace_marked_block( $ht, '# BEGIN Inyfinn Cursor Bridge: php-limits', '# END Inyfinn Cursor Bridge: php-limits', $ht_block );
						file_put_contents( $htaccess, $new, LOCK_EX );
						$results['htaccess'] = array(
							'ok'      => true,
							'message' => Hardening_Messages::get( 'installed_htaccess', $feature_id, array( 'backup' => $backup['path'] ) ),
						);
					}
				} else {
					$results['htaccess'] = array(
						'ok'      => false,
						'skipped' => true,
						'message' => Hardening_Messages::get( 'already_exists', $feature_id ),
					);
				}
			} else {
				$backup = Hardening_Backup::backup_file( $htaccess );
				if ( ! empty( $backup['ok'] ) ) {
					file_put_contents( $htaccess, rtrim( $ht ) . "\n" . $ht_block, LOCK_EX );
					$results['htaccess'] = array(
						'ok'      => true,
						'message' => Hardening_Messages::get( 'installed_htaccess', $feature_id, array( 'backup' => $backup['path'] ) ),
					);
				}
			}
		} else {
			$results['htaccess'] = array(
				'ok'      => true,
				'skipped' => true,
				'note'    => 'No writable .htaccess (Nginx?) — .user.ini used instead',
			);
		}

		$ok = false;
		foreach ( $results as $r ) {
			if ( ! empty( $r['ok'] ) && empty( $r['skipped'] ) ) {
				$ok = true;
			}
		}

		return array(
			'ok'      => $ok || ! empty( $results['user_ini']['ok'] ),
			'results' => $results,
			'message' => Hardening_Messages::get( 'installed_userini', $feature_id, array( 'backup' => Hardening_Backup::backup_root_relative() ) ),
		);
	}

	private static function php_limits_ini_body(): string {
		return "; BEGIN Inyfinn Cursor Bridge: php-limits\n"
			. "upload_max_filesize = 8000M\n"
			. "post_max_size = 8000M\n"
			. "memory_limit = 8000M\n"
			. "max_execution_time = 0\n"
			. "max_input_time = 0\n"
			. "; END Inyfinn Cursor Bridge: php-limits\n";
	}

	private static function php_limits_htaccess_block(): string {
		return "\n# BEGIN Inyfinn Cursor Bridge: php-limits\n"
			. "<IfModule mod_php.c>\n"
			. "php_value upload_max_filesize 8000M\n"
			. "php_value post_max_size 8000M\n"
			. "php_value memory_limit 8000M\n"
			. "php_value max_execution_time 0\n"
			. "php_value max_input_time 0\n"
			. "</IfModule>\n"
			. "# END Inyfinn Cursor Bridge: php-limits\n";
	}

	private static function replace_marked_block( string $content, string $begin, string $end, string $new_block ): string {
		$pattern = '/' . preg_quote( $begin, '/' ) . '.*?' . preg_quote( $end, '/' ) . '\s*/s';
		if ( preg_match( $pattern, $content ) ) {
			$replaced = preg_replace( $pattern, $new_block, $content, 1 );
			return is_string( $replaced ) ? $replaced : $content;
		}
		return rtrim( $content ) . "\n\n" . $new_block;
	}

	private static function strip_wp_config_blocks( string $raw ): string {
		$patterns = array(
			'/\n*\/\/ BEGIN Inyfinn Cursor Bridge: wp-config-safe.*?\/\/ END Inyfinn Cursor Bridge: wp-config-safe\n*/s',
			'/\n*\/\/ BEGIN Inyfinn Cursor Bridge: wp-config.*?\/\/ END Inyfinn Cursor Bridge: wp-config\n*/s',
		);
		foreach ( $patterns as $pattern ) {
			$replaced = preg_replace( $pattern, "\n", $raw, 1 );
			if ( is_string( $replaced ) ) {
				$raw = $replaced;
			}
		}
		return $raw;
	}

	private static function strip_functions_marker_block( string $content, string $marker ): string {
		$pattern = '/\n?\/\*\*\n \* ' . preg_quote( self::MARKER_PREFIX, '/' ) . ' ' . preg_quote( $marker, '/' )
			. '.*?' . preg_quote( '// ' . self::MARKER_END . ' ' . $marker, '/' ) . '\n?/s';
		$replaced = preg_replace( $pattern, '', $content, 1 );
		return is_string( $replaced ) ? $replaced : $content;
	}

	/**
	 * Detect our install or similar third-party code.
	 *
	 * @return array{ours:bool,similar:bool,location?:string,matched_signatures?:list<string>}
	 */
	public static function detect( string $feature_id ): array {
		$features = self::features();
		if ( ! isset( $features[ $feature_id ] ) ) {
			return array( 'ours' => false, 'similar' => false );
		}
		$meta = $features[ $feature_id ];
		$haystacks = array();

		$mu = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		if ( ! empty( $meta['mu_filename'] ) ) {
			$path = trailingslashit( $mu ) . $meta['mu_filename'];
			if ( is_readable( $path ) ) {
				$haystacks[ $path ] = (string) file_get_contents( $path );
			}
		}

		// Scan all mu-plugins for signatures.
		if ( is_dir( $mu ) ) {
			foreach ( glob( trailingslashit( $mu ) . '*.php' ) ?: array() as $file ) {
				if ( ! isset( $haystacks[ $file ] ) && is_readable( $file ) ) {
					$haystacks[ $file ] = (string) file_get_contents( $file );
				}
			}
		}

		$functions = get_stylesheet_directory() . '/functions.php';
		if ( is_readable( $functions ) ) {
			$haystacks[ $functions ] = (string) file_get_contents( $functions );
		}

		if ( 'wp-config' === $feature_id ) {
			$config = ABSPATH . 'wp-config.php';
			if ( ! is_readable( $config ) ) {
				$parent = dirname( ABSPATH ) . '/wp-config.php';
				$config = is_readable( $parent ) ? $parent : $config;
			}
			if ( is_readable( $config ) ) {
				$haystacks[ $config ] = (string) file_get_contents( $config );
			}
		}

		if ( 'php-limits' === $feature_id ) {
			foreach ( array( ABSPATH . '.user.ini', ABSPATH . '.htaccess' ) as $f ) {
				if ( is_readable( $f ) ) {
					$haystacks[ $f ] = (string) file_get_contents( $f );
				}
			}
		}

		$marker = self::MARKER_PREFIX . ' ' . $meta['marker'];
		$ours   = false;
		$similar = false;
		$matched = array();
		$location = null;

		foreach ( $haystacks as $path => $content ) {
			if ( false !== strpos( $content, $marker ) ) {
				$ours     = true;
				$location = $path;
				break;
			}
			if ( 'wp-config' === $feature_id
				&& ( false !== strpos( $content, 'BEGIN Inyfinn Cursor Bridge: wp-config-safe' )
					|| false !== strpos( $content, 'BEGIN Inyfinn Cursor Bridge: wp-config' ) ) ) {
				$ours     = true;
				$location = $path;
				break;
			}
		}

		if ( ! $ours ) {
			$sigs = $meta['signatures'] ?? array();
			foreach ( $haystacks as $path => $content ) {
				foreach ( $sigs as $sig ) {
					// Skip our own marker signatures when looking for similar.
					if ( 0 === strpos( $sig, 'BEGIN Inyfinn' ) ) {
						continue;
					}
					if ( false !== strpos( $content, $sig ) ) {
						// For unique-uploads, wp_unique_filename alone is too generic — require our stamp pattern or multiple hits.
						if ( 'unique-uploads' === $feature_id && 'wp_unique_filename' === $sig ) {
							continue;
						}
						if ( 'wp-config' === $feature_id && in_array( $sig, array( 'FORCE_SSL_ADMIN', 'HTTP_X_FORWARDED_PROTO' ), true ) ) {
							// Common — only treat as similar if our marker missing AND both present without our block.
							continue;
						}
						if ( 'php-limits' === $feature_id && 'upload_max_filesize' === $sig ) {
							continue;
						}
						if ( 'svg-media' === $feature_id && 'image/svg+xml' === $sig ) {
							// Too common; require stronger signals.
							continue;
						}
						$matched[] = $sig;
						$similar   = true;
						$location  = $path;
					}
				}
			}
			$matched = array_values( array_unique( $matched ) );
			// Require stronger evidence for svg: nexta or mimes['svg']
			if ( 'svg-media' === $feature_id && $similar ) {
				$strong = array_intersect( $matched, array( 'inyfinn_cb_svg_metadata', 'nexta_svg_metadata', "mimes['svg']" ) );
				$similar = ! empty( $strong );
				$matched = array_values( $strong );
			}
		}

		return array(
			'ours'                => $ours,
			'similar'             => $similar && ! $ours,
			'location'            => $location,
			'matched_signatures'  => $matched,
		);
	}

	private static function extract_snippet_body( string $mu_content, string $marker ): string {
		$begin = self::MARKER_PREFIX . ' ' . $marker;
		$end   = self::MARKER_END . ' ' . $marker;
		$bpos  = strpos( $mu_content, $begin );
		$epos  = strpos( $mu_content, $end );
		if ( false === $bpos || false === $epos || $epos <= $bpos ) {
			// Fallback: strip header until first defined(ABSPATH).
			$pos = strpos( $mu_content, "defined( 'ABSPATH' )" );
			return false === $pos ? '' : substr( $mu_content, $pos );
		}
		// Include from after plugin header comment through END.
		$start = strpos( $mu_content, "defined( 'ABSPATH' )" );
		if ( false === $start ) {
			$start = $bpos;
		}
		return substr( $mu_content, $start, $epos + strlen( $end ) - $start );
	}

	/**
	 * Lightweight PHP lint. Prefer token_get_all (shared hosting safe).
	 * php -l only when CLI binary is clearly available.
	 *
	 * @return array{ok:bool,error?:string}
	 */
	public static function lint_php_file( string $path ): array {
		$code = file_get_contents( $path );
		if ( false === $code ) {
			return array( 'ok' => false, 'error' => 'unreadable' );
		}

		if ( '' === trim( $code ) ) {
			return array( 'ok' => false, 'error' => 'empty file' );
		}

		// Must look like PHP.
		if ( false === strpos( $code, '<?php' ) && false === strpos( $code, '<?=' ) ) {
			return array( 'ok' => false, 'error' => 'missing PHP open tag' );
		}

		try {
			$tokens = @token_get_all( $code );
			if ( ! is_array( $tokens ) || count( $tokens ) < 2 ) {
				return array( 'ok' => false, 'error' => 'token_get_all failed' );
			}
		} catch ( \ParseError $e ) {
			return array( 'ok' => false, 'error' => $e->getMessage() );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'error' => $e->getMessage() );
		}

		// Optional CLI lint — skip php-fpm / CGI binaries that cannot -l.
		$php = self::find_php_cli_binary();
		if ( $php ) {
			$cmd       = escapeshellarg( $php ) . ' -l ' . escapeshellarg( $path ) . ' 2>&1';
			$out       = array();
			$code_exit = 0;
			@exec( $cmd, $out, $code_exit );
			$joined = implode( "\n", $out );
			if ( 0 === $code_exit && false !== stripos( $joined, 'No syntax errors' ) ) {
				return array( 'ok' => true );
			}
			if ( 0 !== $code_exit && '' !== trim( $joined ) ) {
				return array( 'ok' => false, 'error' => $joined );
			}
			// Empty / inconclusive CLI result → trust token_get_all.
		}

		return array( 'ok' => true );
	}

	private static function find_php_cli_binary(): ?string {
		if ( ! function_exists( 'exec' ) ) {
			return null;
		}
		if ( defined( 'PHP_BINARY' ) && PHP_BINARY ) {
			$bin = (string) PHP_BINARY;
			// php-fpm / php-cgi cannot reliably run -l.
			if ( false !== stripos( $bin, 'fpm' ) || false !== stripos( $bin, 'cgi' ) ) {
				return null;
			}
			if ( is_executable( $bin ) ) {
				return $bin;
			}
		}
		return null;
	}
}
