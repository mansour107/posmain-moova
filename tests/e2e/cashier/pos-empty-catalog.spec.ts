import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';

test.describe('cashier: empty catalog state', () => {
  test('shows empty-state copy when no items match filter', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    await page.locator('#posUnifiedSearch').fill('__no_such_item_xyz_999__');
    await page.waitForTimeout(300);
    const visibleItems = await page.locator('#itemsGrid .item-wrapper:visible').count();
    expect(visibleItems).toBe(0);
  });
});
