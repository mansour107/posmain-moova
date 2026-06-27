import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { openRecentOrdersFromCorner } from '../helpers/pos';

test.describe('cashier: recent orders corner menu', () => {
  test('opens recent orders offcanvas from corner menu', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    await openRecentOrdersFromCorner(page);
    await expect(page.locator('#recentOrdersList')).toBeVisible();
  });
});
