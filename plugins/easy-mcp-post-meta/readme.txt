=== Easy MCP Post Extra Fields ===
Contributors: siteowner
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.12
License: GPLv2 or later

Source URLs, social clickbait titles, and social post URLs on posts. MCP get/set + find posts by source URL array.

== Description ==

Stores extra fields on posts:

* source_urls — news links used to verify the story and to search existing posts
* social_titles — clickbait Facebook/social headlines (emoji at start and end)
* social_posts — one object for all networks: { "facebook": "https://…", "telegram": "https://…" }. Empty or missing key = not posted on that network. Do not add a separate WordPress field per social network.

Admin: metabox on the post editor (Facebook URL + extra network lines).

MCP abilities (Easy MCP AI / Abilities API):

* easy-mcp-post-meta/get-fields
* easy-mcp-post-meta/set-fields — optional social_posts object
* easy-mcp-post-meta/find-by-sources — POST { "urls": ["https://..."] } returns compact cards (title, excerpt, language, source_urls, social_posts, matched_urls). Not full content.
* easy-mcp-post-meta/find-by-social — check one post_id or list posts posted/not posted on a network (default facebook, posted=false).

REST (Application Password, edit_posts):

* GET/POST /wp-json/easy-mcp-post-meta/v1/fields/{post_id}
* POST /wp-json/easy-mcp-post-meta/v1/find-by-sources
* POST /wp-json/easy-mcp-post-meta/v1/find-by-social
* POST /wp-json/easy-mcp-post-meta/v1/list-posts
* GET /wp-json/easy-mcp-post-meta/v1/filters

Optional companion: ZIP import and Polylang create-post do not require this plugin. When it is active they write source_urls / social_titles / social_posts; when it is not, they skip those fields and still import/create.

Debug: define EMPM_DEBUG true or option empm_debug.

== Changelog ==

= 1.3.12 =
* filters: each category includes Polylang language; helper can show cats for the selected language

= 1.3.11 =
* Featured image URL is the original attachment, no medium/thumbnail/large resize

= 1.3.10 =
* REST cards include thumbnail_full for zoom preview

= 1.3.9 =
* REST cards include author display name

= 1.3.8 =
* REST cards include thumbnail URL (featured image)

= 1.3.7 =
* REST auth: WP_Application_Passwords::check_password() is (password, hash), not (user_id, password)

= 1.3.6 =
* REST ping + X-EMPM-Version on every easy-mcp-post-meta response so the helper can see the live plugin file

= 1.3.5 =
* filters: read languages/categories from DB (bypass Polylang/WPML REST filters), POST + no-store

= 1.3.4 =
* filters: PLL model list + categories with lang=all / unfiltered fallback; include plugin_version

= 1.3.3 =
* filters: Polylang language slugs + categories across all languages (lang='')

= 1.3.2 =
* REST login checks Application Password in permission_callback and returns fail=user_not_found|bad_app_password|no_credentials

= 1.3.1 =
* REST: restore stripped Authorization, accept X-EMPM-User / X-EMPM-App-Password, clearer 401 than "Sorry, you are not allowed to do that"

= 1.3.0 =
* REST list-posts + filters for the WP FB Helper Chrome extension
* Metabox field order: social post URLs (facebook now), social titles, source URLs

= 1.2.0 =
* MCP find-by-social: was this post published on facebook/telegram/…, or list unpublished posts
* Queryable _emp_social_posted index; set-fields merges social_posts keys instead of replacing the whole map

= 1.1.0 =
* social_posts map (facebook, telegram, …) instead of a separate field per network
* find-by-sources cards include social_posts

= 1.0.0 =
* Initial release
