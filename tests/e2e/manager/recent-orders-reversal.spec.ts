import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';

test.describe('manager: recent orders reversal UI', () => {
  test('recent orders payload exposes reversal capability fields when orders exist', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');

    const response = await page.request.get('/ajax/get_recent_orders.php');
    expect(response.status()).toBeLessThan(500);

    const payload = await response.json();
    expect(payload).toHaveProperty('success');

    if (payload.success && Array.isArray(payload.orders) && payload.orders.length > 0) {
      const first = payload.orders[0];
      expect(first).toHaveProperty('can_refund');
      expect(first).toHaveProperty('can_void');
    }
  });

  test('refund endpoint rejects GET', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');
    const response = await page.request.get('/ajax/refund_order.php');
    expect(response.status()).toBeLessThan(500);
    const payload = await response.json().catch(() => ({}));
    expect(JSON.stringify(payload)).toMatch(/METHOD_NOT_ALLOWED|غير مسموح|not allowed/i);
  });
});
