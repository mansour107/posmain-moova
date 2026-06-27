import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { clickFirstAddableItem, increaseFirstLineQty, readCartNet } from '../helpers/pos';

test.describe('cashier: takeaway cart', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
  });

  test('add item and adjust quantity updates net total', async ({ page }) => {
    await expect(page.locator('body')).toHaveClass(/pos-premium-dark/);
    const netBefore = await readCartNet(page);
    await clickFirstAddableItem(page);
    await expect.poll(() => readCartNet(page)).toBeGreaterThan(netBefore);
    await expect(page.locator('#itemData .pos-cart-row')).toHaveCount(1);

    await increaseFirstLineQty(page);
    const netAfterQty = await readCartNet(page);
    expect(netAfterQty).toBeGreaterThan(0);
    await expect(page.locator('#total_display')).not.toHaveText('0.00 ج.م');
  });
});
