import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { cartRowCount } from '../helpers/pos';

test.describe('cashier: barcode search', () => {
  test('entering barcode in unified search adds item', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    const firstCard = page.locator('.item-wrapper .item-card.itemButton').first();
    const barcode = await firstCard.getAttribute('data-item-barcode');
    test.skip(!barcode, 'Seed item needs a barcode');

    await page.locator('#posUnifiedSearch').fill(barcode!);
    await page.locator('#posUnifiedSearch').press('Enter');
    await expect.poll(() => cartRowCount(page)).toBeGreaterThan(0);
  });
});
