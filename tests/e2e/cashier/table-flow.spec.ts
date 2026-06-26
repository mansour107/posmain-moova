import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { clickFirstAddableItem, readCartNet, saveTableOrderAndWait, selectFirstAvailableTable } from '../helpers/pos';

test.describe('cashier: table flow', () => {
  test('select table, add item, save order', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');

    await selectFirstAvailableTable(page);
    await clickFirstAddableItem(page);

    const net = await readCartNet(page);
    expect(net).toBeGreaterThan(0);

    await saveTableOrderAndWait(page);

    const body = await page.content();
    expect(body).not.toMatch(/fatal error|SQL syntax/i);
  });
});
