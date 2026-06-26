import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { clickFirstAddableItem, payCashInModal, readCartNet } from '../helpers/pos';

test.describe('cashier: takeaway payment', () => {
  test('cashier pays takeaway order with cash', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    await clickFirstAddableItem(page);

    const net = await readCartNet(page);
    expect(net).toBeGreaterThan(0);

    await Promise.all([
      page.waitForLoadState('load'),
      payCashInModal(page, net),
    ]);

    const body = await page.content();
    expect(body).not.toMatch(/fatal error|SQL syntax/i);
  });
});
