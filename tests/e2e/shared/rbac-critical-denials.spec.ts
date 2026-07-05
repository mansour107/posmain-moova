import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';
import { isHandlerRejected } from '../helpers/rbac';
import { personaCredentials } from '../helpers/env';

test('cashier cannot POST role creation handler', async ({ page }) => {
  await loginAs(page, 'cashier');
  const response = await page.request.post('/do/doadd_role.php', {
    form: {
      rollname: 'rbac-e2e-blocked',
      info: 'should fail',
    },
    failOnStatusCode: false,
    maxRedirects: 0,
  });
  expect(isHandlerRejected(response)).toBeTruthy();
});

test('admin can open users page', async ({ page }) => {
  await loginAs(page, 'admin');
  await page.goto('/users.php');
  await expect(page.locator('body')).not.toContainText(/PERMISSION_DENIED/i);
});
