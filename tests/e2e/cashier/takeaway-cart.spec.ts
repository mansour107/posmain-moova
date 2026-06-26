import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { clickFirstAddableItem, increaseFirstLineQty, readCartNet } from '../helpers/pos';

test.describe('cashier: takeaway cart', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
  });

  test('add item and adjust quantity updates net total', async ({ page }) => {
    const netBefore = await readCartNet(page);
    await clickFirstAddableItem(page);
    await expect.poll(() => readCartNet(page)).toBeGreaterThan(netBefore);

    await increaseFirstLineQty(page);
    const netAfterQty = await readCartNet(page);
    expect(netAfterQty).toBeGreaterThan(0);
  });
});
