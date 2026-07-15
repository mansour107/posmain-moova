import { test, expect } from '@playwright/test';
import { loginAs, logoutIfNeeded, assertNoFatalText } from '../helpers/auth';
import { assertDemoPersonaShape, isAccessBlocked } from '../helpers/rbac';

test.describe('Team Hub login activity', () => {
  test('admin sees login activity tab with recent sessions', async ({ page }) => {
    await loginAs(page, 'manager');
    await logoutIfNeeded(page);

    await loginAs(page, 'admin');
    await page.goto('/team.php?tab=logins', { waitUntil: 'domcontentloaded' });
    const body = await page.content();
    assertNoFatalText(body);

    await expect(page.locator('#tabLogins')).toBeVisible();
    await expect(page.locator('[data-testid="team-login-activity"]')).toBeVisible();
    await expect(page.locator('#loginsTableBody')).toBeVisible();

    const tableText = await page.locator('#loginsTableBody').innerText();
    expect(
      /p6_manager|p6_admin/i.test(tableText),
      `expected recent login usernames in table, got: ${tableText.slice(0, 200)}`,
    ).toBeTruthy();
  });

  test('cashier cannot open team login activity', async ({ page }) => {
    await loginAs(page, 'cashier');
    const response = await page.goto('/team.php?tab=logins', { waitUntil: 'domcontentloaded' });
    const body = await page.content();
    const url = page.url();
    expect(isAccessBlocked(response, body, url, '/team.php')).toBeTruthy();
  });

  test('manager without users.manage does not see login tab', async ({ page }) => {
    await loginAs(page, 'manager');
    await assertDemoPersonaShape(page, 'manager');

    const response = await page.goto('/team.php?tab=logins', { waitUntil: 'domcontentloaded' });
    const body = await page.content();
    const url = page.url();
    const blocked = isAccessBlocked(response, body, url, '/team.php');
    const noLoginTab = (await page.locator('#tabLogins').count()) === 0;
    expect(blocked || noLoginTab).toBeTruthy();
  });
});
