import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { cartRowCount } from '../helpers/pos';

test.describe('cashier: order mode tabs', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
  });

  test('mode tabs sync hidden age radios', async ({ page }) => {
    await page.locator('.pos-mode-tab[data-age-target="age1"]').click();
    await expect(page.locator('#age1')).toBeChecked();

    await page.locator('.pos-mode-tab[data-age-target="age2"]').click();
    await expect(page.locator('#age2')).toBeChecked();
    await expect(page.locator('#tablesModal')).toBeVisible();

    await page.locator('#tablesModal .btn-close, #tablesModal [data-bs-dismiss="modal"]').first().click();
    await page.locator('.pos-mode-tab[data-age-target="age3"]').click();
    await expect(page.locator('#age3')).toBeChecked();
    await expect(page.locator('#deliveryModal')).toBeVisible({ timeout: 10_000 });
  });

  test('takeaway tab keeps cart usable', async ({ page }) => {
    await page.locator('.pos-mode-tab[data-age-target="age1"]').click();
    const firstCard = page.locator('.item-wrapper .item-card.itemButton').first();
    await firstCard.click();
    expect(await cartRowCount(page)).toBeGreaterThan(0);
  });
});
