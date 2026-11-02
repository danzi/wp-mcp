<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Domain\Repository;

interface SeoRepositoryInterface {

	public function is_available(): bool;

	public function save( int $post_id, ?string $title, ?string $description, ?string $focus_keyword ): void;
}
