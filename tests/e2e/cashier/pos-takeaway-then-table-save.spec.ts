import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import {
  clickFirstAddableItem,
  dismissOrderSuccessModal,
  readCartNet,
  saveOrderOnly,
  selectFirstAvailableTable,
} from '../helpers/pos';

test.describe('cashier: takeaway then table save in same session', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
  });

  test('table save works after takeaway save without stale edit id', async ({ page }) => {
    await clickFirstAddableItem(page);
    await expect.poll(() => readCartNet(page)).toBeGreaterThan(0);

    const takeawaySave = page.waitForResponse((response) => {
      return response.url().includes('api/pos/index.php')
        && response.request().method() === 'POST'
        && response.url().includes('route=orders.takeaway');
    });
    await saveOrderOnly(page);
    const takeawayResponse = await takeawaySave;
    expect(takeawayResponse.ok()).toBeTruthy();
    const takeawayBody = await takeawayResponse.json();
    expect(takeawayBody.success).toBeTruthy();
    expect(takeawayBody.order_id).toBeGreaterThan(0);
    await dismissOrderSuccessModal(page);
    await expect(page.locator('.pos-order-footer .pos-save-order-btn').first()).toHaveAttribute('data-pos-save-state', 'saved');

    let duplicateTakeawaySaves = 0;
    const duplicateListener = (request: { url: () => string; method: () => string }) => {
      if (request.url().includes('api/pos/index.php')
        && request.method() === 'POST'
        && request.url().includes('route=orders.takeaway')) {
        duplicateTakeawaySaves += 1;
      }
    };
    page.on('request', duplicateListener);
    await saveOrderOnly(page);
    page.off('request', duplicateListener);
    expect(duplicateTakeawaySaves).toBe(0);

    await selectFirstAvailableTable(page);

    await clickFirstAddableItem(page);
    await expect.poll(() => readCartNet(page)).toBeGreaterThan(0);

    const tableSave = page.waitForResponse((response) => {
      return response.url().includes('api/pos/index.php')
        && response.request().method() === 'POST'
        && response.url().includes('route=orders.table');
    });
    await saveOrderOnly(page);
    const tableResponse = await tableSave;
    const tableBody = await tableResponse.json();

    expect(tableResponse.ok(), JSON.stringify(tableBody)).toBeTruthy();
    expect(tableBody.success).toBeTruthy();
    expect(tableBody.order_id).toBeGreaterThan(0);
    await expect(page.locator('#posOrderSuccessModal')).toBeVisible({ timeout: 5000 });
  });
});
