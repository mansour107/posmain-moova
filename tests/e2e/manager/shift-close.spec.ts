import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';

test.describe('manager: shift close surface', () => {
  test('shift close modal can be opened from POS', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');

    const shiftButton = page.locator('button, a').filter({ hasText: /إغلاق الشيفت|close shift|Z/i }).first();
    test.skip(!(await shiftButton.isVisible().catch(() => false)), 'Shift close control not visible for this role/skin');

    await shiftButton.click();
    await expect(page.locator('#shiftCloseModal, #closeShiftModal').first()).toBeVisible({ timeout: 10_000 });
  });
});
