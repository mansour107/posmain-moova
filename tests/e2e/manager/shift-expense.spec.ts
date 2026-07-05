import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { fillCloseShiftForm } from '../helpers/shift';

async function openShiftExpenseModal(page: import('@playwright/test').Page): Promise<void> {
  const expenseButton = page.locator('button[data-bs-target="#shiftExpenseModal"], button[title="تسجيل مصروف"]').first();
  await expect(expenseButton).toBeVisible({ timeout: 15_000 });
  await expenseButton.click();
  await expect(page.locator('#shiftExpenseModal')).toBeVisible({ timeout: 10_000 });
  await expect(page.locator('#shift_expense_amount')).toBeEnabled({ timeout: 15_000 });
}

test.describe('manager: mid-shift expense logging', () => {
  test('cashier can record an expense during the shift', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');

    await openShiftExpenseModal(page);
    await page.locator('#shift_expense_amount').fill('12.50');
    await page.locator('#shift_expense_reason').fill('توصيل طلب');
    await page.locator('#shiftExpenseSaveBtn').click();

    await expect(page.locator('#shiftExpenseFormAlert')).toContainText(/تم تسجيل المصروف/i, { timeout: 10_000 });
    await expect(page.locator('#shiftExpenseList')).toContainText(/12\.50|12,50/);
    await expect(page.locator('.js-shift-expense-badge').first()).toBeVisible();
  });

  test('server rejects expense recording without active POS unlock', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');

    const openCloseShiftModal = page.locator('[data-bs-target="#closeShiftModal"], button[title="إغلاق الشيفت"]').first();
    await openCloseShiftModal.click();
    await fillCloseShiftForm(page);
    await page.locator('#closeShiftModal .pos-close-shift-btn-confirm').click();
    await page.waitForURL(/closed_sessions\.php/, { timeout: 20_000 });

    const res = await page.request.post('/do/do_record_shift_expense.php', {
      form: { amount: '5', reason: 'late expense', csrf_token: 'invalid' },
      maxRedirects: 0,
      failOnStatusCode: false,
    });
    expect([401, 403]).toContain(res.status());
  });
});
