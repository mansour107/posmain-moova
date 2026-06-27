import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { clickFirstAddableItem, clearCartFromHeader } from '../helpers/pos';

test.describe('cashier: clear cart', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
  });

  test('header trash clears cart rows', async ({ page }) => {
    await clickFirstAddableItem(page);
    await expect(page.locator('#itemData .item-card-order')).toHaveCount(1);
    await clearCartFromHeader(page);
    await expect(page.locator('#total_display')).toHaveText(/0\.00/);
  });
});
