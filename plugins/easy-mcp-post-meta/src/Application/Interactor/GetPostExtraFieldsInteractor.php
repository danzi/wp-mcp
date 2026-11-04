<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Application\Interactor;

use EasyMCP\PostMeta\Domain\Exception\MetaException;
use EasyMCP\PostMeta\Domain\Logger\LoggerInterface;
use EasyMCP\PostMeta\Domain\Repository\ExtraFieldsRepositoryInterface;

final class GetPostExtraFieldsInteractor {

	private ExtraFieldsRepositoryInterface $fields;
	private LoggerInterface $logger;

	public function __construct( ExtraFieldsRepositoryInterface $fields, LoggerInterface $logger ) {
		$this->fields = $fields;
		$this->logger = $logger;
	}

	/**
	 * @param array{post_id?:int} $input
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		try {
			$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
			if ( $post_id <= 0 || get_post( $post_id ) === null ) {
				throw new MetaException( 'Post not found.', 'post_not_found' );
			}
			$card = $this->fields->card_for_id( $post_id );
			if ( $card === null ) {
				throw new MetaException( 'Post not found.', 'post_not_found' );
			}
			$this->logger->debug( 'Read extra fields.', [ 'post_id' => $post_id ] );
			return array_merge(
				[
					'success' => true,
					'error'   => null,
					'code'    => null,
				],
				$card
			);
		} catch ( MetaException $e ) {
			return $this->fail( $e->getMessage(), $e->error_code() );
		} catch ( \Throwable $e ) {
			$this->logger->error( $e->getMessage() );
			return $this->fail( $e->getMessage(), 'get_failed' );
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
		];
	}
}
