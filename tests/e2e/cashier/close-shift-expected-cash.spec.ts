import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { clickFirstAddableItem, payCashInModal } from '../helpers/pos';

async function openCloseShiftModal(page: import('@playwright/test').Page): Promise<void> {
  const closeButton = page.locator('[data-bs-target="#closeShiftModal"], button[title="إغلاق الشيفت"]').first();
  await expect(closeButton).toBeVisible({ timeout: 15_000 });
  await closeButton.click();
  await expect(page.locator('#closeShiftModal')).toBeVisible({ timeout: 10_000 });
}

async function openDrawerCashModal(page: import('@playwright/test').Page): Promise<void> {
  const cashButton = page.locator(
    'button[data-bs-target="#shiftExpenseModal"], button[title="حركة نقدية للدرج"], button[title="تسجيل مصروف"]',
  ).first();
  await expect(cashButton).toBeVisible({ timeout: 15_000 });
  await cashButton.click();
  await expect(page.locator('#shiftExpenseModal')).toBeVisible({ timeout: 10_000 });
}

function parseMoney(value: unknown): number {
  return Number(String(value ?? '0').replace(/,/g, ''));
}

test.describe('cashier: close-shift ledger stays correct while modal is blind', () => {
  test('cash sale updates the ledger but remains hidden in the close modal', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');

    const previewBefore = await page.request.get('/do/get_shift_preview.php');
    expect(previewBefore.ok()).toBeTruthy();
    const beforeBody = await previewBefore.json();
    expect(beforeBody.success).toBe(true);
    const expectedBefore = parseMoney(beforeBody.data?.expected_cash);
    const salesBefore = parseMoney(beforeBody.data?.total_sales);

    await clickFirstAddableItem(page);
    await payCashInModal(page);

    const previewAfterSale = await page.request.get('/do/get_shift_preview.php');
    expect(previewAfterSale.ok()).toBeTruthy();
    const afterSaleBody = await previewAfterSale.json();
    expect(afterSaleBody.success).toBe(true);
    const expectedAfterSale = parseMoney(afterSaleBody.data?.expected_cash);
    const salesAfter = parseMoney(afterSaleBody.data?.total_sales);
    expect(expectedAfterSale).toBeGreaterThan(expectedBefore);
    expect(salesAfter).toBeGreaterThan(salesBefore);

    await openCloseShiftModal(page);
    await expect(page.getByTestId('close-shift-guidance')).toBeVisible();
    await expect(page.locator('#closeShiftModal')).not.toContainText(/إجمالي المبيعات|النقدية المتوقعة|عدد الطلبات/i);
    await expect(page.locator('#closeShiftModal')).not.toContainText(String(expectedAfterSale));
  });

  test('pay-in and payout update expected cash without leaking it in the close modal', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');

    const previewBefore = await page.request.get('/do/get_shift_preview.php');
    const beforeBody = await previewBefore.json();
    const expectedBefore = parseMoney(beforeBody.data?.expected_cash);

    await openDrawerCashModal(page);
    await page.locator('#shiftCashPayinTab').click();
    await page.locator('#shift_payin_amount').fill('20.00');
    await page.locator('#shift_payin_reason').fill('فكة إضافية');
    await page.locator('#shiftPayinSaveBtn').click();
    await expect(page.locator('#shiftPayinFormAlert')).toContainText(/تم تسجيل الإيداع/i, { timeout: 10_000 });

    const previewAfterPayin = await page.request.get('/do/get_shift_preview.php');
    const afterPayinBody = await previewAfterPayin.json();
    const expectedAfterPayin = parseMoney(afterPayinBody.data?.expected_cash);
    expect(expectedAfterPayin).toBeCloseTo(expectedBefore + 20, 1);

    await page.locator('#shiftCashPayoutTab').click();
    await page.locator('#shift_expense_amount').fill('5.00');
    await page.locator('#shift_expense_reason').fill('مصروف صغير');
    await page.locator('#shiftExpenseSaveBtn').click();
    await expect(page.locator('#shiftExpenseFormAlert')).toContainText(/تم تسجيل المصروف/i, { timeout: 10_000 });

    const previewAfterExpense = await page.request.get('/do/get_shift_preview.php');
    const afterExpenseBody = await previewAfterExpense.json();
    const expectedAfterExpense = parseMoney(afterExpenseBody.data?.expected_cash);
    expect(expectedAfterExpense).toBeCloseTo(expectedAfterPayin - 5, 1);

    await openCloseShiftModal(page);
    await expect(page.getByTestId('close-shift-guidance')).toBeVisible();
    await expect(page.locator('#closeShiftModal')).not.toContainText(/إيداعات الشيفت|مصروفات الشيفت|النقدية المتوقعة/i);
    await expect(page.locator('#closeShiftModal')).not.toContainText(String(expectedAfterExpense));
  });
});
