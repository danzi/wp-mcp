<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Infrastructure\Repository;

use EasyMCP\ZipPostImport\Domain\Repository\SeoRepositoryInterface;

final class YoastSeoRepository implements SeoRepositoryInterface {

	public function is_available(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Meta' );
	}

	public function save( int $post_id, ?string $title, ?string $description, ?string $focus_keyword ): void {
		if ( $title !== null && $title !== '' ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $title ) );
		}
		if ( $description !== null && $description !== '' ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_text_field( $description ) );
		}
		if ( $focus_keyword !== null && $focus_keyword !== '' ) {
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', sanitize_text_field( $focus_keyword ) );
		}
	}
}
