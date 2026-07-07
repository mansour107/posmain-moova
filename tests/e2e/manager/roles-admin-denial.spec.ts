import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';
import { isAccessBlocked, assertNoApplicationError } from '../helpers/rbac';
import { openTeamHubRolePermissions } from '../helpers/team-hub';

test('cashier cannot open myroles.php', async ({ page }) => {
  await loginAs(page, 'cashier');
  const response = await page.goto('/myroles.php', { waitUntil: 'domcontentloaded' });
  const body = await page.content();
  assertNoApplicationError(body);
  expect(isAccessBlocked(response, body, page.url(), '/myroles.php')).toBeTruthy();
});

test('cashier cannot open add_role.php', async ({ page }) => {
  await loginAs(page, 'cashier');
  const response = await page.goto('/add_role.php', { waitUntil: 'domcontentloaded' });
  const body = await page.content();
  assertNoApplicationError(body);
  expect(isAccessBlocked(response, body, page.url(), '/add_role.php')).toBeTruthy();
});

test('admin can open role permissions editor', async ({ page }) => {
  await loginAs(page, 'admin');
  await openTeamHubRolePermissions(page, 'مدير');
});
