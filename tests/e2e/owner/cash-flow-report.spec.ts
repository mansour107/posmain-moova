import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';

test.describe('Cash flow report access', () => {
  test('owner can open cash flow report page', async ({ page }) => {
    await loginAs(page, 'admin');
    const response = await page.goto('/cash_flow_report.php');
    expect(response?.status()).toBeLessThan(400);
    await expect(page.locator('h1')).toContainText('تقارير التشغيل');
    await expect(page.locator('#date_from')).toBeVisible();
  });

  test('kitchen is denied cash flow report without permission', async ({ page }) => {
    await loginAs(page, 'kitchen');
    const response = await page.goto('/cash_flow_report.php');
    const status = response?.status() ?? 0;
    const body = await page.content();
    const denied =
      status === 403 ||
      body.includes('403') ||
      body.includes('غير مصرح') ||
      body.includes('Permission') ||
      !body.includes('تقارير التشغيل');
    expect(denied).toBeTruthy();
  });

  test('owner can load cash flow report JSON endpoint', async ({ page }) => {
    await loginAs(page, 'admin');
    const today = new Date().toISOString().slice(0, 10);
    const response = await page.request.get(`/ajax/cash_flow_report.php?date_from=${today}&date_to=${today}`);
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.success).toBe(true);
    expect(body.data?.summary).toBeTruthy();
    expect(body.data?.payment_breakdown).toBeTruthy();
  });
});

test.describe('Cash flow report UX', () => {
  test.beforeEach(async ({ page }) => {
    await loginAs(page, 'admin');
    const start = new Date();
    start.setDate(start.getDate() - 6);
    const dateFrom = start.toISOString().slice(0, 10);
    const dateTo = new Date().toISOString().slice(0, 10);
    await page.goto(`/cash_flow_report.php?date_from=${dateFrom}&date_to=${dateTo}`);
  });

  test('renders the unified workspace fully in Arabic RTL', async ({ page }) => {
    const workspace = page.locator('#cashShiftWorkspace');
    await expect(workspace).toHaveAttribute('dir', 'rtl');
    await expect(workspace).toHaveAttribute('lang', 'ar');
    await expect(page.locator('h1')).toHaveText('تقارير التشغيل');
    await expect(page.getByRole('navigation', { name: 'أقسام تقارير التشغيل' })).toBeVisible();
    await expect(page.getByText('صافي المبيعات', { exact: true })).toBeVisible();
    await expect(page.getByText('إجمالي المبيعات', { exact: true })).toBeVisible();
    await expect(page.getByText('الخصومات', { exact: true })).toBeVisible();
    await expect(page.getByText('المرتجعات', { exact: true })).toBeVisible();
    expect(await workspace.evaluate((element) => getComputedStyle(element).direction)).toBe('rtl');
    expect(await workspace.evaluate((element) => getComputedStyle(element).textAlign)).toBe('right');
  });

  test('keeps every report section Arabic when navigating tabs', async ({ page }) => {
    const sections: Array<[string, string]> = [
      ['shifts', 'الشيفتات وجلسات الدرج'],
      ['orders', 'سجل الطلبات'],
      ['payments', 'تفاصيل التحصيل'],
      ['items', 'أداء الأصناف'],
      ['attention', 'مراجعة الملاحظات'],
      ['movements', 'سجل الدرج'],
      ['settings', 'يوم العمل'],
    ];
    const navigation = page.getByRole('navigation', { name: 'أقسام تقارير التشغيل' });

    for (const [tab, heading] of sections) {
      await navigation.locator(`a[href*="tab=${tab}"]`).click();
      await expect(page).toHaveURL(new RegExp(`tab=${tab}`));
      await expect(page.getByText(heading, { exact: true }).first()).toBeVisible();
      await expect(page.locator('#cashShiftWorkspace')).toHaveAttribute('dir', 'rtl');
    }
  });
});
