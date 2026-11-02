<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Application\Interactor;

use EasyMCP\PostMeta\Domain\Logger\LoggerInterface;
use EasyMCP\PostMeta\Domain\Repository\PostListRepositoryInterface;

final class ListPostFiltersInteractor {

	private PostListRepositoryInterface $list;
	private LoggerInterface $logger;

	public function __construct( PostListRepositoryInterface $list, LoggerInterface $logger ) {
		$this->list   = $list;
		$this->logger = $logger;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function execute(): array {
		try {
			$data = $this->list->filters();
			$this->logger->debug( 'Listed post filters.' );
			return [
				'success'         => true,
				'error'           => null,
				'code'            => null,
				'languages'       => $data['languages'],
				'categories'      => $data['categories'],
				'networks'        => $data['networks'],
				'plugin_version' => isset( $data['plugin_version'] ) ? $data['plugin_version'] : '',
				'debug'          => isset( $data['debug'] ) && is_array( $data['debug'] ) ? $data['debug'] : null,
				'message'        => null,
			];
		} catch ( \Throwable $e ) {
			$this->logger->error( $e->getMessage() );
			return [
				'success'    => false,
				'error'      => $e->getMessage(),
				'code'       => 'filters_failed',
				'languages'      => [],
				'categories'     => [],
				'networks'       => [],
				'plugin_version' => defined( 'EMPM_VERSION' ) ? EMPM_VERSION : '',
				'message'        => null,
			];
		}
	}
}
