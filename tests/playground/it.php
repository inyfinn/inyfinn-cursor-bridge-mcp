<?php
// Integration check of bridge abilities inside WordPress Playground (see tests/playground/README.md).
// Writes /out/result.json; every key is one scenario from a real task.
require '/wordpress/wp-load.php';
wp_set_current_user( 1 );
$out = array( 'wp' => get_bloginfo( 'version' ), 'elementor' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null, 'bridge' => defined( 'INYFINN_CURSOR_BRIDGE_MCP_VERSION' ) ? INYFINN_CURSOR_BRIDGE_MCP_VERSION : null );

$run = static function ( string $name, array $input = array() ) {
	$ab = wp_get_ability( $name );
	if ( ! $ab ) {
		return array( 'missing_ability' => $name );
	}
	$r = $ab->execute( $input ?: null );
	return is_wp_error( $r ) ? array( 'wp_error' => $r->get_error_code() . ': ' . $r->get_error_message() ) : $r;
};

$card = static fn( string $id, string $city ) => array( 'id' => $id, 'elType' => 'container', 'settings' => array( '_title' => $city ), 'elements' => array( array( 'id' => $id . 'h', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => $city ), 'elements' => array() ) ) );
$data = array(
	array( 'id' => 'a000001', 'elType' => 'container', 'settings' => array( 'background_overlay_image' => array( 'id' => 1, 'url' => 'x' ) ), 'elements' => array( $card( 'c000001', 'SUWALKI' ) ) ),
	array( 'id' => 'r000005', 'elType' => 'container', 'settings' => array( '_title' => 'TABLICA 5' ), 'elements' => array( $card( 'c000051', 'PLOCK' ), $card( 'c000052', 'RZESZOW' ), $card( 'c000053', 'PRUSZKOW' ), $card( 'c000054', 'BIELSKO' ), $card( 'c000055', 'SUWALKI' ) ) ),
);
$src = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'SUWALKI', 'post_name' => 'suwalki' ) );
update_post_meta( $src, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
update_post_meta( $src, '_elementor_edit_mode', 'builder' );
update_post_meta( $src, '_wp_page_template', 'elementor_header_footer' );
update_post_meta( $src, '_elementor_page_settings', array( 'hide_title' => 'yes' ) );
$out['src'] = $src;

$out['abilities_bridge'] = count( array_filter( array_keys( wp_get_abilities() ), static fn( $n ) => 0 === strpos( (string) $n, 'cursor-bridge/' ) ) );

$dup = $run( 'cursor-bridge/elementor-duplicate-post', array( 'post_id' => $src, 'title' => 'WROCLAW', 'slug' => 'wroclaw', 'status' => 'publish', 'patches' => array( 'c000001h' => array( 'title' => 'WROCLAW' ) ) ) );
$out['duplicate'] = array_intersect_key( (array) $dup, array_flip( array( 'ok', 'post_id', 'slug', 'slug_changed', 'bytes', 'source_bytes', 'error', 'wp_error', 'missing' ) ) );
if ( ! empty( $dup['post_id'] ) ) {
	$new = json_decode( (string) get_post_meta( $dup['post_id'], '_elementor_data', true ), true );
	$out['duplicate']['title_patched'] = 'WROCLAW' === $new[0]['elements'][0]['elements'][0]['settings']['title'];
	$out['duplicate']['ids_changed']   = 'a000001' !== $new[0]['id'];
	$out['duplicate']['meta_copied']   = get_post_meta( $dup['post_id'], '_elementor_page_settings', true ) === array( 'hide_title' => 'yes' ) && 'elementor_header_footer' === get_post_meta( $dup['post_id'], '_wp_page_template', true );
	$out['duplicate']['source_untouched'] = get_post_meta( $src, '_elementor_data', true ) === wp_json_encode( $data );
}
$dup2 = $run( 'cursor-bridge/elementor-duplicate-post', array( 'post_id' => $src, 'title' => 'X', 'patches' => array( 'nope' => array( 'a' => 1 ) ) ) );
$out['duplicate_bad_patch'] = array( 'ok' => $dup2['ok'] ?? null, 'error' => $dup2['error'] ?? null, 'pages_after' => count( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1 ) ) ) );

$cl = $run( 'cursor-bridge/elementor-clone-element', array( 'post_id' => $src, 'element_id' => 'r000005', 'patches' => array( 'r000005' => array( '_title' => 'TABLICA 6' ), 'c000054' => array( 'hide_desktop' => 'hidden-desktop' ) ) ) );
$after = json_decode( (string) get_post_meta( $src, '_elementor_data', true ), true );
$out['clone'] = array( 'ok' => $cl['ok'] ?? null, 'error' => $cl['error'] ?? ( $cl['wp_error'] ?? null ), 'map' => count( (array) ( $cl['id_map'] ?? array() ) ), 'top_count' => count( $after ), 'third_title' => $after[2]['settings']['_title'] ?? null, 'hidden' => $after[2]['elements'][3]['settings']['hide_desktop'] ?? null, 'backup_key' => $cl['backup_key'] ?? null );
$cl2 = $run( 'cursor-bridge/elementor-clone-element', array( 'post_id' => $dup['post_id'] ?? 0, 'element_id' => 'c000055', 'source_post_id' => $src, 'parent_id' => '', 'position' => 0 ) );
$after2 = json_decode( (string) get_post_meta( $dup['post_id'] ?? 0, '_elementor_data', true ), true );
$out['clone_cross_post'] = array( 'ok' => $cl2['ok'] ?? null, 'error' => $cl2['error'] ?? ( $cl2['wp_error'] ?? null ), 'first_title' => $after2[0]['settings']['_title'] ?? null );

update_post_meta( $src, 'map_info', array( 'roundMarkers' => array( array( 'id' => 'Rzeszów', 'coordinates' => array( 'latitude' => '50.1' ) ) ), 'regions' => array() ) );
$g  = $run( 'cursor-bridge/get-post-meta', array( 'post_id' => $src, 'key' => 'map_info' ) );
$mi = $g['value'];
$mi['roundMarkers'][] = array( 'id' => 'Wrocław', 'coordinates' => array( 'latitude' => '51.1' ), 'content' => 'https://x.pl/wroclaw/' );
$s = $run( 'cursor-bridge/set-post-meta', array( 'post_id' => $src, 'key' => 'map_info', 'value' => $mi ) );
$stored = get_post_meta( $src, 'map_info', true );
$out['meta'] = array( 'get_type' => $g['type'] ?? null, 'set_ok' => $s['ok'] ?? null, 'markers' => count( $stored['roundMarkers'] ?? array() ), 'utf8' => 'Wrocław' === ( $stored['roundMarkers'][1]['id'] ?? '' ), 'backup' => $s['backup_key'] ?? null, 'refuses_elementor' => $run( 'cursor-bridge/set-post-meta', array( 'post_id' => $src, 'key' => '_elementor_data', 'value' => 'x' ) )['error'] ?? null );

$out['db_probe'] = $run( 'cursor-bridge/db-write-probe' );

$php = "<?php\n// probe \$_GET file_put_contents\n";
$w   = $run( 'cursor-bridge/write-wp-content-file', array( 'path' => 'mu-plugins/zz-probe.php', 'content_base64' => base64_encode( $php ), 'create_dirs' => true ) );
$out['write_b64'] = array( 'ok' => file_get_contents( WP_CONTENT_DIR . '/mu-plugins/zz-probe.php' ) === $php, 'raw' => $w['error'] ?? null );
$out['write_bad_b64'] = $run( 'cursor-bridge/write-wp-content-file', array( 'path' => 'x.txt', 'content_base64' => '%%%' ) );
$d = $run( 'cursor-bridge/delete-wp-content-file', array( 'path' => 'mu-plugins/zz-probe.php' ) );
$out['delete'] = array( 'ok' => $d['ok'] ?? null, 'to' => $d['to'] ?? null, 'gone' => ! file_exists( WP_CONTENT_DIR . '/mu-plugins/zz-probe.php' ), 'in_trash' => isset( $d['to'] ) && file_exists( WP_CONTENT_DIR . '/' . $d['to'] ) );
$out['delete_blocked_setup'] = $run( 'cursor-bridge/delete-wp-content-file', array( 'path' => 'inyfinn-cursor-bridge/cursor-setup.json' ) )['error'] ?? null;
$out['read_trash_blocked'] = $run( 'cursor-bridge/read-wp-content-file', array( 'path' => $d['to'] ?? 'x' ) );

// Setup file lifecycle.
\Inyfinn_Cursor_Bridge\Installer::write_setup_file();
$out['setup_present_before_ping'] = is_readable( \Inyfinn_Cursor_Bridge\Installer::setup_file_path() );
$run( 'cursor-bridge/ping' );
$hc = $run( 'cursor-bridge/health-check' );
foreach ( (array) ( $hc['checks'] ?? array() ) as $c ) {
	if ( 'setup_file' === $c['id'] ) {
		$out['health_setup_after_ping'] = $c['status'] . ' / ' . $c['repair_action'];
	}
}
$rp = $run( 'cursor-bridge/repair', array( 'action' => 'remove_setup_file' ) );
$out['repair_remove'] = array( 'ok' => $rp['ok'] ?? ( $rp['result']['ok'] ?? null ), 'gone' => ! file_exists( \Inyfinn_Cursor_Bridge\Installer::setup_file_path() ) );
$hc = $run( 'cursor-bridge/health-check' );
foreach ( (array) ( $hc['checks'] ?? array() ) as $c ) {
	if ( 'setup_file' === $c['id'] ) {
		$out['health_setup_after_remove'] = $c['status'];
	}
}

$media = $run( 'cursor-bridge/media-sideload', array( 'urls' => array( 'https://s.w.org/images/core/emoji/15.0.3/72x72/1f600.png' ), 'name' => 'PROBE' ) );
$out['media'] = array( 'ok' => $media['ok'] ?? null, 'count' => count( (array) ( $media['media'] ?? array() ) ), 'errors' => $media['errors'] ?? ( $media['wp_error'] ?? null ) );


// Discovery: home page button with dynamic internal-url tag to $src, map meta with its URL, menu item.
$home = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home' ) );
$tag  = '[elementor-tag id="aa11bb2" name="internal-url" settings="' . rawurlencode( wp_json_encode( array( 'type' => 'post', 'post_id' => (string) $src ) ) ) . '"]';
update_post_meta( $home, '_elementor_data', wp_slash( wp_json_encode( array( array( 'id' => 'hrow005', 'elType' => 'container', 'settings' => array( '_title' => 'TABLICA 5' ), 'elements' => array( array( 'id' => 'hcard05', 'elType' => 'container', 'settings' => array( '_title' => 'SUWALKI' ), 'elements' => array( array( 'id' => 'hbtn005', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array( 'text' => 'Zobacz', '__dynamic__' => array( 'link' => $tag ) ), 'elements' => array() ) ) ) ) ) ) ) ) );
$map = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Mapa' ) );
update_post_meta( $map, 'map_info', array( 'roundMarkers' => array( array( 'id' => 'Suwalki', 'content' => get_permalink( $src ) ) ) ) );
$menu = wp_create_nav_menu( 'Sklepy' );
wp_update_nav_menu_item( $menu, 0, array( 'menu-item-object-id' => $src, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
$fr = $run( 'cursor-bridge/find-references', array( 'post_id' => $src ) );
$out['find_references'] = array(
	'ok'        => $fr['ok'] ?? null,
	'error'     => $fr['error'] ?? ( $fr['wp_error'] ?? null ),
	'elementor' => array_map( static fn( $e ) => array( $e['post_id'], array_map( static fn( $h ) => $h['element_id'] . ' via ' . $h['via'] . ' in ' . implode( ' > ', $h['parents'] ), $e['elements'] ) ), (array) ( $fr['elementor'] ?? array() ) ),
	'meta'      => array_map( static fn( $m ) => $m['post_id'] . ':' . $m['meta_key'], (array) ( $fr['post_meta'] ?? array() ) ),
	'menus'     => $fr['menus'] ?? null,
	'expect'    => array( 'home' => $home, 'map' => $map ),
);
$fs = $run( 'cursor-bridge/find-similar-pages', array( 'post_id' => $src ) );
$out['find_similar'] = array( 'ok' => $fs['ok'] ?? null, 'error' => $fs['error'] ?? ( $fs['wp_error'] ?? null ), 'hits' => array_map( static fn( $x ) => $x['post_id'] . ' ' . $x['title'] . ' ' . $x['similarity'] . '%', (array) ( $fs['similar'] ?? array() ) ), 'expect_dup' => $dup['post_id'] ?? null, 'home_excluded' => ! in_array( $home, array_column( (array) ( $fs['similar'] ?? array() ), 'post_id' ), true ) );

$fresh = $run( 'cursor-bridge/elementor-duplicate-post', array( 'post_id' => $dup['post_id'], 'title' => 'BYDGOSZCZ', 'status' => 'publish' ) );
$fs2   = $run( 'cursor-bridge/find-similar-pages', array( 'post_id' => $fresh['post_id'] ?? 0 ) );
$out['find_similar_fresh'] = array( 'hits' => array_map( static fn( $x ) => $x['post_id'] . ' ' . $x['title'] . ' ' . $x['similarity'] . '%', (array) ( $fs2['similar'] ?? array() ) ), 'expect' => $dup['post_id'] ?? null );

$out['guard_htaccess_delete'] = $run( 'cursor-bridge/delete-wp-content-file', array( 'path' => 'inyfinn-cursor-bridge/.htaccess' ) )['error'] ?? 'NOT BLOCKED';
$out['guard_htaccess_write']  = $run( 'cursor-bridge/write-wp-content-file', array( 'path' => 'inyfinn-cursor-bridge/.htaccess', 'content' => '' ) )['error'] ?? 'NOT BLOCKED';
$out['guard_htaccess_intact'] = false !== strpos( (string) @file_get_contents( WP_CONTENT_DIR . '/inyfinn-cursor-bridge/.htaccess' ), 'Deny' );

file_put_contents( '/out/result.json', wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
echo "done\n";
