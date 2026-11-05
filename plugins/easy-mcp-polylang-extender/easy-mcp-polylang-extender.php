<?php
/**
 * Plugin Name:       Easy MCP Polylang Ability Extender
 * Plugin URI:        https://notatabooo.com
 * Description:       Extends Easy MCP AI with Polylang abilities (set/get language, list languages, link translations, create post with language). Uses WordPress Abilities API so tools appear automatically in MCP.
 * Version:           1.2.9
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Site Owner
 * License:           GPL-2.0-or-later
 * Text Domain:       easy-mcp-polylang-extender
 * Domain Path:       /languages
 */

declare(strict_types=1);

namespace EasyMCP\PolylangExtender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EMPL_VERSION', '1.2.9' );
define( 'EMPL_PLUGIN_FILE', __FILE__ );
define( 'EMPL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EMPL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EMPL_PLUGIN_DIR . 'src/Infrastructure/Autoloader.php';
Autoloader::register();

add_action( 'plugins_loaded', static function (): void {
	if ( ! function_exists( 'pll_set_post_language' ) ) {
		add_action( 'admin_notices', static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Easy MCP Polylang Ability Extender requires Polylang (or Polylang Pro) to be active.', 'easy-mcp-polylang-extender' );
			echo '</p></div>';
		} );
		return;
	}

	$container = new Infrastructure\Container();
	$container->boot();
} );
