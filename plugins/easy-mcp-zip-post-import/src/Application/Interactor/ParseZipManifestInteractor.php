<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Application\Interactor;

use EasyMCP\ZipPostImport\Application\UseCase\ManifestAliasNormalizer;
use EasyMCP\ZipPostImport\Domain\Exception\ImportException;
use EasyMCP\ZipPostImport\Domain\Logger\LoggerInterface;

final class ParseZipManifestInteractor {

	private LoggerInterface $logger;
	private ManifestAliasNormalizer $aliases;

	public function __construct( LoggerInterface $logger, ?ManifestAliasNormalizer $aliases = null ) {
		$this->logger  = $logger;
		$this->aliases = $aliases ?: new ManifestAliasNormalizer();
	}

	/**
	 * @return array{
	 *   extracted_dir:string,
	 *   base_dir:string,
	 *   images_dir:string,
	 *   images_folder:string,
	 *   post_id:int,
	 *   title:string,
	 *   content:string,
	 *   status:string,
	 *   excerpt:string,
	 *   slug:string,
	 *   post_type:string,
	 *   language:string,
	 *   category_ids:int[],
	 *   category_names:string[],
	 *   tags:string[],
	 *   yoast_title:?string,
	 *   yoast_description:?string,
	 *   yoast_focus_keyword:?string,
	 *   thumbnail:string,
	 *   require_polylang:bool,
	 *   require_yoast:bool,
	 *   source_urls:?string[],
	 *   social_titles:?string[],
	 *   social_posts:?array<string,string>
	 * }
	 */
	public function execute( string $extracted_dir ): array {
		$json_path = $this->find_post_json( $extracted_dir );
		$base_dir  = dirname( $json_path );
		$raw       = file_get_contents( $json_path );
		if ( $raw === false || $raw === '' ) {
			throw new ImportException( 'post.json is empty or unreadable.', 'manifest_empty' );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			throw new ImportException( 'post.json is not valid JSON.', 'manifest_invalid_json' );
		}

		$this->logger->debug( 'Parsed post.json.', [ 'path' => $json_path ] );

		$post_id = 0;
		if ( isset( $data['post_id'] ) ) {
			$post_id = (int) $data['post_id'];
		} elseif ( isset( $data['postid'] ) ) {
			$post_id = (int) $data['postid'];
		}

		$images_folder = $this->aliases->images_folder( $data );
		$from_json     = $base_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $images_folder );
		$images_dir    = is_dir( $from_json ) ? $from_json : trailingslashit( $base_dir ) . $images_folder;

		$yoast = [];
		if ( isset( $data['yoast'] ) && is_array( $data['yoast'] ) ) {
			$yoast = $data['yoast'];
		}

		$category_ids = [];
		if ( ! empty( $data['category_ids'] ) && is_array( $data['category_ids'] ) ) {
			foreach ( $data['category_ids'] as $id ) {
				$category_ids[] = (int) $id;
			}
		}

		return [
			'extracted_dir'        => $extracted_dir,
			'base_dir'             => $base_dir,
			'images_dir'           => $images_dir,
			'images_folder'        => $images_folder,
			'post_id'              => $post_id,
			'title'                => isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '',
			'content'              => isset( $data['content'] ) ? (string) $data['content'] : '',
			'status'               => isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : '',
			'excerpt'              => isset( $data['excerpt'] ) ? sanitize_textarea_field( (string) $data['excerpt'] ) : '',
			'slug'                 => isset( $data['slug'] ) ? sanitize_title( (string) $data['slug'] ) : '',
			'post_type'            => isset( $data['post_type'] ) ? sanitize_key( (string) $data['post_type'] ) : 'post',
			'language'             => isset( $data['language'] ) ? sanitize_key( (string) $data['language'] ) : '',
			'category_ids'         => $category_ids,
			'category_names'       => array_map( 'sanitize_text_field', $this->aliases->category_names( $data ) ),
			'tags'                 => $this->collect_tags( $data ),
			'yoast_title'          => $this->yoast_field( $data, $yoast, 'yoast_title', 'title' ),
			'yoast_description'    => $this->yoast_field( $data, $yoast, 'yoast_description', 'description' ),
			'yoast_focus_keyword'  => $this->yoast_field( $data, $yoast, 'yoast_focus_keyword', 'focus_keyword' ),
			'thumbnail'            => $this->aliases->thumbnail( $data ),
			'require_polylang'     => ! empty( $data['require_polylang'] ),
			'require_yoast'        => ! empty( $data['require_yoast'] ),
			'source_urls'          => $this->optional_string_list( $data, [ 'source_urls', 'sources', 'source_links' ] ),
			'social_titles'        => $this->optional_string_list( $data, [ 'social_titles', 'fb_titles', 'facebook_titles' ] ),
			'social_posts'         => $this->optional_network_map( $data, 'social_posts' ),
		];
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $yoast
	 */
	private function yoast_field( array $data, array $yoast, string $flat_key, string $nested_key ): ?string {
		if ( isset( $data[ $flat_key ] ) ) {
			$value = sanitize_text_field( (string) $data[ $flat_key ] );
			return $value !== '' ? $value : null;
		}
		if ( isset( $yoast[ $nested_key ] ) ) {
			$value = sanitize_text_field( (string) $yoast[ $nested_key ] );
			return $value !== '' ? $value : null;
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param string[]             $keys
	 * @return string[]|null null when none of the keys are present
	 */
	private function optional_string_list( array $data, array $keys ): ?array {
		$found = false;
		$raw   = [];
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			$found = true;
			$value = $data[ $key ];
			if ( is_array( $value ) ) {
				$raw = array_merge( $raw, $value );
			} elseif ( is_string( $value ) ) {
				$raw = array_merge( $raw, preg_split( '/\r\n|\r|\n/', $value ) ?: [] );
			}
		}
		if ( ! $found ) {
			return null;
		}
		$out = [];
		foreach ( $raw as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$item = trim( (string) $item );
			if ( $item !== '' ) {
				$out[] = $item;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, string>|null
	 */
	private function optional_network_map( array $data, string $key ): ?array {
		if ( ! array_key_exists( $key, $data ) ) {
			return null;
		}
		if ( ! is_array( $data[ $key ] ) ) {
			return [];
		}
		$out = [];
		foreach ( $data[ $key ] as $network => $url ) {
			$slug = sanitize_key( (string) $network );
			if ( $slug === '' || ! is_scalar( $url ) ) {
				continue;
			}
			$link = trim( (string) $url );
			if ( $link !== '' ) {
				$out[ $slug ] = $link;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return string[]
	 */
	private function collect_tags( array $data ): array {
		$raw = [];
		if ( isset( $data['tags'] ) && is_array( $data['tags'] ) ) {
			$raw = $data['tags'];
		} elseif ( isset( $data['tags'] ) && is_string( $data['tags'] ) ) {
			$raw = [ $data['tags'] ];
		} elseif ( isset( $data['tag'] ) && is_string( $data['tag'] ) ) {
			$raw = [ $data['tag'] ];
		}

		$out = [];
		foreach ( $raw as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$parts = preg_split( '/[,;]+/', (string) $item );
			if ( ! is_array( $parts ) ) {
				continue;
			}
			foreach ( $parts as $part ) {
				$part = trim( $part );
				if ( $part !== '' ) {
					$out[] = $part;
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	private function find_post_json( string $extracted_dir ): string {
		$names = [ 'post.json', 'manifest.json' ];
		$candidates = [];
		foreach ( $names as $name ) {
			$candidates[] = trailingslashit( $extracted_dir ) . $name;
		}

		$entries = scandir( $extracted_dir );
		if ( is_array( $entries ) ) {
			foreach ( $entries as $entry ) {
				if ( $entry === '.' || $entry === '..' ) {
					continue;
				}
				$dir = trailingslashit( $extracted_dir ) . $entry;
				if ( is_dir( $dir ) ) {
					foreach ( $names as $name ) {
						$candidates[] = trailingslashit( $dir ) . $name;
					}
				}
			}
		}

		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) ) {
				return $path;
			}
		}

		$lonely = $this->single_json_in_root( $extracted_dir );
		if ( $lonely !== null ) {
			return $lonely;
		}

		throw new ImportException( 'post.json not found in ZIP (root or first-level folder).', 'manifest_missing' );
	}

	private function single_json_in_root( string $extracted_dir ): ?string {
		$found = [];
		$entries = scandir( $extracted_dir );
		if ( ! is_array( $entries ) ) {
			return null;
		}
		foreach ( $entries as $entry ) {
			if ( strtolower( (string) pathinfo( $entry, PATHINFO_EXTENSION ) ) !== 'json' ) {
				continue;
			}
			$path = trailingslashit( $extracted_dir ) . $entry;
			if ( is_file( $path ) && is_readable( $path ) ) {
				$found[] = $path;
			}
		}
		return count( $found ) === 1 ? $found[0] : null;
	}
}
