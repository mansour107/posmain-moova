import { test, expect } from '@playwright/test';
import {
  assertCashModalTabBehavior,
  closeShiftCashModal,
  prepareCleanShift,
  recordShiftPayOut,
  skipUnlessHandoverEnabled,
  startFreshHandoverShift,
} from '../helpers/handover';
import { captureShiftShot } from '../helpers/shift_ux';

test.describe('cashier: shift expense persona', () => {
  test.beforeAll(() => {
    prepareCleanShift();
  });

  test('cashier can record an expense on an open shift', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'cashier', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    await recordShiftPayOut(page, '7.25', 'مصروف كاشير persona e2e');
    await captureShiftShot(page, 'cashier-expense-recorded');
  });

  test('cashier pay-in and safe-drop show manager-override affordance', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'cashier', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    await assertCashModalTabBehavior(page, 'cashier');
    await captureShiftShot(page, 'cashier-cash-modal-override-affordance');
    await closeShiftCashModal(page);
  });
});
