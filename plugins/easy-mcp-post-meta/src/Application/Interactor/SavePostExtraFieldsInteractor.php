<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Application\Interactor;

use EasyMCP\PostMeta\Domain\Exception\MetaException;
use EasyMCP\PostMeta\Domain\Logger\LoggerInterface;
use EasyMCP\PostMeta\Domain\Repository\ExtraFieldsRepositoryInterface;

final class SavePostExtraFieldsInteractor {

	private ExtraFieldsRepositoryInterface $fields;
	private PersistLeadSimhashInteractor $persist;
	private LoggerInterface $logger;

	public function __construct(
		ExtraFieldsRepositoryInterface $fields,
		PersistLeadSimhashInteractor $persist,
		LoggerInterface $logger
	) {
		$this->fields  = $fields;
		$this->persist = $persist;
		$this->logger  = $logger;
	}

	/**
	 * @param array{post_id?:int,source_urls?:string[],social_titles?:string[]} $input
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		try {
			$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
			if ( $post_id <= 0 || get_post( $post_id ) === null ) {
				throw new MetaException( 'Post not found.', 'post_not_found' );
			}
			$current = $this->fields->get( $post_id );
			$urls    = array_key_exists( 'source_urls', $input ) ? (array) $input['source_urls'] : $current['source_urls'];
			$titles  = array_key_exists( 'social_titles', $input ) ? (array) $input['social_titles'] : $current['social_titles'];
			$posts = null;
			if ( array_key_exists( 'social_posts', $input ) && is_array( $input['social_posts'] ) ) {
				$posts = $current['social_posts'];
				foreach ( $input['social_posts'] as $network => $url ) {
					$key = sanitize_key( (string) $network );
					if ( $key === '' ) {
						continue;
					}
					$link = trim( (string) $url );
					if ( $link === '' ) {
						unset( $posts[ $key ] );
					} else {
						$posts[ $key ] = $link;
					}
				}
			}
			$this->fields->save( $post_id, $urls, $titles, $posts );
			$stored = isset( $current['lead_simhash'] ) ? (string) $current['lead_simhash'] : '';
			if ( $stored === '' && array_key_exists( 'lead_simhash', $input ) ) {
				$hash = strtolower( trim( (string) $input['lead_simhash'] ) );
				if ( $hash !== '' && ! preg_match( '/^[0-9a-f]{16}$/', $hash ) ) {
					throw new MetaException( 'lead_simhash must be 16 lowercase hex characters.', 'invalid_lead_simhash' );
				}
				if ( $hash !== '' ) {
					$this->fields->save_lead_simhash( $post_id, $hash );
				}
			}
			$this->persist->fill_if_empty( $post_id );
			$saved = $this->fields->get( $post_id );
			$this->logger->info( 'Saved extra fields.', [ 'post_id' => $post_id ] );
			return [
				'success'       => true,
				'error'         => null,
				'code'          => null,
				'post_id'       => $post_id,
				'source_urls'   => $saved['source_urls'],
				'social_titles' => $saved['social_titles'],
				'social_posts'  => $saved['social_posts'],
				'lead_simhash'  => $saved['lead_simhash'],
				'message'       => 'Extra fields saved.',
			];
		} catch ( MetaException $e ) {
			return $this->fail( $e->getMessage(), $e->error_code() );
		} catch ( \Throwable $e ) {
			$this->logger->error( $e->getMessage() );
			return $this->fail( $e->getMessage(), 'save_failed' );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fail( string $message, string $code ): array {
		return [
			'success'       => false,
			'error'         => $message,
			'code'          => $code,
			'post_id'       => null,
			'source_urls'   => [],
			'social_titles' => [],
			'social_posts'  => [],
			'lead_simhash'  => '',
			'message'       => null,
		];
	}
}
