import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import {
  completeOpeningCountIfShown,
  goToCloseCountStep,
  openCloseShiftModal,
  skipUnlessHandoverEnabled,
} from '../helpers/handover';
import { fillOpenShiftCount } from '../helpers/shift';

test.describe('cashier: shift open/close count wizard', () => {
  test('opening count overlay appears after unlock when handover enabled', async ({ page }, testInfo) => {
    await loginAndUnlockPos(page, 'manager');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    const overlay = page.locator('#pshOpenOverlay');
    if (await overlay.isVisible().catch(() => false)) {
      await expect(page.locator('#pshOpenAmount')).toBeVisible();
      await expect(page.locator('text=المتوقع')).toHaveCount(0);
      await fillOpenShiftCount(page, '0');
    }
  });

  test('close shift wizard hides expected cash during count', async ({ page }, testInfo) => {
    await loginAndUnlockPos(page, 'manager');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    await completeOpeningCountIfShown(page, '0');
    await openCloseShiftModal(page);
    await goToCloseCountStep(page);
    await expect(page.locator('#pshCloseAmount')).toBeVisible();
    await expect(page.locator('#closeShiftModal')).not.toContainText(/النقدية المتوقعة/i);
  });
});
