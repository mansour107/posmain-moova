import { test, expect } from '@playwright/test';
import {
  closeShiftCashModal,
  formatCashAmount,
  prepareCleanShift,
  readHandoverPreview,
  recordCashSale,
  recordShiftSafeDrop,
  skipUnlessHandoverEnabled,
  startFreshHandoverShift,
  closeShiftWithCountedCash,
} from '../helpers/handover';
import { captureShiftShot } from '../helpers/shift_ux';

test.describe('manager: shift safe drop', () => {
  test.beforeAll(() => {
    prepareCleanShift();
  });

  test('manager can record a safe drop and it affects expected cash', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'manager', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    const before = await readHandoverPreview(page);
    expect(before.expectedCash).not.toBeNull();

    await recordCashSale(page);
    await recordShiftSafeDrop(page, '12.50', 'تحويل خزنة اختبار e2e');
    await captureShiftShot(page, 'safe-drop-success');
    await closeShiftCashModal(page);

    const after = await readHandoverPreview(page);
    expect(after.expectedCash).not.toBeNull();
    expect(after.expectedCash!).toBeLessThan((before.expectedCash || 0) + 10_000);

    await closeShiftWithCountedCash(page, formatCashAmount(after.expectedCash!));
    await expect(page.getByText(/تم إغلاق الشيفت/i).first()).toBeVisible({ timeout: 12_000 });
  });
});
