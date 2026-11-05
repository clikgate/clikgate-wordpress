# App banners browser test (WordPress + WooCommerce)

Written, **not yet run**: it needs a WordPress stack, a Clikgate API in test mode and a browser, which the default
CI job doesn't have. The owner or CI runs it on a machine with room for it.

## What it checks (emulated iPhone 13)

1. A product page shows the banner; `window.ClikgatePage` is `product` with the SKU and no price.
2. The banner's link carries `cg_page` with the path only (no query or fragment) plus `page_type`,
   `wc_product_id`, `sku`, `product_slug` and nothing else.
3. Opening the test app with that link (`POST /v1/sdk/resolve`, test SDK key) answers the mapped app screen
   (`deep_link.data.app_path` = `/products/jollof-spice-mix`) and the product fields.
4. Checkout (`ClikgatePage.type` = `checkout`) and cart show no banner.

## Stack (`docker-compose.yml`)

- `db`: MariaDB 11.4.3 (in memory).
- `wordpress`: WordPress 6.7.1 on PHP 8.2 (Apache), port 8080 on your machine, with this plugin mounted read-only
  and `CLIKGATE_API_BASE`, `CLIKGATE_SCRIPT_URL` and `CLIKGATE_APP_BANNERS_URL` pointing at `CLIKGATE_API_URL`.
- `wpcli` (profile `tools`): seeds WooCommerce 9.4.3, the Storefront 4.6.0 theme (classic theme), checkout pages,
  pretty permalinks and the product "Jollof spice mix" (`JSM-250`).

## Running it

```sh
export CLIKGATE_API_URL=...          # the Clikgate API under test, reachable from the containers and the browser
export WP_BASE_URL=...               # how the browser reaches the WordPress container (port 8080)
docker compose up -d
docker compose run --rm wpcli sh /seed/seed.sh     # prints WP_PRODUCT_ID
```

Then, once, in the Clikgate test organisation (Growth plan, test mode):

1. Verify the shop's website (Settings › Developers › Websites).
2. Settings › Clikgate in WordPress: "Connect to Clikgate" and approve it in test mode.
3. Turn on "Show my App banners on this site", approve the request in Clikgate with your security code, then "Check
   again" in WordPress.
4. On the new App banner site add the WooCommerce "product" rule (`/product/{slug}` → `/products/{slug}`), and
   publish a banner on a link of the test app (with app links set up), targeting product pages.

```sh
export WP_PRODUCT_ID=...             # from the seed step
export CLIKGATE_TEST_SDK_KEY=cg_sdk_test_...
npx playwright@1.48.2 test --config playwright.config.ts
```

The block-theme run is the same with `wp theme install twentytwentyfive --activate` instead of Storefront.
