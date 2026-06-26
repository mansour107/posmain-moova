import { test, expect } from '@playwright/test';
import { loginAs, assertNoFatalText } from '../helpers/auth';

test.describe('owner: inventory stock levels', () => {
  test('inventory stock levels page renders for admin', async ({ page }) => {
    await loginAs(page, 'admin');
    const response = await page.goto('/inventory_stock_levels.php');
    expect(response?.status() ?? 0).toBeLessThan(500);

    const body = await page.content();
    assertNoFatalText(body);
    expect(body).toMatch(/مستويات المخزون|stock/i);
  });
});
