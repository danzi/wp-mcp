<?php
declare(strict_types=1);

namespace EasyMCP\PolylangExtender\Domain\Repository;

interface LanguageRepositoryInterface {

	/**
	 * @return list<array{slug:string,name:string,locale:string,is_default:bool,term_id:int}>
	 */
	public function list_languages(): array;

	public function get_post_language( int $post_id ): ?string;

	/**
	 * @return array<string, int>  slug => post_id
	 */
	public function get_post_translations( int $post_id ): array;

	/**
	 * @return true|\WP_Error
	 */
	public function set_post_language( int $post_id, string $lang_slug );

	/**
	 * Link several posts as translations of each other.
	 * Keys = language slug, values = post IDs.
	 *
	 * @param array<string, int> $translations
	 * @return true|\WP_Error
	 */
	public function save_post_translations( array $translations );
}
