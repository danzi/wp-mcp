<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Application\UseCase;

use EasyMCP\ZipPostImport\Domain\Exception\ImportException;

/**
 * Maps user JSON aliases onto the plugin schema (no WordPress dependency).
 *
 * images / images_folder → images folder
 * category / categories / category_names → category names
 */
final class ManifestAliasNormalizer {

	/**
	 * @param array<string, mixed> $data
	 */
	public function images_folder( array $data ): string {
		$raw = '';
		if ( isset( $data['images_folder'] ) && is_string( $data['images_folder'] ) && trim( $data['images_folder'] ) !== '' ) {
			$raw = $data['images_folder'];
		} elseif ( isset( $data['images'] ) && is_string( $data['images'] ) && trim( $data['images'] ) !== '' ) {
			$raw = $data['images'];
		} elseif ( isset( $data['images_dir'] ) && is_string( $data['images_dir'] ) && trim( $data['images_dir'] ) !== '' ) {
			$raw = $data['images_dir'];
		} else {
			$raw = 'images';
		}

		$folder = trim( str_replace( '\\', '/', $raw ), '/' );
		if ( $folder === '' || strpos( $folder, '..' ) !== false ) {
			throw new ImportException( 'Invalid images folder value.', 'invalid_images_folder' );
		}
		return $folder;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return string[]
	 */
	public function category_names( array $data ): array {
		$out = [];
		$out = array_merge( $out, $this->as_string_list( $data['category_names'] ?? null ) );
		$out = array_merge( $out, $this->as_string_list( $data['category'] ?? null ) );
		$out = array_merge( $out, $this->as_string_list( $data['categories'] ?? null ) );
		$unique = [];
		foreach ( $out as $name ) {
			$unique[ $name ] = $name;
		}
		return array_values( $unique );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function thumbnail( array $data ): string {
		$raw = isset( $data['thumbnail'] ) ? trim( (string) $data['thumbnail'] ) : '';
		if ( $raw === '' ) {
			return '';
		}
		$raw = str_replace( '\\', '/', $raw );
		if ( strpos( $raw, '..' ) !== false ) {
			throw new ImportException( 'Invalid thumbnail path.', 'invalid_thumbnail' );
		}
		return ltrim( $raw, '/' );
	}

	/**
	 * @param mixed $value
	 * @return string[]
	 */
	private function as_string_list( $value ): array {
		if ( $value === null || $value === '' ) {
			return [];
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return [];
		}
		if ( is_string( $value ) ) {
			$name = trim( $value );
			return $name !== '' ? [ $name ] : [];
		}
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) && ! is_numeric( $item ) ) {
				continue;
			}
			$item = trim( (string) $item );
			if ( $item !== '' ) {
				$out[] = $item;
			}
		}
		return $out;
	}
}
