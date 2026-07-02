import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import {
  clickFirstAddableItem,
  expectSaveButtonState,
  isCashierOrderSaveResponse,
  readCartNet,
  selectFirstAvailableTable,
  selectOrderModeTab,
} from '../helpers/pos';

test.describe('cashier: mode switch clears cart context', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
  });

  test('switching table to takeaway clears items and save button is not stuck', async ({ page }) => {
    await selectOrderModeTab(page, 'age2');
    await selectFirstAvailableTable(page);
    await clickFirstAddableItem(page);
    await expect.poll(() => readCartNet(page)).toBeGreaterThan(0);
    await expect(page.locator('#itemData .item-card-order')).toHaveCount(1);

    await selectOrderModeTab(page, 'age1');
    await expect(page.locator('#itemData .item-card-order')).toHaveCount(0);
    await expectSaveButtonState(page, 'empty', 'حفظ');

    await clickFirstAddableItem(page);
    await expect.poll(() => readCartNet(page)).toBeGreaterThan(0);
    await expectSaveButtonState(page, 'dirty', 'حفظ');

    const saveResponse = page.waitForResponse((response) => isCashierOrderSaveResponse(response));
    await page.locator('.pos-order-footer .pos-save-order-btn').first().click({ force: true });
    const response = await saveResponse;
    const body = await response.json();
    expect(response.ok(), JSON.stringify(body)).toBeTruthy();
    expect(body.success).toBeTruthy();
    await expect(page.locator('#itemData .item-card-order')).toHaveCount(0);
    await expectSaveButtonState(page, 'empty', 'حفظ');
  });
});
