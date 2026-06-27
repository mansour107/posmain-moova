import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { clickFirstAddableItem } from '../helpers/pos';

test.describe('cashier: print receipt', () => {
  test('print receipt action submits without fatal errors', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    await clickFirstAddableItem(page);

    const printButton = page.locator('.pos-order-footer .pos-print-order-btn');
    await printButton.scrollIntoViewIfNeeded();
    await expect(printButton).toBeVisible();

    await Promise.all([
      page.waitForLoadState('load'),
      printButton.click(),
    ]);

    const body = await page.content();
    expect(body).not.toMatch(/fatal error|SQL syntax/i);
  });
});
