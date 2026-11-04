<?php
declare(strict_types=1);

namespace EasyMCP\PolylangExtender\Application\Interactor;

use EasyMCP\PolylangExtender\Domain\Repository\LanguageRepositoryInterface;

/**
 * Creates a post and immediately assigns a Polylang language.
 * Optionally sets categories, tags and Yoast SEO meta in the same call.
 */
final class CreatePostWithLanguageInteractor {

	private LanguageRepositoryInterface $repo;

	public function __construct( LanguageRepositoryInterface $repo ) {
		$this->repo = $repo;
	}

	/**
	 * @param array{
	 *   title: string,
	 *   content?: string,
	 *   status?: string,
	 *   language: string,
	 *   excerpt?: string,
	 *   slug?: string,
	 *   post_type?: string,
	 *   category_ids?: int[],
	 *   category_names?: string[],
	 *   tags?: string[],
	 *   featured_image_id?: int,
	 *   yoast_title?: string,
	 *   yoast_description?: string,
	 *   yoast_focus_keyword?: string,
	 *   source_urls?: string[],
	 *   social_titles?: string[],
	 *   social_posts?: array<string, string>,
	 *   lead_simhash?: string,
	 *   meta?: array<string, mixed>
	 * } $input
	 * @return array{success:bool,post_id?:int,language?:string,edit_link?:string,message?:string,error?:string,code?:string}
	 */
	public function execute( array $input ): array {
		$title    = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
		$content  = isset( $input['content'] ) ? (string) $input['content'] : '';
		$status   = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'draft';
		$language = isset( $input['language'] ) ? sanitize_key( (string) $input['language'] ) : '';
		$excerpt  = isset( $input['excerpt'] ) ? sanitize_textarea_field( (string) $input['excerpt'] ) : '';
		$slug     = isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '';
		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : 'post';

		if ( $title === '' ) {
			return [
				'success' => false,
				'error'   => 'Title is required.',
				'code'    => 'missing_title',
			];
		}

		if ( $language === '' ) {
			return [
				'success' => false,
				'error'   => 'Language slug is required (e.g. de, en, ru).',
				'code'    => 'missing_language',
			];
		}

		// Validate language early so we don't create an orphan post.
		$valid   = $this->repo->list_languages();
		$slugs   = array_column( $valid, 'slug' );
		if ( ! in_array( $language, $slugs, true ) ) {
			return [
				'success' => false,
				'error'   => sprintf(
					'Language "%s" is not configured. Available: %s',
					$language,
					implode( ', ', $slugs )
				),
				'code'    => 'invalid_language',
			];
		}

		$allowed_statuses = [ 'draft', 'publish', 'pending', 'private', 'future' ];
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'draft';
		}

		if ( ! post_type_exists( $post_type ) ) {
			return [
				'success' => false,
				'error'   => sprintf( 'Post type "%s" does not exist.', $post_type ),
				'code'    => 'invalid_post_type',
			];
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return [
				'success' => false,
				'error'   => 'You do not have permission to create posts.',
				'code'    => 'forbidden',
			];
		}

		$lead_hash = $this->cabinet_lead_simhash( $input );

		$postarr = [
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'post_type'    => $post_type,
			'post_excerpt' => $excerpt,
		];
		if ( $lead_hash !== '' ) {
			$postarr['meta_input'] = [
				'_emp_lead_simhash' => $lead_hash,
			];
		}

		if ( $slug !== '' ) {
			$postarr['post_name'] = $slug;
		}

		// Categories by ID
		$category_ids = [];
		if ( ! empty( $input['category_ids'] ) && is_array( $input['category_ids'] ) ) {
			foreach ( $input['category_ids'] as $cid ) {
				$cid = (int) $cid;
				if ( $cid > 0 ) {
					$category_ids[] = $cid;
				}
			}
		}

		// Categories by name (create if missing)
		if ( ! empty( $input['category_names'] ) && is_array( $input['category_names'] ) ) {
			foreach ( $input['category_names'] as $name ) {
				$name = sanitize_text_field( (string) $name );
				if ( $name === '' ) {
					continue;
				}
				$term = term_exists( $name, 'category' );
				if ( ! $term ) {
					$term = wp_insert_term( $name, 'category' );
				}
				if ( ! is_wp_error( $term ) ) {
					$category_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
				}
			}
		}

		if ( ! empty( $category_ids ) ) {
			$postarr['post_category'] = array_values( array_unique( $category_ids ) );
		}

		$post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			return [
				'success' => false,
				'error'   => $post_id->get_error_message(),
				'code'    => $post_id->get_error_code(),
			];
		}

		$post_id = (int) $post_id;

		$lang_result = $this->repo->set_post_language( $post_id, $language );

		if ( is_wp_error( $lang_result ) ) {
			return [
				'success'   => false,
				'post_id'   => $post_id,
				'error'     => 'Post created, but language could not be set: ' . $lang_result->get_error_message(),
				'code'      => $lang_result->get_error_code(),
				'edit_link' => get_edit_post_link( $post_id, 'raw' ),
			];
		}

		if ( ! empty( $input['tags'] ) && is_array( $input['tags'] ) ) {
			$tags = array_map( static function ( $t ) {
				return sanitize_text_field( (string) $t );
			}, $input['tags'] );
			$tags = array_filter( $tags );
			if ( ! empty( $tags ) ) {
				$result = wp_set_post_tags( $post_id, $tags, false );
				if ( is_wp_error( $result ) ) {
					return [
						'success'   => false,
						'post_id'   => $post_id,
						'language'  => $language,
						'error'     => 'Post created, but tags could not be set: ' . $result->get_error_message(),
						'code'      => 'tags_failed',
						'edit_link' => get_edit_post_link( $post_id, 'raw' ),
					];
				}
			}
		}

		if ( ! empty( $input['featured_image_id'] ) ) {
			$thumb_id = (int) $input['featured_image_id'];
			if ( $thumb_id > 0 && get_post( $thumb_id ) ) {
				set_post_thumbnail( $post_id, $thumb_id );
			}
		}

		if ( ! empty( $input['yoast_title'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( (string) $input['yoast_title'] ) );
		}
		if ( ! empty( $input['yoast_description'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_text_field( (string) $input['yoast_description'] ) );
		}
		if ( ! empty( $input['yoast_focus_keyword'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', sanitize_text_field( (string) $input['yoast_focus_keyword'] ) );
		}

		$has_extra = array_key_exists( 'source_urls', $input )
			|| array_key_exists( 'social_titles', $input )
			|| array_key_exists( 'social_posts', $input );
		if ( defined( 'EMPM_VERSION' ) && $has_extra ) {
			$this->save_extra_fields(
				$post_id,
				isset( $input['source_urls'] ) && is_array( $input['source_urls'] ) ? $input['source_urls'] : [],
				isset( $input['social_titles'] ) && is_array( $input['social_titles'] ) ? $input['social_titles'] : [],
				isset( $input['social_posts'] ) && is_array( $input['social_posts'] ) ? $input['social_posts'] : []
			);
		}

		if ( ! empty( $input['meta'] ) && is_array( $input['meta'] ) ) {
			foreach ( $input['meta'] as $key => $value ) {
				$key = sanitize_key( (string) $key );
				if ( $key === '' ) {
					continue;
				}
				if ( strpos( $key, '_yoast_wpseo_') === 0 ) {
					continue;
				}
				update_post_meta( $post_id, $key, $value );
			}
		}

		$edit_link = get_edit_post_link( $post_id, 'raw' );

		return [
			'success'   => true,
			'post_id'   => $post_id,
			'language'  => $language,
			'status'    => $status,
			'edit_link' => $edit_link ?: null,
			'message'   => sprintf(
				'Post %d created as "%s" with language "%s".',
				$post_id,
				$status,
				$language
			),
		];
	}

	/**
	 * Cabinet hash if Extra Fields is active and valid. Empty = omit; WP computes on save.
	 * Invalid value is ignored — create still succeeds.
	 *
	 * @param array<string, mixed> $input
	 */
	private function cabinet_lead_simhash( array $input ): string {
		if ( ! defined( 'EMPM_VERSION' ) || ! array_key_exists( 'lead_simhash', $input ) ) {
			return '';
		}
		$hash = strtolower( trim( (string) $input['lead_simhash'] ) );
		if ( $hash === '' || ! preg_match( '/^[0-9a-f]{16}$/', $hash ) ) {
			return '';
		}
		return $hash;
	}

	/**
	 * Same keys as Easy MCP Post Extra Fields.
	 *
	 * @param mixed[] $source_urls
	 * @param mixed[] $social_titles
	 * @param mixed[] $social_posts
	 */
	private function save_extra_fields( int $post_id, array $source_urls, array $social_titles, array $social_posts = [] ): void {
		$urls   = [];
		$titles = [];
		foreach ( $source_urls as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$url = esc_url_raw( trim( (string) $item ) );
			if ( $url !== '' ) {
				$urls[] = untrailingslashit( $url );
			}
		}
		$urls = array_values( array_unique( $urls ) );
		foreach ( $social_titles as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$title = sanitize_text_field( (string) $item );
			if ( $title !== '' ) {
				$titles[] = $title;
			}
		}
		$titles = array_values( array_unique( $titles ) );

		update_post_meta( $post_id, '_emp_source_urls', $urls );
		delete_post_meta( $post_id, '_emp_source_url' );
		foreach ( $urls as $url ) {
			add_post_meta( $post_id, '_emp_source_url', $url, false );
		}
		update_post_meta( $post_id, '_emp_social_titles', $titles );
		$posted = [];
		foreach ( $social_posts as $network => $url ) {
			$key = sanitize_key( (string) $network );
			if ( $key === '' || ! is_scalar( $url ) ) {
				continue;
			}
			$link = esc_url_raw( trim( (string) $url ) );
			if ( $link !== '' ) {
				$posted[ $key ] = $link;
			}
		}
		ksort( $posted );
		update_post_meta( $post_id, '_emp_social_posts', $posted );
		delete_post_meta( $post_id, '_emp_social_posted' );
		foreach ( array_keys( $posted ) as $network ) {
			add_post_meta( $post_id, '_emp_social_posted', $network, false );
		}
	}
}
