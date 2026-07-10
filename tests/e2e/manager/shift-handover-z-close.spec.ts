import { test, expect } from '@playwright/test';
import {
  closeViaZReport,
  formatCashAmount,
  openZReport,
  prepareCleanShift,
  readHandoverPreview,
  skipUnlessHandoverEnabled,
  startFreshHandoverShift,
} from '../helpers/handover';

test.describe('manager: Z-report handover', () => {
  test.beforeAll(() => {
    prepareCleanShift();
  });

  test('Z close without completed count token stays on Z report with error', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'manager', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    await openZReport(page);
    const drawerSessionId = await page.locator('input[name="drawer_session_id"]').inputValue();
    expect(drawerSessionId).not.toBe('0');

    // Bypass the client count flow and POST without a close_token.
    const csrf = await page.locator('#closeForm input[name="csrf_token"]').inputValue();
    const response = await page.request.post('/do_close_shift_z.php', {
      form: {
        csrf_token: csrf,
        sys_total_sales: '0',
        sys_total_cash: '0',
        sys_total_visa: '0',
        sys_expenses: '0',
        expected_cash: '100',
        drawer_session_id: drawerSessionId,
        drawer_expected_cash: '100',
        drawer_cash_difference: '0',
        actual_cash: '100.00',
        actual_visa: '0',
        notes: 'e2e missing token',
      },
      maxRedirects: 0,
      failOnStatusCode: false,
    });

    expect([302, 303]).toContain(response.status());
    const location = response.headers().location || '';
    expect(location).toMatch(/z_report\.php/);

    await page.goto('/z_report.php');
    await expect(page.getByText(/عدّ النقد.*نقطة البيع|رمز إغلاق/i).first()).toBeVisible({
      timeout: 10_000,
    });
  });

  test('Z close with handover count flow succeeds', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'manager', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    const preview = await readHandoverPreview(page);
    const amount = preview.expectedCash != null ? formatCashAmount(preview.expectedCash) : '100.00';
    await closeViaZReport(page, amount, 'e2e z happy path');
  });
});
