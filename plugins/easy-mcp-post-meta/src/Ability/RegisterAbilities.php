<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Ability;

use EasyMCP\PostMeta\Application\Interactor\FindPostsByLeadSimhashInteractor;
use EasyMCP\PostMeta\Application\Interactor\FindPostsBySocialInteractor;
use EasyMCP\PostMeta\Application\Interactor\FindPostsBySourcesInteractor;
use EasyMCP\PostMeta\Application\Interactor\GetPostExtraFieldsInteractor;
use EasyMCP\PostMeta\Application\Interactor\SavePostExtraFieldsInteractor;
use EasyMCP\PostMeta\Domain\Logger\LoggerInterface;

final class RegisterAbilities {

	private GetPostExtraFieldsInteractor $get;
	private SavePostExtraFieldsInteractor $save;
	private FindPostsBySourcesInteractor $find;
	private FindPostsBySocialInteractor $social;
	private FindPostsByLeadSimhashInteractor $lead;
	private LoggerInterface $logger;

	public function __construct(
		GetPostExtraFieldsInteractor $get,
		SavePostExtraFieldsInteractor $save,
		FindPostsBySourcesInteractor $find,
		FindPostsBySocialInteractor $social,
		FindPostsByLeadSimhashInteractor $lead,
		LoggerInterface $logger
	) {
		$this->get    = $get;
		$this->save   = $save;
		$this->find   = $find;
		$this->social = $social;
		$this->lead   = $lead;
		$this->logger = $logger;
	}

	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'easy-mcp-post-meta',
			[
				'label'       => 'Easy MCP Post Extra Fields',
				'description' => 'Source URLs, social titles, social post URLs, and lead simhash on WordPress posts.',
			]
		);
	}

	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->logger->error( 'Abilities API missing.' );
			return;
		}

		$mcp = static function ( string $title, bool $readonly = false ): array {
			return [
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => [ 'public' => true ],
				'annotations'  => [
					'title'       => $title,
					'readonly'    => $readonly,
					'destructive' => false,
					'idempotent'  => $readonly,
				],
			];
		};

		wp_register_ability(
			'easy-mcp-post-meta/get-fields',
			[
				'label'               => 'Get source URLs and social titles',
				'description'         => 'Returns source_urls, social_titles, social_posts, and lead_simhash (16 hex or empty) for a post. Does not return post content.',
				'category'            => 'easy-mcp-post-meta',
				'meta'                => $mcp( 'Post Extra Fields: Get', true ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [
							'type'        => 'integer',
							'description' => 'WordPress post ID.',
						],
					],
					'required'   => [ 'post_id' ],
				],
				'output_schema'       => $this->fields_output_schema(),
				'execute_callback'    => function ( array $input ) {
					return $this->get->execute( $input );
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'easy-mcp-post-meta/set-fields',
			[
				'label'               => 'Set source URLs, social titles, and social posts',
				'description'         => 'Writes source_urls, social_titles, social_posts, and/or lead_simhash on an existing post. Omit a field to keep the current value. Pass the cabinet lead_simhash as-is (16 lowercase hex); do not recompute. social_posts is one object for all networks. Empty or missing social key = not posted there.',
				'category'            => 'easy-mcp-post-meta',
				'meta'                => $mcp( 'Post Extra Fields: Set', false ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [
							'type'        => 'integer',
							'description' => 'WordPress post ID.',
						],
						'source_urls' => [
							'type'        => 'array',
							'description' => 'News source URLs (one real article URL per item). Replaces the full list.',
							'items'       => [ 'type' => 'string' ],
						],
						'social_titles' => [
							'type'        => 'array',
							'description' => 'Clickbait social titles (typically 3). Format: emoji(s) + text + emoji(s). Replaces the full list.',
							'items'       => [ 'type' => 'string' ],
						],
						'social_posts' => [
							'type'                 => 'object',
							'description'          => 'Map of network slug to public post URL. Replaces the full map. Keys: facebook, telegram, instagram, x, vk, or any other slug. Omit empty networks.',
							'additionalProperties' => [ 'type' => 'string' ],
						],
						'lead_simhash' => [
							'type'        => 'string',
							'description' => '16 lowercase hex. Written only if the post has no hash yet. A stored hash is never overwritten.',
						],
					],
					'required'   => [ 'post_id' ],
				],
				'output_schema'       => $this->fields_output_schema(),
				'execute_callback'    => function ( array $input ) {
					return $this->save->execute( $input );
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'easy-mcp-post-meta/find-by-sources',
			[
				'label'               => 'Find posts by source URLs',
				'description'         => 'Pass an array of source URLs. Returns compact cards for posts that already stored any of those URLs (title, short excerpt, language, source_urls, social_posts, matched_urls, status, edit_link). Does not return full content. On news search, if count>0 skip that story and search another.',
				'category'            => 'easy-mcp-post-meta',
				'meta'                => $mcp( 'Post Extra Fields: Find By Sources', true ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'urls' => [
							'type'        => 'array',
							'description' => 'Source URLs to look up. Alias: source_urls.',
							'items'       => [ 'type' => 'string' ],
						],
						'source_urls' => [
							'type'        => 'array',
							'description' => 'Alias of urls.',
							'items'       => [ 'type' => 'string' ],
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'      => [ 'type' => 'boolean' ],
						'error'        => [ 'type' => [ 'string', 'null' ] ],
						'code'         => [ 'type' => [ 'string', 'null' ] ],
						'queried_urls' => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'count'        => [ 'type' => 'integer' ],
						'posts'        => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'post_id'      => [ 'type' => 'integer' ],
									'title'        => [ 'type' => 'string' ],
									'excerpt'      => [ 'type' => 'string' ],
									'status'       => [ 'type' => 'string' ],
									'language'     => [ 'type' => [ 'string', 'null' ] ],
									'source_urls'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
									'social_posts' => [ 'type' => 'object' ],
									'matched_urls' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
									'edit_link'    => [ 'type' => [ 'string', 'null' ] ],
								],
							],
						],
						'message'      => [ 'type' => [ 'string', 'null' ] ],
					],
				],
				'execute_callback'    => function ( array $input ) {
					return $this->find->execute( $input );
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'easy-mcp-post-meta/find-by-social',
			[
				'label'               => 'Find posts by social publish status',
				'description'         => 'Checks where a post was published on socials. With post_id: returns that post and posted=true/false for the network (default facebook). Without post_id: lists compact cards. posted=false (default) = not yet published on that network; posted=true = already has a public URL there. Optional language and limit (max 50). Card fields: title, excerpt, language, source_urls, social_posts, posted_on[], status. Does not return full content.',
				'category'            => 'easy-mcp-post-meta',
				'meta'                => $mcp( 'Post Extra Fields: Find By Social', true ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [
							'type'        => 'integer',
							'description' => 'If set, check this post only (ignore posted filter).',
						],
						'network' => [
							'type'        => 'string',
							'description' => 'Network slug. Default facebook. Examples: facebook, telegram, instagram, x.',
						],
						'posted' => [
							'type'        => 'boolean',
							'description' => 'Ignored when post_id is set. false = not published on this network yet (default). true = already published there.',
						],
						'language' => [
							'type'        => 'string',
							'description' => 'Optional Polylang language slug (de, ru, …).',
						],
						'limit' => [
							'type'        => 'integer',
							'description' => 'Max cards, default 20, max 50.',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => [ 'type' => 'boolean' ],
						'error'   => [ 'type' => [ 'string', 'null' ] ],
						'code'    => [ 'type' => [ 'string', 'null' ] ],
						'post_id' => [ 'type' => [ 'integer', 'null' ] ],
						'network' => [ 'type' => [ 'string', 'null' ] ],
						'posted'  => [ 'type' => [ 'boolean', 'null' ] ],
						'count'   => [ 'type' => 'integer' ],
						'posts'   => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'post_id'      => [ 'type' => 'integer' ],
									'title'        => [ 'type' => 'string' ],
									'excerpt'      => [ 'type' => 'string' ],
									'status'       => [ 'type' => 'string' ],
									'language'     => [ 'type' => [ 'string', 'null' ] ],
									'source_urls'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
									'social_posts' => [ 'type' => 'object' ],
									'posted_on'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
									'matched_urls' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
									'edit_link'    => [ 'type' => [ 'string', 'null' ] ],
								],
							],
						],
						'message' => [ 'type' => [ 'string', 'null' ] ],
					],
				],
				'execute_callback'    => function ( array $input ) {
					return $this->social->execute( $input );
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'easy-mcp-post-meta/find-by-lead-simhash',
			[
				'label'               => 'Find posts by lead simhash',
				'description'         => 'Duplicate check by lead fingerprint (not source URL). Pass hash (16 hex) or title+content (lead HTML/text). Hamming default 12. Compact cards: post_id, title, language, excerpt, lead_simhash, hamming, source_urls, status, edit_link. count>0 = this story is already on the site. Does not replace find-by-sources. Empty stored hashes are computed from post_title+post_content and saved unless persist_empty=false.',
				'category'            => 'easy-mcp-post-meta',
				'meta'                => $mcp( 'Post Extra Fields: Find By Lead Simhash', true ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'hash' => [
							'type'        => 'string',
							'description' => '16 lowercase hex lead_simhash. If set, used as the query fingerprint.',
						],
						'title' => [
							'type'        => 'string',
							'description' => 'Used to compute the hash when hash is omitted.',
						],
						'content' => [
							'type'        => 'string',
							'description' => 'Lead/body HTML or text. Excerpt is not used. Used with title when hash is omitted.',
						],
						'max_hamming' => [
							'type'        => 'integer',
							'description' => 'Max Hamming distance. Default 12.',
						],
						'persist_empty' => [
							'type'        => 'boolean',
							'description' => 'If true (default), write computed hashes onto posts that still have empty meta.',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'      => [ 'type' => 'boolean' ],
						'error'        => [ 'type' => [ 'string', 'null' ] ],
						'code'         => [ 'type' => [ 'string', 'null' ] ],
						'queried_hash' => [ 'type' => 'string' ],
						'max_hamming'  => [ 'type' => 'integer' ],
						'count'        => [ 'type' => 'integer' ],
						'posts'        => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'post_id'      => [ 'type' => 'integer' ],
									'title'        => [ 'type' => 'string' ],
									'excerpt'      => [ 'type' => 'string' ],
									'status'       => [ 'type' => 'string' ],
									'language'     => [ 'type' => [ 'string', 'null' ] ],
									'source_urls'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
									'lead_simhash' => [ 'type' => 'string' ],
									'hamming'      => [ 'type' => 'integer' ],
									'edit_link'    => [ 'type' => [ 'string', 'null' ] ],
								],
							],
						],
						'message'      => [ 'type' => [ 'string', 'null' ] ],
					],
				],
				'execute_callback'    => function ( array $input ) {
					return $this->lead->execute( $input );
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fields_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'success'       => [ 'type' => 'boolean' ],
				'error'         => [ 'type' => [ 'string', 'null' ] ],
				'code'          => [ 'type' => [ 'string', 'null' ] ],
				'post_id'       => [ 'type' => [ 'integer', 'null' ] ],
				'source_urls'   => [ 'type'  => 'array', 'items' => [ 'type' => 'string' ] ],
				'social_titles' => [ 'type'  => 'array', 'items' => [ 'type' => 'string' ] ],
				'social_posts'  => [ 'type' => 'object' ],
				'lead_simhash'  => [ 'type' => 'string' ],
				'message'       => [ 'type' => [ 'string', 'null' ] ],
			],
		];
	}
}
