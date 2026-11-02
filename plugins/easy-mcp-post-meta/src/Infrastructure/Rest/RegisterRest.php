<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Infrastructure\Rest;

use EasyMCP\PostMeta\Application\Interactor\FindPostsBySocialInteractor;
use EasyMCP\PostMeta\Application\Interactor\FindPostsBySourcesInteractor;
use EasyMCP\PostMeta\Application\Interactor\GetPostExtraFieldsInteractor;
use EasyMCP\PostMeta\Application\Interactor\ListPostFiltersInteractor;
use EasyMCP\PostMeta\Application\Interactor\ListPostsInteractor;
use EasyMCP\PostMeta\Application\Interactor\SavePostExtraFieldsInteractor;

final class RegisterRest {

	private GetPostExtraFieldsInteractor $get;
	private SavePostExtraFieldsInteractor $save;
	private FindPostsBySourcesInteractor $find;
	private FindPostsBySocialInteractor $social;
	private ListPostsInteractor $list;
	private ListPostFiltersInteractor $filters;
	private RestAuth $auth;

	public function __construct(
		GetPostExtraFieldsInteractor $get,
		SavePostExtraFieldsInteractor $save,
		FindPostsBySourcesInteractor $find,
		FindPostsBySocialInteractor $social,
		ListPostsInteractor $list,
		ListPostFiltersInteractor $filters,
		RestAuth $auth
	) {
		$this->get     = $get;
		$this->save    = $save;
		$this->find    = $find;
		$this->social  = $social;
		$this->list    = $list;
		$this->filters = $filters;
		$this->auth    = $auth;
	}

	public function register(): void {
		register_rest_route(
			'easy-mcp-post-meta/v1',
			'/fields/(?P<post_id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => function ( \WP_REST_Request $request ) {
						return rest_ensure_response( $this->get->execute( [ 'post_id' => (int) $request['post_id'] ] ) );
					},
					'permission_callback' => [ $this, 'can_edit' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => function ( \WP_REST_Request $request ) {
						$body = $request->get_json_params();
						if ( ! is_array( $body ) ) {
							$body = [];
						}
						$body['post_id'] = (int) $request['post_id'];
						return rest_ensure_response( $this->save->execute( $body ) );
					},
					'permission_callback' => [ $this, 'can_edit' ],
				],
			]
		);

		register_rest_route(
			'easy-mcp-post-meta/v1',
			'/find-by-sources',
			[
				'methods'             => 'POST',
				'callback'            => function ( \WP_REST_Request $request ) {
					$body = $request->get_json_params();
					return rest_ensure_response( $this->find->execute( is_array( $body ) ? $body : [] ) );
				},
				'permission_callback' => [ $this, 'can_edit' ],
			]
		);

		register_rest_route(
			'easy-mcp-post-meta/v1',
			'/find-by-social',
			[
				'methods'             => 'POST',
				'callback'            => function ( \WP_REST_Request $request ) {
					$body = $request->get_json_params();
					return rest_ensure_response( $this->social->execute( is_array( $body ) ? $body : [] ) );
				},
				'permission_callback' => [ $this, 'can_edit' ],
			]
		);

		register_rest_route(
			'easy-mcp-post-meta/v1',
			'/list-posts',
			[
				'methods'             => 'POST',
				'callback'            => function ( \WP_REST_Request $request ) {
					$body = $request->get_json_params();
					return rest_ensure_response( $this->list->execute( is_array( $body ) ? $body : [] ) );
				},
				'permission_callback' => [ $this, 'can_edit' ],
			]
		);

		$stamp = static function ( $payload ) {
			$response = rest_ensure_response( $payload );
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );
			$response->header( 'X-EMPM-Version', defined( 'EMPM_VERSION' ) ? EMPM_VERSION : '' );
			return $response;
		};
		$filters_cb = function () use ( $stamp ) {
			return $stamp( $this->filters->execute() );
		};
		register_rest_route(
			'easy-mcp-post-meta/v1',
			'/ping',
			[
				[
					'methods'             => 'GET',
					'callback'            => function () use ( $stamp ) {
						global $wpdb;
						$cat  = 0;
						$lang = 0;
						if ( isset( $wpdb ) && is_object( $wpdb ) ) {
							$cat  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'category'" );
							$lang = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'language'" );
						}
						return $stamp(
							[
								'success'         => true,
								'error'           => null,
								'code'            => null,
								'plugin_version'  => defined( 'EMPM_VERSION' ) ? EMPM_VERSION : '',
								'plugin_file'     => defined( 'EMPM_PLUGIN_FILE' ) ? EMPM_PLUGIN_FILE : '',
								'pll'             => function_exists( 'pll_languages_list' ),
								'category_terms'  => $cat,
								'language_terms'  => $lang,
								'message'         => null,
							]
						);
					},
					'permission_callback' => [ $this, 'can_edit' ],
				],
			]
		);
		register_rest_route(
			'easy-mcp-post-meta/v1',
			'/filters',
			[
				[
					'methods'             => 'GET',
					'callback'            => $filters_cb,
					'permission_callback' => [ $this, 'can_edit' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => $filters_cb,
					'permission_callback' => [ $this, 'can_edit' ],
				],
			]
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public function can_edit() {
		$this->auth->ensure_logged_in();
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return true;
		}
		$debug = $this->auth->debug_data();
		$hint  = 'fail=' . ( isset( $debug['fail'] ) ? (string) $debug['fail'] : 'unknown' );
		if ( ! empty( $debug['fail'] ) && $debug['fail'] === 'user_not_found' ) {
			$hint = 'login not found (use username or email, not display name)';
		} elseif ( ! empty( $debug['fail'] ) && $debug['fail'] === 'bad_app_password' ) {
			$hint = 'application password does not match this user — create a new one';
		} elseif ( ! empty( $debug['fail'] ) && $debug['fail'] === 'no_credentials' ) {
			$hint = 'no login/password reached PHP (redirect or host stripped headers; use https:// without redirect)';
		}
		return new \WP_Error(
			'empm_rest_forbidden',
			'Application Password rejected: ' . $hint,
			[
				'status' => 401,
				'debug'  => $debug,
			]
		);
	}
}
