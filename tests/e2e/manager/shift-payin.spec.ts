import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';

async function openDrawerCashModal(page: import('@playwright/test').Page): Promise<void> {
  const cashButton = page.locator(
  'button[data-bs-target="#shiftExpenseModal"], button[title="حركة نقدية للدرج"], button[title="تسجيل مصروف"]',
  ).first();
  await expect(cashButton).toBeVisible({ timeout: 15_000 });
  await cashButton.click();
  await expect(page.locator('#shiftExpenseModal')).toBeVisible({ timeout: 10_000 });
}

test.describe('manager: mid-shift pay-in logging', () => {
  test('manager can record a pay-in during the shift', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');

    await openDrawerCashModal(page);
    await page.locator('#shiftCashPayinTab').click();
    await expect(page.locator('#shiftCashPayinPane')).toHaveClass(/show active/);
    await expect(page.locator('#shift_payin_amount')).toBeEnabled({ timeout: 15_000 });

    await page.locator('#shift_payin_amount').fill('30.00');
    await page.locator('#shift_payin_reason').fill('تعبئة صندوق');
    await page.locator('#shiftPayinSaveBtn').click();

    await expect(page.locator('#shiftPayinFormAlert')).toContainText(/تم تسجيل الإيداع/i, { timeout: 10_000 });
    await expect(page.locator('#shiftPayinList')).toContainText(/30\.00|30,00/);
  });

  test('close preview shows pay-in total after recording', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');

    await openDrawerCashModal(page);
    await page.locator('#shiftCashPayinTab').click();
    await page.locator('#shift_payin_amount').fill('18.75');
    await page.locator('#shift_payin_reason').fill('فكة إضافية');
    await page.locator('#shiftPayinSaveBtn').click();
    await expect(page.locator('#shiftPayinFormAlert')).toContainText(/تم تسجيل الإيداع/i, { timeout: 10_000 });

    const closeButton = page.locator('[data-bs-target="#closeShiftModal"], button[title="إغلاق الشيفت"]').first();
    await closeButton.click();
    await expect(page.locator('#closeShiftModal')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#shiftPreview')).toContainText(/إيداعات الشيفت/i, { timeout: 15_000 });
    await expect(page.locator('#shiftPreview')).toContainText(/18\.75|18,75/);
  });
});
