<?php
/**
 * Plugin Name: Inyfinn Unique Upload Filenames
 * Description: Appends Ymd-His to upload names when a file with the same name already exists (cache-bust). Managed by Inyfinn Cursor Bridge MCP.
 * Version: 1.0.0
 *
 * BEGIN Inyfinn Cursor Bridge: unique-uploads
 */

defined( 'ABSPATH' ) || exit;

/**
 * When the proposed filename already exists in the upload dir, append UTC timestamp
 * so re-uploads of the same name always produce a distinct file (avoids stale CDN/browser cache).
 *
 * WordPress already adds -1, -2; we prefer a date stamp for clarity and cache-busting.
 *
 * @param string $filename Suggested filename (with extension).
 * @param string $ext      Extension with leading dot.
 * @param string $dir      Target directory.
 * @return string
 */
add_filter(
	'wp_unique_filename',
	static function ( $filename, $ext, $dir ) {
		$dir      = trailingslashit( $dir );
		$original = $filename;

		// If original name (without -N) is free, keep WP default behaviour for first upload.
		$name_only = pathinfo( $filename, PATHINFO_FILENAME );
		// Strip trailing -1, -2 … that WP may have already added.
		$base = preg_replace( '/-\d+$/', '', $name_only );
		$ext  = $ext ? $ext : ( '.' . pathinfo( $filename, PATHINFO_EXTENSION ) );

		$first_candidate = $base . $ext;
		if ( ! file_exists( $dir . $first_candidate ) ) {
			return $first_candidate;
		}

		$stamp    = gmdate( 'Ymd-His' );
		$candidate = $base . '-' . $stamp . $ext;
		$i         = 0;
		while ( file_exists( $dir . $candidate ) && $i < 50 ) {
			++$i;
			$candidate = $base . '-' . $stamp . '-' . $i . $ext;
		}

		return $candidate ? $candidate : $original;
	},
	10,
	3
);

// END Inyfinn Cursor Bridge: unique-uploads
