<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport;

final class Autoloader {

	public static function register(): void {
		spl_autoload_register( static function ( string $class ): void {
			$prefix = 'EasyMCP\\ZipPostImport\\';
			if ( strpos( $class, $prefix ) !== 0 ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$file     = EMPZI_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		} );
	}
}
