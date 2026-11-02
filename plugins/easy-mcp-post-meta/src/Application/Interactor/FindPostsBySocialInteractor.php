<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Application\Interactor;

use EasyMCP\PostMeta\Domain\Exception\MetaException;
use EasyMCP\PostMeta\Domain\Logger\LoggerInterface;
use EasyMCP\PostMeta\Domain\Repository\ExtraFieldsRepositoryInterface;

final class FindPostsBySocialInteractor {

	private ExtraFieldsRepositoryInterface $fields;
	private LoggerInterface $logger;

	public function __construct( ExtraFieldsRepositoryInterface $fields, LoggerInterface $logger ) {
		$this->fields = $fields;
		$this->logger = $logger;
	}

	/**
	 * @param array{post_id?:int,network?:string,posted?:bool,limit?:int,language?:string} $input
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		try {
			$network = sanitize_key( isset( $input['network'] ) ? (string) $input['network'] : 'facebook' );
			if ( $network === '' ) {
				throw new MetaException( 'Pass a network slug (facebook, telegram, …).', 'network_required' );
			}

			$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
			if ( $post_id > 0 ) {
				$card = $this->fields->card_for_id( $post_id );
				if ( $card === null ) {
					throw new MetaException( 'Post not found.', 'post_not_found' );
				}
				$posted_on = isset( $card['posted_on'] ) && is_array( $card['posted_on'] ) ? $card['posted_on'] : [];
				$is_posted = in_array( $network, $posted_on, true );
				$this->logger->debug(
					'Checked social post.',
					[ 'post_id' => $post_id, 'network' => $network, 'posted' => $is_posted ]
				);
				return $this->ok( $network, $is_posted, [ $card ], $post_id );
			}

			$posted = $this->bool_flag( $input, 'posted', false );
			$limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 20;
			$lang   = isset( $input['language'] ) ? sanitize_key( (string) $input['language'] ) : '';
			$cards  = $this->fields->find_by_social( $network, $posted, $limit, $lang !== '' ? $lang : null );
			$this->logger->debug(
				'Social search.',
				[ 'network' => $network, 'posted' => $posted, 'hits' => count( $cards ) ]
			);
			return $this->ok( $network, $posted, $cards, null );
		} catch ( MetaException $e ) {
			return $this->fail( $e->getMessage(), $e->error_code() );
		} catch ( \Throwable $e ) {
			$this->logger->error( $e->getMessage() );
			return $this->fail( $e->getMessage(), 'search_failed' );
		}
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function bool_flag( array $input, string $key, bool $default ): bool {
		if ( ! array_key_exists( $key, $input ) ) {
			return $default;
		}
		$value = $input[ $key ];
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return $value === 1;
		}
		if ( is_string( $value ) ) {
			$norm = strtolower( trim( $value ) );
			if ( in_array( $norm, [ '1', 'true', 'yes', 'on' ], true ) ) {
				return true;
			}
			if ( in_array( $norm, [ '0', 'false', 'no', 'off' ], true ) ) {
				return false;
			}
		}
		return $default;
	}

	/**
	 * @param list<array<string, mixed>> $posts
	 * @return array<string, mixed>
	 */
	private function ok( string $network, bool $posted, array $posts, ?int $post_id ): array {
		$count = count( $posts );
		if ( $post_id !== null ) {
			$message = $posted
				? 'This post is marked as published on ' . $network . '.'
				: 'This post is not marked as published on ' . $network . '.';
		} elseif ( $count === 0 ) {
			$message = $posted
				? 'No posts marked as published on ' . $network . '.'
				: 'No posts found that are unpublished on ' . $network . '.';
		} else {
			$message = null;
		}

		return [
			'success'      => true,
			'error'        => null,
			'code'         => null,
			'post_id'      => $post_id,
			'network'      => $network,
			'posted'       => $posted,
			'count'        => $count,
			'posts'        => $posts,
			'message'      => $message,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fail( string $message, string $code ): array {
		return [
			'success' => false,
			'error'   => $message,
			'code'    => $code,
			'post_id' => null,
			'network' => null,
			'posted'  => null,
			'count'   => 0,
			'posts'   => [],
			'message' => null,
		];
	}
}
