import { test, expect } from '@playwright/test';
import { loginAs, assertNoFatalText } from '../helpers/auth';

test.describe('owner: Moova integration', () => {
  test('moova integration admin page renders', async ({ page }) => {
    await loginAs(page, 'admin');
    const response = await page.goto('/moova_integration.php');
    expect(response?.status() ?? 0).toBeLessThan(500);

    const body = await page.content();
    assertNoFatalText(body);
    expect(body).toMatch(/Moova|موفا|sync/i);
  });
});
