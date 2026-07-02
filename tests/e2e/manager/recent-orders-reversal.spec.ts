import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';

test.describe('manager: recent orders reversal UI', () => {
  test('recent orders payload hides paid reversal capabilities for manager', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');

    const response = await page.request.get('/ajax/get_recent_orders.php');
    expect(response.status()).toBeLessThan(500);

    const payload = await response.json();
    expect(payload).toHaveProperty('success');

    if (payload.success && Array.isArray(payload.orders) && payload.orders.length > 0) {
      for (const order of payload.orders) {
        expect(order.can_refund).toBeFalsy();
        expect(order.can_void).toBeFalsy();
      }
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
