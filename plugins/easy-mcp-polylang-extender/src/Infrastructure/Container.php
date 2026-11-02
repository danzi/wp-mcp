<?php
declare(strict_types=1);

namespace EasyMCP\PolylangExtender\Infrastructure;

use EasyMCP\PolylangExtender\Domain\Repository\LanguageRepositoryInterface;
use EasyMCP\PolylangExtender\Infrastructure\Repository\PolylangLanguageRepository;
use EasyMCP\PolylangExtender\Application\Interactor\SetPostLanguageInteractor;
use EasyMCP\PolylangExtender\Application\Interactor\GetPostLanguageInteractor;
use EasyMCP\PolylangExtender\Application\Interactor\ListLanguagesInteractor;
use EasyMCP\PolylangExtender\Application\Interactor\LinkPostTranslationsInteractor;
use EasyMCP\PolylangExtender\Application\Interactor\CreatePostWithLanguageInteractor;
use EasyMCP\PolylangExtender\Ability\RegisterAbilities;

/**
 * Simple DI container / composition root.
 */
final class Container {

	/** @var array<string, object> */
	private array $services = [];

	public function boot(): void {
		$this->services[ LanguageRepositoryInterface::class ] = new PolylangLanguageRepository();

		$this->services[ SetPostLanguageInteractor::class ] = new SetPostLanguageInteractor(
			$this->get( LanguageRepositoryInterface::class )
		);
		$this->services[ GetPostLanguageInteractor::class ] = new GetPostLanguageInteractor(
			$this->get( LanguageRepositoryInterface::class )
		);
		$this->services[ ListLanguagesInteractor::class ] = new ListLanguagesInteractor(
			$this->get( LanguageRepositoryInterface::class )
		);
		$this->services[ LinkPostTranslationsInteractor::class ] = new LinkPostTranslationsInteractor(
			$this->get( LanguageRepositoryInterface::class )
		);
		$this->services[ CreatePostWithLanguageInteractor::class ] = new CreatePostWithLanguageInteractor(
			$this->get( LanguageRepositoryInterface::class )
		);

		$registrar = new RegisterAbilities(
			$this->get( SetPostLanguageInteractor::class ),
			$this->get( GetPostLanguageInteractor::class ),
			$this->get( ListLanguagesInteractor::class ),
			$this->get( LinkPostTranslationsInteractor::class ),
			$this->get( CreatePostWithLanguageInteractor::class )
		);

		// Categories first (fires before abilities).
		add_action( 'wp_abilities_api_categories_init', [ $registrar, 'register_category' ] );

		// Abilities (correct hook name: wp_abilities_api_init).
		add_action( 'wp_abilities_api_init', [ $registrar, 'register' ] );

		// REST fallback always available.
		add_action( 'rest_api_init', [ $registrar, 'register_rest_fallback' ] );
	}

	/**
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	public function get( string $id ): object {
		if ( ! isset( $this->services[ $id ] ) ) {
			throw new \RuntimeException( "Service not found: {$id}" );
		}
		/** @var T */
		return $this->services[ $id ];
	}
}
