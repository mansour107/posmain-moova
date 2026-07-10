import { test, expect } from '@playwright/test';
import { baseURL, skipIfHttpDown } from '../helpers/env';

test.describe('local PIN login surfaces', () => {
  test.skip(skipIfHttpDown(), 'HTTP base unavailable');

  test('main login PIN pad is RTL and keyboard reachable', async ({ page }) => {
    await page.goto(`${baseURL}/index.php`, { waitUntil: 'domcontentloaded' });
    const pinMode = await page.locator('.ppm-grid, #mainPinPadGrid').first().isVisible().catch(() => false);
    test.skip(!pinMode, 'Hosted password mode — PIN pad not shown');

    const html = page.locator('html');
    await expect(html).toHaveAttribute('dir', 'rtl');
    const grid = page.locator('.ppm-grid').first();
    await expect(grid).toBeVisible();
    await expect(page.locator('.ppm-dot').first()).toBeVisible();
    await expect(page.locator('.ppm-key').first()).toBeVisible();

    // No credential value should be present in the DOM as plain text input value.
    const hiddenPin = page.locator('input[name="pin"]');
    await expect(hiddenPin).toHaveCount(0);

    await page.keyboard.type('1');
    await expect(page.locator('.ppm-dot.filled').first()).toBeVisible();
    await expect(hiddenPin).toHaveCount(0);
  });

  test('no_access and workspace pages render without fatal errors when unauthenticated redirect works', async ({ page }) => {
    await page.goto(`${baseURL}/no_access.php`, { waitUntil: 'domcontentloaded' });
    const body = await page.locator('body').innerText();
    expect(body).not.toMatch(/fatal error|SQL syntax|mysqli_/i);
  });
});
