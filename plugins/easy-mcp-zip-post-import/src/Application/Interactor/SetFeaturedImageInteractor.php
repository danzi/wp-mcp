<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Application\Interactor;

use EasyMCP\ZipPostImport\Domain\Exception\ImportException;
use EasyMCP\ZipPostImport\Domain\Logger\LoggerInterface;
use EasyMCP\ZipPostImport\Domain\Repository\MediaRepositoryInterface;
use EasyMCP\ZipPostImport\Domain\Repository\PostRepositoryInterface;
use EasyMCP\ZipPostImport\Infrastructure\Zip\ThumbnailLocator;

final class SetFeaturedImageInteractor {

	private MediaRepositoryInterface $media;
	private PostRepositoryInterface $posts;
	private ThumbnailLocator $locator;
	private LoggerInterface $logger;

	public function __construct(
		MediaRepositoryInterface $media,
		PostRepositoryInterface $posts,
		ThumbnailLocator $locator,
		LoggerInterface $logger
	) {
		$this->media   = $media;
		$this->posts   = $posts;
		$this->locator = $locator;
		$this->logger  = $logger;
	}

	/**
	 * @param array<string, int> $media_ids_by_name
	 * @return array{name:string,attachment_id:int,url:string}|null
	 */
	public function execute(
		int $post_id,
		string $extracted_dir,
		string $images_dir,
		string $requested_thumbnail,
		array $media_ids_by_name
	): ?array {
		$path = $this->locator->locate( $extracted_dir, $images_dir, $requested_thumbnail, $extracted_dir );
		if ( $path === null ) {
			$this->logger->debug( 'No thumbnail in ZIP or JSON, skip featured image' );
			return null;
		}

		$basename = basename( $path );
		$keys     = array_unique( array_filter( [
			$basename,
			strtolower( $basename ),
			$requested_thumbnail,
			basename( $requested_thumbnail ),
		] ) );

		$attachment_id = 0;
		foreach ( $keys as $key ) {
			if ( isset( $media_ids_by_name[ $key ] ) ) {
				$attachment_id = (int) $media_ids_by_name[ $key ];
				break;
			}
			foreach ( $media_ids_by_name as $name => $id ) {
				if ( strtolower( (string) $name ) === strtolower( (string) $key ) ) {
					$attachment_id = (int) $id;
					break 2;
				}
			}
		}

		if ( $attachment_id <= 0 ) {
			$attachment_id = $this->media->sideload_from_path( $path, $post_id, $basename );
			$this->logger->info( 'Uploaded thumbnail', [ 'id' => $attachment_id, 'file' => $basename, 'path' => $path ] );
		}

		$this->posts->set_thumbnail( $post_id, $attachment_id );

		if ( $this->media->get_featured_image_id( $post_id ) !== $attachment_id ) {
			throw new ImportException(
				sprintf( 'Thumbnail file exists and was uploaded (attachment %d) but set_post_thumbnail failed for post %d.', $attachment_id, $post_id ),
				'featured_image_not_assigned'
			);
		}

		return [
			'name'          => $basename,
			'attachment_id' => $attachment_id,
			'url'           => $this->media->get_url( $attachment_id ),
		];
	}
}
