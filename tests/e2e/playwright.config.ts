// Playwright settings for the App banners browser test (one worker: the WordPress stack is shared).
import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: ".",
  workers: 1,
  retries: 0,
  timeout: 60_000,
  reporter: [["list"]],
  use: { trace: "retain-on-failure" },
});
