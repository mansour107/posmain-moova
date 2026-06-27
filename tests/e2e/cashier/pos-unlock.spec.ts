import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';

test.describe('cashier: POS unlock', () => {
  test('cashier unlocks POS barcode screen', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    await expect(page.locator('#posForm')).toBeVisible();
    await expect(page.locator('body')).toHaveClass(/pos-premium-dark/);
    await expect(page.locator('.pos-topbar')).toBeHidden();
    await expect(page.locator('.item-wrapper').first()).toBeVisible({ timeout: 20_000 });
  });
});
