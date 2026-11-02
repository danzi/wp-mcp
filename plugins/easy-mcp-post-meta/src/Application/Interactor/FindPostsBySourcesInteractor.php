<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Application\Interactor;

use EasyMCP\PostMeta\Domain\Exception\MetaException;
use EasyMCP\PostMeta\Domain\Logger\LoggerInterface;
use EasyMCP\PostMeta\Domain\Repository\ExtraFieldsRepositoryInterface;
use EasyMCP\PostMeta\Infrastructure\Url\SourceUrlNormalizer;

final class FindPostsBySourcesInteractor {

	private ExtraFieldsRepositoryInterface $fields;
	private SourceUrlNormalizer $urls;
	private LoggerInterface $logger;

	public function __construct( ExtraFieldsRepositoryInterface $fields, SourceUrlNormalizer $urls, LoggerInterface $logger ) {
		$this->fields = $fields;
		$this->urls   = $urls;
		$this->logger = $logger;
	}

	/**
	 * @param array{urls?:string[],source_urls?:string[]} $input
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		try {
			$raw = [];
			if ( isset( $input['urls'] ) && is_array( $input['urls'] ) ) {
				$raw = $input['urls'];
			} elseif ( isset( $input['source_urls'] ) && is_array( $input['source_urls'] ) ) {
				$raw = $input['source_urls'];
			}
			$needles = $this->urls->list_from( $raw );
			if ( $needles === [] ) {
				throw new MetaException( 'Pass a non-empty urls array.', 'urls_required' );
			}
			if ( count( $needles ) > 30 ) {
				$needles = array_slice( $needles, 0, 30 );
			}
			$posts = $this->fields->find_by_source_urls( $needles );
			$this->logger->debug( 'Source search.', [ 'needles' => count( $needles ), 'hits' => count( $posts ) ] );
			return [
				'success'     => true,
				'error'       => null,
				'code'        => null,
				'queried_urls'=> $needles,
				'count'       => count( $posts ),
				'posts'       => $posts,
				'message'     => count( $posts ) === 0 ? 'No posts use these source URLs.' : null,
			];
		} catch ( MetaException $e ) {
			return $this->fail( $e->getMessage(), $e->error_code() );
		} catch ( \Throwable $e ) {
			$this->logger->error( $e->getMessage() );
			return $this->fail( $e->getMessage(), 'search_failed' );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fail( string $message, string $code ): array {
		return [
			'success'      => false,
			'error'        => $message,
			'code'         => $code,
			'queried_urls' => [],
			'count'        => 0,
			'posts'        => [],
			'message'      => null,
		];
	}
}
