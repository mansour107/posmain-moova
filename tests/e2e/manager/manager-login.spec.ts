import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';

test.describe('manager: login', () => {
  test('manager reaches dashboard', async ({ page }) => {
    await loginAs(page, 'manager');
    await expect(page).toHaveURL(/dashboard\.php/);
  });
});
