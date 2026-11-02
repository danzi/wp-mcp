<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Infrastructure\Repository;

use EasyMCP\PostMeta\Domain\ExtraFieldsKeys;
use EasyMCP\PostMeta\Domain\Repository\ExtraFieldsRepositoryInterface;
use EasyMCP\PostMeta\Domain\Repository\PostListRepositoryInterface;

final class WpPostListRepository implements PostListRepositoryInterface {

	private ExtraFieldsRepositoryInterface $fields;

	public function __construct( ExtraFieldsRepositoryInterface $fields ) {
		$this->fields = $fields;
	}

	public function list( array $query ): array {
		$page     = max( 1, isset( $query['page'] ) ? (int) $query['page'] : 1 );
		$per_page = isset( $query['per_page'] ) ? (int) $query['per_page'] : 20;
		$per_page = max( 1, min( 50, $per_page ) );
		$network  = sanitize_key( isset( $query['network'] ) ? (string) $query['network'] : 'facebook' );
		if ( $network === '' ) {
			$network = 'facebook';
		}
		$social = isset( $query['social'] ) ? sanitize_key( (string) $query['social'] ) : 'any';
		$order  = isset( $query['order'] ) ? sanitize_key( (string) $query['order'] ) : 'date_desc';
		$search = isset( $query['search'] ) ? trim( (string) $query['search'] ) : '';
		$lang   = isset( $query['language'] ) ? sanitize_key( (string) $query['language'] ) : '';
		$cat    = isset( $query['category'] ) ? trim( (string) $query['category'] ) : '';

		$args = [
			'post_type'              => 'post',
			'post_status'            => [ 'draft', 'pending', 'private', 'publish', 'future' ],
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'orderby'                => 'date',
			'order'                  => $order === 'date_asc' ? 'ASC' : 'DESC',
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => true,
		];
		if ( $search !== '' ) {
			$args['s'] = $search;
		}
		if ( $lang !== '' ) {
			$args['lang'] = $lang;
		}
		if ( $cat !== '' ) {
			if ( ctype_digit( $cat ) ) {
				$args['cat'] = (int) $cat;
			} else {
				$args['category_name'] = sanitize_title( $cat );
			}
		}

		$posted_ids = $this->posted_ids( $network );
		if ( $social === 'posted' ) {
			if ( $posted_ids === [] ) {
				return [
					'posts'    => [],
					'total'    => 0,
					'page'     => $page,
					'per_page' => $per_page,
				];
			}
			$args['post__in'] = $posted_ids;
		} elseif ( $social === 'not_posted' && $posted_ids !== [] ) {
			$args['post__not_in'] = $posted_ids;
		}

		$query_obj = new \WP_Query( $args );
		$posts     = [];
		foreach ( $query_obj->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$card = $this->fields->card_for_id( (int) $post->ID );
			if ( $card !== null ) {
				$posts[] = $card;
			}
		}

		return [
			'posts'    => $posts,
			'total'    => (int) $query_obj->found_posts,
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	public function filters(): array {
		$languages  = $this->language_rows();
		$categories = $this->category_rows();

		return [
			'languages'      => $languages,
			'categories'     => $categories,
			'networks'       => [ 'facebook' ],
			'plugin_version' => defined( 'EMPM_VERSION' ) ? EMPM_VERSION : '',
			'debug'          => [
				'pll'        => function_exists( 'pll_languages_list' ),
				'wpml'       => defined( 'ICL_SITEPRESS_VERSION' ),
				'lang_count' => count( $languages ),
				'cat_count'  => count( $categories ),
			],
		];
	}

	/**
	 * @return array<int, array{slug:string,name:string}>
	 */
	private function language_rows(): array {
		$seen = [];
		$rows = [];

		$push = static function ( $slug, $name ) use ( &$seen, &$rows ): void {
			$key = strtolower( trim( (string) $slug ) );
			$key = str_replace( '_', '-', $key );
			if ( $key === '' || isset( $seen[ $key ] ) ) {
				return;
			}
			$seen[ $key ] = true;
			$label        = trim( (string) $name );
			$rows[]       = [ 'slug' => $key, 'name' => $label !== '' ? $label : $key ];
		};

		foreach ( $this->terms_from_db( 'language' ) as $term ) {
			$push( $term['slug'], $term['name'] );
		}

		if ( function_exists( 'PLL' ) ) {
			try {
				$pll = PLL();
				if ( is_object( $pll ) && isset( $pll->model ) && method_exists( $pll->model, 'get_languages_list' ) ) {
					$list = $pll->model->get_languages_list();
					if ( is_array( $list ) ) {
						foreach ( $list as $lang ) {
							if ( is_object( $lang ) && ! empty( $lang->slug ) ) {
								$push( $lang->slug, isset( $lang->name ) ? $lang->name : $lang->slug );
							}
						}
					}
				}
			} catch ( \Throwable $e ) {
				// SQL already used
			}
		}

		if ( function_exists( 'pll_languages_list' ) ) {
			$slugs = pll_languages_list( [ 'fields' => 'slug' ] );
			$names = pll_languages_list( [ 'fields' => 'name' ] );
			$slugs = is_array( $slugs ) ? array_values( $slugs ) : [];
			$names = is_array( $names ) ? array_values( $names ) : [];
			foreach ( $slugs as $i => $slug ) {
				if ( is_object( $slug ) && ! empty( $slug->slug ) ) {
					$push( $slug->slug, isset( $slug->name ) ? $slug->name : $slug->slug );
					continue;
				}
				$label = isset( $names[ $i ] ) ? $names[ $i ] : $slug;
				if ( is_object( $label ) && isset( $label->name ) ) {
					$label = $label->name;
				}
				$push( $slug, $label );
			}
		}

		if ( has_filter( 'wpml_active_languages' ) ) {
			$wpml = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
			if ( is_array( $wpml ) ) {
				foreach ( $wpml as $lang ) {
					if ( ! is_array( $lang ) ) {
						continue;
					}
					$code = isset( $lang['code'] ) ? $lang['code'] : ( isset( $lang['language_code'] ) ? $lang['language_code'] : '' );
					$push( $code, isset( $lang['native_name'] ) ? $lang['native_name'] : $code );
				}
			}
		}

		return $rows;
	}

	/**
	 * @return array<int, array{id:int,name:string,slug:string}>
	 */
	private function category_rows(): array {
		$rows = $this->terms_from_db( 'category' );
		$langs = $this->term_languages_map();
		$out  = [];
		$seen = [];
		foreach ( $rows as $row ) {
			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$lang        = '';
			if ( function_exists( 'pll_get_term_language' ) ) {
				$got = pll_get_term_language( $id, 'slug' );
				if ( is_string( $got ) && $got !== '' ) {
					$lang = strtolower( $got );
				}
			}
			if ( $lang === '' && isset( $langs[ $id ] ) ) {
				$lang = $langs[ $id ];
			}
			$row['language'] = $lang;
			$out[]           = $row;
		}
		return $out;
	}

	/**
	 * @return array<int, string> term_id => language slug
	 */
	private function term_languages_map(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return [];
		}
		$sql = "SELECT tr.object_id AS term_id, t.slug AS lang
			FROM {$wpdb->term_relationships} AS tr
			INNER JOIN {$wpdb->term_taxonomy} AS tt
				ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'term_language'
			INNER JOIN {$wpdb->terms} AS t ON t.term_id = tt.term_id";
		$found = $wpdb->get_results( $sql );
		$map   = [];
		if ( ! is_array( $found ) ) {
			return [];
		}
		foreach ( $found as $row ) {
			if ( ! is_object( $row ) ) {
				continue;
			}
			$id = isset( $row->term_id ) ? (int) $row->term_id : 0;
			if ( $id <= 0 ) {
				continue;
			}
			$map[ $id ] = strtolower( (string) $row->lang );
		}
		return $map;
	}

	/**
	 * Direct DB read so Polylang/WPML REST filters cannot empty the lists.
	 *
	 * @return array<int, array{id:int,name:string,slug:string}>
	 */
	private function terms_from_db( string $taxonomy ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return [];
		}
		$sql = $wpdb->prepare(
			"SELECT t.term_id, t.name, t.slug
			FROM {$wpdb->terms} AS t
			INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
			WHERE tt.taxonomy = %s
			ORDER BY t.name ASC
			LIMIT 200",
			$taxonomy
		);
		$found = $wpdb->get_results( $sql );
		if ( ! is_array( $found ) ) {
			return [];
		}
		$rows = [];
		foreach ( $found as $row ) {
			if ( ! is_object( $row ) ) {
				continue;
			}
			$id = isset( $row->term_id ) ? (int) $row->term_id : 0;
			if ( $id <= 0 ) {
				continue;
			}
			$rows[] = [
				'id'   => $id,
				'name' => isset( $row->name ) ? (string) $row->name : '',
				'slug' => isset( $row->slug ) ? (string) $row->slug : '',
			];
		}
		return $rows;
	}

	/**
	 * @return int[]
	 */
	private function posted_ids( string $network ): array {
		$ids = get_posts(
			[
				'post_type'              => 'post',
				'post_status'            => [ 'draft', 'pending', 'private', 'publish', 'future' ],
				'posts_per_page'         => 200,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => [
					[
						'key'     => ExtraFieldsKeys::SOCIAL_POSTED,
						'value'   => $network,
						'compare' => '=',
					],
				],
			]
		);
		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}
}
