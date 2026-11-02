<?php
declare(strict_types=1);

namespace EasyMCP\PolylangExtender\Application\Interactor;

use EasyMCP\PolylangExtender\Domain\Repository\LanguageRepositoryInterface;

final class ListLanguagesInteractor {

	private LanguageRepositoryInterface $repo;

	public function __construct( LanguageRepositoryInterface $repo ) {
		$this->repo = $repo;
	}

	/**
	 * @return array{success:bool,languages:list<array{slug:string,name:string,locale:string,is_default:bool,term_id:int}>}
	 */
	public function execute(): array {
		return [
			'success'   => true,
			'languages' => $this->repo->list_languages(),
		];
	}
}
