import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { expectPremiumDarkTheme } from '../helpers/pos';

test.describe('cashier: premium theme', () => {
  test('loads immersive dark POS shell', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    await expectPremiumDarkTheme(page);
    await expect(page.locator('.pos-corner-menu')).toBeVisible();
    await expect(page.locator('.pos-topbar')).toBeHidden();
    await expect(page.locator('.pos-catalog-panel')).toBeVisible();
    await expect(page.locator('.pos-order-panel')).toBeVisible();
    await expect(page.locator('#posUnifiedSearch')).toHaveAttribute('placeholder', 'ابحث عن الصنف...');
  });
});
