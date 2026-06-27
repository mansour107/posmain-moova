import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { clickFirstAddableItem, selectDeliveryMode, payCashInModal } from '../helpers/pos';

test.describe('cashier: delivery checkout', () => {
  test('delivery customer confirm then pay remains functional', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    await selectDeliveryMode(page);

    await page.locator('#customer_phone').fill('01099998888');
    await page.locator('#customer_name').fill('عميل تجريبي');
    await page.locator('#customer_address').fill('القاهرة - المعادي');

    const zoneSelect = page.locator('#delivery_zone_id, #delivery_zone, select[name="delivery_zone_id"]').first();
    if (await zoneSelect.isVisible().catch(() => false)) {
      const optionCount = await zoneSelect.locator('option').count();
      if (optionCount > 1) {
        await zoneSelect.selectOption({ index: 1 });
      }
    }

    const confirmButton = page.locator('#confirmOrderBtn, #saveCustomerBtn').first();
    await confirmButton.click();
    await page.locator('#deliveryModal').waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => undefined);

    await clickFirstAddableItem(page);
    await Promise.all([
      page.waitForURL(/print\/receipt\.php|pos_barcode\.php/, { timeout: 30_000 }),
      payCashInModal(page),
    ]);

    const body = await page.content();
    expect(body).not.toMatch(/fatal error|SQL syntax/i);
  });
});
