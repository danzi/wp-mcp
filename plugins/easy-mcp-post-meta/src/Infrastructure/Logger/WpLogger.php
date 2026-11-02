<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Infrastructure\Logger;

use EasyMCP\PostMeta\Domain\Logger\LoggerInterface;

final class WpLogger implements LoggerInterface {

	public function debug( string $message, array $context = [] ): void {
		if ( ! $this->debug_enabled() ) {
			return;
		}
		error_log( '[EMPM][debug] ' . $message . ' ' . wp_json_encode( $context ) );
	}

	public function info( string $message, array $context = [] ): void {
		if ( ! $this->debug_enabled() ) {
			return;
		}
		error_log( '[EMPM][info] ' . $message . ' ' . wp_json_encode( $context ) );
	}

	public function error( string $message, array $context = [] ): void {
		error_log( '[EMPM][error] ' . $message . ' ' . wp_json_encode( $context ) );
	}

	private function debug_enabled(): bool {
		if ( defined( 'EMPM_DEBUG' ) && EMPM_DEBUG ) {
			return true;
		}
		return (bool) get_option( 'empm_debug', false );
	}
}
