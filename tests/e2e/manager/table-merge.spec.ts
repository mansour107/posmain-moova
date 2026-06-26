import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import {
  clickFirstAddableItem,
  readCartNet,
  saveTableOrderAndWait,
  selectFirstAvailableTable,
} from '../helpers/pos';

test.describe('manager: table merge/move surface', () => {
  test('table transfer button is available in table mode', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');

    await selectFirstAvailableTable(page);
    await clickFirstAddableItem(page);
    expect(await readCartNet(page)).toBeGreaterThan(0);

    await saveTableOrderAndWait(page);

    const transferBtn = page.locator('#transferTableBtn, .pos-transfer-table-btn').first();
    await expect(transferBtn).toBeVisible({ timeout: 15_000 });

    await transferBtn.click();
    await expect(page.locator('#tablesModal')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#tablesModalLabel')).toContainText(/نقل الطاولة/i);

    const body = await page.content();
    expect(body).not.toMatch(/fatal error|SQL syntax/i);
  });
});
