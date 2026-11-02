=== Easy MCP ZIP Post Import ===
Contributors: siteowner
Tags: mcp, zip, import, yoast, polylang
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Insert or update a WordPress post from a ZIP package (post.json + images). Optional Polylang language and Yoast SEO.

== Description ==

This plugin registers a WordPress Ability that Easy MCP AI / MCP Adapter expose as an MCP tool, plus an admin upload page and a REST fallback.

Tool:

1. **easy-mcp-zip-post-import/zip-package-spec** – ZIP layout + post.json fields + example
2. **easy-mcp-zip-post-import/import-from-zip** – insert or update a post from zip_url or zip_path

ZIP layout:

```
article-slug/
  post.json
  thumbnail.jpg   # optional featured image (also searched in images/)
  images/
    thumbnail.jpg
    image1.jpg
    image2.jpg
```

`post.json` fields:

* post_id / postid – if set and the post exists, update; if omitted or 0, insert; if ID is missing on the site, error (no insert)
* title, content, status (default draft), excerpt, slug, post_type
* language – applied only when Polylang is active (unless require_polylang)
* images / images_folder / images_dir – images folder (default images)
* category / categories / category_names / category_ids
* tags
* yoast object or yoast_title, yoast_description, yoast_focus_keyword
* thumbnail – optional. Missing file skips featured image (import still succeeds)
* require_polylang / require_yoast
* source_urls / sources / source_links – news verification URLs
* social_titles / fb_titles / facebook_titles – clickbait social headlines
* social_posts – object of network => public post URL (facebook, telegram, …). Empty key = not posted.

Content shortcode `[image name="image1.jpg" alt="..."]` uploads that file from the images folder and replaces the shortcode. If a thumbnail file is present it is set as featured image.

Architecture (clean / layered):

* Domain – repository interfaces, ImportException, LoggerInterface
* Application – ParseZipManifest / ReplaceContentImages / ImportPostFromZip interactors
* Infrastructure – WP/Polylang/Yoast repositories + DI Container + logger
* Ability – RegisterAbilities
* Admin – Tools → ZIP Post Import

Debug logging: define `EMPZI_DEBUG` in wp-config.php or enable it on the import screen. Errors are always logged.

== Installation ==

1. Upload the `easy-mcp-zip-post-import` folder to `/wp-content/plugins/`
2. Activate the plugin
3. Import via Tools → ZIP Post Import, or reconnect MCP and use import-from-zip

== REST fallback ==

POST /wp-json/easy-mcp-zip-post-import/v1/import

Must be authenticated. Anonymous calls return 401. Use a WordPress Application Password:

Authorization: Basic base64(USERNAME:APPLICATION_PASSWORD)

JSON body:

* zip_url — public http(s) ZIP link (WordPress server downloads it)
* zip_path — path on the WordPress host (uploads/temp only)
* or multipart field `file`

Create the password: WP Admin → Users → Profile → Application Passwords. The user needs edit_posts and upload_files.

Prefer the MCP tool easy-mcp-zip-post-import/import-from-zip with zip_url while Easy MCP is already logged into WordPress.

== Changelog ==

= 1.2.8 =
* Writes _emp_social_posted index with social_posts so Extra Fields find-by-social can query ZIP imports

= 1.2.7 =
* Optional social_posts object in post.json (written only if Extra Fields is active)

= 1.2.6 =
* Thumbnail in ZIP is optional even if post.json names thumbnail.jpg but the file is absent

= 1.2.5 =
* No plugin dependency on Extra Fields; source_urls/social_titles/social_posts applied only if that plugin is active

= 1.2.4 =
* Requires Plugins: easy-mcp-post-meta (removed in 1.2.5)

= 1.2.3 =
* post.json source_urls and social_titles written to the same meta as Easy MCP Post Extra Fields

= 1.2.2 =
* REST 401 now explains that Application Password / MCP login is required
* REST accepts zipUrl as well as zip_url

= 1.2.1 =
* Admin + MCP: import ZIP from a public URL as well as file upload
* URL checks: http(s) only, no localhost/private IPs, max 50 MB, ZIP magic bytes

= 1.2.0 =
* JSON aliases: images, category/categories; nested yoast object
* Thumbnail locator (jpg/jpeg/png/webp); JSON promise without file is an error
* Featured image verified via _thumbnail_id
* Image shortcode alt, safer path resolve, reuse uploaded files
* require_polylang / require_yoast
* Keep original rule: unknown post_id fails, does not insert

= 1.1.0 =
* Admin Tools screen: ZIP drop zone and (i) format help modal in English

= 1.0.3 =
* Admin Tools screen: ZIP drop zone and (i) format help modal in English

= 1.0.2 =
* Include ARTICLE-INSTRUCTION.md in the plugin folder

= 1.0.1 =
* MCP: zip-package-spec ability with full ZIP layout and post.json example
* Import tool description now includes the package structure
* Delete source ZIP on the server after successful import
* REST GET /package-spec

= 1.0.0 =
* Initial release
* Insert/update from ZIP
* Image shortcodes, thumbnail, Polylang, Yoast
* Admin upload, MCP ability, REST fallback
