import { defineConfig, devices } from '@playwright/test';
import path from 'path';

const baseURL = process.env.POSMAIN_TEST_HTTP_BASE || process.env.POSMAIN_LOCAL_POS_URL || 'http://127.0.0.1:8010';
const campaignRunId = process.env.POSMAIN_QA_RUN_ID || '';
const campaignEnv = process.env.POSMAIN_QA_ENV || 'local';
const campaignArtifactRoot = campaignRunId
  ? path.join('var', 'qa', campaignRunId, campaignEnv)
  : '';
const jsonReportFile = campaignArtifactRoot
  ? path.join(campaignArtifactRoot, 'playwright.json')
  : 'test-results.json';
const htmlReportFolder = campaignArtifactRoot
  ? path.join(campaignArtifactRoot, 'playwright-report')
  : 'playwright-report';

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [
    ['list'],
    ['json', { outputFile: jsonReportFile }],
    ['html', { open: 'never', outputFolder: htmlReportFolder }],
  ],
  timeout: 90_000,
  expect: { timeout: 15_000 },
  use: {
    baseURL,
    locale: 'ar-EG',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    // Apple Silicon + Playwright's headless-shell registry can resolve to the mac-x64
    // folder name while only the mac-arm64 binary is installed, causing a SIGSEGV when
    // the symlinked arm64 binary is launched as x64. Pin the executable to the working
    // arm64 headless shell so launches are stable across dev/CI machines.
    launchOptions: {
      executablePath:
        process.env.POSMAIN_PLAYWRIGHT_EXECUTABLE ||
        (process.arch === 'arm64' && process.platform === 'darwin'
          ? require('path').join(
              process.env.PLAYWRIGHT_BROWSERS_PATH ||
                require('os').homedir() + '/Library/Caches/ms-playwright',
              'chromium_headless_shell-1228/chrome-headless-shell-mac-arm64/chrome-headless-shell',
            )
          : undefined),
      chromiumSandbox: false,
    },
  },
  projects: [
    {
      name: 'shared',
      testMatch: /shared\/.*\.spec\.ts/,
    },
    {
      name: 'cashier',
      testMatch: /cashier\/.*\.spec\.ts/,
    },
    {
      name: 'waiter',
      testMatch: /waiter\/.*\.spec\.ts/,
    },
    {
      name: 'manager',
      testMatch: /manager\/.*\.spec\.ts/,
    },
    {
      name: 'owner',
      testMatch: /owner\/.*\.spec\.ts/,
    },
    {
      name: 'sync_ops',
      testMatch: /sync_ops\/.*\.spec\.ts/,
    },
  ],
});
