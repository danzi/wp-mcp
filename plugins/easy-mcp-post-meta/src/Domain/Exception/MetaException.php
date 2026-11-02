<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Domain\Exception;

final class MetaException extends \RuntimeException {

	private string $error_code;

	public function __construct( string $message, string $error_code = 'meta_failed', int $http = 0, ?\Throwable $previous = null ) {
		parent::__construct( $message, $http, $previous );
		$this->error_code = $error_code;
	}

	public function error_code(): string {
		return $this->error_code;
	}
}
