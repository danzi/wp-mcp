<?php
declare(strict_types=1);

namespace EasyMCP\PolylangExtender\Ability;

use EasyMCP\PolylangExtender\Application\Interactor\SetPostLanguageInteractor;
use EasyMCP\PolylangExtender\Application\Interactor\GetPostLanguageInteractor;
use EasyMCP\PolylangExtender\Application\Interactor\ListLanguagesInteractor;
use EasyMCP\PolylangExtender\Application\Interactor\LinkPostTranslationsInteractor;
use EasyMCP\PolylangExtender\Application\Interactor\CreatePostWithLanguageInteractor;

/**
 * Registers Polylang abilities for the WordPress Abilities API.
 * Easy MCP AI / MCP Adapter expose abilities with meta.mcp.public = true as MCP tools.
 */
final class RegisterAbilities {

	private SetPostLanguageInteractor $setLang;
	private GetPostLanguageInteractor $getLang;
	private ListLanguagesInteractor $listLang;
	private LinkPostTranslationsInteractor $linkTranslations;
	private CreatePostWithLanguageInteractor $createWithLang;

	public function __construct(
		SetPostLanguageInteractor $setLang,
		GetPostLanguageInteractor $getLang,
		ListLanguagesInteractor $listLang,
		LinkPostTranslationsInteractor $linkTranslations,
		CreatePostWithLanguageInteractor $createWithLang
	) {
		$this->setLang          = $setLang;
		$this->getLang          = $getLang;
		$this->listLang         = $listLang;
		$this->linkTranslations = $linkTranslations;
		$this->createWithLang   = $createWithLang;
	}

	/**
	 * Register the "polylang" ability category.
	 * Must run on wp_abilities_api_categories_init (before abilities).
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'polylang',
			[
				'label'       => 'Polylang',
				'description' => 'Multilingual tools: list languages, get/set post language, link translations, create post with language.',
			]
		);
	}

	/**
	 * Register all Polylang abilities.
	 * Must run on wp_abilities_api_init.
	 */
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

		// 1. List languages (read-only)
		wp_register_ability(
			'easy-mcp-polylang/list-languages',
			[
				'label'               => 'List Polylang languages',
				'description'         => 'Returns all languages configured in Polylang (slug, name, locale, is_default). Call this first to know valid language slugs (e.g. de, en).',
				'category'            => 'polylang',
				'meta'                => $mcp_meta( 'Polylang: List Languages', true ),
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'   => [ 'type' => 'boolean' ],
						'languages' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'slug'       => [ 'type' => 'string' ],
									'name'       => [ 'type' => 'string' ],
									'locale'     => [ 'type' => 'string' ],
									'is_default' => [ 'type' => 'boolean' ],
									'term_id'    => [ 'type' => 'integer' ],
								],
							],
						],
					],
				],
				'execute_callback'    => function () {
					return $this->listLang->execute();
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		// 2. Get post language + translations
		wp_register_ability(
			'easy-mcp-polylang/get-post-language',
			[
				'label'               => 'Get Polylang language of a post',
				'description'         => 'Returns the language slug of a post and the map of its translations (slug => post_id).',
				'category'            => 'polylang',
				'meta'                => $mcp_meta( 'Polylang: Get Post Language', true ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [
							'type'        => 'integer',
							'description' => 'The post ID.',
							'minimum'     => 1,
						],
					],
					'required'   => [ 'post_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'      => [ 'type' => 'boolean' ],
						'post_id'      => [ 'type' => 'integer' ],
						'language'     => [ 'type' => [ 'string', 'null' ] ],
						'translations' => [ 'type' => 'object' ],
					],
				],
				'execute_callback'    => function ( array $input ) {
					$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
					return $this->getLang->execute( $post_id );
				},
				'permission_callback' => function ( array $input ) {
					$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
					return $post_id > 0 && current_user_can( 'edit_post', $post_id );
				},
			]
		);

		// 3. Set post language
		wp_register_ability(
			'easy-mcp-polylang/set-post-language',
			[
				'label'               => 'Set Polylang language of a post',
				'description'         => 'Sets the language of an existing post (e.g. "de", "en", "ru"). Uses pll_set_post_language. Call right after creating a post, or use create-post-with-language instead.',
				'category'            => 'polylang',
				'meta'                => $mcp_meta( 'Polylang: Set Post Language' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id'  => [
							'type'        => 'integer',
							'description' => 'The post ID to assign a language to.',
							'minimum'     => 1,
						],
						'language' => [
							'type'        => 'string',
							'description' => 'Language slug (e.g. de, en, ru, fr). Must be configured in Polylang.',
						],
					],
					'required'   => [ 'post_id', 'language' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'  => [ 'type' => 'boolean' ],
						'post_id'  => [ 'type' => 'integer' ],
						'language' => [ 'type' => 'string' ],
						'message'  => [ 'type' => 'string' ],
						'error'    => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => function ( array $input ) {
					$post_id  = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
					$language = isset( $input['language'] ) ? sanitize_key( (string) $input['language'] ) : '';
					return $this->setLang->execute( $post_id, $language );
				},
				'permission_callback' => function ( array $input ) {
					$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
					return $post_id > 0 && current_user_can( 'edit_post', $post_id );
				},
			]
		);

		// 4. Link translations
		wp_register_ability(
			'easy-mcp-polylang/link-post-translations',
			[
				'label'               => 'Link Polylang post translations',
				'description'         => 'Links several posts as translations of each other. Pass an object { "de": 123, "en": 456 }.',
				'category'            => 'polylang',
				'meta'                => $mcp_meta( 'Polylang: Link Post Translations' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'translations' => [
							'type'                 => 'object',
							'description'          => 'Map of language slug => post ID.',
							'additionalProperties' => [
								'type' => 'integer',
							],
						],
					],
					'required'   => [ 'translations' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => [ 'type' => 'boolean' ],
						'message' => [ 'type' => 'string' ],
						'linked'  => [ 'type' => 'object' ],
						'error'   => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => function ( array $input ) {
					$translations = isset( $input['translations'] ) && is_array( $input['translations'] )
						? $input['translations']
						: [];
					return $this->linkTranslations->execute( $translations );
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		// 5. Create post with language (one-shot) — main tool for AI
		wp_register_ability(
			'easy-mcp-polylang/create-post-with-language',
			[
				'label'               => 'Create post with Polylang language',
				'description'         => 'Creates a WordPress post and immediately sets its Polylang language in one call. Optionally sets categories, tags, featured image, Yoast SEO. Optional source_urls, social_titles, social_posts, and lead_simhash are stored only if Easy MCP Post Extra Fields is active; create still works without it. Pass cabinet lead_simhash as 16 lowercase hex when you have it. Empty, omit, or invalid hash is ignored: Extra Fields computes once on save and never overwrites. Default status is "draft". Prefer this over wp_create_post + set-post-language.',
				'category'            => 'polylang',
				'meta'                => $mcp_meta( 'Polylang: Create Post With Language' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'title' => [
							'type'        => 'string',
							'description' => 'Post title (required).',
						],
						'content' => [
							'type'        => 'string',
							'description' => 'Post content (HTML allowed).',
						],
						'language' => [
							'type'        => 'string',
							'description' => 'Polylang language slug (required), e.g. de, en, ru, fr.',
						],
						'status' => [
							'type'        => 'string',
							'description' => 'Post status: draft (default), publish, pending, private.',
							'default'     => 'draft',
						],
						'excerpt' => [
							'type'        => 'string',
							'description' => 'Post excerpt.',
						],
						'slug' => [
							'type'        => 'string',
							'description' => 'Post slug (post_name). Auto-generated from title if omitted.',
						],
						'post_type' => [
							'type'        => 'string',
							'description' => 'Post type. Default: post.',
							'default'     => 'post',
						],
						'category_ids' => [
							'type'        => 'array',
							'description' => 'Array of category term IDs.',
							'items'       => [ 'type' => 'integer' ],
						],
						'category_names' => [
							'type'        => 'array',
							'description' => 'Array of category names (created if missing).',
							'items'       => [ 'type' => 'string' ],
						],
						'tags' => [
							'type'        => 'array',
							'description' => 'Array of tag names.',
							'items'       => [ 'type' => 'string' ],
						],
						'featured_image_id' => [
							'type'        => 'integer',
							'description' => 'Attachment ID to use as featured image.',
						],
						'yoast_title' => [
							'type'        => 'string',
							'description' => 'Yoast SEO title (_yoast_wpseo_title).',
						],
						'yoast_description' => [
							'type'        => 'string',
							'description' => 'Yoast SEO meta description (_yoast_wpseo_metadesc).',
						],
						'yoast_focus_keyword' => [
							'type'        => 'string',
							'description' => 'Yoast focus keyphrase (_yoast_wpseo_focuskw).',
						],
						'source_urls' => [
							'type'        => 'array',
							'description' => 'Optional. News source URLs. Stored only if Easy MCP Post Extra Fields is active.',
							'items'       => [ 'type' => 'string' ],
						],
						'social_titles' => [
							'type'        => 'array',
							'description' => 'Optional. Clickbait social/Facebook titles (typically 3). Stored only if Easy MCP Post Extra Fields is active.',
							'items'       => [ 'type' => 'string' ],
						],
						'social_posts' => [
							'type'                 => 'object',
							'description'          => 'Optional. Network slug => public post URL. Empty/missing key = not posted. Stored only if Extra Fields is active.',
							'additionalProperties' => [ 'type' => 'string' ],
						],
						'lead_simhash' => [
							'type'        => 'string',
							'description' => 'Optional. Cabinet lead fingerprint, 16 lowercase hex. Stored once; never overwritten. Empty, omit, or invalid = Extra Fields computes from title+content on save. Does not fail create. Extra Fields active only.',
						],
						'meta' => [
							'type'                 => 'object',
							'description'          => 'Extra post meta key => value pairs.',
							'additionalProperties' => true,
						],
					],
					'required'   => [ 'title', 'language' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'   => [ 'type' => 'boolean' ],
						'post_id'   => [ 'type' => 'integer' ],
						'language'  => [ 'type' => 'string' ],
						'status'    => [ 'type' => 'string' ],
						'edit_link' => [ 'type' => [ 'string', 'null' ] ],
						'message'   => [ 'type' => 'string' ],
						'error'     => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => function ( array $input ) {
					return $this->createWithLang->execute( $input );
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);
	}

	/**
	 * Fallback REST routes (always available even without Abilities API / MCP discovery).
	 */
	public function register_rest_fallback(): void {
		register_rest_route(
			'easy-mcp-polylang/v1',
			'/set-post-language',
			[
				'methods'             => 'POST',
				'callback'            => function ( \WP_REST_Request $request ) {
					$post_id  = (int) $request->get_param( 'post_id' );
					$language = sanitize_key( (string) $request->get_param( 'language' ) );
					$result   = $this->setLang->execute( $post_id, $language );
					$status   = ! empty( $result['success'] ) ? 200 : 400;
					return new \WP_REST_Response( $result, $status );
				},
				'permission_callback' => function ( \WP_REST_Request $request ) {
					$post_id = (int) $request->get_param( 'post_id' );
					return $post_id > 0 && current_user_can( 'edit_post', $post_id );
				},
				'args'                => [
					'post_id'  => [
						'required' => true,
						'type'     => 'integer',
					],
					'language' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);

		register_rest_route(
			'easy-mcp-polylang/v1',
			'/list-languages',
			[
				'methods'             => 'GET',
				'callback'            => function () {
					return new \WP_REST_Response( $this->listLang->execute(), 200 );
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		register_rest_route(
			'easy-mcp-polylang/v1',
			'/create-post-with-language',
			[
				'methods'             => 'POST',
				'callback'            => function ( \WP_REST_Request $request ) {
					$input = [
						'title'               => $request->get_param( 'title' ),
						'content'             => $request->get_param( 'content' ),
						'language'            => $request->get_param( 'language' ),
						'status'              => $request->get_param( 'status' ),
						'excerpt'             => $request->get_param( 'excerpt' ),
						'slug'                => $request->get_param( 'slug' ),
						'post_type'           => $request->get_param( 'post_type' ),
						'category_ids'        => $request->get_param( 'category_ids' ),
						'category_names'      => $request->get_param( 'category_names' ),
						'tags'                => $request->get_param( 'tags' ),
						'featured_image_id'   => $request->get_param( 'featured_image_id' ),
						'yoast_title'         => $request->get_param( 'yoast_title' ),
						'yoast_description'   => $request->get_param( 'yoast_description' ),
						'yoast_focus_keyword' => $request->get_param( 'yoast_focus_keyword' ),
						'source_urls'         => $request->get_param( 'source_urls' ),
						'social_titles'       => $request->get_param( 'social_titles' ),
						'social_posts'        => $request->get_param( 'social_posts' ),
						'lead_simhash'        => $request->get_param( 'lead_simhash' ),
						'meta'                => $request->get_param( 'meta' ),
					];
					$input  = array_filter( $input, static fn( $v ) => $v !== null );
					$result = $this->createWithLang->execute( $input );
					$status = ! empty( $result['success'] ) ? 200 : 400;
					return new \WP_REST_Response( $result, $status );
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => [
					'title'    => [
						'required' => true,
						'type'     => 'string',
					],
					'language' => [
						'required' => true,
						'type'     => 'string',
					],
					'content'  => [
						'type' => 'string',
					],
					'status'   => [
						'type' => 'string',
					],
				],
			]
		);
	}
}
