=== Easy MCP Polylang Ability Extender ===
Contributors: siteowner
Tags: mcp, polylang, easy-mcp-ai, multilingual, abilities
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Extends Easy MCP AI with Polylang language abilities so AI clients can set/get post language and create posts with language in one call.

== Description ==

This plugin registers five WordPress Abilities that Easy MCP AI / MCP Adapter expose as MCP tools:

1. **easy-mcp-polylang/list-languages** – list all Polylang languages
2. **easy-mcp-polylang/get-post-language** – get language + translations of a post
3. **easy-mcp-polylang/set-post-language** – set language of an existing post
4. **easy-mcp-polylang/link-post-translations** – link several posts as translations
5. **easy-mcp-polylang/create-post-with-language** – create a post AND set language in one call (optional: categories, tags, Yoast SEO, source_urls, social_titles, social_posts)

Architecture (clean / layered):

* Domain – LanguageRepositoryInterface
* Application – Interactors (Set / Get / List / Link / CreateWithLanguage)
* Infrastructure – PolylangLanguageRepository + DI Container
* Ability – RegisterAbilities (wp_register_ability + category)

Requirements:

* Polylang or Polylang Pro active
* Easy MCP AI recommended (to surface the abilities as MCP tools)
* WordPress 6.9+ for Abilities API (REST fallback routes always work)

== Installation ==

1. Upload the `easy-mcp-polylang-extender` folder to `/wp-content/plugins/`
2. Activate the plugin
3. Refresh / reconnect the tool list in your AI client
4. You should see the five Polylang tools (category: polylang)

== Usage from AI ==

**One-shot create with language (recommended):**

```
Call tool: easy-mcp-polylang/create-post-with-language
Arguments: {
  "title": "Mein Artikel",
  "content": "<p>Inhalt…</p>",
  "language": "de",
  "status": "draft",
  "category_names": ["Nachrichten"],
  "tags": ["tag1", "tag2", "tag3", "tag4", "tag5"],
  "yoast_title": "SEO Titel",
  "yoast_description": "Meta description…",
  "yoast_focus_keyword": "keyword",
  "source_urls": ["https://example.com/original-story"],
  "social_titles": ["😢 Clickbait headline 💔"],
  "social_posts": { "facebook": "" }
}
```

**REST fallback (if abilities not discovered yet):**

* GET  /wp-json/easy-mcp-polylang/v1/list-languages
* POST /wp-json/easy-mcp-polylang/v1/set-post-language
* POST /wp-json/easy-mcp-polylang/v1/create-post-with-language

== Changelog ==

= 1.2.6 =
* Writes _emp_social_posted index with social_posts for Extra Fields find-by-social

= 1.2.5 =
* Optional social_posts object on create-post-with-language (Extra Fields active only)

= 1.2.4 =
* No plugin dependency on Extra Fields; source_urls/social_titles stored only if that plugin is active

= 1.2.3 =
* Requires Plugins: easy-mcp-post-meta (removed in 1.2.4)

= 1.2.2 =
* create-post-with-language accepts source_urls and social_titles (same meta as Easy MCP Post Extra Fields)

= 1.2.1 =
* Fix: set Polylang language before tags so tags stay on the post in the chosen language
* Fail visibly if tags cannot be assigned after create

= 1.2.0 =
* Fix: register ability category on wp_abilities_api_categories_init
* Fix: use correct hook wp_abilities_api_init (was abilities_api_init)
* Fix: meta.public + show_in_rest + mcp.public so abilities appear in MCP and REST
* Abilities now show under /wp-abilities/v1/abilities and as MCP tools after reconnect

= 1.1.0 =
* New tool: easy-mcp-polylang/create-post-with-language
* Creates post + sets Polylang language in one call
* Optional: categories, tags, featured image, Yoast SEO fields

= 1.0.0 =
* Initial release
* set / get / list / link abilities
* Clean architecture + REST fallback routes
