import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';

test.describe('owner: admin login', () => {
  test('admin reaches dashboard', async ({ page }) => {
    await loginAs(page, 'admin');
    await expect(page).toHaveURL(/dashboard\.php/);
  });
});
