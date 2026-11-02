<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Infrastructure\Repository;

use EasyMCP\ZipPostImport\Domain\Repository\LanguageRepositoryInterface;

final class NullLanguageRepository implements LanguageRepositoryInterface {

	public function is_available(): bool {
		return false;
	}

	public function list_slugs(): array {
		return [];
	}

	public function set_post_language( int $post_id, string $lang_slug ): void {
	}
}
