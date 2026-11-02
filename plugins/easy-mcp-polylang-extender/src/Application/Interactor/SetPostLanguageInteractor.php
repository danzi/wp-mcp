<?php
declare(strict_types=1);

namespace EasyMCP\PolylangExtender\Application\Interactor;

use EasyMCP\PolylangExtender\Domain\Repository\LanguageRepositoryInterface;

final class SetPostLanguageInteractor {

	private LanguageRepositoryInterface $repo;

	public function __construct( LanguageRepositoryInterface $repo ) {
		$this->repo = $repo;
	}

	/**
	 * @return array{success:bool,post_id:int,language:string,message:string}|array{success:false,error:string,code?:string}
	 */
	public function execute( int $post_id, string $lang_slug ): array {
		$result = $this->repo->set_post_language( $post_id, $lang_slug );

		if ( is_wp_error( $result ) ) {
			return [
				'success' => false,
				'error'   => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			];
		}

		return [
			'success'  => true,
			'post_id'  => $post_id,
			'language' => $lang_slug,
			'message'  => sprintf( 'Post %d language set to "%s".', $post_id, $lang_slug ),
		];
	}
}
