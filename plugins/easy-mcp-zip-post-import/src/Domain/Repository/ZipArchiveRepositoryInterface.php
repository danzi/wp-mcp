<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Domain\Repository;

interface ZipArchiveRepositoryInterface {

	/**
	 * Extract zip into a fresh temp directory. Caller must cleanup().
	 */
	public function extract( string $zip_path ): string;

	public function download_from_url( string $url ): string;

	public function cleanup( string $directory ): void;

	public function delete_source( string $zip_path ): void;
}
