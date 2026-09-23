<?php
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user( 1 );
$r = activate_plugin( 'inyfinn-cursor-bridge-mcp/inyfinn-cursor-bridge-mcp.php' );
file_put_contents( '/out/activate.txt', is_wp_error( $r ) ? $r->get_error_message() : 'activated; mem=' . memory_get_peak_usage() );
