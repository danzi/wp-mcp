<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Infrastructure;

use EasyMCP\PostMeta\Ability\RegisterAbilities;
use EasyMCP\PostMeta\Admin\MetaBox;
use EasyMCP\PostMeta\Application\Interactor\FindPostsBySocialInteractor;
use EasyMCP\PostMeta\Application\Interactor\FindPostsBySourcesInteractor;
use EasyMCP\PostMeta\Application\Interactor\GetPostExtraFieldsInteractor;
use EasyMCP\PostMeta\Application\Interactor\ListPostFiltersInteractor;
use EasyMCP\PostMeta\Application\Interactor\ListPostsInteractor;
use EasyMCP\PostMeta\Application\Interactor\SavePostExtraFieldsInteractor;
use EasyMCP\PostMeta\Domain\Logger\LoggerInterface;
use EasyMCP\PostMeta\Domain\Repository\ExtraFieldsRepositoryInterface;
use EasyMCP\PostMeta\Domain\Repository\PostListRepositoryInterface;
use EasyMCP\PostMeta\Infrastructure\Repository\WpPostListRepository;
use EasyMCP\PostMeta\Infrastructure\Logger\WpLogger;
use EasyMCP\PostMeta\Infrastructure\Repository\WpExtraFieldsRepository;
use EasyMCP\PostMeta\Infrastructure\Rest\RegisterRest;
use EasyMCP\PostMeta\Infrastructure\Rest\RestAuth;
use EasyMCP\PostMeta\Infrastructure\Url\SourceUrlNormalizer;

final class Container {

	/** @var array<string, object> */
	private array $services = [];

	public function boot(): void {
		$this->services[ LoggerInterface::class ] = new WpLogger();
		$this->services[ RestAuth::class ]        = new RestAuth( $this->get( LoggerInterface::class ) );
		$this->get( RestAuth::class )->register();
		$this->services[ SourceUrlNormalizer::class ]            = new SourceUrlNormalizer();
		$this->services[ ExtraFieldsRepositoryInterface::class ] = new WpExtraFieldsRepository(
			$this->get( SourceUrlNormalizer::class )
		);
		$this->services[ PostListRepositoryInterface::class ] = new WpPostListRepository(
			$this->get( ExtraFieldsRepositoryInterface::class )
		);
		$this->services[ GetPostExtraFieldsInteractor::class ] = new GetPostExtraFieldsInteractor(
			$this->get( ExtraFieldsRepositoryInterface::class ),
			$this->get( LoggerInterface::class )
		);
		$this->services[ SavePostExtraFieldsInteractor::class ] = new SavePostExtraFieldsInteractor(
			$this->get( ExtraFieldsRepositoryInterface::class ),
			$this->get( LoggerInterface::class )
		);
		$this->services[ FindPostsBySourcesInteractor::class ] = new FindPostsBySourcesInteractor(
			$this->get( ExtraFieldsRepositoryInterface::class ),
			$this->get( SourceUrlNormalizer::class ),
			$this->get( LoggerInterface::class )
		);
		$this->services[ FindPostsBySocialInteractor::class ] = new FindPostsBySocialInteractor(
			$this->get( ExtraFieldsRepositoryInterface::class ),
			$this->get( LoggerInterface::class )
		);
		$this->services[ ListPostsInteractor::class ] = new ListPostsInteractor(
			$this->get( PostListRepositoryInterface::class ),
			$this->get( LoggerInterface::class )
		);
		$this->services[ ListPostFiltersInteractor::class ] = new ListPostFiltersInteractor(
			$this->get( PostListRepositoryInterface::class ),
			$this->get( LoggerInterface::class )
		);

		$registrar = new RegisterAbilities(
			$this->get( GetPostExtraFieldsInteractor::class ),
			$this->get( SavePostExtraFieldsInteractor::class ),
			$this->get( FindPostsBySourcesInteractor::class ),
			$this->get( FindPostsBySocialInteractor::class ),
			$this->get( LoggerInterface::class )
		);
		add_action( 'wp_abilities_api_categories_init', [ $registrar, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $registrar, 'register' ] );

		$rest = new RegisterRest(
			$this->get( GetPostExtraFieldsInteractor::class ),
			$this->get( SavePostExtraFieldsInteractor::class ),
			$this->get( FindPostsBySourcesInteractor::class ),
			$this->get( FindPostsBySocialInteractor::class ),
			$this->get( ListPostsInteractor::class ),
			$this->get( ListPostFiltersInteractor::class ),
			$this->get( RestAuth::class )
		);
		add_action( 'rest_api_init', [ $rest, 'register' ] );

		$box = new MetaBox( $this->get( ExtraFieldsRepositoryInterface::class ) );
		$box->register();
	}

	/**
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	public function get( string $id ): object {
		if ( ! isset( $this->services[ $id ] ) ) {
			throw new \RuntimeException( 'Unknown service: ' . $id );
		}
		/** @var T $service */
		$service = $this->services[ $id ];
		return $service;
	}
}
