import { test, expect } from '@playwright/test';
import {
  closeShiftWithCountedCash,
  formatCashAmount,
  prepareCleanShift,
  readHandoverPreview,
  recordCashSale,
  recordShiftPayIn,
  recordShiftPayOut,
  parseMoney,
  skipUnlessHandoverEnabled,
  startFreshHandoverShift,
} from '../helpers/handover';

test.describe.serial('manager: shift handover money tracking', () => {
  test.beforeAll(() => {
    prepareCleanShift();
  });

  test('cash sale + pay-in + pay-out reflected in preview then matched blind close', async ({ page }, testInfo) => {
    test.setTimeout(120_000);
    await startFreshHandoverShift(page, 'manager', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    const baseline = await readHandoverPreview(page);
    const expectedStart = baseline.expectedCash ?? 100;

    await recordCashSale(page);
    await recordShiftPayIn(page, '25.00', 'فكة e2e handover');
    await recordShiftPayOut(page, '10.00', 'مصروف e2e handover');

    const preview = await readHandoverPreview(page);
    expect(preview.expectedCash).not.toBeNull();
    expect(parseMoney(preview.expectedCash)).toBeGreaterThan(expectedStart + 10);

    await closeShiftWithCountedCash(page, formatCashAmount(preview.expectedCash!));
    await expect(page.getByText(/تم إغلاق الشيفت/i)).toBeVisible({ timeout: 10_000 });
  });

  test('manager preview shows expected cash after movements (pre-close)', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'manager', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    const before = await readHandoverPreview(page);
    await recordShiftPayIn(page, '12.50', 'إيداع للاختبار');
    const after = await readHandoverPreview(page);

    expect(parseMoney(after.expectedCash)).toBeCloseTo(parseMoney(before.expectedCash) + 12.5, 1);
    expect(after.expectedCash).not.toBeNull();
  });
});
