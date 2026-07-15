import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';

test.describe('manager: login', () => {
  test('manager reaches useful back-office landing', async ({ page }) => {
    await loginAs(page, 'manager');
    await expect(page).toHaveURL(/sales-reports\.php|cash_flow_report\.php|inventory_dashboard\.php|dashboard\.php/);
  });
});
