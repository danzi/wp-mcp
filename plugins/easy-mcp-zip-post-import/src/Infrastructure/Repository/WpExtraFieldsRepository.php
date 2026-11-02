<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Infrastructure\Repository;

use EasyMCP\ZipPostImport\Domain\Repository\ExtraFieldsRepositoryInterface;

/**
 * Same meta keys as Easy MCP Post Extra Fields so ZIP import fills the metabox/MCP search.
 */
final class WpExtraFieldsRepository implements ExtraFieldsRepositoryInterface {

	private const SOURCE_URLS    = '_emp_source_urls';
	private const SOURCE_URL     = '_emp_source_url';
	private const SOCIAL_TITLES  = '_emp_social_titles';
	private const SOCIAL_POSTS   = '_emp_social_posts';
	private const SOCIAL_POSTED  = '_emp_social_posted';

	public function save( int $post_id, array $source_urls, array $social_titles, ?array $social_posts = null ): void {
		$urls   = $this->unique_strings( $source_urls, true );
		$titles = $this->unique_strings( $social_titles, false );

		update_post_meta( $post_id, self::SOURCE_URLS, $urls );
		delete_post_meta( $post_id, self::SOURCE_URL );
		foreach ( $urls as $url ) {
			add_post_meta( $post_id, self::SOURCE_URL, $url, false );
		}
		update_post_meta( $post_id, self::SOCIAL_TITLES, $titles );
		if ( $social_posts !== null ) {
			$map = $this->network_map( $social_posts );
			update_post_meta( $post_id, self::SOCIAL_POSTS, $map );
			delete_post_meta( $post_id, self::SOCIAL_POSTED );
			foreach ( array_keys( $map ) as $network ) {
				add_post_meta( $post_id, self::SOCIAL_POSTED, $network, false );
			}
		}
	}

	public function get( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::SOURCE_URLS, true );
		$urls   = is_array( $stored ) ? $this->unique_strings( $stored, true ) : [];
		if ( $urls === [] ) {
			$singles = get_post_meta( $post_id, self::SOURCE_URL, false );
			$urls    = is_array( $singles ) ? $this->unique_strings( $singles, true ) : [];
		}
		$titles = get_post_meta( $post_id, self::SOCIAL_TITLES, true );

		return [
			'source_urls'   => $urls,
			'social_titles' => is_array( $titles ) ? $this->unique_strings( $titles, false ) : [],
			'social_posts'  => $this->network_map( get_post_meta( $post_id, self::SOCIAL_POSTS, true ) ),
		];
	}

	/**
	 * @param mixed $raw
	 * @return array<string, string>
	 */
	private function network_map( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $network => $url ) {
			$key = sanitize_key( (string) $network );
			if ( $key === '' ) {
				continue;
			}
			$link = $this->normalize_url( (string) $url );
			if ( $link !== '' ) {
				$out[ $key ] = $link;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @param mixed[] $items
	 * @return string[]
	 */
	private function unique_strings( array $items, bool $as_url ): array {
		$out  = [];
		$seen = [];
		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$value = $as_url ? $this->normalize_url( (string) $item ) : sanitize_text_field( (string) $item );
			if ( $value === '' || isset( $seen[ $value ] ) ) {
				continue;
			}
			$seen[ $value ] = true;
			$out[]          = $value;
		}
		return $out;
	}

	private function normalize_url( string $url ): string {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return untrailingslashit( strtolower( $url ) );
		}
		$host = strtolower( (string) $parts['host'] );
		if ( strpos( $host, 'www.' ) === 0 ) {
			$host = substr( $host, 4 );
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( $path === '/' ) {
			$path = '';
		}
		$query = isset( $parts['query'] ) ? '?' . (string) $parts['query'] : '';
		return untrailingslashit( $scheme . '://' . $host . $path . $query );
	}
}
