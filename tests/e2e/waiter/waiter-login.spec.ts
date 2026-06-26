import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';

test.describe('waiter: login', () => {
  test('waiter reaches dashboard', async ({ page }) => {
    await loginAs(page, 'waiter');
    await expect(page).toHaveURL(/dashboard\.php/);
  });
});
