<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Application\Interactor;

use EasyMCP\ZipPostImport\Domain\Exception\ImportException;
use EasyMCP\ZipPostImport\Domain\Logger\LoggerInterface;
use EasyMCP\ZipPostImport\Domain\Repository\ExtraFieldsRepositoryInterface;
use EasyMCP\ZipPostImport\Domain\Repository\LanguageRepositoryInterface;
use EasyMCP\ZipPostImport\Domain\Repository\MediaRepositoryInterface;
use EasyMCP\ZipPostImport\Domain\Repository\PostRepositoryInterface;
use EasyMCP\ZipPostImport\Domain\Repository\SeoRepositoryInterface;
use EasyMCP\ZipPostImport\Domain\Repository\TermRepositoryInterface;
use EasyMCP\ZipPostImport\Domain\Repository\ZipArchiveRepositoryInterface;

final class ImportPostFromZipInteractor {

	private ZipArchiveRepositoryInterface $zip;
	private ParseZipManifestInteractor $parser;
	private ReplaceContentImagesInteractor $images;
	private SetFeaturedImageInteractor $featured;
	private PostRepositoryInterface $posts;
	private MediaRepositoryInterface $media;
	private TermRepositoryInterface $terms;
	private LanguageRepositoryInterface $languages;
	private SeoRepositoryInterface $seo;
	private ExtraFieldsRepositoryInterface $extra;
	private LoggerInterface $logger;

	public function __construct(
		ZipArchiveRepositoryInterface $zip,
		ParseZipManifestInteractor $parser,
		ReplaceContentImagesInteractor $images,
		SetFeaturedImageInteractor $featured,
		PostRepositoryInterface $posts,
		MediaRepositoryInterface $media,
		TermRepositoryInterface $terms,
		LanguageRepositoryInterface $languages,
		SeoRepositoryInterface $seo,
		ExtraFieldsRepositoryInterface $extra,
		LoggerInterface $logger
	) {
		$this->zip       = $zip;
		$this->parser    = $parser;
		$this->images    = $images;
		$this->featured  = $featured;
		$this->posts     = $posts;
		$this->media     = $media;
		$this->terms     = $terms;
		$this->languages = $languages;
		$this->seo       = $seo;
		$this->extra     = $extra;
		$this->logger    = $logger;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function execute_from_path( string $zip_path ): array {
		$extracted = $this->zip->extract( $zip_path );

		try {
			$result = $this->import_extracted( $extracted );
			try {
				$this->zip->delete_source( $zip_path );
			} catch ( \Throwable $e ) {
				$this->logger->error( 'Could not delete source ZIP after import.', [
					'zip'   => $zip_path,
					'error' => $e->getMessage(),
				] );
			}
			return $result;
		} finally {
			$this->zip->cleanup( $extracted );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function execute_from_url( string $url ): array {
		$tmp = $this->zip->download_from_url( $url );

		try {
			return $this->execute_from_path( $tmp );
		} finally {
			if ( is_string( $tmp ) && file_exists( $tmp ) ) {
				unlink( $tmp );
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function import_extracted( string $extracted ): array {
		$manifest = $this->parser->execute( $extracted );
		$action   = $manifest['post_id'] > 0 ? 'update' : 'insert';

		$this->logger->debug( 'Starting ZIP import.', [
			'action'  => $action,
			'post_id' => $manifest['post_id'],
		] );

		if ( $action === 'update' ) {
			if ( ! $this->posts->exists( $manifest['post_id'] ) ) {
				throw new ImportException(
					sprintf( 'Post ID %d does not exist. Remove post_id to insert a new post.', $manifest['post_id'] ),
					'post_not_found'
				);
			}
			if ( ! current_user_can( 'edit_post', $manifest['post_id'] ) ) {
				throw new ImportException( 'You cannot edit this post.', 'forbidden' );
			}
			$post_id = $manifest['post_id'];
		} else {
			if ( ! current_user_can( 'edit_posts' ) ) {
				throw new ImportException( 'You do not have permission to create posts.', 'forbidden' );
			}
			if ( $manifest['title'] === '' ) {
				throw new ImportException( 'Title is required for insert.', 'missing_title' );
			}
			$post_id = $this->create_stub( $manifest );
		}

		$images_result = $this->images->execute(
			$manifest['content'],
			$manifest['images_dir'],
			$post_id,
			$manifest['base_dir']
		);
		$content = $images_result['content'];

		$language = $this->apply_language( $post_id, $manifest );

		$lang_slug    = $language ?: '';
		$category_ids = $this->terms->resolve_category_ids( $manifest['category_ids'], $manifest['category_names'], $lang_slug );

		$post_data = [
			'post_content' => $content,
		];
		if ( $manifest['title'] !== '' ) {
			$post_data['post_title'] = $manifest['title'];
		}
		if ( $manifest['excerpt'] !== '' ) {
			$post_data['post_excerpt'] = $manifest['excerpt'];
		}
		if ( $manifest['slug'] !== '' ) {
			$post_data['post_name'] = $manifest['slug'];
		}

		$status = $this->normalize_status( $manifest['status'], $action );
		if ( $status !== '' ) {
			$post_data['post_status'] = $status;
		}
		if ( $action === 'insert' ) {
			$post_data['post_type'] = $this->normalize_post_type( $manifest['post_type'] );
		}
		if ( ! empty( $category_ids ) ) {
			$post_data['post_category'] = $category_ids;
		}

		$this->posts->update( $post_id, $post_data );
		$this->terms->set_categories( $post_id, $category_ids );
		$assigned_tags = $this->terms->set_tags( $post_id, $manifest['tags'], $lang_slug );
		$this->logger->debug( 'Assigned tags.', [ 'tags' => $assigned_tags ] );

		$this->apply_seo( $post_id, $manifest );
		$this->apply_extra_fields( $post_id, $manifest );

		$thumbnail = $this->featured->execute(
			$post_id,
			$manifest['extracted_dir'],
			$manifest['images_dir'],
			$manifest['thumbnail'],
			$images_result['media_ids_by_name']
		);

		if ( $thumbnail !== null && $this->media->get_featured_image_id( $post_id ) !== (int) $thumbnail['attachment_id'] ) {
			throw new ImportException(
				'Thumbnail file exists but featured image was not assigned to the post.',
				'featured_image_not_assigned'
			);
		}

		$edit_link = $this->posts->get_edit_link( $post_id );
		$warning   = $thumbnail === null ? 'No thumbnail in ZIP/JSON; featured image skipped.' : null;
		$message   = sprintf(
			'Post %d %s from ZIP%s.',
			$post_id,
			$action === 'update' ? 'updated' : 'created',
			$thumbnail !== null ? ' with featured image ' . $thumbnail['attachment_id'] : ''
		);

		$this->logger->info( $message, [ 'post_id' => $post_id, 'action' => $action ] );

		$media_ids = [];
		foreach ( $images_result['images'] as $image ) {
			$media_ids[] = (int) $image['attachment_id'];
		}
		if ( $thumbnail !== null && ! in_array( (int) $thumbnail['attachment_id'], $media_ids, true ) ) {
			$media_ids[] = (int) $thumbnail['attachment_id'];
		}

		return [
			'success'            => true,
			'error'              => null,
			'code'               => null,
			'post_id'            => $post_id,
			'action'             => $action,
			'language'           => $language,
			'status'             => $status !== '' ? $status : null,
			'edit_link'          => $edit_link,
			'featured_image_id'  => $thumbnail !== null ? (int) $thumbnail['attachment_id'] : null,
			'warning'            => $warning,
			'tags'               => $assigned_tags,
			'images'             => $images_result['images'],
			'thumbnail'          => $thumbnail,
			'uploaded_media_ids' => array_values( array_unique( $media_ids ) ),
			'message'            => $message,
		];
	}

	/**
	 * @param array<string, mixed> $manifest
	 */
	private function apply_language( int $post_id, array $manifest ): ?string {
		$language = (string) $manifest['language'];
		if ( $language === '' && empty( $manifest['require_polylang'] ) ) {
			return null;
		}

		if ( ! $this->languages->is_available() ) {
			if ( ! empty( $manifest['require_polylang'] ) ) {
				throw new ImportException( 'JSON requires Polylang, but the plugin is not active.', 'polylang_required' );
			}
			$this->logger->info( 'Language skipped because Polylang is not active.', [
				'language' => $language,
			] );
			return null;
		}

		if ( $language === '' ) {
			return null;
		}

		$this->languages->set_post_language( $post_id, $language );
		return $language;
	}

	/**
	 * @param array<string, mixed> $manifest
	 */
	private function apply_seo( int $post_id, array $manifest ): void {
		$has_fields = ( $manifest['yoast_title'] !== null && $manifest['yoast_title'] !== '' )
			|| ( $manifest['yoast_description'] !== null && $manifest['yoast_description'] !== '' )
			|| ( $manifest['yoast_focus_keyword'] !== null && $manifest['yoast_focus_keyword'] !== '' );

		if ( ! $has_fields && empty( $manifest['require_yoast'] ) ) {
			return;
		}

		if ( ! $this->seo->is_available() ) {
			if ( ! empty( $manifest['require_yoast'] ) ) {
				throw new ImportException( 'JSON requires Yoast SEO, but the plugin is not active.', 'yoast_required' );
			}
			$this->logger->debug( 'Yoast SEO is not active, skip SEO fields' );
			return;
		}

		if ( ! $has_fields ) {
			return;
		}

		$this->seo->save(
			$post_id,
			$manifest['yoast_title'],
			$manifest['yoast_description'],
			$manifest['yoast_focus_keyword']
		);
	}

	/**
	 * @param array<string, mixed> $manifest
	 */
	private function apply_extra_fields( int $post_id, array $manifest ): void {
		if ( $manifest['source_urls'] === null && $manifest['social_titles'] === null && $manifest['social_posts'] === null ) {
			return;
		}
		if ( ! defined( 'EMPM_VERSION' ) ) {
			$this->logger->debug( 'Easy MCP Post Extra Fields is not active, skip source_urls/social_titles/social_posts.' );
			return;
		}
		$current = $this->extra->get( $post_id );
		$urls    = $manifest['source_urls'] !== null ? $manifest['source_urls'] : $current['source_urls'];
		$titles  = $manifest['social_titles'] !== null ? $manifest['social_titles'] : $current['social_titles'];
		$posts   = $manifest['social_posts'] !== null ? $manifest['social_posts'] : null;
		$this->extra->save( $post_id, $urls, $titles, $posts );
		$this->logger->debug( 'Saved extra fields.', [ 'post_id' => $post_id ] );
	}

	/**
	 * @param array<string, mixed> $manifest
	 */
	private function create_stub( array $manifest ): int {
		$status = $this->normalize_status( $manifest['status'], 'insert' );
		$data   = [
			'post_title'   => $manifest['title'],
			'post_content' => '',
			'post_status'  => $status !== '' ? $status : 'draft',
			'post_type'    => $this->normalize_post_type( $manifest['post_type'] ),
		];
		if ( $manifest['slug'] !== '' ) {
			$data['post_name'] = $manifest['slug'];
		}

		return $this->posts->insert( $data );
	}

	private function normalize_status( string $status, string $action ): string {
		$allowed = [ 'draft', 'publish', 'pending', 'private', 'future' ];
		if ( $status === '' ) {
			return $action === 'insert' ? 'draft' : '';
		}
		if ( ! in_array( $status, $allowed, true ) ) {
			return $action === 'insert' ? 'draft' : '';
		}

		return $status;
	}

	private function normalize_post_type( string $post_type ): string {
		if ( $post_type === '' ) {
			$post_type = 'post';
		}
		if ( ! post_type_exists( $post_type ) ) {
			throw new ImportException( sprintf( 'Post type "%s" does not exist.', $post_type ), 'invalid_post_type' );
		}

		return $post_type;
	}
}
