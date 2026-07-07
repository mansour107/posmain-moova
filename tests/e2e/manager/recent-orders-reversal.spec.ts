import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';

test.describe('manager: recent orders reversal UI', () => {
  test('recent orders payload exposes eligibility for paid completed orders', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');

    const response = await page.request.get('/ajax/get_recent_orders.php');
    expect(response.status()).toBeLessThan(500);

    const payload = await response.json();
    expect(payload).toHaveProperty('success');

    if (payload.success && Array.isArray(payload.orders) && payload.orders.length > 0) {
      const paidCompleted = payload.orders.filter((order: { payment_status?: string; order_status?: string }) =>
        order.payment_status === 'paid' && order.order_status === 'completed',
      );
      for (const order of paidCompleted) {
        expect(order.refund_eligible || order.can_refund).toBeTruthy();
        expect(order.void_eligible || order.can_void).toBeTruthy();
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
