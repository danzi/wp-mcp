<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Infrastructure;

use EasyMCP\ZipPostImport\Ability\RegisterAbilities;
use EasyMCP\ZipPostImport\Admin\ImportPage;
use EasyMCP\ZipPostImport\Application\Interactor\GetZipPackageSpecInteractor;
use EasyMCP\ZipPostImport\Application\Interactor\ImportPostFromZipInteractor;
use EasyMCP\ZipPostImport\Application\Interactor\ParseZipManifestInteractor;
use EasyMCP\ZipPostImport\Application\Interactor\ReplaceContentImagesInteractor;
use EasyMCP\ZipPostImport\Application\Interactor\SetFeaturedImageInteractor;
use EasyMCP\ZipPostImport\Application\UseCase\ManifestAliasNormalizer;
use EasyMCP\ZipPostImport\Infrastructure\Zip\ThumbnailLocator;
use EasyMCP\ZipPostImport\Domain\Logger\LoggerInterface;
use EasyMCP\ZipPostImport\Domain\Repository\ExtraFieldsRepositoryInterface;
use EasyMCP\ZipPostImport\Domain\Repository\LanguageRepositoryInterface;
use EasyMCP\ZipPostImport\Domain\Repository\MediaRepositoryInterface;
use EasyMCP\ZipPostImport\Domain\Repository\PostRepositoryInterface;
use EasyMCP\ZipPostImport\Domain\Repository\SeoRepositoryInterface;
use EasyMCP\ZipPostImport\Domain\Repository\TermRepositoryInterface;
use EasyMCP\ZipPostImport\Domain\Repository\ZipArchiveRepositoryInterface;
use EasyMCP\ZipPostImport\Infrastructure\Logger\WpLogger;
use EasyMCP\ZipPostImport\Infrastructure\Repository\NullLanguageRepository;
use EasyMCP\ZipPostImport\Infrastructure\Repository\PhpZipArchiveRepository;
use EasyMCP\ZipPostImport\Infrastructure\Repository\PolylangLanguageRepository;
use EasyMCP\ZipPostImport\Infrastructure\Repository\WpExtraFieldsRepository;
use EasyMCP\ZipPostImport\Infrastructure\Repository\WpMediaRepository;
use EasyMCP\ZipPostImport\Infrastructure\Repository\WpPostRepository;
use EasyMCP\ZipPostImport\Infrastructure\Repository\WpTermRepository;
use EasyMCP\ZipPostImport\Infrastructure\Repository\YoastSeoRepository;

final class Container {

	/** @var array<string, object> */
	private array $services = [];

	public function boot(): void {
		$this->services[ LoggerInterface::class ] = new WpLogger();

		$language = new PolylangLanguageRepository();
		if ( ! $language->is_available() ) {
			$language = new NullLanguageRepository();
		}
		$this->services[ LanguageRepositoryInterface::class ] = $language;

		$this->services[ PostRepositoryInterface::class ]       = new WpPostRepository();
		$this->services[ MediaRepositoryInterface::class ]      = new WpMediaRepository();
		$this->services[ TermRepositoryInterface::class ]       = new WpTermRepository();
		$this->services[ SeoRepositoryInterface::class ]         = new YoastSeoRepository();
		$this->services[ ExtraFieldsRepositoryInterface::class ] = new WpExtraFieldsRepository();
		$this->services[ ZipArchiveRepositoryInterface::class ] = new PhpZipArchiveRepository(
			$this->get( LoggerInterface::class )
		);

		$this->services[ ManifestAliasNormalizer::class ] = new ManifestAliasNormalizer();
		$this->services[ ThumbnailLocator::class ]        = new ThumbnailLocator();
		$this->services[ ParseZipManifestInteractor::class ] = new ParseZipManifestInteractor(
			$this->get( LoggerInterface::class ),
			$this->get( ManifestAliasNormalizer::class )
		);
		$this->services[ ReplaceContentImagesInteractor::class ] = new ReplaceContentImagesInteractor(
			$this->get( MediaRepositoryInterface::class ),
			$this->get( LoggerInterface::class )
		);
		$this->services[ SetFeaturedImageInteractor::class ] = new SetFeaturedImageInteractor(
			$this->get( MediaRepositoryInterface::class ),
			$this->get( PostRepositoryInterface::class ),
			$this->get( ThumbnailLocator::class ),
			$this->get( LoggerInterface::class )
		);
		$this->services[ ImportPostFromZipInteractor::class ] = new ImportPostFromZipInteractor(
			$this->get( ZipArchiveRepositoryInterface::class ),
			$this->get( ParseZipManifestInteractor::class ),
			$this->get( ReplaceContentImagesInteractor::class ),
			$this->get( SetFeaturedImageInteractor::class ),
			$this->get( PostRepositoryInterface::class ),
			$this->get( MediaRepositoryInterface::class ),
			$this->get( TermRepositoryInterface::class ),
			$this->get( LanguageRepositoryInterface::class ),
			$this->get( SeoRepositoryInterface::class ),
			$this->get( ExtraFieldsRepositoryInterface::class ),
			$this->get( LoggerInterface::class )
		);

		$this->services[ GetZipPackageSpecInteractor::class ] = new GetZipPackageSpecInteractor();

		$registrar = new RegisterAbilities(
			$this->get( ImportPostFromZipInteractor::class ),
			$this->get( GetZipPackageSpecInteractor::class ),
			$this->get( LoggerInterface::class )
		);

		add_action( 'wp_abilities_api_categories_init', [ $registrar, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $registrar, 'register' ] );
		add_action( 'rest_api_init', [ $registrar, 'register_rest_fallback' ] );

		$page = new ImportPage(
			$this->get( ImportPostFromZipInteractor::class ),
			$this->get( GetZipPackageSpecInteractor::class ),
			$this->get( LoggerInterface::class )
		);
		$page->register();
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
