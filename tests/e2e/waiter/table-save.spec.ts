import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { clickFirstAddableItem, readCartNet, saveTableOrderAndWait, selectFirstAvailableTable } from '../helpers/pos';

test.describe('waiter: table save', () => {
  test('waiter saves a table order without paying', async ({ page }) => {
    await loginAndUnlockPos(page, 'waiter');

    await selectFirstAvailableTable(page);
    await clickFirstAddableItem(page);
    expect(await readCartNet(page)).toBeGreaterThan(0);

    await saveTableOrderAndWait(page);

    const body = await page.content();
    expect(body).not.toMatch(/fatal error|SQL syntax/i);
  });
});
