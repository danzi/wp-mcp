<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Domain\Repository;

interface MediaRepositoryInterface {

	public function sideload_from_path( string $absolute_path, int $parent_post_id, string $filename ): int;

	public function get_url( int $attachment_id ): string;

	public function get_html( int $attachment_id, string $alt = '' ): string;

	public function get_featured_image_id( int $post_id ): int;
}
