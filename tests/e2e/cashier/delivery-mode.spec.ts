import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { selectDeliveryMode } from '../helpers/pos';

test.describe('cashier: delivery mode', () => {
  test('delivery mode exposes customer UI hooks', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');

    await selectDeliveryMode(page);
    await expect(page.locator('#deliveryModal')).toBeVisible();
    await expect(page.locator('#customer_phone')).toBeVisible();
  });
});
