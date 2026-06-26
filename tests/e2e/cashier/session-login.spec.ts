import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';

test.describe('cashier: session login', () => {
  test('cashier reaches dashboard after login', async ({ page }) => {
    await loginAs(page, 'cashier');
    await expect(page).toHaveURL(/dashboard\.php/);
    await expect(page.locator('.content-wrapper')).toBeVisible();
  });
});
