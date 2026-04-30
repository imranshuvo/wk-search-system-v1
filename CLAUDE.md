# wk-search-system-v1

Legacy/frozen WooCommerce search product. managed at search.techgrow.ltd. Security and critical bug fixes. Qliving.com has this version. 

Active development happens in a separate workspace, `wk-search-system-v2`. If a task feels like a feature, cleanup, or "let's modernize this," it belongs there, not here.

## Layout

- `tg-woo-fast-search/` — WordPress plugin, version 2.0.0. Single ~1839-line `woo-fast-search.php` monolith plus modules in `includes/`: `FeedEmitter`, `DeltaSync`, `ApiClient`, `RenderController`, `OverlayLoader`, `SearchOverlay`, `Settings`, `Plugin`, `Installer`, `Admin/`, `CLI/`.
- `search-api/` — Laravel backend the plugin talks to (the "edge API" that `wk_fast_search_edge_url` points at). Controllers under `Admin/`, `Api/`, `Serve/`.

## Working mechanism in one paragraph

The plugin emits `/uploads/wk-search/{tenant}/products.json` and delta-syncs product changes to `search-api` via `ApiClient::ingestProducts()`. On the storefront, `OverlayLoader` enqueues `assets/js/search-overlay.js`, which calls `${edge_url}/api/serve/search` directly from the browser (no WP AJAX hop). The api returns ranked product IDs; the plugin's `RenderController` then renders native WooCommerce product cards locally via the `wkss_render_products` AJAX action.

## Working in this repo

- One long-lived branch: `main`. Hotfix branches off `main`, merge back, tag `v2.0.x` on release.
- Client deploys are pinned to tags, never to `main`.
- Do not port patterns or code from v2 — the two systems have diverged and are not source-compatible (v2 plugin is modular/namespaced under `WKSearchSystem\*`; v2 api adds merchandising, pinning, analytics, queue ingestion, and several new endpoints v1 does not understand).
- If a fix needs to land in both v1 and v2, do it as two independent changes.
