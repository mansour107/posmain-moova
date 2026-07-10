import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';
import {
  forceCloseFirstOpenDrawer,
  prepareCleanShift,
  skipUnlessHandoverEnabled,
  startFreshHandoverShift,
} from '../helpers/handover';

test.describe('manager: shift handover admin operations', () => {
  test.beforeAll(() => {
    prepareCleanShift();
  });

  test('closed sessions shows open drawer panel when sessions are active', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'manager', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    await page.goto('/closed_sessions.php');
    await expect(page.getByRole('heading', { name: /جلسات درج مفتوحة/i })).toBeVisible({
      timeout: 15_000,
    });
    await expect(
      page.locator('[data-bs-target="#forceCloseDrawerModal"]').first()
        .or(page.getByText(/يتطلب صلاحية مدير/i).first()),
    ).toBeVisible({ timeout: 15_000 });
  });

  test('manager can force-close an open drawer when permitted', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'manager', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    const forced = await forceCloseFirstOpenDrawer(page, '100.00', 'e2e force close production test');
    expect(forced).toBe(true);
    await expect(page.getByText(/تم إغلاق جلسة الدرج|إغلاق/i).first()).toBeVisible({ timeout: 10_000 });
  });

  test('opening baseline modal hidden after branch has drawer sessions', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'manager', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    await page.goto('/closed_sessions.php');
    await expect(page.locator('[data-bs-target="#openingBaselineModal"]')).toHaveCount(0);
  });
});
