<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Infrastructure\Url;

final class SourceUrlNormalizer {

	public function normalize( string $url ): string {
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}
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

	/**
	 * @param mixed $raw
	 * @return string[]
	 */
	public function list_from( $raw ): array {
		$items = [];
		if ( is_string( $raw ) ) {
			$items = preg_split( '/\r\n|\r|\n/', $raw ) ?: [];
		} elseif ( is_array( $raw ) ) {
			$items = $raw;
		}
		$out = [];
		$seen = [];
		foreach ( $items as $item ) {
			$normalized = $this->normalize( (string) $item );
			if ( $normalized === '' || isset( $seen[ $normalized ] ) ) {
				continue;
			}
			$seen[ $normalized ] = true;
			$out[] = $normalized;
		}
		return $out;
	}

	/**
	 * @param mixed $raw
	 * @return string[]
	 */
	public function titles_from( $raw ): array {
		$items = [];
		if ( is_string( $raw ) ) {
			$items = preg_split( '/\r\n|\r|\n/', $raw ) ?: [];
		} elseif ( is_array( $raw ) ) {
			$items = $raw;
		}
		$out = [];
		foreach ( $items as $item ) {
			$title = sanitize_text_field( (string) $item );
			if ( $title !== '' ) {
				$out[] = $title;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
