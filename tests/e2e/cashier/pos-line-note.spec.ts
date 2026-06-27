import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { clickFirstAddableItem, cartRowCount } from '../helpers/pos';

test.describe('cashier: line note', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
  });

  test('line note button opens modal on cart row', async ({ page }) => {
    await clickFirstAddableItem(page);
    await page.locator('#itemData .lineNoteButton').first().click();
    await expect(page.locator('#lineNoteModal')).toBeVisible({ timeout: 5_000 });
    await page.locator('#lineNoteModal textarea, #lineNoteInputField').first().fill('بدون سكر');
    await page.locator('#lineNoteModal .btn-primary, #lineNoteModal [data-line-note-save]').first().click();
    await expect(page.locator('#itemData .line-note-has-value')).toHaveCount(1);
    expect(await cartRowCount(page)).toBe(1);
  });
});
