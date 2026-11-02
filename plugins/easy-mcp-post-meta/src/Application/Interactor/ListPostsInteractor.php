<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Application\Interactor;

use EasyMCP\PostMeta\Domain\Exception\MetaException;
use EasyMCP\PostMeta\Domain\Logger\LoggerInterface;
use EasyMCP\PostMeta\Domain\Repository\PostListRepositoryInterface;

final class ListPostsInteractor {

	private PostListRepositoryInterface $list;
	private LoggerInterface $logger;

	public function __construct( PostListRepositoryInterface $list, LoggerInterface $logger ) {
		$this->list   = $list;
		$this->logger = $logger;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		try {
			$result = $this->list->list( $input );
			$this->logger->debug(
				'Listed posts for social helper.',
				[ 'count' => count( $result['posts'] ), 'page' => $result['page'] ]
			);
			return [
				'success'  => true,
				'error'    => null,
				'code'     => null,
				'posts'    => $result['posts'],
				'total'    => $result['total'],
				'page'     => $result['page'],
				'per_page' => $result['per_page'],
				'message'  => null,
			];
		} catch ( MetaException $e ) {
			return $this->fail( $e->getMessage(), $e->error_code() );
		} catch ( \Throwable $e ) {
			$this->logger->error( $e->getMessage() );
			return $this->fail( $e->getMessage(), 'list_failed' );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fail( string $message, string $code ): array {
		return [
			'success'  => false,
			'error'    => $message,
			'code'     => $code,
			'posts'    => [],
			'total'    => 0,
			'page'     => 1,
			'per_page' => 0,
			'message'  => null,
		];
	}
}
