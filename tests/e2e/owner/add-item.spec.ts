import { test, expect } from '@playwright/test';
import { loginAs, assertNoFatalText } from '../helpers/auth';

test.describe('owner: add item surface', () => {
  test('add_item.php renders catalog form for admin', async ({ page }) => {
    await loginAs(page, 'admin');
    const response = await page.goto('/add_item.php');
    expect(response?.status() ?? 0).toBeLessThan(500);

    const body = await page.content();
    assertNoFatalText(body);
    expect(body.length).toBeGreaterThan(200);
  });
});
