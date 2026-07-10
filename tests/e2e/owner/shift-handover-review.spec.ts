import { test, expect } from '@playwright/test';
import { loginAs, assertNoFatalText } from '../helpers/auth';
import { prepareCleanShift, startFreshHandoverShift } from '../helpers/handover';

test.describe('owner/manager: shift review and money tracking surfaces', () => {
  test.beforeAll(() => {
    prepareCleanShift();
  });

  test('closed sessions page loads handover admin sections', async ({ page }) => {
    await loginAs(page, 'manager');
    await page.goto('/closed_sessions.php');

    const body = await page.content();
    assertNoFatalText(body);
    await expect(page.locator('h1')).toContainText(/الشيفتات المغلقة/i);
  });

  test('cash flow report links to drawer session detail when sessions exist', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/cash_flow_report.php');

    const body = await page.content();
    assertNoFatalText(body);

    const sessionLink = page.locator('a[href*="drawer_session.php?id="]').first();
    if (!(await sessionLink.isVisible().catch(() => false))) {
      // No sessions in current date range — page still must load cleanly.
      await expect(page.locator('h1')).toContainText(/التدفق النقدي|Cash/i);
      return;
    }

    await sessionLink.click();
    await expect(page).toHaveURL(/drawer_session\.php\?id=\d+/);
    await expect(
      page.getByRole('heading', { name: /محاولات العد|سجل الحركات/i }).first(),
    ).toBeVisible({ timeout: 15_000 });
  });

  test('drawer session detail shows resolution history section when present', async ({ page }) => {
    await startFreshHandoverShift(page, 'manager', '100.00');
    await page.goto('/closed_sessions.php');

    const detailLink = page.locator('a[href*="drawer_session.php?id="]').first();
    await expect(detailLink).toBeVisible({ timeout: 15_000 });
    await detailLink.click();
    await expect(page).toHaveURL(/drawer_session\.php\?id=\d+/);

    const body = await page.content();
    assertNoFatalText(body);
    await expect(
      page.getByRole('heading', { name: /سجل الحلول|محاولات العد|سجل الحركات/i }).first(),
    ).toBeVisible({ timeout: 15_000 });
  });

  test('unresolved variance API returns JSON for managers', async ({ page }) => {
    await loginAs(page, 'manager');
    const response = await page.request.get('/ajax/shift_unresolved_list.php');
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body).toHaveProperty('success');
    if (body.success) {
      expect(Array.isArray(body.data)).toBe(true);
      expect(body.meta).toBeTruthy();
      expect(typeof body.meta.total).toBe('number');
    }
  });

  test('unresolved queue supports type filters and pagination', async ({ page }) => {
    await loginAs(page, 'manager');
    await page.goto('/closed_sessions.php');
    assertNoFatalText(await page.content());

    const panel = page.getByTestId('unresolved-queue-panel');
    if (!(await panel.isVisible().catch(() => false))) {
      test.skip(true, 'No unresolved cases in this environment');
      return;
    }

    await expect(page.getByTestId('unresolved-type-filters')).toBeVisible();
    await expect(page.getByTestId('unresolved-filter-all')).toHaveClass(/is-active/);

    const rowCount = await panel.locator('tbody tr').count();
    expect(rowCount).toBeLessThanOrEqual(10);

    await page.getByTestId('unresolved-filter-closing').click();
    await expect(page).toHaveURL(/unresolved_type=closing/);
    await expect(page.getByTestId('unresolved-filter-closing')).toHaveClass(/is-active/);

    const pagination = page.getByTestId('unresolved-queue-pagination');
    if (await pagination.isVisible().catch(() => false)) {
      await pagination.getByRole('link', { name: '2' }).first().click();
      await expect(page).toHaveURL(/unresolved_page=2/);
      await expect(page.getByTestId('unresolved-queue-meta')).toContainText(/الصفحة 2/);
    }
  });
});
