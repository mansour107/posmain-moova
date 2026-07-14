import { test, expect } from '@playwright/test';
import { startFreshHandoverShift } from '../helpers/handover';
import {
  clickFirstAddableItem,
  expectSaveButtonState,
  isCashierOrderSaveResponse,
  readCartNet,
  selectFirstAvailableTable,
  selectOrderModeTab,
} from '../helpers/pos';

test.describe('cashier: mode switch transfers cart', () => {
  test.beforeEach(async ({ page }) => {
    await startFreshHandoverShift(page, 'cashier');
    await expect(page.locator('#posShiftRecoveryOverlay')).toBeHidden({ timeout: 15_000 });
    await expect(page.locator('#posForm')).toBeVisible({ timeout: 20_000 });
  });

  test('switching takeaway to table keeps unsaved items and empty table accepts them', async ({ page }) => {
    await selectOrderModeTab(page, 'age1');
    await clickFirstAddableItem(page);
    await expect.poll(() => readCartNet(page)).toBeGreaterThan(0);
    await expect(page.locator('#itemData .item-card-order')).toHaveCount(1);

    await selectOrderModeTab(page, 'age2');
    await expect(page.locator('#age2')).toBeChecked();
    await expect(page.locator('#itemData .item-card-order')).toHaveCount(1);
    await expect(page.locator('.pos-mode-transfer-toast')).toBeVisible();
    await expect(page.locator('.pos-mode-transfer-toast__message')).toContainText('طاولة');

    await selectFirstAvailableTable(page);
    await expect(page.locator('#itemData .item-card-order')).toHaveCount(1);
    await expectSaveButtonState(page, 'dirty', 'حفظ');
  });

  test('switching table draft to takeaway keeps items and save still works', async ({ page }) => {
    await selectOrderModeTab(page, 'age2');
    await selectFirstAvailableTable(page);
    await clickFirstAddableItem(page);
    await expect.poll(() => readCartNet(page)).toBeGreaterThan(0);
    await expect(page.locator('#itemData .item-card-order')).toHaveCount(1);

    await selectOrderModeTab(page, 'age1');
    await expect(page.locator('#age1')).toBeChecked();
    await expect(page.locator('#itemData .item-card-order')).toHaveCount(1);
    await expectSaveButtonState(page, 'dirty', 'حفظ');
    await expect(page.locator('.pos-mode-transfer-toast__message')).toContainText('تيك اواي');

    const saveResponse = page.waitForResponse((response) => isCashierOrderSaveResponse(response));
    await page.locator('.pos-order-footer .pos-save-order-btn').first().click({ force: true });
    const response = await saveResponse;
    const body = await response.json();
    expect(response.ok(), JSON.stringify(body)).toBeTruthy();
    expect(body.success).toBeTruthy();
    await expect(page.locator('#itemData .item-card-order')).toHaveCount(0);
    await expectSaveButtonState(page, 'empty', 'حفظ');
  });

  test('switching takeaway to delivery keeps items while delivery modal opens', async ({ page }) => {
    await selectOrderModeTab(page, 'age1');
    await clickFirstAddableItem(page);
    await expect(page.locator('#itemData .item-card-order')).toHaveCount(1);

    await selectOrderModeTab(page, 'age3');
    await expect(page.locator('#age3')).toBeChecked();
    await expect(page.locator('#itemData .item-card-order')).toHaveCount(1);
    await expect(page.locator('#deliveryModal')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('.pos-mode-transfer-toast__message')).toContainText('دليفري');
  });
});
