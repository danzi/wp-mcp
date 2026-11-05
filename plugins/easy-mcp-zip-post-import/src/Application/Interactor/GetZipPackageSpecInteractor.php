<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Application\Interactor;

final class GetZipPackageSpecInteractor {

	public function mcp_description(): string {
		return implode(
			' ',
			[
				'Insert or update a WordPress post from a ZIP package.',
				'ZIP layout (post.json at ZIP root OR inside one first-level folder):',
				'article-slug/post.json ; article-slug/images/image1.jpg ; optional thumbnail.jpg at root and/or images/.',
				'post.json fields: post_id|postid (0 or omit = insert; existing ID = update; missing ID = error, no insert),',
				'title (required on insert), content (HTML), status (default draft), excerpt, slug, post_type (default post),',
				'language (Polylang slug, skipped if Polylang inactive unless require_polylang),',
				'images or images_folder (default images), category / categories / category_names[], category_ids[],',
				'tags[] or comma-separated tags string, yoast object or yoast_title / yoast_description / yoast_focus_keyword,',
				'source_urls[] (news verification links; aliases sources, source_links), social_titles[] (clickbait FB headlines; aliases fb_titles, facebook_titles),',
				'social_posts object (optional; facebook/telegram/… => public post URL; empty/missing key = not posted),',
				'thumbnail (optional; omit or missing file = skip featured image, import still succeeds).',
				'In content use [image name="image1.jpg" alt="..."] — file is uploaded from images/ and replaced with a centered Gutenberg image block.',
				'Do not put thumbnail in content; when present it is set as featured image.',
				'Import with zip_url (public http/https link) or zip_path (uploads/temp on the WordPress server). Prefer zip_url when the ZIP is hosted. Localhost and private IPs are rejected.',
				'After a successful import the source ZIP on the server is deleted.',
				'Call zip-package-spec for the full JSON example.',
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function execute(): array {
		$example = [
			'post_id'              => 0,
			'title'                => 'Example article title',
			'slug'                 => 'example-article-title',
			'status'               => 'draft',
			'language'             => 'de',
			'excerpt'              => 'Clickbait text for Facebook.',
			'images'               => 'images',
			'images_folder'        => 'images',
			'thumbnail'            => 'thumbnail.jpg',
			'category'             => 'Nachrichten',
			'category_names'       => [ 'Nachrichten' ],
			'tags'                 => [ 'tag1', 'tag2', 'tag3', 'tag4', 'tag5' ],
			'yoast'                => [
				'title'         => 'SEO title',
				'description'   => 'SEO meta description.',
				'focus_keyword' => 'keyword',
			],
			'yoast_title'          => 'SEO title',
			'yoast_description'    => 'SEO meta description.',
			'yoast_focus_keyword'  => 'keyword',
			'source_urls'          => [ 'https://example.com/original-story' ],
			'social_titles'        => [
				'😢 Clickbait social headline one 💔',
				'😱 Clickbait social headline two 🏠',
				'💔 Clickbait social headline three 😢',
			],
			'social_posts'         => [
				'facebook' => '',
			],
			'require_polylang'     => false,
			'require_yoast'        => false,
			'content'              => "<p>Lead paragraph.</p>\n\n<h2>Heading</h2>\n\n<p>Body text.</p>\n\n[image name=\"image1.jpg\" alt=\"scene\"]\n\n<p>More text.</p>\n\n[image name=\"image2.jpg\"]\n",
		];

		return [
			'success' => true,
			'layout'  => [
				'article-slug/post.json',
				'article-slug/thumbnail.jpg  (optional featured image)',
				'article-slug/images/thumbnail.jpg  (optional)',
				'article-slug/images/image1.jpg',
				'article-slug/images/image2.jpg',
			],
			'rules'   => [
				'post.json must be at ZIP root or in one first-level folder (manifest.json also accepted).',
				'post_id/postid 0 or omitted = insert draft. Existing ID = update. Missing ID = error, no insert.',
				'JSON aliases: images = images_folder; category / categories = category_names.',
				'tags: string array or comma-separated string.',
				'content shortcode [image name="file.jpg"] uploads from images/ and replaces the shortcode with a centered Gutenberg image block. Optional alt="...".',
				'thumbnail is optional. Missing file never fails the import; featured image is skipped.',
				'When a thumbnail file exists, it is set as featured image (_thumbnail_id). Failure to assign is an error.',
				'language is applied only when Polylang is active, unless require_polylang is true.',
				'Yoast fields (object or yoast_*) are written only when Yoast is active, unless require_yoast is true.',
				'source_urls, social_titles, and social_posts are optional. Written only if Easy MCP Post Extra Fields is active; import still succeeds without that plugin.',
				'After successful import the source ZIP on the WordPress server is deleted.',
			],
			'fields'  => [
				'post_id'             => 'integer, optional, alias postid',
				'title'               => 'string, required on insert',
				'content'             => 'string HTML + [image name="file.jpg" alt="..."]',
				'status'              => 'draft|publish|pending|private|future, default draft on insert',
				'excerpt'             => 'string, optional',
				'slug'                => 'string, optional',
				'post_type'           => 'string, default post',
				'language'            => 'Polylang slug, optional',
				'images'              => 'alias of images_folder, default images',
				'images_folder'       => 'folder name relative to post.json, default images',
				'category'            => 'string or string[] category name(s)',
				'categories'          => 'alias of category_names',
				'category_names'      => 'string[], optional',
				'category_ids'        => 'int[], optional',
				'tags'                => 'string[] or comma-separated string',
				'thumbnail'           => 'optional filename; omit to auto-find thumbnail.jpg|jpeg|png|webp',
				'yoast'               => 'object with title / description / focus_keyword',
				'yoast_title'         => 'string, optional',
				'yoast_description'   => 'string, optional',
				'yoast_focus_keyword' => 'string, optional',
				'require_polylang'    => 'bool, fail import if Polylang is missing',
				'require_yoast'       => 'bool, fail import if Yoast is missing',
				'source_urls'         => 'optional string[] news source URLs, aliases sources / source_links; applied only if Extra Fields plugin is active',
				'social_titles'       => 'optional string[] clickbait social titles, aliases fb_titles / facebook_titles; applied only if Extra Fields plugin is active',
				'social_posts'        => 'optional object network => public URL (facebook, telegram, …). Empty/missing key = not posted. Applied only if Extra Fields is active.',
			],
			'example' => $example,
		];
	}
}
