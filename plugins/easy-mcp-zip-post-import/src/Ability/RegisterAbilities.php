<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Ability;

use EasyMCP\ZipPostImport\Application\Interactor\GetZipPackageSpecInteractor;
use EasyMCP\ZipPostImport\Application\Interactor\ImportPostFromZipInteractor;
use EasyMCP\ZipPostImport\Domain\Exception\ImportException;
use EasyMCP\ZipPostImport\Domain\Logger\LoggerInterface;

final class RegisterAbilities {

	private ImportPostFromZipInteractor $import;
	private GetZipPackageSpecInteractor $spec;
	private LoggerInterface $logger;

	public function __construct( ImportPostFromZipInteractor $import, GetZipPackageSpecInteractor $spec, LoggerInterface $logger ) {
		$this->import = $import;
		$this->spec   = $spec;
		$this->logger = $logger;
	}

	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'zip-post-import',
			[
				'label'       => 'ZIP Post Import',
				'description' => 'Insert or update a WordPress post from a ZIP package (post.json + images).',
			]
		);
	}

	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$mcp_meta = static function ( string $title, bool $readonly = false ): array {
			return [
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => [
					'public' => true,
				],
				'annotations'  => [
					'title'       => $title,
					'readonly'    => $readonly,
					'destructive' => false,
					'idempotent'  => $readonly,
				],
			];
		};

		wp_register_ability(
			'easy-mcp-zip-post-import/zip-package-spec',
			[
				'label'               => 'ZIP package format',
				'description'         => 'Returns the required ZIP layout, post.json fields, image shortcode rules, and a full example. Call this before building a ZIP for import-from-zip.',
				'category'            => 'zip-post-import',
				'meta'                => $mcp_meta( 'ZIP: Package Spec', true ),
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => [ 'type' => 'boolean' ],
						'layout'  => [ 'type' => 'array' ],
						'rules'   => [ 'type' => 'array' ],
						'fields'  => [ 'type' => 'object' ],
						'example' => [ 'type' => 'object' ],
					],
				],
				'execute_callback'    => function () {
					return $this->spec->execute();
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		$input_schema = [
			'type'       => 'object',
			'properties' => [
				'zip_url' => [
					'type'        => 'string',
					'format'      => 'uri',
					'description' => 'Public http(s) URL of the ZIP. Preferred when you cannot attach a file. Private/localhost URLs are rejected. WordPress downloads the ZIP, checks it is a real archive, imports, then deletes the temp file.',
				],
				'zip_path' => [
					'type'        => 'string',
					'description' => 'Absolute server path already on this WordPress host (uploads or temp only). Not a local path on the AI machine.',
				],
			],
		];

		$output_schema = [
			'type'       => 'object',
			'properties' => [
				'success'   => [ 'type' => 'boolean' ],
				'post_id'   => [ 'type' => 'integer' ],
				'action'    => [ 'type' => 'string' ],
				'language'  => [ 'type' => [ 'string', 'null' ] ],
				'status'    => [ 'type' => [ 'string', 'null' ] ],
				'edit_link' => [ 'type' => [ 'string', 'null' ] ],
				'tags'      => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'images'             => [ 'type' => 'array' ],
				'thumbnail'          => [ 'type' => [ 'object', 'null' ] ],
				'featured_image_id'  => [ 'type' => [ 'integer', 'null' ] ],
				'warning'            => [ 'type' => [ 'string', 'null' ] ],
				'uploaded_media_ids' => [ 'type' => 'array' ],
				'message'            => [ 'type' => 'string' ],
				'error'              => [ 'type' => [ 'string', 'null' ] ],
				'code'               => [ 'type' => [ 'string', 'null' ] ],
			],
		];

		wp_register_ability(
			'easy-mcp-zip-post-import/import-from-zip',
			[
				'label'               => 'Import or update post from ZIP',
				'description'         => $this->spec->mcp_description(),
				'category'            => 'zip-post-import',
				'meta'                => $mcp_meta( 'ZIP: Import Post' ),
				'input_schema'        => $input_schema,
				'output_schema'       => $output_schema,
				'execute_callback'    => function ( array $input ) {
					return $this->run( $input );
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);
	}

	public function register_rest_fallback(): void {
		register_rest_route(
			'easy-mcp-zip-post-import/v1',
			'/package-spec',
			[
				'methods'             => 'GET',
				'callback'            => function () {
					return new \WP_REST_Response( $this->spec->execute(), 200 );
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		register_rest_route(
			'easy-mcp-zip-post-import/v1',
			'/import',
			[
				'methods'             => 'POST',
				'callback'            => function ( \WP_REST_Request $request ) {
					$files = $request->get_file_params();
					if ( ! empty( $files['file']['tmp_name'] ) ) {
						$result = $this->safe_path( (string) $files['file']['tmp_name'] );
					} else {
						$zip_url  = $request->get_param( 'zip_url' );
						if ( $zip_url === null || $zip_url === '' ) {
							$zip_url = $request->get_param( 'zipUrl' );
						}
						$zip_path = $request->get_param( 'zip_path' );
						if ( $zip_path === null || $zip_path === '' ) {
							$zip_path = $request->get_param( 'zipPath' );
						}
						$result = $this->run( [
							'zip_url'  => $zip_url,
							'zip_path' => $zip_path,
						] );
					}
					$status = ! empty( $result['success'] ) ? 200 : 400;
					return new \WP_REST_Response( $result, $status );
				},
				'permission_callback' => [ $this, 'rest_import_permission' ],
				'args'                => [
					'zip_url'  => [
						'type' => 'string',
					],
					'zip_path' => [
						'type' => 'string',
					],
				],
			]
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public function rest_import_permission() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_forbidden',
				'Authentication required. Anonymous ZIP import is disabled. Connect Easy MCP with a WordPress user, or send an Application Password: Authorization Basic base64(username:application_password).',
				[ 'status' => 401 ]
			);
		}
		if ( ! current_user_can( 'edit_posts' ) || ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'The logged-in user needs both edit_posts and upload_files.',
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function run( array $input ): array {
		$zip_url  = isset( $input['zip_url'] ) ? esc_url_raw( (string) $input['zip_url'] ) : '';
		$zip_path = isset( $input['zip_path'] ) ? (string) $input['zip_path'] : '';

		try {
			if ( $zip_url !== '' ) {
				return $this->import->execute_from_url( $zip_url );
			}
			if ( $zip_path !== '' ) {
				return $this->safe_path( $zip_path );
			}
			throw new ImportException( 'Provide zip_url or zip_path.', 'missing_zip' );
		} catch ( ImportException $e ) {
			$this->logger->error( $e->getMessage(), [ 'code' => $e->error_code() ] );
			return [
				'success' => false,
				'error'   => $e->getMessage(),
				'code'    => $e->error_code(),
			];
		} catch ( \Throwable $e ) {
			$this->logger->error( $e->getMessage() );
			return [
				'success' => false,
				'error'   => $e->getMessage(),
				'code'    => 'unexpected',
			];
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function safe_path( string $zip_path ): array {
		try {
			return $this->import->execute_from_path( $zip_path );
		} catch ( ImportException $e ) {
			$this->logger->error( $e->getMessage(), [ 'code' => $e->error_code() ] );
			return [
				'success' => false,
				'error'   => $e->getMessage(),
				'code'    => $e->error_code(),
			];
		} catch ( \Throwable $e ) {
			$this->logger->error( $e->getMessage() );
			return [
				'success' => false,
				'error'   => $e->getMessage(),
				'code'    => 'unexpected',
			];
		}
	}
}
