import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';

test.describe('cashier: recent orders', () => {
  test('recent orders endpoint is reachable from POS context', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');

    const response = await page.request.get('/ajax/get_recent_orders.php');
    expect(response.status()).toBeLessThan(500);

    const payload = await response.json().catch(() => null);
    if (payload && typeof payload === 'object') {
      expect(payload).toHaveProperty('success');
    }
  });

  test('recent orders endpoint defaults to limit 30', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');

    const response = await page.request.get('/ajax/get_recent_orders.php');
    expect(response.status()).toBeLessThan(500);

    const payload = await response.json();
    expect(payload.success).toBe(true);
    expect(payload.limit).toBe(30);
    expect(Array.isArray(payload.orders)).toBe(true);
    expect(payload.orders.length).toBeLessThanOrEqual(30);
  });
});
