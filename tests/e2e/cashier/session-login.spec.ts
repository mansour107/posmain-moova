import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';

test.describe('cashier: session login', () => {
  test('cashier reaches POS after login', async ({ page }) => {
    await loginAs(page, 'cashier');
    await expect(page).toHaveURL(/pos_barcode\.php|register_pair\.php/);
  });
});
