<?php
/**
 * Plugin Name: Inyfinn SVG + Media Library
 * Description: Safe SVG/SVGZ uploads, metadata, media previews, infinite scroll. Managed by Inyfinn Cursor Bridge MCP.
 * Version: 1.0.0
 *
 * BEGIN Inyfinn Cursor Bridge: svg-media
 */

defined( 'ABSPATH' ) || exit;

/**
 * Allow SVG / SVGZ for users who can upload files.
 * Note: SVG can contain scripts — only trusted admins should upload.
 */
add_filter(
	'upload_mimes',
	static function ( $mimes ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $mimes;
		}
		$mimes['svg']  = 'image/svg+xml';
		$mimes['svgz'] = 'image/svg+xml';
		return $mimes;
	}
);

/**
 * Fix filetype/ext validation for SVG.
 */
add_filter(
	'wp_check_filetype_and_ext',
	static function ( $data, $file, $filename, $mimes, $real_mime = null ) {
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( 'svg' === $ext || 'svgz' === $ext ) {
			$data['ext']  = $ext;
			$data['type'] = 'image/svg+xml';
		}
		return $data;
	},
	10,
	5
);

/**
 * Generate width/height metadata for SVG attachments.
 *
 * @param array $metadata
 * @param int   $attachment_id
 * @return array
 */
function inyfinn_cb_svg_metadata( $metadata, $attachment_id ) {
	if ( 'image/svg+xml' !== get_post_mime_type( $attachment_id ) ) {
		return $metadata;
	}

	$file_path = get_attached_file( $attachment_id );
	if ( ! $file_path || ! file_exists( $file_path ) ) {
		return $metadata;
	}

	$width  = 100;
	$height = 100;
	$raw    = file_get_contents( $file_path );

	if ( is_string( $raw ) && '' !== $raw && class_exists( 'SimpleXMLElement' ) ) {
		libxml_use_internal_errors( true );
		try {
			$svg  = new SimpleXMLElement( $raw );
			$attr = $svg->attributes();

			$svg_w = isset( $attr->width ) ? preg_replace( '/[^0-9.]/', '', (string) $attr->width ) : '';
			$svg_h = isset( $attr->height ) ? preg_replace( '/[^0-9.]/', '', (string) $attr->height ) : '';

			if ( $svg_w && $svg_h ) {
				$width  = (int) round( (float) $svg_w );
				$height = (int) round( (float) $svg_h );
			} elseif ( isset( $attr->viewBox ) ) {
				$parts = preg_split( '/[\s,]+/', trim( (string) $attr->viewBox ) );
				if ( is_array( $parts ) && 4 === count( $parts ) ) {
					$width  = (int) round( (float) $parts[2] );
					$height = (int) round( (float) $parts[3] );
				}
			}
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			// Keep defaults.
		}
		libxml_clear_errors();
	}

	$metadata['width']  = max( 1, $width );
	$metadata['height'] = max( 1, $height );
	$metadata['file']   = _wp_relative_upload_path( $file_path );
	unset( $metadata['sizes'] );

	return $metadata;
}
add_filter( 'wp_generate_attachment_metadata', 'inyfinn_cb_svg_metadata', 10, 2 );

/**
 * Media library JS response for SVG.
 */
add_filter(
	'wp_prepare_attachment_for_js',
	static function ( $response, $attachment, $meta ) {
		if ( empty( $response['mime'] ) || 'image/svg+xml' !== $response['mime'] ) {
			return $response;
		}

		$width  = ! empty( $meta['width'] ) ? (int) $meta['width'] : 100;
		$height = ! empty( $meta['height'] ) ? (int) $meta['height'] : 100;

		$response['type']    = 'image';
		$response['subtype'] = 'svg+xml';
		$response['width']   = $width;
		$response['height']  = $height;
		$response['sizes']   = array(
			'full' => array(
				'url'         => $response['url'],
				'width'       => $width,
				'height'      => $height,
				'orientation' => ( $width > $height ) ? 'landscape' : ( ( $height > $width ) ? 'portrait' : 'square' ),
			),
		);

		return $response;
	},
	10,
	3
);

/**
 * Admin CSS for SVG thumbnails.
 */
add_action(
	'admin_head',
	static function () {
		echo '<style id="inyfinn-cb-svg-admin">
.attachment .thumbnail,
.media-frame .attachment-preview,
.attachments-browser .attachment .thumbnail {
	display:flex;align-items:center;justify-content:center;background:#fff;
}
.attachment .thumbnail img,
.media-frame .attachment-preview img,
.attachments-browser .attachment .thumbnail img,
td.media-icon img[src$=".svg"],
img[src$=".svg"].attachment-post-thumbnail {
	width:100%!important;height:auto!important;object-fit:contain!important;
}
</style>';
	}
);

/**
 * Infinite scroll + larger media batch (single registration — no duplicates).
 */
add_filter( 'media_library_infinite_scrolling', '__return_true' );
add_filter(
	'ajax_query_attachments_args',
	static function ( $query ) {
		$query['posts_per_page'] = 80;
		return $query;
	}
);

// END Inyfinn Cursor Bridge: svg-media
