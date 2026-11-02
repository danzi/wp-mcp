<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Domain\Repository;

interface LanguageRepositoryInterface {

	public function is_available(): bool;

	/**
	 * @return list<string>
	 */
	public function list_slugs(): array;

	public function set_post_language( int $post_id, string $lang_slug ): void;
}
