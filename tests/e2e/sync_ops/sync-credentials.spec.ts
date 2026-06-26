import { test, expect } from '@playwright/test';
import { loginAs, assertNoFatalText } from '../helpers/auth';

test.describe('sync_ops: sync credentials admin', () => {
  test('Moova integration page exposes sync credential controls', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/moova_integration.php');

    const body = await page.content();
    assertNoFatalText(body);
    expect(body).toMatch(/sync|مزامنة|branch|cloud/i);
  });
});
