<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Infrastructure\Repository;

use EasyMCP\ZipPostImport\Domain\Exception\ImportException;
use EasyMCP\ZipPostImport\Domain\Repository\TermRepositoryInterface;

final class WpTermRepository implements TermRepositoryInterface {

	public function resolve_category_ids( array $category_ids, array $category_names, string $language = '' ): array {
		$ids = [];
		foreach ( $category_ids as $cid ) {
			$cid = (int) $cid;
			if ( $cid > 0 ) {
				$ids[] = $this->term_for_language( $cid, 'category', $language );
			}
		}

		foreach ( $category_names as $name ) {
			$name = sanitize_text_field( (string) $name );
			if ( $name === '' ) {
				continue;
			}
			$ids[] = $this->ensure_term( $name, 'category', $language );
		}

		return array_values( array_unique( $ids ) );
	}

	public function set_tags( int $post_id, array $tags, string $language = '' ): array {
		$clean = [];
		foreach ( $tags as $tag ) {
			$tag = sanitize_text_field( (string) $tag );
			if ( $tag !== '' ) {
				$clean[] = $tag;
			}
		}
		$clean = array_values( array_unique( $clean ) );

		if ( empty( $clean ) ) {
			return [];
		}

		$term_ids = [];
		foreach ( $clean as $name ) {
			$term_ids[] = $this->ensure_term( $name, 'post_tag', $language );
		}

		$result = wp_set_object_terms( $post_id, $term_ids, 'post_tag', false );
		if ( is_wp_error( $result ) ) {
			throw new ImportException( $result->get_error_message(), 'tags_failed' );
		}
		if ( $result === false ) {
			throw new ImportException( 'Failed to set tags on the post.', 'tags_failed' );
		}

		$assigned = wp_get_post_tags( $post_id, [ 'fields' => 'names' ] );
		if ( is_wp_error( $assigned ) ) {
			throw new ImportException( $assigned->get_error_message(), 'tags_failed' );
		}

		$assigned_names = array_map( 'strval', (array) $assigned );
		foreach ( $clean as $name ) {
			if ( ! $this->name_in_list( $name, $assigned_names ) ) {
				throw new ImportException(
					sprintf( 'Tag was not assigned to the post: %s', $name ),
					'tags_not_assigned'
				);
			}
		}

		return $assigned_names;
	}

	public function set_categories( int $post_id, array $category_ids ): void {
		if ( empty( $category_ids ) ) {
			return;
		}

		$result = wp_set_post_categories( $post_id, $category_ids, false );
		if ( is_wp_error( $result ) ) {
			throw new ImportException( $result->get_error_message(), 'categories_failed' );
		}
		if ( $result === false ) {
			throw new ImportException( 'Failed to set categories on the post.', 'categories_failed' );
		}
	}

	private function ensure_term( string $name, string $taxonomy, string $language ): int {
		$term = term_exists( $name, $taxonomy );
		if ( ! $term ) {
			$term = wp_insert_term( $name, $taxonomy );
		}
		if ( is_wp_error( $term ) ) {
			throw new ImportException( $term->get_error_message(), $taxonomy . '_failed' );
		}

		$term_id = (int) ( is_array( $term ) ? $term['term_id'] : $term );

		return $this->term_for_language( $term_id, $taxonomy, $language );
	}

	private function term_for_language( int $term_id, string $taxonomy, string $language ): int {
		if ( $language === '' || ! function_exists( 'pll_set_term_language' ) ) {
			return $term_id;
		}

		if ( function_exists( 'pll_get_term' ) ) {
			$translated = pll_get_term( $term_id, $language );
			if ( $translated ) {
				return (int) $translated;
			}
		}

		$current = function_exists( 'pll_get_term_language' ) ? pll_get_term_language( $term_id ) : false;
		if ( ! $current ) {
			pll_set_term_language( $term_id, $language );
			return $term_id;
		}

		if ( $current === $language ) {
			return $term_id;
		}

		$term = get_term( $term_id, $taxonomy );
		$name = ( $term instanceof \WP_Term ) ? $term->name : '';
		$slug = sanitize_title( $name ) . '-' . $language;
		$copy = wp_insert_term( $name, $taxonomy, [ 'slug' => $slug ] );
		if ( is_wp_error( $copy ) ) {
			$existing_id = (int) $copy->get_error_data( 'term_exists' );
			if ( $existing_id > 0 ) {
				if ( function_exists( 'pll_get_term_language' ) && pll_get_term_language( $existing_id ) !== $language ) {
					pll_set_term_language( $existing_id, $language );
				}
				$this->link_term_translation( $term_id, $current, $existing_id, $language );
				return $existing_id;
			}
			throw new ImportException( $copy->get_error_message(), $taxonomy . '_failed' );
		}

		$new_id = (int) ( is_array( $copy ) ? $copy['term_id'] : $copy );
		pll_set_term_language( $new_id, $language );
		$this->link_term_translation( $term_id, $current, $new_id, $language );

		return $new_id;
	}

	private function link_term_translation( int $source_id, string $source_lang, int $target_id, string $target_lang ): void {
		if ( ! function_exists( 'pll_save_term_translations' ) ) {
			return;
		}

		$translations = function_exists( 'pll_get_term_translations' ) ? pll_get_term_translations( $source_id ) : [];
		if ( ! is_array( $translations ) ) {
			$translations = [];
		}
		$translations[ $source_lang ] = $source_id;
		$translations[ $target_lang ] = $target_id;
		pll_save_term_translations( $translations );
	}

	/**
	 * @param string[] $list
	 */
	private function name_in_list( string $name, array $list ): bool {
		foreach ( $list as $item ) {
			if ( strcasecmp( $name, (string) $item ) === 0 ) {
				return true;
			}
		}

		return false;
	}
}
