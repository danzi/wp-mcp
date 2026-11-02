<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Infrastructure\Repository;

use EasyMCP\ZipPostImport\Domain\Exception\ImportException;
use EasyMCP\ZipPostImport\Domain\Repository\LanguageRepositoryInterface;

final class PolylangLanguageRepository implements LanguageRepositoryInterface {

	public function is_available(): bool {
		return function_exists( 'pll_set_post_language' );
	}

	public function list_slugs(): array {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return [];
		}

		$slugs = pll_languages_list( [ 'fields' => 'slug' ] );
		$out   = [];
		foreach ( (array) $slugs as $slug ) {
			$out[] = (string) $slug;
		}

		return $out;
	}

	public function set_post_language( int $post_id, string $lang_slug ): void {
		if ( ! $this->is_available() ) {
			throw new ImportException( 'Polylang is not active.', 'polylang_missing' );
		}

		$slugs = $this->list_slugs();
		if ( ! in_array( $lang_slug, $slugs, true ) ) {
			throw new ImportException(
				sprintf( 'Language "%s" is not configured. Available: %s', $lang_slug, implode( ', ', $slugs ) ),
				'invalid_language'
			);
		}

		pll_set_post_language( $post_id, $lang_slug );

		if ( function_exists( 'pll_get_post_language' ) ) {
			$current = pll_get_post_language( $post_id, 'slug' );
			if ( $current !== $lang_slug ) {
				throw new ImportException( 'Language was not applied.', 'set_language_failed' );
			}
		}
	}
}
