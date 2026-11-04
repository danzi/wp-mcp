<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Application\Interactor;

use EasyMCP\PostMeta\Domain\Exception\MetaException;
use EasyMCP\PostMeta\Domain\LeadSimhash;
use EasyMCP\PostMeta\Domain\Logger\LoggerInterface;
use EasyMCP\PostMeta\Domain\Repository\ExtraFieldsRepositoryInterface;

final class FindPostsByLeadSimhashInteractor {

	private ExtraFieldsRepositoryInterface $fields;
	private LeadSimhash $simhash;
	private LoggerInterface $logger;

	public function __construct(
		ExtraFieldsRepositoryInterface $fields,
		LeadSimhash $simhash,
		LoggerInterface $logger
	) {
		$this->fields  = $fields;
		$this->simhash = $simhash;
		$this->logger  = $logger;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		try {
			$hash = '';
			if ( isset( $input['hash'] ) && trim( (string) $input['hash'] ) !== '' ) {
				$hash = $this->simhash->normalize_hex( $input['hash'] );
			} else {
				$title   = isset( $input['title'] ) ? (string) $input['title'] : '';
				$content = isset( $input['content'] ) ? (string) $input['content'] : '';
				if ( trim( $title ) === '' && trim( $content ) === '' ) {
					throw new MetaException( 'Pass hash or title/content.', 'hash_or_text_required' );
				}
				$hash = $this->simhash->compute( $title, $content );
			}

			$max = LeadSimhash::DEFAULT_HAMMING;
			if ( isset( $input['max_hamming'] ) && $input['max_hamming'] !== '' ) {
				$max = (int) $input['max_hamming'];
			}
			if ( $max < 0 ) {
				$max = 0;
			}
			if ( $max > LeadSimhash::BITS ) {
				$max = LeadSimhash::BITS;
			}

			$persist = true;
			if ( array_key_exists( 'persist_empty', $input ) ) {
				$persist = (bool) $input['persist_empty'];
			} elseif ( array_key_exists( 'persist', $input ) ) {
				$persist = (bool) $input['persist'];
			}

			if ( $hash === '' ) {
				$this->logger->debug( 'Lead simhash empty (too few tokens).' );
				return $this->ok( '', $max, [] );
			}

			$posts = $this->fields->find_by_lead_simhash( $hash, $max, $persist );
			$this->logger->debug(
				'Lead simhash search.',
				[ 'hash' => $hash, 'max' => $max, 'hits' => count( $posts ) ]
			);
			return $this->ok( $hash, $max, $posts );
		} catch ( MetaException $e ) {
			return $this->fail( $e->getMessage(), $e->error_code() );
		} catch ( \Throwable $e ) {
			$this->logger->error( $e->getMessage() );
			return $this->fail( $e->getMessage(), 'search_failed' );
		}
	}

	/**
	 * @param list<array<string, mixed>> $posts
	 * @return array<string, mixed>
	 */
	private function ok( string $hash, int $max, array $posts ): array {
		return [
			'success'      => true,
			'error'        => null,
			'code'         => null,
			'queried_hash' => $hash,
			'max_hamming'  => $max,
			'count'        => count( $posts ),
			'posts'        => $posts,
			'message'      => count( $posts ) === 0 ? 'No similar lead on the site.' : null,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fail( string $message, string $code ): array {
		return [
			'success'      => false,
			'error'        => $message,
			'code'         => $code,
			'queried_hash' => '',
			'max_hamming'  => LeadSimhash::DEFAULT_HAMMING,
			'count'        => 0,
			'posts'        => [],
			'message'      => null,
		];
	}
}
