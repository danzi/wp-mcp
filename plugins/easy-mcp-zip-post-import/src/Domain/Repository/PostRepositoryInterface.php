<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Domain\Repository;

interface PostRepositoryInterface {

	public function exists( int $post_id ): bool;

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( array $data ): int;

	/**
	 * @param array<string, mixed> $data
	 */
	public function update( int $post_id, array $data ): void;

	public function set_thumbnail( int $post_id, int $attachment_id ): void;

	public function get_edit_link( int $post_id ): ?string;
}
