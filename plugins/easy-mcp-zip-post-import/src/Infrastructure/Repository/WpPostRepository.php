<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Infrastructure\Repository;

use EasyMCP\ZipPostImport\Domain\Exception\ImportException;
use EasyMCP\ZipPostImport\Domain\Repository\PostRepositoryInterface;

final class WpPostRepository implements PostRepositoryInterface {

	public function exists( int $post_id ): bool {
		return $post_id > 0 && get_post( $post_id ) instanceof \WP_Post;
	}

	public function insert( array $data ): int {
		$post_id = wp_insert_post( $this->to_postarr( $data ), true );
		if ( is_wp_error( $post_id ) ) {
			throw new ImportException( $post_id->get_error_message(), (string) $post_id->get_error_code() );
		}

		return (int) $post_id;
	}

	public function update( int $post_id, array $data ): void {
		$postarr             = $this->to_postarr( $data );
		$postarr['ID']       = $post_id;
		$result              = wp_update_post( $postarr, true );
		if ( is_wp_error( $result ) ) {
			throw new ImportException( $result->get_error_message(), (string) $result->get_error_code() );
		}
	}

	public function set_thumbnail( int $post_id, int $attachment_id ): void {
		if ( $attachment_id <= 0 || ! get_post( $attachment_id ) ) {
			throw new ImportException( 'Featured image attachment not found.', 'featured_image_missing' );
		}

		set_post_thumbnail( $post_id, $attachment_id );
		if ( (int) get_post_thumbnail_id( $post_id ) === $attachment_id ) {
			return;
		}

		update_post_meta( $post_id, '_thumbnail_id', $attachment_id );
		if ( (int) get_post_thumbnail_id( $post_id ) !== $attachment_id ) {
			throw new ImportException( 'Failed to set featured image (_thumbnail_id).', 'featured_image_failed' );
		}
	}

	public function get_edit_link( int $post_id ): ?string {
		$link = get_edit_post_link( $post_id, 'raw' );

		return $link ? (string) $link : null;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function to_postarr( array $data ): array {
		$postarr = [];
		if ( isset( $data['post_title'] ) ) {
			$postarr['post_title'] = (string) $data['post_title'];
		}
		if ( isset( $data['post_content'] ) ) {
			$postarr['post_content'] = (string) $data['post_content'];
		}
		if ( isset( $data['post_status'] ) ) {
			$postarr['post_status'] = (string) $data['post_status'];
		}
		if ( isset( $data['post_excerpt'] ) ) {
			$postarr['post_excerpt'] = (string) $data['post_excerpt'];
		}
		if ( isset( $data['post_name'] ) ) {
			$postarr['post_name'] = (string) $data['post_name'];
		}
		if ( isset( $data['post_type'] ) ) {
			$postarr['post_type'] = (string) $data['post_type'];
		}
		if ( isset( $data['post_category'] ) && is_array( $data['post_category'] ) ) {
			$postarr['post_category'] = $data['post_category'];
		}

		return $postarr;
	}
}
