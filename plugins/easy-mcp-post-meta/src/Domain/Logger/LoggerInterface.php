<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Domain\Logger;

interface LoggerInterface {

	public function debug( string $message, array $context = [] ): void;

	public function info( string $message, array $context = [] ): void;

	public function error( string $message, array $context = [] ): void;
}
