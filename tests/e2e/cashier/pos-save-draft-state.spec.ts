import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import {
  clickFirstAddableItem,
  dismissOrderSuccessModal,
  expectSaveButtonState,
  isCashierOrderSaveResponse,
  isCashierOrderSaveUrl,
  readCartNet,
  saveOrderOnly,
} from '../helpers/pos';

test.describe('cashier: save draft state', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
  });

  test('save clears the order screen and blocks duplicate save until items change', async ({ page }) => {
    await clickFirstAddableItem(page);
    await expect.poll(() => readCartNet(page)).toBeGreaterThan(0);
    await expectSaveButtonState(page, 'dirty', 'حفظ');

    const firstSave = page.waitForResponse((response) => isCashierOrderSaveResponse(response));
    await saveOrderOnly(page);
    const firstResponse = await firstSave;
    const firstBody = await firstResponse.json();
    expect(firstBody.success).toBeTruthy();
    expect(firstBody.order_id).toBeGreaterThan(0);
    await dismissOrderSuccessModal(page);

    await expect(page.locator('#itemData .item-card-order')).toHaveCount(0);
    await expectSaveButtonState(page, 'empty', 'حفظ');

    let duplicateSaveRequests = 0;
    const duplicateListener = (request: { url: () => string; method: () => string }) => {
      if (isCashierOrderSaveUrl(request.url(), request.method())) {
        duplicateSaveRequests += 1;
      }
    };
    page.on('request', duplicateListener);
    await saveOrderOnly(page);
    page.off('request', duplicateListener);
    expect(duplicateSaveRequests).toBe(0);

    await clickFirstAddableItem(page);
    await expectSaveButtonState(page, 'dirty', 'حفظ');

    const secondSave = page.waitForResponse((response) => isCashierOrderSaveResponse(response));
    await saveOrderOnly(page);
    const secondResponse = await secondSave;
    const secondBody = await secondResponse.json();
    expect(secondBody.success).toBeTruthy();
    expect(secondBody.order_id).toBeGreaterThan(0);
    await dismissOrderSuccessModal(page);
    await expect(page.locator('#itemData .item-card-order')).toHaveCount(0);
    await expectSaveButtonState(page, 'empty', 'حفظ');
  });
});
