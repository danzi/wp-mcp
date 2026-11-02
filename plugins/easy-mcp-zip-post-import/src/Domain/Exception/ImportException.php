<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Domain\Exception;

final class ImportException extends \RuntimeException {

	private string $error_code;

	public function __construct( string $message, string $error_code = 'import_failed', int $http = 0, ?\Throwable $previous = null ) {
		parent::__construct( $message, $http, $previous );
		$this->error_code = $error_code;
	}

	public function error_code(): string {
		return $this->error_code;
	}

	public function getErrorCode(): string {
		return $this->error_code;
	}
}
