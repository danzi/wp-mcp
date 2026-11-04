<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Application\Interactor;

use EasyMCP\PostMeta\Domain\LeadSimhash;
use EasyMCP\PostMeta\Domain\Logger\LoggerInterface;
use EasyMCP\PostMeta\Domain\Repository\ExtraFieldsRepositoryInterface;

final class PersistLeadSimhashInteractor {

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

	public function fill_if_empty( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		if ( $post->post_type !== 'post' ) {
			return '';
		}
		if ( $post->post_status === 'auto-draft' ) {
			return '';
		}
		$current = $this->fields->get( $post_id );
		$stored  = isset( $current['lead_simhash'] ) ? (string) $current['lead_simhash'] : '';
		if ( $stored !== '' ) {
			return $stored;
		}
		$hash = $this->simhash->compute( (string) $post->post_title, (string) $post->post_content );
		if ( $hash === '' ) {
			return '';
		}
		$this->fields->save_lead_simhash( $post_id, $hash );
		$this->logger->debug( 'Stored lead simhash once.', [ 'post_id' => $post_id, 'hash' => $hash ] );
		return $hash;
	}
}
