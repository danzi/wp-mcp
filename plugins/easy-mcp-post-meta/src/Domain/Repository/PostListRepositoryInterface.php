<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Domain\Repository;

interface PostListRepositoryInterface {

	/**
	 * @param array{
	 *   search?:string,
	 *   language?:string,
	 *   category?:string,
	 *   network?:string,
	 *   social?:string,
	 *   order?:string,
	 *   page?:int,
	 *   per_page?:int
	 * } $query
	 * @return array{posts:list<array<string,mixed>>,total:int,page:int,per_page:int}
	 */
	public function list( array $query ): array;

	/**
	 * @return array{languages:list<array{slug:string,name:string}>,categories:list<array{id:int,name:string,slug:string}>,networks:string[]}
	 */
	public function filters(): array;
}
