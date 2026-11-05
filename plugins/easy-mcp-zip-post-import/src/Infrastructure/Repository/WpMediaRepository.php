<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Infrastructure\Repository;

use EasyMCP\ZipPostImport\Domain\Exception\ImportException;
use EasyMCP\ZipPostImport\Domain\Repository\MediaRepositoryInterface;

final class WpMediaRepository implements MediaRepositoryInterface {

	public function sideload_from_path( string $absolute_path, int $parent_post_id, string $filename ): int {
		if ( ! is_readable( $absolute_path ) ) {
			throw new ImportException( sprintf( 'Image file is not readable: %s', $filename ), 'image_unreadable' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( $filename );
		if ( $tmp === false || ! copy( $absolute_path, $tmp ) ) {
			throw new ImportException( sprintf( 'Could not copy image to temp: %s', $filename ), 'image_copy_failed' );
		}

		$file_array = [
			'name'     => $filename,
			'tmp_name' => $tmp,
		];

		$attachment_id = media_handle_sideload( $file_array, $parent_post_id );
		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
			throw new ImportException(
				sprintf( 'Failed to upload %s: %s', $filename, $attachment_id->get_error_message() ),
				'image_upload_failed'
			);
		}

		return (int) $attachment_id;
	}

	public function get_url( int $attachment_id ): string {
		$url = wp_get_attachment_url( $attachment_id );

		return is_string( $url ) ? $url : '';
	}

	public function get_html( int $attachment_id, string $alt = '' ): string {
		$attr = [ 'class' => 'wp-image-' . $attachment_id ];
		if ( $alt !== '' ) {
			$attr['alt'] = $alt;
		}
		$html = wp_get_attachment_image( $attachment_id, 'full', false, $attr );
		if ( ! is_string( $html ) || $html === '' ) {
			$url = $this->get_url( $attachment_id );
			if ( $url === '' ) {
				throw new ImportException( sprintf( 'Uploaded image %d has no URL.', $attachment_id ), 'image_url_missing' );
			}
			$html = sprintf(
				'<img class="wp-image-%d" src="%s" alt="%s" />',
				$attachment_id,
				esc_url( $url ),
				esc_attr( $alt )
			);
		}

		return sprintf(
			"<!-- wp:image {\"id\":%d,\"sizeSlug\":\"full\",\"linkDestination\":\"none\",\"align\":\"center\"} -->\n<figure class=\"wp-block-image aligncenter size-full\">%s</figure>\n<!-- /wp:image -->",
			$attachment_id,
			$html
		);
	}

	public function get_featured_image_id( int $post_id ): int {
		return (int) get_post_thumbnail_id( $post_id );
	}
}
