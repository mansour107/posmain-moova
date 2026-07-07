import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import {
  clickFirstAddableItem,
  openPaymentModal,
  payCashInModal,
  readCartNet,
  selectFirstAvailableTable,
} from '../helpers/pos';

test.describe('cashier: table pay without prior save', () => {
  test('can pay a new table order directly after adding items', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');

    await selectFirstAvailableTable(page);
    await clickFirstAddableItem(page);
    await expect.poll(() => readCartNet(page)).toBeGreaterThan(0);

    const paymentResponse = page.waitForResponse((response) =>
      response.url().includes('api/pos/index.php')
      && response.request().method() === 'POST'
      && response.url().includes('route=orders.payment'),
    );

    await payCashInModal(page);

    const response = await paymentResponse;
    const body = await response.json();
    expect(response.ok(), JSON.stringify(body)).toBeTruthy();
    expect(body.success).toBeTruthy();
    expect(Number(body.order_id)).toBeGreaterThan(0);

    const bodyText = await page.content();
    expect(bodyText).not.toMatch(/لا يوجد طلب نشط لهذه الطاولة/i);
  });

  test('payment modal opens for unsaved table cart', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    await selectFirstAvailableTable(page);
    await clickFirstAddableItem(page);
    await openPaymentModal(page);
    await expect(page.locator('#paymentModal')).toBeVisible();
    await expect(page.locator('.pos-pay-confirm-btn')).toBeVisible();
  });
});
