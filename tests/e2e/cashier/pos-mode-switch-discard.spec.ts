import { test, expect } from '@playwright/test';
import { startFreshHandoverShift } from '../helpers/handover';
import { clickFirstAddableItem, selectOrderModeTab } from '../helpers/pos';

test('saved order mode switch discard clears cart without transfer', async ({ page }) => {
  await startFreshHandoverShift(page, 'cashier');
  await selectOrderModeTab(page, 'age1');
  await clickFirstAddableItem(page);
  await expect(page.locator('#itemData .item-card-order')).toHaveCount(1);

  // Simulate a saved-order edit context so the confirm dialog appears.
  await page.evaluate(() => {
    const edit = document.getElementById('edit_order_id') as HTMLInputElement | null;
    if (edit) {
      edit.value = '999001';
    }
  });

  await selectOrderModeTab(page, 'age2');
  const deny = page.locator('.swal2-deny, .pos-swal-premium__deny').filter({ hasText: 'بدء طلب جديد بدون نقل' });
  await expect(deny).toBeVisible({ timeout: 10_000 });
  await deny.click();

  await expect(page.locator('#age2')).toBeChecked();
  await expect(page.locator('#itemData .item-card-order')).toHaveCount(0);
  await expect(page.locator('.swal2-container')).toHaveCount(0);
  await expect(page.locator('#tablesModal')).toBeVisible();
});
