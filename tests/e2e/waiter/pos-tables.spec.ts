import { test, expect } from '@playwright/test';
import { loginAs, assertNoFatalText } from '../helpers/auth';

test.describe('waiter: pos_tables surface', () => {
  test('pos_tables.php loads for waiter session', async ({ page }) => {
    await loginAs(page, 'waiter');
    const response = await page.goto('/pos_tables.php');
    expect(response?.status() ?? 0).toBeLessThan(500);

    const body = await page.content();
    assertNoFatalText(body);
    await expect(page.locator('#tables-section, #pos-section').first()).toBeVisible({ timeout: 15_000 });
  });
});
