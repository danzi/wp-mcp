<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Domain\Repository;

interface ExtraFieldsRepositoryInterface {

	/**
	 * @param string[]                  $source_urls
	 * @param string[]                  $social_titles
	 * @param array<string,string>|null $social_posts
	 */
	public function save( int $post_id, array $source_urls, array $social_titles, ?array $social_posts = null ): void;

	/**
	 * @return array{source_urls:string[],social_titles:string[],social_posts:array<string,string>}
	 */
	public function get( int $post_id ): array;
}
