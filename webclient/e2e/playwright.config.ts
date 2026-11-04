import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  timeout: 30_000,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: { baseURL: 'http://127.0.0.1:4173', trace: 'retain-on-failure' },
  webServer: { command: 'node server.mjs', url: 'http://127.0.0.1:4173/', reuseExistingServer: !process.env.CI },
  // Mock-worker specs run on the four device projects; the real-host smoke spec only in `smoke`
  // (and only when CD_E2E_* is set, see README.md).
  projects: [
    { name: 'iphone', use: { ...devices['iPhone 14'] }, testIgnore: /smoke/ },
    { name: 'pixel', use: { ...devices['Pixel 7'] }, testIgnore: /smoke/ },
    { name: 'ipad', use: { ...devices['iPad Pro 11'] }, testIgnore: /smoke/ },
    { name: 'desktop', use: { ...devices['Desktop Chrome'] }, testIgnore: /smoke/ },
    // Only defined when a real host is configured, so a plain `playwright test` never needs Chrome.
    ...(process.env.CD_E2E_URL ? [{
      name: 'smoke',
      testMatch: /smoke/,
      // A real host needs real video decoding: Chrome or Edge (H.264), not the bundled Chromium.
      use: { ...devices['Pixel 7'], channel: process.env.CD_E2E_CHANNEL || 'chrome', ignoreHTTPSErrors: true, baseURL: process.env.CD_E2E_URL },
      timeout: 120_000,
    }] : []),
  ],
});
