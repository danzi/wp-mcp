<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Infrastructure\Repository;

use EasyMCP\PostMeta\Domain\ExtraFieldsKeys;
use EasyMCP\PostMeta\Domain\LeadSimhash;
use EasyMCP\PostMeta\Domain\Repository\ExtraFieldsRepositoryInterface;
use EasyMCP\PostMeta\Infrastructure\Url\SourceUrlNormalizer;

final class WpExtraFieldsRepository implements ExtraFieldsRepositoryInterface {

	private const SCAN_WITH_HASH = 1000;
	private const SCAN_EMPTY     = 400;

	private SourceUrlNormalizer $urls;
	private LeadSimhash $simhash;

	public function __construct( SourceUrlNormalizer $urls, LeadSimhash $simhash ) {
		$this->urls    = $urls;
		$this->simhash = $simhash;
	}

	public function save( int $post_id, array $source_urls, array $social_titles, ?array $social_posts = null ): void {
		$urls   = $this->urls->list_from( $source_urls );
		$titles = $this->urls->titles_from( $social_titles );

		update_post_meta( $post_id, ExtraFieldsKeys::SOURCE_URLS, $urls );
		delete_post_meta( $post_id, ExtraFieldsKeys::SOURCE_URL );
		foreach ( $urls as $url ) {
			add_post_meta( $post_id, ExtraFieldsKeys::SOURCE_URL, $url, false );
		}
		update_post_meta( $post_id, ExtraFieldsKeys::SOCIAL_TITLES, $titles );
		if ( $social_posts !== null ) {
			$map = $this->normalize_social_posts( $social_posts );
			update_post_meta( $post_id, ExtraFieldsKeys::SOCIAL_POSTS, $map );
			delete_post_meta( $post_id, ExtraFieldsKeys::SOCIAL_POSTED );
			foreach ( array_keys( $map ) as $network ) {
				add_post_meta( $post_id, ExtraFieldsKeys::SOCIAL_POSTED, $network, false );
			}
		}
	}

	public function save_lead_simhash( int $post_id, string $hash ): void {
		$hash = strtolower( trim( $hash ) );
		if ( $hash === '' ) {
			delete_post_meta( $post_id, ExtraFieldsKeys::LEAD_SIMHASH );
			return;
		}
		update_post_meta( $post_id, ExtraFieldsKeys::LEAD_SIMHASH, $hash );
	}

	public function get( int $post_id ): array {
		$stored_urls = get_post_meta( $post_id, ExtraFieldsKeys::SOURCE_URLS, true );
		$urls        = $this->urls->list_from( $stored_urls );
		if ( $urls === [] ) {
			$singles = get_post_meta( $post_id, ExtraFieldsKeys::SOURCE_URL, false );
			$urls    = $this->urls->list_from( is_array( $singles ) ? $singles : [] );
		}

		$titles = $this->urls->titles_from( get_post_meta( $post_id, ExtraFieldsKeys::SOCIAL_TITLES, true ) );

		return [
			'source_urls'   => $urls,
			'social_titles' => $titles,
			'social_posts'  => $this->normalize_social_posts( get_post_meta( $post_id, ExtraFieldsKeys::SOCIAL_POSTS, true ) ),
			'lead_simhash'  => $this->stored_hash( $post_id ),
		];
	}

	public function find_by_source_urls( array $urls ): array {
		$needles = $this->urls->list_from( $urls );
		if ( $needles === [] ) {
			return [];
		}

		$meta_query = [ 'relation' => 'OR' ];
		foreach ( $needles as $needle ) {
			$meta_query[] = [
				'key'     => ExtraFieldsKeys::SOURCE_URL,
				'value'   => $needle,
				'compare' => '=',
			];
		}

		$query = new \WP_Query(
			[
				'post_type'              => 'post',
				'post_status'            => [ 'draft', 'pending', 'private', 'publish', 'future' ],
				'posts_per_page'         => 50,
				'meta_query'             => $meta_query,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			]
		);

		$cards = [];
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$fields  = $this->get( (int) $post->ID );
			$matched = array_values( array_intersect( $fields['source_urls'], $needles ) );
			$cards[] = $this->card( $post, $fields, $matched );
		}

		return $cards;
	}

	public function find_by_social( string $network, bool $posted, int $limit, ?string $language ): array {
		$network = sanitize_key( $network );
		if ( $network === '' ) {
			throw new \InvalidArgumentException( 'network is required.' );
		}
		$limit = max( 1, min( 50, $limit ) );

		$posted_ids = get_posts(
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
		if ( ! is_array( $posted_ids ) ) {
			$posted_ids = [];
		}
		$posted_ids = array_map( 'intval', $posted_ids );

		$args = [
			'post_type'              => 'post',
			'post_status'            => [ 'draft', 'pending', 'private', 'publish', 'future' ],
			'posts_per_page'         => ( $language !== null && $language !== '' ) ? min( 100, $limit * 5 ) : $limit,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		];
		if ( $posted ) {
			if ( $posted_ids === [] ) {
				return [];
			}
			$args['post__in'] = $posted_ids;
		} else {
			if ( $posted_ids !== [] ) {
				$args['post__not_in'] = $posted_ids;
			}
		}

		$query = new \WP_Query( $args );
		$cards = [];
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			if ( $language !== null && $language !== '' ) {
				$lang = $this->language( (int) $post->ID );
				if ( $lang !== $language ) {
					continue;
				}
			}
			$fields = $this->get( (int) $post->ID );
			$has    = isset( $fields['social_posts'][ $network ] ) && $fields['social_posts'][ $network ] !== '';
			if ( $has !== $posted ) {
				continue;
			}
			$cards[] = $this->card( $post, $fields, [] );
			if ( count( $cards ) >= $limit ) {
				break;
			}
		}

		return $cards;
	}

	public function card_for_id( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		return $this->card( $post, $this->get( $post_id ), [] );
	}

	public function find_by_lead_simhash( string $hash, int $max_hamming, bool $persist_empty ): array {
		$statuses = [ 'draft', 'pending', 'private', 'publish', 'future' ];
		$seen     = [];
		$hits     = [];

		foreach ( $this->query_posts( $statuses, self::SCAN_WITH_HASH, true ) as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$id = (int) $post->ID;
			$seen[ $id ] = true;
			$stored = $this->stored_hash( $id );
			$post_hash = $stored;
			if ( $post_hash === '' ) {
				$post_hash = $this->hash_post( $post );
				if ( $persist_empty && $post_hash !== '' ) {
					$this->save_lead_simhash( $id, $post_hash );
				}
			}
			if ( $post_hash === '' ) {
				continue;
			}
			$dist = $this->simhash->hamming( $hash, $post_hash );
			if ( $dist > $max_hamming ) {
				continue;
			}
			$card = $this->card( $post, $this->get( $id ), [] );
			$card['lead_simhash'] = $post_hash;
			$card['hamming']      = $dist;
			$hits[] = $card;
		}

		foreach ( $this->query_posts( $statuses, self::SCAN_EMPTY, false ) as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$id = (int) $post->ID;
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$post_hash = $this->hash_post( $post );
			if ( $persist_empty && $post_hash !== '' ) {
				$this->save_lead_simhash( $id, $post_hash );
			}
			if ( $post_hash === '' ) {
				continue;
			}
			$dist = $this->simhash->hamming( $hash, $post_hash );
			if ( $dist > $max_hamming ) {
				continue;
			}
			$fields = $this->get( $id );
			$fields['lead_simhash'] = $post_hash;
			$card = $this->card( $post, $fields, [] );
			$card['lead_simhash'] = $post_hash;
			$card['hamming']      = $dist;
			$hits[] = $card;
		}

		usort(
			$hits,
			static function ( array $a, array $b ): int {
				$ha = isset( $a['hamming'] ) ? (int) $a['hamming'] : 64;
				$hb = isset( $b['hamming'] ) ? (int) $b['hamming'] : 64;
				if ( $ha === $hb ) {
					return (int) $b['post_id'] - (int) $a['post_id'];
				}
				return $ha - $hb;
			}
		);

		return $hits;
	}

	/**
	 * @param string[] $statuses
	 * @return \WP_Post[]
	 */
	private function query_posts( array $statuses, int $limit, bool $with_hash ): array {
		$meta_query = $with_hash
			? [
				[
					'key'     => ExtraFieldsKeys::LEAD_SIMHASH,
					'value'   => '',
					'compare' => '!=',
				],
			]
			: [
				'relation' => 'OR',
				[
					'key'     => ExtraFieldsKeys::LEAD_SIMHASH,
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => ExtraFieldsKeys::LEAD_SIMHASH,
					'value'   => '',
					'compare' => '=',
				],
			];
		$query = new \WP_Query(
			[
				'post_type'              => 'post',
				'post_status'            => $statuses,
				'posts_per_page'         => $limit,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'meta_query'             => $meta_query,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			]
		);
		$posts = [];
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$posts[] = $post;
			}
		}
		return $posts;
	}

	private function hash_post( \WP_Post $post ): string {
		return $this->simhash->compute( (string) $post->post_title, (string) $post->post_content );
	}

	private function stored_hash( int $post_id ): string {
		$raw = get_post_meta( $post_id, ExtraFieldsKeys::LEAD_SIMHASH, true );
		$hash = strtolower( trim( is_string( $raw ) ? $raw : '' ) );
		if ( $hash !== '' && preg_match( LeadSimhash::HEX_PATTERN, $hash ) ) {
			return $hash;
		}
		return '';
	}

	/**
	 * @param string[]             $matched_urls
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function card( \WP_Post $post, array $fields, array $matched_urls ): array {
		$posted_on = array_keys( $fields['social_posts'] );
		$cats     = [];
		foreach ( get_the_category( (int) $post->ID ) ?: [] as $cat ) {
			if ( $cat instanceof \WP_Term ) {
				$cats[] = [
					'id'   => (int) $cat->term_id,
					'name' => (string) $cat->name,
					'slug' => (string) $cat->slug,
				];
			}
		}
		$thumb = '';
		$thumb_id = get_post_thumbnail_id( (int) $post->ID );
		if ( $thumb_id ) {
			$orig = wp_get_attachment_url( (int) $thumb_id );
			if ( is_string( $orig ) && $orig !== '' ) {
				$thumb = $orig;
			}
		}
		$author = '';
		$user   = get_userdata( (int) $post->post_author );
		if ( $user ) {
			$author = (string) $user->display_name;
			if ( $author === '' ) {
				$author = (string) $user->user_login;
			}
		}
		return [
			'post_id'      => (int) $post->ID,
			'title'        => (string) $post->post_title,
			'excerpt'      => $this->short_excerpt( $post ),
			'status'       => (string) $post->post_status,
			'date'         => (string) $post->post_date,
			'author'       => $author,
			'permalink'    => get_permalink( (int) $post->ID ) ?: null,
			'language'     => $this->language( (int) $post->ID ),
			'categories'   => $cats,
			'thumbnail'      => $thumb,
			'thumbnail_full' => $thumb,
			'source_urls'  => $fields['source_urls'],
			'social_titles'=> $fields['social_titles'],
			'social_posts' => $fields['social_posts'],
			'lead_simhash' => isset( $fields['lead_simhash'] ) ? (string) $fields['lead_simhash'] : '',
			'posted_on'    => $posted_on,
			'matched_urls' => $matched_urls,
			'edit_link'    => get_edit_post_link( (int) $post->ID, 'raw' ),
		];
	}

	/**
	 * @param mixed $raw
	 * @return array<string, string>
	 */
	private function normalize_social_posts( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $network => $url ) {
			$key = sanitize_key( (string) $network );
			if ( $key === '' ) {
				continue;
			}
			$link = esc_url_raw( trim( (string) $url ) );
			if ( $link !== '' ) {
				$out[ $key ] = $link;
			}
		}
		ksort( $out );
		return $out;
	}

	private function short_excerpt( \WP_Post $post ): string {
		$text = $post->post_excerpt !== '' ? $post->post_excerpt : wp_strip_all_tags( $post->post_content );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) ?: '' );
		if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > 220 ) {
			return mb_substr( $text, 0, 217 ) . '...';
		}
		if ( strlen( $text ) > 220 ) {
			return substr( $text, 0, 217 ) . '...';
		}
		return $text;
	}

	private function language( int $post_id ): ?string {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$slug = pll_get_post_language( $post_id, 'slug' );
			return is_string( $slug ) && $slug !== '' ? $slug : null;
		}
		return null;
	}
}
