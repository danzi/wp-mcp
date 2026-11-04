<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Domain\Repository;

interface ExtraFieldsRepositoryInterface {

	/**
	 * @param string[]             $source_urls
	 * @param string[]             $social_titles
	 * @param array<string,string>|null $social_posts  network => url; null = leave unchanged
	 */
	public function save( int $post_id, array $source_urls, array $social_titles, ?array $social_posts = null ): void;

	public function save_lead_simhash( int $post_id, string $hash ): void;

	/**
	 * @return array{source_urls:string[],social_titles:string[],social_posts:array<string,string>,lead_simhash:string}
	 */
	public function get( int $post_id ): array;

	/**
	 * @param string[] $urls
	 * @return list<array<string, mixed>>
	 */
	public function find_by_source_urls( array $urls ): array;

	/**
	 * @return list<array<string, mixed>>
	 */
	public function find_by_social( string $network, bool $posted, int $limit, ?string $language ): array;

	/**
	 * @return array<string, mixed>|null
	 */
	public function card_for_id( int $post_id ): ?array;

	/**
	 * @return list<array<string, mixed>>
	 */
	public function find_by_lead_simhash( string $hash, int $max_hamming, bool $persist_empty ): array;
}
