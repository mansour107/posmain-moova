import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';
import { openTeamHubRolePermissions, openTeamHubStaffPermissions } from '../helpers/team-hub';

test('admin can open role permissions editor', async ({ page }) => {
  await loginAs(page, 'admin');
  await openTeamHubRolePermissions(page, 'مدير');
});

test('admin can open user permission overrides section', async ({ page }) => {
  await loginAs(page, 'admin');
  await openTeamHubStaffPermissions(page, 'p6_cashier');
  await expect(page.locator('input[data-perm]').first()).toBeAttached();
});
