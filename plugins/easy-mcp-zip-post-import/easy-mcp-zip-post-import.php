<?php
/**
 * Plugin Name:       Easy MCP ZIP Post Import
 * Plugin URI:        https://notatabooo.com
 * Description:       Inserts or updates a WordPress post from a ZIP package (post.json + images). Optional Polylang language and Yoast SEO. Exposed as MCP abilities.
 * Version:           1.2.8
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Site Owner
 * License:           GPL-2.0-or-later
 * Text Domain:       easy-mcp-zip-post-import
 * Domain Path:       /languages
 */

declare(strict_types=1);

namespace EasyMCP\ZipPostImport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EMPZI_VERSION', '1.2.8' );
define( 'EMPZI_PLUGIN_FILE', __FILE__ );
define( 'EMPZI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EMPZI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EMPZI_PLUGIN_DIR . 'src/Infrastructure/Autoloader.php';
Autoloader::register();

add_action( 'plugins_loaded', static function (): void {
	$container = new Infrastructure\Container();
	$container->boot();
} );
