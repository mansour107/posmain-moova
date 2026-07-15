import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';

test.describe('waiter: login', () => {
  test('waiter reaches POS after login', async ({ page }) => {
    await loginAs(page, 'waiter');
    await expect(page).toHaveURL(/pos_barcode\.php|pos_tables\.php|workspace\.php|register_pair\.php/);
  });
});
