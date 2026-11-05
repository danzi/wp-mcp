<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Application\Interactor;

use EasyMCP\ZipPostImport\Domain\Exception\ImportException;
use EasyMCP\ZipPostImport\Domain\Logger\LoggerInterface;
use EasyMCP\ZipPostImport\Domain\Repository\MediaRepositoryInterface;

final class ReplaceContentImagesInteractor {

	private MediaRepositoryInterface $media;
	private LoggerInterface $logger;

	public function __construct( MediaRepositoryInterface $media, LoggerInterface $logger ) {
		$this->media  = $media;
		$this->logger = $logger;
	}

	/**
	 * Replaces [image name="file.jpg"] with a centered Gutenberg image block.
	 *
	 * @return array{
	 *   content:string,
	 *   images:list<array{name:string,attachment_id:int,url:string}>,
	 *   media_ids_by_name:array<string,int>
	 * }
	 */
	public function execute( string $content, string $images_dir, int $post_id, string $base_dir = '' ): array {
		$uploaded = [];
		$by_name  = [];

		if ( ! preg_match_all( '/\[image\s+([^\]]+)\]/i', $content, $matches, PREG_SET_ORDER ) ) {
			return [
				'content'            => $content,
				'images'             => [],
				'media_ids_by_name'  => [],
			];
		}

		foreach ( $matches as $match ) {
			$full  = $match[0];
			$attrs = $this->parse_attrs( $match[1] );
			$name  = isset( $attrs['name'] ) ? $attrs['name'] : '';
			if ( $name === '' ) {
				throw new ImportException( 'Image shortcode is missing name="...".', 'image_name_missing' );
			}
			$this->assert_safe_name( $name );

			$key = basename( $name );
			if ( ! isset( $by_name[ $key ] ) ) {
				$path          = $this->resolve_image_path( $images_dir, $name, $base_dir );
				$id            = $this->media->sideload_from_path( $path, $post_id, $key );
				$url           = $this->media->get_url( $id );
				$by_name[ $key ] = $id;
				$by_name[ $name ] = $id;
				$by_name[ strtolower( $key ) ] = $id;

				$uploaded[] = [
					'name'          => $key,
					'attachment_id' => $id,
					'url'           => $url,
				];

				$this->logger->debug( 'Uploaded content image.', [ 'name' => $key, 'id' => $id ] );
			}

			$id  = $by_name[ $key ];
			$alt = isset( $attrs['alt'] ) ? $attrs['alt'] : pathinfo( $key, PATHINFO_FILENAME );
			$html = $this->media->get_html( $id, $alt );
			$content = str_replace( $full, $html, $content );
		}

		return [
			'content'           => $content,
			'images'            => $uploaded,
			'media_ids_by_name' => $by_name,
		];
	}

	/**
	 * @return array<string, string>
	 */
	private function parse_attrs( string $raw ): array {
		$attrs = [];
		if ( preg_match_all( '/([a-zA-Z0-9_-]+)\s*=\s*["\']([^"\']*)["\']/', $raw, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$attrs[ strtolower( $row[1] ) ] = $row[2];
			}
		}
		return $attrs;
	}

	private function assert_safe_name( string $name ): void {
		$normalized = str_replace( '\\', '/', $name );
		if ( $normalized === '' || strpos( $normalized, '..' ) !== false ) {
			throw new ImportException( 'Unsafe image name: ' . $name, 'image_unsafe_name' );
		}
	}

	private function resolve_image_path( string $images_dir, string $name, string $base_dir ): string {
		$candidates = [
			trailingslashit( $images_dir ) . basename( $name ),
			trailingslashit( $images_dir ) . $name,
		];
		if ( $base_dir !== '' ) {
			$candidates[] = trailingslashit( $base_dir ) . $name;
			$candidates[] = trailingslashit( $base_dir ) . 'images' . DIRECTORY_SEPARATOR . basename( $name );
		}

		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) && is_file( $path ) ) {
				return $path;
			}
		}

		if ( ! is_dir( $images_dir ) ) {
			throw new ImportException(
				sprintf( 'Images folder not found while resolving "%s".', $name ),
				'images_folder_missing'
			);
		}

		$needle = strtolower( basename( $name ) );
		$files  = scandir( $images_dir );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( strtolower( $file ) === $needle ) {
					return trailingslashit( $images_dir ) . $file;
				}
			}
		}

		throw new ImportException( sprintf( 'Image not found in ZIP: %s', $name ), 'image_missing' );
	}
}
