<?php
declare(strict_types=1);

namespace EasyMCP\PolylangExtender\Infrastructure\Repository;

use EasyMCP\PolylangExtender\Domain\Repository\LanguageRepositoryInterface;

final class PolylangLanguageRepository implements LanguageRepositoryInterface {

	public function list_languages(): array {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return [];
		}

		$slugs = pll_languages_list( [ 'fields' => 'slug' ] );
		$result = [];

		foreach ( (array) $slugs as $slug ) {
			$lang = function_exists( 'PLL' ) ? PLL()->model->get_language( $slug ) : null;
			if ( ! $lang ) {
				$result[] = [
					'slug'       => (string) $slug,
					'name'       => (string) $slug,
					'locale'     => '',
					'is_default' => function_exists( 'pll_default_language' ) && pll_default_language() === $slug,
					'term_id'    => 0,
				];
				continue;
			}
			$result[] = [
				'slug'       => (string) $lang->slug,
				'name'       => (string) $lang->name,
				'locale'     => (string) $lang->locale,
				'is_default' => (bool) $lang->is_default,
				'term_id'    => (int) $lang->term_id,
			];
		}

		return $result;
	}

	public function get_post_language( int $post_id ): ?string {
		if ( ! function_exists( 'pll_get_post_language' ) ) {
			return null;
		}
		$lang = pll_get_post_language( $post_id, 'slug' );
		return $lang ? (string) $lang : null;
	}

	public function get_post_translations( int $post_id ): array {
		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return [];
		}
		$raw = pll_get_post_translations( $post_id );
		$out = [];
		foreach ( (array) $raw as $slug => $id ) {
			$out[ (string) $slug ] = (int) $id;
		}
		return $out;
	}

	public function set_post_language( int $post_id, string $lang_slug ) {
		if ( ! function_exists( 'pll_set_post_language' ) ) {
			return new \WP_Error( 'polylang_missing', 'Polylang is not active.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'invalid_post', 'Post not found.', [ 'status' => 404 ] );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden', 'You cannot edit this post.', [ 'status' => 403 ] );
		}

		$valid = $this->list_languages();
		$slugs = array_column( $valid, 'slug' );
		if ( ! in_array( $lang_slug, $slugs, true ) ) {
			return new \WP_Error(
				'invalid_language',
				sprintf( 'Language "%s" is not configured. Available: %s', $lang_slug, implode( ', ', $slugs ) ),
				[ 'status' => 400 ]
			);
		}

		pll_set_post_language( $post_id, $lang_slug );

		// Verify
		$current = $this->get_post_language( $post_id );
		if ( $current !== $lang_slug ) {
			return new \WP_Error( 'set_failed', 'Language was not applied.' );
		}

		return true;
	}

	public function save_post_translations( array $translations ) {
		if ( ! function_exists( 'pll_save_post_translations' ) ) {
			return new \WP_Error( 'polylang_missing', 'Polylang is not active.' );
		}

		$clean = [];
		foreach ( $translations as $slug => $id ) {
			$slug = sanitize_key( (string) $slug );
			$id   = (int) $id;
			if ( $slug === '' || $id <= 0 ) {
				continue;
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return new \WP_Error( 'forbidden', "Cannot edit post {$id}.", [ 'status' => 403 ] );
			}
			$clean[ $slug ] = $id;
		}

		if ( count( $clean ) < 2 ) {
			return new \WP_Error( 'invalid_translations', 'Need at least two language => post_id pairs.' );
		}

		pll_save_post_translations( $clean );
		return true;
	}
}
