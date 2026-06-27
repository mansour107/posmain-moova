import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';

test.describe('cashier: category filter', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
  });

  test('category pill filters visible items', async ({ page }) => {
    const allCount = await page.locator('#itemsGrid .item-wrapper').count();
    test.skip(allCount < 2, 'Need multiple items to validate category filtering');

    const categoryButton = page.locator('.category-btn').filter({ hasNotText: 'الكل' }).first();
    const categoryId = await categoryButton.getAttribute('data-category');
    test.skip(!categoryId || categoryId === 'all', 'Need a concrete category pill');

    await categoryButton.click();
    const filteredCount = await page.locator('#itemsGrid .item-wrapper:not(.d-none)').count();
    expect(filteredCount).toBeGreaterThan(0);
    expect(filteredCount).toBeLessThanOrEqual(allCount);
  });
});
