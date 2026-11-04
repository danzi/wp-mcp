<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Admin;

use EasyMCP\PostMeta\Application\Interactor\PersistLeadSimhashInteractor;

final class PersistLeadSimhashHook {

	private PersistLeadSimhashInteractor $persist;

	public function __construct( PersistLeadSimhashInteractor $persist ) {
		$this->persist = $persist;
	}

	public function register(): void {
		add_action( 'save_post_post', [ $this, 'on_save' ], 20, 2 );
	}

	public function on_save( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( $post->post_type !== 'post' ) {
			return;
		}
		$this->persist->fill_if_empty( $post_id );
	}
}
