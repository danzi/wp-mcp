# WP FB Helper

Chrome extension for posting WordPress news to Facebook.

Visual language is copied from Flow Hub (dark panel, chips, copy buttons). There is no Flow Hub API and no sync service.

## Why not MCP

MCP is for Cursor. The browser cannot use that session. Settings store a WordPress **Application Password** and call the same Extra Fields REST routes (`list-posts`, `filters`, `fields`). Use the same WP user as MCP.

WP Admin → Users → Profile → Application Passwords.

Needs **Easy MCP Post Extra Fields 1.3.0+**.

## Toolbar icon

Settings: WordPress login + **Panel** tab (S/M/L/XL, dock icon fixed/edge/hidden, look, hide delays, hover, vertical position) — same chrome as Flow Hub.

On Facebook: dock tab **WP**, size buttons, hide-to-side, vertical resize, drag. Content is Main / Extra.

## On facebook.com

Tabs in the panel: **Main** (WordPress post) and **Extra** (local text + history: copy / edit / delete, not sent to WordPress).

Floating panel (drag / collapse like Flow Hub):

1. **Choose post** — search, language, category, Facebook posted/not, date sort
2. Fields (copy only, except social URLs via listen/refresh):
   1. Title
   2. Social titles
   3. Social posts — one field per network, currently Facebook only, **Refresh** reloads from WordPress

## Facebook URL capture

A MAIN-world hook listens to `fetch` / XHR. Default URL fragment: `graph.facebook.com`. When a permalink is found and a post is selected, it writes `social_posts.facebook` on that WordPress post.

Replace the URL fragment in settings when you have the real publish request.

## Load unpacked

Chrome → Extensions → Developer mode → Load unpacked → this folder.
