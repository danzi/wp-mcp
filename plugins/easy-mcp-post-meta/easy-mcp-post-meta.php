<?php
/**
 * Plugin Name:       Easy MCP Post Extra Fields
 * Plugin URI:        https://notatabooo.com
 * Description:       Source URLs, social titles, and social post URLs (facebook, telegram, …) on posts. MCP: get/set fields, find by sources, find by social publish status.
 * Version:           1.3.12
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Site Owner
 * License:           GPL-2.0-or-later
 * Text Domain:       easy-mcp-post-meta
 */

declare(strict_types=1);

namespace EasyMCP\PostMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EMPM_VERSION', '1.3.12' );
define( 'EMPM_PLUGIN_FILE', __FILE__ );
define( 'EMPM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EMPM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EMPM_PLUGIN_DIR . 'src/Infrastructure/Autoloader.php';
Autoloader::register();

add_action( 'plugins_loaded', static function (): void {
	$container = new Infrastructure\Container();
	$container->boot();
} );

add_filter( 'rest_post_dispatch', static function ( $response, $server, $request ) {
	if ( ! $request instanceof \WP_REST_Request ) {
		return $response;
	}
	if ( strpos( (string) $request->get_route(), '/easy-mcp-post-meta/' ) === false ) {
		return $response;
	}
	if ( $response instanceof \WP_REST_Response ) {
		$response->header( 'X-EMPM-Version', EMPM_VERSION );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );
		$data = $response->get_data();
		if ( is_array( $data ) && empty( $data['plugin_version'] ) ) {
			$data['plugin_version'] = EMPM_VERSION;
			$response->set_data( $data );
		}
	}
	return $response;
}, 99, 3 );
