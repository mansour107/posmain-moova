import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { cartRowCount } from '../helpers/pos';

test.describe('cashier: variant select', () => {
  test('variant item opens modal and adds selected line', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    const variantCard = page.locator('.item-wrapper .item-has-variants[data-has-variants="1"]').first();
    test.skip((await variantCard.count()) === 0, 'Seed data needs at least one variant parent item');

    await variantCard.click();
    await expect(page.locator('#itemVariantModal')).toBeVisible({ timeout: 10_000 });
    await page.locator('#itemVariantModal .itemVariantChoice').first().click();
    await expect.poll(() => cartRowCount(page)).toBeGreaterThan(0);
  });
});
