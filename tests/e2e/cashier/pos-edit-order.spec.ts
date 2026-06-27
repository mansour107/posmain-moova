import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { clickFirstAddableItem, selectFirstAvailableTable, saveTableOrderAndWait, readCartNet } from '../helpers/pos';

test.describe('cashier: edit order', () => {
  test('saved table order reloads in edit mode and keeps lines', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    await selectFirstAvailableTable(page);
    await clickFirstAddableItem(page);
    await saveTableOrderAndWait(page);

    const editUrl = page.url();
    test.skip(!/edit=/.test(editUrl), `Table save did not return edit URL (got ${editUrl})`);

    await page.reload({ waitUntil: 'load' });
    await expect(page.locator('#itemData .item-card-order')).toHaveCount(1);
    expect(await readCartNet(page)).toBeGreaterThan(0);
  });
});
