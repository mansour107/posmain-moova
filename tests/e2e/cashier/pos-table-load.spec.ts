import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { clickFirstAddableItem, selectFirstAvailableTable, saveTableOrderAndWait } from '../helpers/pos';

test.describe('cashier: table load', () => {
  test('occupied table path remains reachable after UI refresh', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    await selectFirstAvailableTable(page);
    await clickFirstAddableItem(page);
    await expect(page.locator('#itemData .item-card-order')).toHaveCount(1);
    await saveTableOrderAndWait(page);
    const body = await page.content();
    expect(body).not.toMatch(/fatal error|SQL syntax/i);
  });
});
