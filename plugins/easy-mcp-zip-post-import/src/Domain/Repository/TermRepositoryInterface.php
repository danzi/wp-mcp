<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Domain\Repository;

interface TermRepositoryInterface {

	/**
	 * @param int[]    $category_ids
	 * @param string[] $category_names
	 * @return int[]
	 */
	public function resolve_category_ids( array $category_ids, array $category_names, string $language = '' ): array;

	/**
	 * @param string[] $tags
	 * @return string[] assigned tag names
	 */
	public function set_tags( int $post_id, array $tags, string $language = '' ): array;

	/**
	 * @param int[] $category_ids
	 */
	public function set_categories( int $post_id, array $category_ids ): void;
}
