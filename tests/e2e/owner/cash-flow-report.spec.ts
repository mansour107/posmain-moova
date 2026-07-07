import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';

test.describe('Cash flow report access', () => {
  test('owner can open cash flow report page', async ({ page }) => {
    await loginAs(page, 'owner');
    const response = await page.goto('/cash_flow_report.php');
    expect(response?.status()).toBeLessThan(400);
    await expect(page.locator('h1')).toContainText('تقرير التدفق النقدي');
    await expect(page.locator('form input[name="date_from"]')).toBeVisible();
  });

  test('cashier is denied cash flow report without permission', async ({ page }) => {
    await loginAs(page, 'cashier');
    const response = await page.goto('/cash_flow_report.php');
    const status = response?.status() ?? 0;
    const body = await page.content();
    expect(status === 403 || body.includes('403') || body.includes('غير مصرح') || body.includes('Permission')).toBeTruthy();
  });

  test('owner can load cash flow report JSON endpoint', async ({ page }) => {
    await loginAs(page, 'owner');
    const today = new Date().toISOString().slice(0, 10);
    const response = await page.request.get(`/ajax/cash_flow_report.php?date_from=${today}&date_to=${today}`);
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.success).toBe(true);
    expect(body.data?.summary).toBeTruthy();
    expect(body.data?.payment_breakdown).toBeTruthy();
  });
});
