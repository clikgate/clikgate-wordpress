// App banners on a WordPress + WooCommerce test shop, in an emulated phone (Playwright).
// Needs the stack from docker-compose.yml in this folder and a Clikgate API in test mode; see README.md here.
// Not run in the default CI job: it needs the WordPress stack, a Clikgate API and a browser.
import { test, expect, devices, type Page } from "@playwright/test";

const shop = required("WP_BASE_URL"); // the WordPress container, e.g. http://wordpress.e2e.example:8080
const api = required("CLIKGATE_API_URL"); // the Clikgate API the plugin and the loader use (test mode)
const sdkKey = required("CLIKGATE_TEST_SDK_KEY"); // cg_sdk_test_... of the test app (for the resolve step)
// The seeded product and the screen rule set up on the test App banner site: /product/{slug} -> /products/{slug}
const PRODUCT = { slug: "jollof-spice-mix", sku: "JSM-250", appPath: "/products/jollof-spice-mix" };

function required(name: string): string {
  const value = process.env[name];
  if (!value) throw new Error(`${name} is not set (see tests/e2e/README.md)`);
  return value.replace(/\/$/, "");
}

test.use({ ...devices["iPhone 13"] });

/** The CTA link of the banner the loader rendered (inside its open shadow root), or null when none is shown. */
async function bannerHref(page: Page): Promise<string | null> {
  const host = page.locator("[data-clikgate-app-banner]");
  try {
    await host.waitFor({ state: "attached", timeout: 10_000 });
  } catch {
    return null;
  }
  return host.evaluate((el) => el.shadowRoot?.querySelector("[part=cta]")?.getAttribute("href") ?? null);
}

test("a product page shows the banner and the tap carries the product", async ({ page }) => {
  await page.goto(`${shop}/product/${PRODUCT.slug}/`);
  const pageData = await page.evaluate(() => (window as unknown as { ClikgatePage?: Record<string, unknown> }).ClikgatePage);
  expect(pageData?.type).toBe("product");
  expect(pageData?.sku).toBe(PRODUCT.sku);
  expect(pageData).not.toHaveProperty("price");

  const href = await bannerHref(page);
  expect(href, "a banner is shown on the product page").not.toBeNull();
  const cta = new URL(href!);
  expect(cta.searchParams.get("jrn")).toMatch(/^jrn_/);
  const carried = new URL(cta.searchParams.get("cg_page")!, "https://page.invalid");
  expect(carried.pathname).toBe(`/product/${PRODUCT.slug}/`);
  expect(carried.searchParams.get("page_type")).toBe("product");
  expect(carried.searchParams.get("wc_product_id")).toMatch(/^\d+$/);
  expect(carried.searchParams.get("sku")).toBe(PRODUCT.sku);
  expect(carried.searchParams.get("product_slug")).toBe(PRODUCT.slug);
  // Only allowlisted keys travel: the page's own query never does
  for (const key of carried.searchParams.keys()) {
    expect(["page_type", "wc_product_id", "sku", "product_slug", "site_key"]).toContain(key);
  }
});

test("the test app link resolves to the mapped app screen", async ({ page, request }) => {
  await page.goto(`${shop}/product/${PRODUCT.slug}/?utm_source=e2e#reviews`);
  const href = await bannerHref(page);
  expect(href).not.toBeNull();
  // The app opened through its app link with the banner's URL, as the SDK does after a tap
  const response = await request.post(`${api}/v1/sdk/resolve`, {
    headers: { "Clikgate-Sdk-Key": sdkKey, "Clikgate-Installation-Id": crypto.randomUUID() },
    data: { url: href },
  });
  expect(response.status()).toBe(200);
  const body = await response.json();
  expect(body.matched).toBe(true);
  const data = body.deep_link.data;
  expect(data.page).toBe(`/product/${PRODUCT.slug}/`);
  expect(data.sku).toBe(PRODUCT.sku);
  expect(data.product_slug).toBe(PRODUCT.slug);
  expect(data.app_path).toBe(PRODUCT.appPath);
});

test("checkout, cart and order pages never show a banner", async ({ page }) => {
  // Put the product in the cart first, so the checkout page renders its form
  await page.goto(`${shop}/?add-to-cart=${encodeURIComponent(process.env.WP_PRODUCT_ID ?? "")}`);
  for (const path of ["/checkout/", "/cart/"]) {
    await page.goto(`${shop}${path}`);
    if (path === "/checkout/") {
      const type = await page.evaluate(() => (window as unknown as { ClikgatePage?: { type?: string } }).ClikgatePage?.type);
      expect(type).toBe("checkout");
    }
    expect(await bannerHref(page), `no banner on ${path}`).toBeNull();
  }
});
