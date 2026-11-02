<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Infrastructure\Zip;

/**
 * Finds thumbnail.jpg (also jpeg/png/webp) in ZIP root, images/, slug folders, nested paths.
 */
final class ThumbnailLocator {

	private const AUTO_BASENAMES = [
		'thumbnail.jpg',
		'thumbnail.jpeg',
		'thumbnail.png',
		'thumbnail.webp',
	];

	/**
	 * @return string|null Absolute path, or null when thumbnail is optional and absent.
	 */
	public function locate( string $extracted_dir, string $images_dir, string $requested, string $cleanup_path = '' ): ?string {
		$roots = $this->unique_roots( [
			$extracted_dir,
			$images_dir,
			rtrim( $extracted_dir, '/\\' ) . DIRECTORY_SEPARATOR . 'images',
			$cleanup_path,
		] );

		$requested = $this->normalize_requested( $requested );
		if ( $requested !== '' ) {
			$by_json = $this->find_by_requested_name( $roots, $requested );
			if ( $by_json !== null ) {
				return $by_json;
			}
		}

		return $this->find_auto_thumbnail( $roots );
	}

	/**
	 * @param string[] $roots
	 * @return string[]
	 */
	private function unique_roots( array $roots ): array {
		$out = [];
		foreach ( $roots as $root ) {
			if ( $root === '' || ! is_dir( $root ) ) {
				continue;
			}
			$real = realpath( $root );
			if ( ! is_string( $real ) ) {
				continue;
			}
			$out[ $real ] = $real;
		}
		return array_values( $out );
	}

	private function normalize_requested( string $raw ): string {
		$name = str_replace( '\\', '/', trim( $raw ) );
		if ( $name === '' || strpos( $name, '..' ) !== false ) {
			return '';
		}
		return ltrim( $name, '/' );
	}

	/**
	 * @param string[] $roots
	 */
	private function find_by_requested_name( array $roots, string $requested ): ?string {
		$basename = strtolower( basename( $requested ) );
		foreach ( $roots as $root ) {
			$direct = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $requested );
			if ( is_file( $direct ) && is_readable( $direct ) ) {
				return $direct;
			}
			$in_images = $root . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . basename( $requested );
			if ( is_file( $in_images ) && is_readable( $in_images ) ) {
				return $in_images;
			}
		}

		foreach ( $this->walk_files( $roots ) as $path ) {
			if ( strtolower( basename( $path ) ) === $basename ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * @param string[] $roots
	 */
	private function find_auto_thumbnail( array $roots ): ?string {
		$wanted = [];
		foreach ( self::AUTO_BASENAMES as $name ) {
			$wanted[ $name ] = true;
		}

		foreach ( $this->walk_files( $roots ) as $path ) {
			$base = strtolower( basename( $path ) );
			if ( isset( $wanted[ $base ] ) ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * @param string[] $roots
	 * @return \Generator<int, string>
	 */
	private function walk_files( array $roots ): \Generator {
		foreach ( $roots as $root ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || ! $file->isFile() ) {
					continue;
				}
				$path = $file->getPathname();
				if ( strpos( $path, DIRECTORY_SEPARATOR . '__MACOSX' . DIRECTORY_SEPARATOR ) !== false ) {
					continue;
				}
				yield $path;
			}
		}
	}
}
