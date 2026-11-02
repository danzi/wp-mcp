<?php
declare(strict_types=1);

namespace EasyMCP\PolylangExtender\Application\Interactor;

use EasyMCP\PolylangExtender\Domain\Repository\LanguageRepositoryInterface;

final class GetPostLanguageInteractor {

	private LanguageRepositoryInterface $repo;

	public function __construct( LanguageRepositoryInterface $repo ) {
		$this->repo = $repo;
	}

	/**
	 * @return array{success:bool,post_id:int,language:?string,translations:array<string,int>}
	 */
	public function execute( int $post_id ): array {
		return [
			'success'      => true,
			'post_id'      => $post_id,
			'language'     => $this->repo->get_post_language( $post_id ),
			'translations' => $this->repo->get_post_translations( $post_id ),
		];
	}
}
