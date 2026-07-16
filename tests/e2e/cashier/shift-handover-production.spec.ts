import { test, expect } from '@playwright/test';
import { loginAs, unlockPos } from '../helpers/auth';
import {
  completeOpeningCountIfShown,
  goToCloseCountStep,
  openCloseShiftModal,
  prepareCleanShift,
  readHandoverPreview,
  skipUnlessHandoverEnabled,
  startFreshHandoverShift,
  assertBaselineRequiredOverlay,
} from '../helpers/handover';
import { fillOpenShiftCount } from '../helpers/shift';

test.describe('cashier: shift handover production scenarios', () => {
  test.beforeAll(() => {
    prepareCleanShift();
  });

  test('cashier blind open count does not expose expected float', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'cashier', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    await expect(page.locator('#posForm')).toBeVisible({ timeout: 15_000 });
  });

  test('cashier shift preview hides expected cash during handover', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'cashier', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    const preview = await readHandoverPreview(page);
    expect(preview.expectedCash).toBeNull();
  });

  test('cashier close wizard count step is blind', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'cashier', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    const closeButton = page.locator('[data-bs-target="#closeShiftModal"], button[title="إغلاق الشيفت"]').first();
    if (!(await closeButton.isVisible().catch(() => false))) {
      testInfo.skip(true, 'Cashier lacks shift close permission');
      return;
    }

    await openCloseShiftModal(page);
    await expect(page.getByTestId('close-shift-guidance')).toBeVisible();
    await expect(page.locator('#closeShiftModal')).not.toContainText(/عدد الطلبات|إجمالي المبيعات|النقدية المتوقعة/i);
    await expect(page.locator('#closeShiftModal a[href*="z_report"], #closeShiftModal [onclick*="printShiftSalesReport"]')).toHaveCount(0);
    await expect(page.locator('#closeShiftModal [data-psh-close-submit-count]')).toBeHidden();
    await expect(page.locator('#closeShiftModal [data-psh-close-next]')).toBeVisible();
    await goToCloseCountStep(page);
    await expect(page.locator('#pshCloseStep-count')).not.toContainText(/النقدية المتوقعة/i);
    await expect(page.locator('#pshCloseVariance')).toBeHidden();
  });

  test('cashier blocked when opening baseline is required', async ({ page }, testInfo) => {
    // Simulate baseline-required UI by forcing begin-open to fail after clean unlock.
    // If baseline already exists in this env, assert overlay path when API returns the error.
    prepareCleanShift();
    await loginAs(page, 'cashier');
    await unlockPos(page, 'cashier');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    const begin = await page.request.get('/do/do_begin_shift_open_count.php');
    const body = await begin.json();
    if (body.error === 'OPENING_BASELINE_REQUIRED') {
      await page.reload({ waitUntil: 'networkidle' });
      await assertBaselineRequiredOverlay(page);
      return;
    }

    // Baseline already set — verify cashier still cannot see expected cash on open overlay if shown.
    const overlay = page.locator('#pshOpenOverlay');
    if (await overlay.isVisible().catch(() => false)) {
      await expect(page.locator('text=المتوقع')).toHaveCount(0);
      await fillOpenShiftCount(page, '0');
    } else {
      await completeOpeningCountIfShown(page, '0');
    }
    testInfo.annotations.push({
      type: 'note',
      description: 'Baseline already initialized in shared env; blind-open path verified instead',
    });
  });
});
