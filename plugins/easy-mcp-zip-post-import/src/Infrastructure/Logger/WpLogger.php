<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Infrastructure\Logger;

use EasyMCP\ZipPostImport\Domain\Logger\LoggerInterface;

final class WpLogger implements LoggerInterface {

	public function is_debug_enabled(): bool {
		if ( defined( 'EMPZI_DEBUG' ) ) {
			return (bool) EMPZI_DEBUG;
		}

		return (bool) get_option( 'empzi_debug', false );
	}

	public function debug( string $message, array $context = [] ): void {
		if ( ! $this->is_debug_enabled() ) {
			return;
		}
		$this->write( 'debug', $message, $context );
	}

	public function info( string $message, array $context = [] ): void {
		$this->write( 'info', $message, $context );
	}

	public function error( string $message, array $context = [] ): void {
		$this->write( 'error', $message, $context );
	}

	private function write( string $level, string $message, array $context ): void {
		$line = '[EMPZI][' . $level . '] ' . $message;
		if ( ! empty( $context ) ) {
			$encoded = wp_json_encode( $context );
			if ( is_string( $encoded ) ) {
				$line .= ' ' . $encoded;
			}
		}
		error_log( $line );
	}
}
