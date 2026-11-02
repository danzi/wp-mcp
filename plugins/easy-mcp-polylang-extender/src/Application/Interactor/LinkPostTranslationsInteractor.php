<?php
declare(strict_types=1);

namespace EasyMCP\PolylangExtender\Application\Interactor;

use EasyMCP\PolylangExtender\Domain\Repository\LanguageRepositoryInterface;

final class LinkPostTranslationsInteractor {

	private LanguageRepositoryInterface $repo;

	public function __construct( LanguageRepositoryInterface $repo ) {
		$this->repo = $repo;
	}

	/**
	 * @param array<string, int> $translations  slug => post_id
	 * @return array{success:bool,message?:string,error?:string,code?:string}
	 */
	public function execute( array $translations ): array {
		$result = $this->repo->save_post_translations( $translations );

		if ( is_wp_error( $result ) ) {
			return [
				'success' => false,
				'error'   => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			];
		}

		return [
			'success' => true,
			'message' => 'Translations linked successfully.',
			'linked'  => $translations,
		];
	}
}
