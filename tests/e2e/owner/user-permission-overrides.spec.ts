import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';

test('admin can open role permissions editor', async ({ page }) => {
  await loginAs(page, 'admin');
  await page.goto('/role_permissions.php?id=29', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('form[action*="doedit_role_permissions"]')).toBeVisible();
});

test('admin can open user permission overrides section', async ({ page }) => {
  await loginAs(page, 'admin');
  await page.goto('/users.php', { waitUntil: 'domcontentloaded' });
  const editLink = page.locator('a[href*="edit_user.php"]').first();
  await expect(editLink).toBeVisible();
  await editLink.click();
  await expect(page.locator('form[action*="doedit_user_permissions"], #permission-overrides, [name="permission_mode"]').first()).toBeVisible();
});
