import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { clickFirstAddableItem, readCartNet, saveOrderOnly } from '../helpers/pos';

test.describe('cashier: takeaway save without reload', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
  });

  test('save posts to API and shows success modal without navigation', async ({ page }) => {
    const netBefore = await readCartNet(page);
    await clickFirstAddableItem(page);
    await expect.poll(() => readCartNet(page)).toBeGreaterThan(netBefore);

    const initialUrl = page.url();
    const apiResponse = page.waitForResponse((response) => {
      return response.url().includes('api/pos/index.php')
        && response.request().method() === 'POST'
        && response.url().includes('route=orders.takeaway');
    });

    await saveOrderOnly(page);
    const response = await apiResponse;
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.success).toBeTruthy();
    expect(body.order_id).toBeGreaterThan(0);

    await expect(page.locator('#posOrderSuccessModal')).toBeVisible({ timeout: 5000 });
    expect(new URL(page.url()).pathname).toBe(new URL(initialUrl).pathname);

    const totalText = await page.locator('#total_display').innerText();
    expect(totalText).not.toMatch(/^0\.00/);
  });
});
