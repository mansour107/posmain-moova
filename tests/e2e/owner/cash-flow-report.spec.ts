import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';

test.describe('Cash flow report access', () => {
  test('owner can open cash flow report page', async ({ page }) => {
    await loginAs(page, 'admin');
    const response = await page.goto('/cash_flow_report.php');
    expect(response?.status()).toBeLessThan(400);
    await expect(page.locator('h1')).toContainText('تقرير التدفق النقدي');
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
      !body.includes('تقرير التدفق النقدي');
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

  test('shows verdict strip and paginated sessions tab by default', async ({ page }) => {
    await expect(page.locator('.pr-verdict')).toBeVisible();
    await expect(page.getByTestId('cash-flow-tab-sessions')).toHaveClass(/is-active/);
    await expect(page.getByTestId('cash-flow-panel-sessions')).toHaveClass(/is-active/);

    const sessionRows = page.getByTestId('cash-flow-panel-sessions').locator('tbody tr');
    const rowCount = await sessionRows.count();
    expect(rowCount).toBeGreaterThan(0);
    expect(rowCount).toBeLessThanOrEqual(10);

    await expect(page.getByTestId('cash-flow-session-meta')).toContainText('جلسة');
  });

  test('switches to movements tab without scrolling through all sessions', async ({ page }) => {
    await expect(page.getByTestId('cash-flow-session-pagination')).toBeVisible();

    await page.getByTestId('cash-flow-tab-movements').click();
    await expect(page.getByTestId('cash-flow-panel-movements')).toHaveClass(/is-active/);
    await expect(page.locator('#movement_type')).toBeVisible();

    const visibleSessionRows = await page
      .getByTestId('cash-flow-panel-sessions')
      .locator('tbody tr')
      .count();
    expect(visibleSessionRows).toBeLessThanOrEqual(10);
  });

  test('session pagination navigates pages', async ({ page }) => {
    const pagination = page.getByTestId('cash-flow-session-pagination');
    if (!(await pagination.isVisible())) {
      test.skip();
    }

    const firstPageFirstCashier = await page
      .getByTestId('cash-flow-panel-sessions')
      .locator('tbody tr')
      .first()
      .locator('.pr-pill--user')
      .innerText();

    await pagination.locator('.page-item').filter({ hasText: '2' }).first().click();
    await expect(page).toHaveURL(/session_page=2/);

    const secondPageFirstCashier = await page
      .getByTestId('cash-flow-panel-sessions')
      .locator('tbody tr')
      .first()
      .locator('.pr-pill--user')
      .innerText();

    expect(secondPageFirstCashier).not.toBe(firstPageFirstCashier);
  });

  test('opens drawer session detail from sessions table', async ({ page }) => {
    const detailLink = page.getByTestId('session-detail-link').first();
    await expect(detailLink).toBeVisible();
    await detailLink.click();
    await expect(page).toHaveURL(/drawer_session\.php\?id=\d+/);
    await expect(page.locator('h1')).toBeVisible();
  });

  test('uses natural Arabic labels in overview', async ({ page }) => {
    await expect(page.locator('.pr-verdict-label', { hasText: 'المتوقع في الدرج' })).toBeVisible();
    await expect(page.locator('.pr-verdict-label', { hasText: 'ما تم عده في الدرج' })).toBeVisible();
    await expect(page.getByText('تفاصيل مسار النقد')).toBeVisible();
    await expect(page.getByText('تسوية المبيعات')).toHaveCount(0);
    await expect(page.getByText('معد فعلياً')).toHaveCount(0);
  });
});
