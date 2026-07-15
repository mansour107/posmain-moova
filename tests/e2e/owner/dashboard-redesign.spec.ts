import { test, expect } from '@playwright/test';
import { loginAs, gotoAfterLogin, assertNoFatalText } from '../helpers/auth';
import { isAccessBlocked } from '../helpers/rbac';

test.describe('owner: dashboard redesign', () => {
  test('admin dashboard shows today KPIs needs attention and quick actions', async ({ page }) => {
    await loginAs(page, 'admin');
    await gotoAfterLogin(page, '/dashboard.php');
    const body = await page.content();
    assertNoFatalText(body);

    await expect(page.locator('body')).toHaveClass(/premium-report-page/);
    await expect(page.locator('.premium-report h1, h1.dashboard-page-title')).toContainText('الرئيسية');
    await expect(page.locator('[data-testid="dashboard-today-kpis"]')).toBeVisible();
    await expect(page.locator('[data-testid="dashboard-quick-actions"]')).toBeVisible();
    await expect(page.locator('[data-testid="dashboard-needs-attention"]')).toBeVisible();
    await expect(page.locator('.pr-verdict-card').first()).toBeVisible();

    const attention = page.locator('[data-testid="dashboard-needs-attention"]');
    const healthy = attention.locator('.dashboard-healthy-state, .pr-callout--success');
    const rows = attention.locator('.pr-dashboard-attention-row');
    const healthyVisible = await healthy.count();
    const rowCount = await rows.count();
    expect(healthyVisible + (rowCount > 0 ? 1 : 0)).toBeGreaterThan(0);
    if (healthyVisible > 0) {
      await expect(healthy).toContainText('لا يوجد ما يتطلب انتباهك');
    }
  });

  test('admin dashboard has no dead hash links', async ({ page }) => {
    await loginAs(page, 'admin');
    await gotoAfterLogin(page, '/dashboard.php');

    const badHrefs = await page.locator('.content-wrapper a[href]').evaluateAll((anchors) =>
      anchors
        .map((a) => (a as HTMLAnchorElement).getAttribute('href') || '')
        .filter((href) => href.trim() === '#' || href.trim() === ''),
    );
    expect(badHrefs, `dead links found: ${badHrefs.join(', ')}`).toEqual([]);
  });

  test('admin quick actions navigate to real pages', async ({ page }) => {
    await loginAs(page, 'admin');
    await gotoAfterLogin(page, '/dashboard.php');

    const hrefs = await page.locator('[data-testid="dashboard-quick-actions"] a[href]').evaluateAll((anchors) =>
      anchors.map((a) => (a as HTMLAnchorElement).getAttribute('href') || ''),
    );
    expect(hrefs.length).toBeGreaterThan(0);
    expect(hrefs.length).toBeLessThanOrEqual(6);

    for (const href of hrefs) {
      expect(href).not.toBe('#');
      const response = await page.request.get('/' + href.replace(/^\//, ''));
      expect(response.status(), `GET ${href}`).toBeLessThan(500);
      const text = await response.text();
      assertNoFatalText(text);
    }
  });

  test('admin sales KPI drill-down opens operations summary', async ({ page }) => {
    await loginAs(page, 'admin');
    await gotoAfterLogin(page, '/dashboard.php');

    const salesLink = page.locator('[data-testid="dashboard-today-kpis"] a[data-kpi="today_sales"]').first();
    await expect(salesLink).toBeVisible();
    await salesLink.click();
    await page.waitForURL(/operations_summary\.php/, { timeout: 20_000 });
    const body = await page.content();
    assertNoFatalText(body);
  });

  test('cashier cannot stay on dashboard', async ({ page }) => {
    await loginAs(page, 'cashier');
    const response = await page.goto('/dashboard.php', { waitUntil: 'domcontentloaded' });
    const body = await page.content();
    const url = page.url();
    const blocked = isAccessBlocked(response, body, url, '/dashboard.php');
    const leftDashboard = !/dashboard\.php/i.test(url);
    expect(blocked || leftDashboard).toBeTruthy();
  });
});
